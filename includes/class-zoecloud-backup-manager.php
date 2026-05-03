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
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zoecloud_zip_missing', __( 'ZipArchive is required to create backups.', 'zoe-cloud' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'include_core' => false,
				'upload_drive' => true,
			)
		);

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
		);

		$database_result = $this->export_database( $working_dir . '/database.sql' );

		if ( is_wp_error( $database_result ) ) {
			$this->cleanup_directory( $working_dir );
			return $database_result;
		}

		file_put_contents( $working_dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$zip = new ZipArchive();

		if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->cleanup_directory( $working_dir );
			return new WP_Error( 'zoecloud_zip_failed', __( 'Could not create the backup archive.', 'zoe-cloud' ) );
		}

		$zip->addEmptyDir( 'files' );
		$this->add_path_to_zip( $zip, WP_CONTENT_DIR, 'files/wp-content', array( wp_normalize_path( $storage_dir ) ) );

		if ( ! empty( $args['include_core'] ) ) {
			$this->add_core_to_zip( $zip, array( wp_normalize_path( WP_CONTENT_DIR ), wp_normalize_path( $storage_dir ) ) );
		}

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
	 * @return true|WP_Error
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

		fwrite( $handle, "-- ZoeCloud database export\nSET foreign_key_checks = 0;\n\n" );

		foreach ( $tables as $table ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			fwrite( $handle, "DROP TABLE IF EXISTS `{$table}`;\n" );
			fwrite( $handle, $create[1] . ";\n\n" );

			$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );

			foreach ( $rows as $row ) {
				$values = array();

				foreach ( $row as $value ) {
					if ( null === $value ) {
						$values[] = 'NULL';
						continue;
					}

					$values[] = "'" . $this->escape_sql_value( $value ) . "'";
				}

				fwrite( $handle, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
			}

			fwrite( $handle, "\n" );
		}

		fwrite( $handle, "SET foreign_key_checks = 1;\n" );
		fclose( $handle );

		return true;
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
	 * Add a file tree to the archive.
	 *
	 * @param ZipArchive $zip           Zip archive.
	 * @param string     $source_path   Absolute source path.
	 * @param string     $archive_root  Target path inside the zip.
	 * @param array      $excluded_roots Absolute normalized paths to skip.
	 * @return void
	 */
	private function add_path_to_zip( ZipArchive $zip, $source_path, $archive_root, array $excluded_roots = array() ) {
		$source_path = wp_normalize_path( $source_path );

		if ( ! is_dir( $source_path ) ) {
			return;
		}

		$archive_root = trim( $archive_root, '/' );
		$zip->addEmptyDir( $archive_root );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$item_path = wp_normalize_path( $item->getPathname() );

			if ( $this->is_excluded_path( $item_path, $excluded_roots ) ) {
				continue;
			}

			$relative     = ltrim( substr( $item_path, strlen( $source_path ) ), '/' );
			$archive_path = $archive_root . '/' . $relative;

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $archive_path );
				continue;
			}

			$zip->addFile( $item_path, $archive_path );
		}
	}

	/**
	 * Add WordPress core files to the archive.
	 *
	 * @param ZipArchive $zip            Zip archive.
	 * @param array      $excluded_roots Paths to exclude.
	 * @return void
	 */
	private function add_core_to_zip( ZipArchive $zip, array $excluded_roots ) {
		$root_items = scandir( ABSPATH );

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
				$this->add_path_to_zip( $zip, $source_path, 'files/' . $item, $excluded_roots );
				continue;
			}

			$zip->addFile( $source_path, 'files/' . $item );
		}
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
