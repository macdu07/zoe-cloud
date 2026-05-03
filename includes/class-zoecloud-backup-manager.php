<?php
/**
 * Backup generation.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZoeCloud_Backup_Manager {
	/**
	 * Drive service.
	 *
	 * @var ZoeCloud_Drive_Service
	 */
	private $drive_service;

	/**
	 * Number of rows read per database batch.
	 *
	 * @var int
	 */
	private $db_batch_size = 250;

	/**
	 * Constructor.
	 *
	 * @param ZoeCloud_Drive_Service $drive_service Drive service.
	 */
	public function __construct( ZoeCloud_Drive_Service $drive_service ) {
		$this->drive_service = $drive_service;
	}

	/**
	 * Create a full site backup.
	 *
	 * @param array $args Backup arguments.
	 * @return array|WP_Error
	 */
	public function create_backup( array $args = array() ) {
		$preflight = $this->get_preflight_status();

		if ( ! $preflight['ready'] ) {
			return new WP_Error( 'zoecloud_preflight_failed', __( 'Server requirements are not met for backups.', 'zoe-cloud' ), $preflight );
		}

		$args     = wp_parse_args(
			$args,
			array(
				'include_core' => false,
				'upload_drive' => true,
			)
		);
		$settings = $this->get_settings();

		$domain       = wp_parse_url( home_url(), PHP_URL_HOST );
		$timestamp    = gmdate( 'Y-m-d-H-i' );
		$slug         = sanitize_title_with_dashes( (string) $domain );
		$filename     = sprintf( 'backup-%1$s-%2$s.zip', $slug, $timestamp );
		$storage_dir  = $this->get_storage_dir();
		$working_dir  = trailingslashit( $storage_dir ) . 'tmp-' . wp_generate_password( 12, false, false );
		$archive_path = trailingslashit( $storage_dir ) . $filename;

		wp_mkdir_p( $working_dir );

		$manifest = array(
			'plugin_version' => ZOECLOUD_VERSION,
			'generated_at'   => gmdate( 'c' ),
			'domain'         => (string) $domain,
			'home_url'       => home_url(),
			'site_url'       => site_url(),
			'include_core'   => (bool) $args['include_core'],
			'wordpress'      => get_bloginfo( 'version' ),
			'exclusions'     => $settings['excluded_paths'],
			'files_count'    => 0,
			'files_size'     => 0,
			'database_tables' => 0,
			'database_rows'  => 0,
			'database_table_names' => array(),
		);

		$database_result = $this->export_database( $working_dir . '/database.sql' );

		if ( is_wp_error( $database_result ) ) {
			$this->cleanup_directory( $working_dir );
			return $database_result;
		}

		$manifest['database_tables'] = $database_result['tables'];
		$manifest['database_rows']   = $database_result['rows'];
		$manifest['database_table_names'] = $database_result['table_names'];

		file_put_contents( $working_dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$zip = new ZipArchive();

		if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->cleanup_directory( $working_dir );
			return new WP_Error( 'zoecloud_zip_failed', __( 'Could not create the backup archive.', 'zoe-cloud' ) );
		}

		$zip->addEmptyDir( 'files' );
		$excluded_roots = $this->build_excluded_roots( $storage_dir, $settings['excluded_paths'] );
		$file_stats     = $this->add_path_to_zip( $zip, WP_CONTENT_DIR, 'files/wp-content', $excluded_roots );

		if ( ! empty( $args['include_core'] ) ) {
			$core_stats = $this->add_core_to_zip( $zip, array_merge( $excluded_roots, array( wp_normalize_path( WP_CONTENT_DIR ) ) ) );
			$file_stats['count'] += $core_stats['count'];
			$file_stats['size']  += $core_stats['size'];
		}

		$manifest['files_count'] = $file_stats['count'];
		$manifest['files_size']  = $file_stats['size'];
		file_put_contents( $working_dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$zip->addFile( $working_dir . '/database.sql', 'database.sql' );
		$zip->addFile( $working_dir . '/manifest.json', 'manifest.json' );
		$zip->close();
		$this->cleanup_directory( $working_dir );

		$record = array(
			'id'           => wp_generate_uuid4(),
			'created_at'   => current_time( 'mysql', true ),
			'filename'     => $filename,
			'path'         => $archive_path,
			'download_url' => $this->build_download_url( $filename ),
			'size'         => file_exists( $archive_path ) ? filesize( $archive_path ) : 0,
			'manifest'     => $manifest,
			'drive'        => null,
		);

		if ( ! empty( $args['upload_drive'] ) ) {
			$drive_upload = $this->drive_service->upload_backup( $archive_path, $manifest );

			if ( ! is_wp_error( $drive_upload ) ) {
				$record['drive'] = $drive_upload;
			} else {
				$record['drive_error'] = $drive_upload->get_error_message();
			}
		}

		$this->store_record( $record );
		$this->apply_retention_policy();

		return $record;
	}

	/**
	 * List stored backups.
	 *
	 * @return array
	 */
	public function list_backups() {
		$records = get_option( 'zoecloud_backups', array() );

		return is_array( $records ) ? array_values( $records ) : array();
	}

	/**
	 * Inspect server requirements before a backup runs.
	 *
	 * @return array
	 */
	public function get_preflight_status() {
		$storage_dir = $this->get_storage_dir();
		$checks      = array(
			'ziparchive'       => class_exists( 'ZipArchive' ),
			'uploads_writable' => wp_is_writable( $storage_dir ),
			'can_create_files' => $this->can_create_storage_file( $storage_dir ),
			'disk_free_bytes'  => function_exists( 'disk_free_space' ) ? (int) disk_free_space( $storage_dir ) : null,
			'memory_limit'     => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
		);

		$checks['ready'] = ! empty( $checks['ziparchive'] ) && ! empty( $checks['uploads_writable'] ) && ! empty( $checks['can_create_files'] );

		return $checks;
	}

	/**
	 * Resolve a backup file by filename.
	 *
	 * @param string $filename Archive name.
	 * @return string
	 */
	public function get_backup_path( $filename ) {
		return trailingslashit( $this->get_storage_dir() ) . basename( $filename );
	}

	/**
	 * Stream a backup file to the browser.
	 *
	 * @return void
	 */
	public function stream_backup_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download backups.', 'zoe-cloud' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'zoecloud_download_backup' );

		$filename = isset( $_GET['filename'] ) ? sanitize_file_name( wp_unslash( $_GET['filename'] ) ) : '';
		$path     = $this->get_backup_path( $filename );

		if ( empty( $filename ) || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'zoe-cloud' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Background task entrypoint.
	 *
	 * @return void
	 */
	public function run_scheduled_backup() {
		$settings = wp_parse_args(
			get_option( 'zoecloud_settings', array() ),
			array(
				'auto_upload_drive' => 1,
			)
		);

		$this->create_backup(
			array(
				'include_core' => false,
				'upload_drive' => ! empty( $settings['auto_upload_drive'] ),
			)
		);
	}

	/**
	 * Create SQL dump.
	 *
	 * @param string $target_file SQL path.
	 * @return array|WP_Error
	 */
	private function export_database( $target_file ) {
		global $wpdb;

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		if ( empty( $tables ) ) {
			return new WP_Error( 'zoecloud_db_tables_missing', __( 'No database tables were found.', 'zoe-cloud' ) );
		}

		$handle = fopen( $target_file, 'wb' );

		if ( false === $handle ) {
			return new WP_Error( 'zoecloud_db_dump_failed', __( 'Could not write the database dump.', 'zoe-cloud' ) );
		}

		$total_rows = 0;

		fwrite( $handle, "-- ZoeCloud database export\nSET foreign_key_checks = 0;\n\n" );

		foreach ( $tables as $table ) {
			$table_name = $this->quote_identifier( $table );
			$create     = $wpdb->get_row( "SHOW CREATE TABLE {$table_name}", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( empty( $create[1] ) ) {
				fclose( $handle );
				return new WP_Error( 'zoecloud_db_schema_failed', __( 'Could not export a database table schema.', 'zoe-cloud' ), array( 'table' => $table ) );
			}

			fwrite( $handle, "DROP TABLE IF EXISTS {$table_name};\n" );
			fwrite( $handle, $create[1] . ";\n\n" );

			$offset = 0;

			do {
				$query = $wpdb->prepare( "SELECT * FROM {$table_name} LIMIT %d OFFSET %d", $this->db_batch_size, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows  = $wpdb->get_results( $query, ARRAY_A );

				foreach ( $rows as $row ) {
					$this->write_insert_statement( $handle, $table_name, $row );
					$total_rows++;
				}

				$offset += $this->db_batch_size;
			} while ( count( $rows ) === $this->db_batch_size );

			fwrite( $handle, "\n" );
		}

		fwrite( $handle, "SET foreign_key_checks = 1;\n" );
		fclose( $handle );

		return array(
			'tables'      => count( $tables ),
			'rows'        => $total_rows,
			'table_names' => array_values( $tables ),
		);
	}

	/**
	 * Store a backup record.
	 *
	 * @param array $record Backup metadata.
	 * @return void
	 */
	private function store_record( array $record ) {
		$records   = $this->list_backups();
		$records[] = $record;

		usort(
			$records,
			static function ( $left, $right ) {
				return strcmp( $right['created_at'], $left['created_at'] );
			}
		);

		update_option( 'zoecloud_backups', $records, false );
	}

	/**
	 * Remove old backups according to retention.
	 *
	 * @return void
	 */
	private function apply_retention_policy() {
		$settings  = wp_parse_args( get_option( 'zoecloud_settings', array() ), array( 'retention_limit' => 10 ) );
		$retention = max( 1, absint( $settings['retention_limit'] ) );
		$records   = $this->list_backups();

		if ( count( $records ) <= $retention ) {
			return;
		}

		$expired = array_slice( $records, $retention );
		$active  = array_slice( $records, 0, $retention );

		foreach ( $expired as $record ) {
			if ( ! empty( $record['path'] ) && file_exists( $record['path'] ) ) {
				unlink( $record['path'] );
			}
		}

		update_option( 'zoecloud_backups', $active, false );
	}

	/**
	 * Compute storage dir.
	 *
	 * @return string
	 */
	private function get_storage_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'zoecloud-backups';

		wp_mkdir_p( $dir );
		$this->protect_storage_directory( $dir );

		return $dir;
	}

	/**
	 * Build authenticated download URL.
	 *
	 * @param string $filename Archive filename.
	 * @return string
	 */
	private function build_download_url( $filename ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'zoecloud_download_backup',
					'filename' => $filename,
				),
				admin_url( 'admin-post.php' )
			),
			'zoecloud_download_backup'
		);
	}

	/**
	 * Escape a scalar value for SQL dumps.
	 *
	 * @param mixed $value Database value.
	 * @return string
	 */
	private function escape_sql_value( $value ) {
		return strtr(
			(string) $value,
			array(
				"\\" => "\\\\",
				"\0" => "\\0",
				"\n" => "\\n",
				"\r" => "\\r",
				"'"  => "\\'",
				'"'  => '\\"',
				"\x1a" => "\\Z",
			)
		);
	}

	/**
	 * Write one INSERT statement preserving explicit column names.
	 *
	 * @param resource $handle     File handle.
	 * @param string   $table_name Escaped table name.
	 * @param array    $row        Database row.
	 * @return void
	 */
	private function write_insert_statement( $handle, $table_name, array $row ) {
		$columns = array();
		$values  = array();

		foreach ( $row as $column => $value ) {
			$columns[] = $this->quote_identifier( $column );

			if ( null === $value ) {
				$values[] = 'NULL';
				continue;
			}

			$values[] = "'" . $this->escape_sql_value( $value ) . "'";
		}

		fwrite( $handle, "INSERT INTO {$table_name} (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ");\n" );
	}

	/**
	 * Escape a MySQL identifier.
	 *
	 * @param string $identifier Raw identifier.
	 * @return string
	 */
	private function quote_identifier( $identifier ) {
		return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
	}

	/**
	 * Add a file tree to the archive.
	 *
	 * @param ZipArchive $zip           Zip archive.
	 * @param string     $source_path   Absolute source path.
	 * @param string     $archive_root  Target path inside the zip.
	 * @param array      $excluded_roots Absolute normalized paths to skip.
	 * @return array
	 */
	private function add_path_to_zip( ZipArchive $zip, $source_path, $archive_root, array $excluded_roots = array() ) {
		$source_path = wp_normalize_path( $source_path );
		$stats       = array(
			'count' => 0,
			'size'  => 0,
		);

		if ( ! is_dir( $source_path ) ) {
			return $stats;
		}

		$archive_root = trim( $archive_root, '/' );
		$zip->addEmptyDir( $archive_root );

		$directory = new RecursiveDirectoryIterator( $source_path, RecursiveDirectoryIterator::SKIP_DOTS );
		$filter    = new RecursiveCallbackFilterIterator(
			$directory,
			function ( $item ) use ( $excluded_roots ) {
				$item_path = wp_normalize_path( $item->getPathname() );

				return ! $this->is_excluded_path( $item_path, $excluded_roots ) && ! $item->isLink() && $item->isReadable();
			}
		);
		$iterator  = new RecursiveIteratorIterator(
			$filter,
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = wp_normalize_path( $item->getPathname() );

			$relative     = ltrim( substr( $item_path, strlen( $source_path ) ), '/' );
			$archive_path = $archive_root . '/' . $relative;

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $archive_path );
				continue;
			}

			$zip->addFile( $item_path, $archive_path );
			$stats['count']++;
			$stats['size'] += $item->getSize();
		}

		return $stats;
	}

	/**
	 * Add WordPress core files to the archive.
	 *
	 * @param ZipArchive $zip            Zip archive.
	 * @param array      $excluded_roots Paths to exclude.
	 * @return array
	 */
	private function add_core_to_zip( ZipArchive $zip, array $excluded_roots ) {
		$root_items = scandir( ABSPATH );
		$stats      = array(
			'count' => 0,
			'size'  => 0,
		);

		foreach ( $root_items as $item ) {
			if ( '.' === $item || '..' === $item || 'wp-content' === $item ) {
				continue;
			}

			$source_path = trailingslashit( ABSPATH ) . $item;
			$source_path = wp_normalize_path( $source_path );

			if ( $this->is_excluded_path( $source_path, $excluded_roots ) ) {
				continue;
			}

			if ( is_dir( $source_path ) ) {
				$path_stats = $this->add_path_to_zip( $zip, $source_path, 'files/' . $item, $excluded_roots );
				$stats['count'] += $path_stats['count'];
				$stats['size']  += $path_stats['size'];
				continue;
			}

			$zip->addFile( $source_path, 'files/' . $item );
			$stats['count']++;
			$stats['size'] += filesize( $source_path );
		}

		return $stats;
	}

	/**
	 * Check whether a path should be skipped.
	 *
	 * @param string $path           Path to inspect.
	 * @param array  $excluded_roots Excluded base paths.
	 * @return bool
	 */
	private function is_excluded_path( $path, array $excluded_roots ) {
		foreach ( $excluded_roots as $excluded_root ) {
			$excluded_root = trailingslashit( wp_normalize_path( $excluded_root ) );

			if ( 0 === strpos( trailingslashit( $path ), $excluded_root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Protect generated backup files from direct listing.
	 *
	 * @param string $dir Storage directory.
	 * @return void
	 */
	private function protect_storage_directory( $dir ) {
		$index_file = trailingslashit( $dir ) . 'index.php';

		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n<FilesMatch \"\\.(zip|sql)$\">\nDeny from all\n</FilesMatch>\n" );
		}
	}

	/**
	 * Return normalized plugin settings used by backups.
	 *
	 * @return array
	 */
	private function get_settings() {
		$settings = wp_parse_args(
			get_option( 'zoecloud_settings', array() ),
			array(
				'excluded_paths' => array(),
			)
		);

		if ( is_string( $settings['excluded_paths'] ) ) {
			$settings['excluded_paths'] = preg_split( '/\r\n|\r|\n/', $settings['excluded_paths'] );
		}

		$settings['excluded_paths'] = array_values(
			array_filter(
				array_map( 'trim', (array) $settings['excluded_paths'] )
			)
		);

		return $settings;
	}

	/**
	 * Build absolute exclusion roots from settings.
	 *
	 * @param string $storage_dir    Backup storage directory.
	 * @param array  $excluded_paths User configured exclusions.
	 * @return array
	 */
	private function build_excluded_roots( $storage_dir, array $excluded_paths ) {
		$roots = array(
			wp_normalize_path( $storage_dir ),
			wp_normalize_path( WP_CONTENT_DIR . '/cache' ),
			wp_normalize_path( WP_CONTENT_DIR . '/upgrade' ),
			wp_normalize_path( WP_CONTENT_DIR . '/debug.log' ),
		);

		foreach ( $excluded_paths as $path ) {
			$path = ltrim( wp_normalize_path( $path ), '/' );

			if ( '' === $path ) {
				continue;
			}

			$roots[] = wp_normalize_path( trailingslashit( ABSPATH ) . $path );
		}

		return array_unique( $roots );
	}

	/**
	 * Verify a tiny file can be created in storage.
	 *
	 * @param string $storage_dir Storage directory.
	 * @return bool
	 */
	private function can_create_storage_file( $storage_dir ) {
		$probe = trailingslashit( $storage_dir ) . '.zoecloud-write-test';

		if ( false === file_put_contents( $probe, 'ok' ) ) {
			return false;
		}

		unlink( $probe );

		return true;
	}

	/**
	 * Recursively remove a temp directory.
	 *
	 * @param string $path Directory.
	 * @return void
	 */
	private function cleanup_directory( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getRealPath() ) : unlink( $item->getRealPath() );
		}

		rmdir( $path );
	}
}
