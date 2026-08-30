<?php
/**
 * Restore workflows.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates backup archives and restores files/database content.
 */
class ZoeCloud_Restore_Manager {
	/**
	 * Private storage service.
	 *
	 * @var ZoeCloud_Storage
	 */
	private $storage;

	/**
	 * Backup repository.
	 *
	 * @var ZoeCloud_Backup_Repository
	 */
	private $backups;

	/**
	 * Create a restore service.
	 *
	 * @param ZoeCloud_Storage|null           $storage Private storage service.
	 * @param ZoeCloud_Backup_Repository|null $backups Backup repository.
	 */
	public function __construct( $storage = null, $backups = null ) {
		$this->storage = $storage instanceof ZoeCloud_Storage ? $storage : new ZoeCloud_Storage();
		$this->backups = $backups instanceof ZoeCloud_Backup_Repository ? $backups : new ZoeCloud_Backup_Repository();
	}

	/**
	 * Number of rows processed per search/replace batch.
	 *
	 * @var int
	 */
	private $replace_batch_size = 250;

	/**
	 * Maximum ZIP entries accepted during restore validation.
	 *
	 * @var int
	 */
	private $max_zip_entries = 200000;

	/**
	 * Maximum total uncompressed bytes accepted during restore validation.
	 *
	 * @var int
	 */
	private $max_uncompressed_bytes = 10737418240;

	/**
	 * Maximum archive-wide compression ratio accepted during restore validation.
	 *
	 * @var int
	 */
	private $max_compression_ratio = 100;

	/**
	 * Validate a backup archive.
	 *
	 * @param string $zip_path Archive path.
	 * @return array|WP_Error
	 */
	public function validate_backup( $zip_path ) {
		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'zoecloud_restore_missing', __( 'Backup file not found.', 'zoe-cloud' ) );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zoecloud_restore_zip_invalid', __( 'Could not open the backup archive.', 'zoe-cloud' ) );
		}

		$entry_validation = $this->validate_zip_entries( $zip );

		if ( is_wp_error( $entry_validation ) ) {
			$zip->close();
			return $entry_validation;
		}

		$required = array( 'database.sql', 'manifest.json', 'checksums.jsonl' );

		foreach ( $required as $file ) {
			if ( false === $zip->locateName( $file ) ) {
				$zip->close();
				return new WP_Error( 'zoecloud_restore_structure_invalid', __( 'Backup structure is invalid.', 'zoe-cloud' ) );
			}
		}

		if ( false === $zip->locateName( 'files/' ) && false === $zip->locateName( 'files' ) ) {
			$zip->close();
			return new WP_Error( 'zoecloud_restore_structure_invalid', __( 'Backup files directory is missing.', 'zoe-cloud' ) );
		}

		$manifest_raw = $zip->getFromName( 'manifest.json' );
		if ( ! is_string( $manifest_raw ) || strlen( $manifest_raw ) > 1048576 ) {
			$zip->close();
			return new WP_Error( 'zoecloud_restore_manifest_invalid', __( 'The backup manifest is missing or too large.', 'zoe-cloud' ) );
		}
		$manifest     = is_string( $manifest_raw ) ? json_decode( $manifest_raw, true ) : null;
		if ( ! is_array( $manifest ) || 'zoecloud-backup' !== ( $manifest['format'] ?? '' ) || 2 !== (int) ( $manifest['format_version'] ?? 0 ) ) {
			$zip->close();
			return new WP_Error( 'zoecloud_restore_manifest_invalid', __( 'The archive does not contain a valid ZoeCloud v2 manifest.', 'zoe-cloud' ) );
		}
		$manifest_validation = $this->validate_manifest( $manifest );
		if ( is_wp_error( $manifest_validation ) ) {
			$zip->close();
			return $manifest_validation;
		}

		$checksum_validation = $this->validate_archive_checksums( $zip, $manifest );
		if ( is_wp_error( $checksum_validation ) ) {
			$zip->close();
			return $checksum_validation;
		}
		$zip->close();

		return array(
			'valid'    => true,
			'manifest' => is_array( $manifest ) ? $manifest : array(),
			'size'     => filesize( $zip_path ),
		);
	}

	/**
	 * Build a restore plan without changing the site.
	 *
	 * @param string $zip_path Archive path.
	 * @return array|WP_Error
	 */
	public function get_restore_plan( $zip_path ) {
		$validated = $this->validate_backup( $zip_path );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$manifest = $validated['manifest'];

		return array(
			'valid'           => true,
			'archive_size'    => $validated['size'],
			'generated_at'    => $manifest['generated_at'] ?? '',
			'origin_home_url' => $manifest['home_url'] ?? '',
			'origin_site_url' => $manifest['site_url'] ?? '',
			'files_count'     => $manifest['files_count'] ?? null,
			'files_size'      => $manifest['files_size'] ?? null,
			'database_tables' => $manifest['database_tables'] ?? null,
			'database_rows'   => $manifest['database_rows'] ?? null,
		);
	}

	/**
	 * Restore a site from a backup file.
	 *
	 * @param string $zip_path    Archive path.
	 * @param string $search      Old URL.
	 * @param string $replacement New URL.
	 * @param bool   $confirmed   Whether the destructive restore was confirmed.
	 * @return true|WP_Error
	 */
	public function restore_backup( $zip_path, $search = '', $replacement = '', $confirmed = false ) {
		if ( ! $confirmed ) {
			return new WP_Error( 'zoecloud_restore_confirmation_required', __( 'Restore confirmation is required.', 'zoe-cloud' ) );
		}

		$validated = $this->validate_backup( $zip_path );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$temp_dir = trailingslashit( $this->storage->get_subdirectory( 'restore' ) ) . 'job-' . bin2hex( random_bytes( 8 ) );
		wp_mkdir_p( $temp_dir );

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path );

		if ( true !== $opened ) {
			return new WP_Error( 'zoecloud_restore_zip_invalid', __( 'Could not open the backup archive.', 'zoe-cloud' ) );
		}

		if ( true !== $zip->extractTo( $temp_dir ) ) {
			$zip->close();
			$this->cleanup_directory( $temp_dir );
			return new WP_Error( 'zoecloud_restore_extract_failed', __( 'Could not extract the backup archive.', 'zoe-cloud' ) );
		}

		$zip->close();

		$preserved_options = $this->get_preserved_options();
		$sql               = file_get_contents( $temp_dir . '/database.sql' );

		$imported = $this->import_sql( $sql, $validated['manifest'] );

		if ( is_wp_error( $imported ) ) {
			$this->cleanup_directory( $temp_dir );
			return $imported;
		}

		$this->restore_preserved_options( $preserved_options );

		if ( $search && $replacement && $search !== $replacement ) {
			$tables   = $this->get_target_table_names( $validated['manifest'] );
			$replaced = $this->replace_urls_in_database( $search, $replacement, $tables );

			if ( is_wp_error( $replaced ) ) {
				$this->cleanup_directory( $temp_dir );
				return $replaced;
			}
		}

		$files_restored = $this->restore_files( $temp_dir . '/files' );
		$this->cleanup_directory( $temp_dir );

		return is_wp_error( $files_restored ) ? $files_restored : true;
	}

	/** Preserve operational options that must remain local to the destination. */
	private function get_preserved_options() {
		$options = array();
		foreach ( array( 'zoecloud_settings', 'zoecloud_storage_token', 'zoecloud_db_version' ) as $name ) {
			$options[ $name ] = get_option( $name, null );
		}

		return $options;
	}

	/**
	 * Restore destination-local operational options after importing wp_options.
	 *
	 * @param array $options Preserved option values.
	 * @return void
	 */
	private function restore_preserved_options( array $options ) {
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( $options as $name => $value ) {
			wp_cache_delete( $name, 'options' );
			if ( null !== $value ) {
				update_option( $name, $value, false );
			}
		}
	}

	/**
	 * Build a local backup record for the archive being restored.
	 *
	 * @param string $zip_path Archive path.
	 * @param array  $manifest Backup manifest.
	 * @return array
	 */
	private function build_backup_record_from_archive( $zip_path, array $manifest ) {
		$filename = basename( $zip_path );

		return array(
			'id'           => md5( wp_normalize_path( $zip_path ) ),
			'created_at'   => current_time( 'mysql', true ),
			'filename'     => $filename,
			'path'         => $zip_path,
			'download_url' => wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'zoecloud_download_backup',
						'filename' => $filename,
					),
					admin_url( 'admin-post.php' )
				),
				'zoecloud_download_backup'
			),
			'size'         => file_exists( $zip_path ) ? filesize( $zip_path ) : 0,
			'manifest'     => $manifest,
			'drive'        => null,
		);
	}

	/**
	 * Merge preserved local backup records back after database import.
	 *
	 * @param array $preserved_records Backup records from before restore.
	 * @return void
	 */
	private function merge_backup_records( array $preserved_records ) {
		global $wpdb;

		wp_cache_delete( 'zoecloud_backups', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		$current_records = get_option( 'zoecloud_backups', array() );
		$current_records = is_array( $current_records ) ? $current_records : array();
		$merged          = array();

		foreach ( array_merge( $current_records, $preserved_records ) as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$key = ! empty( $record['id'] ) ? $record['id'] : ( $record['filename'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$merged[ $key ] = $record;
		}

		$records = array_values( $merged );

		usort(
			$records,
			static function ( $left, $right ) {
				return strcmp( $right['created_at'] ?? '', $left['created_at'] ?? '' );
			}
		);

		$serialized = maybe_serialize( $records );
		$exists     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				'zoecloud_backups'
			)
		);

		if ( $exists ) {
			$wpdb->update(
				$wpdb->options,
				array(
					'option_value' => $serialized,
					'autoload'     => 'off',
				),
				array(
					'option_name' => 'zoecloud_backups',
				)
			);
		} else {
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => 'zoecloud_backups',
					'option_value' => $serialized,
					'autoload'     => 'off',
				)
			);
		}

		wp_cache_delete( 'zoecloud_backups', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Restore extracted files to ABSPATH.
	 *
	 * @param string $files_root Extracted files root.
	 * @return true|WP_Error
	 */
	private function restore_files( $files_root ) {
		if ( ! is_dir( $files_root ) ) {
			return true;
		}

		$items = scandir( $files_root );

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$source = $files_root . '/' . $item;
			$target = trailingslashit( ABSPATH ) . $item;
			$copied = $this->copy_directory( $source, $target );

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
		}

		return true;
	}

	/**
	 * Copy files or folders recursively.
	 *
	 * @param string $source Source path.
	 * @param string $target Destination path.
	 * @return true|WP_Error
	 */
	private function copy_directory( $source, $target ) {
		if ( is_dir( $source ) ) {
			if ( ! wp_mkdir_p( $target ) ) {
				return new WP_Error( 'zoecloud_restore_directory_failed', __( 'Could not create a restore directory.', 'zoe-cloud' ), array( 'target' => $target ) );
			}

			$items = scandir( $source );

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$copied = $this->copy_directory( $source . '/' . $item, $target . '/' . $item );

				if ( is_wp_error( $copied ) ) {
					return $copied;
				}
			}

			return true;
		}

		if ( ! copy( $source, $target ) ) {
			return new WP_Error( 'zoecloud_restore_file_failed', __( 'Could not restore a file.', 'zoe-cloud' ), array( 'target' => $target ) );
		}

		return true;
	}

	/**
	 * Import SQL statements.
	 *
	 * @param string $sql      SQL content.
	 * @param array  $manifest Backup manifest.
	 * @return true|WP_Error
	 */
	private function import_sql( $sql, array $manifest ) {
		global $wpdb;

		if ( ! is_string( $sql ) ) {
			return new WP_Error( 'zoecloud_restore_sql_missing', __( 'The database export is missing.', 'zoe-cloud' ) );
		}
		$sql        = $this->remap_database_prefix( $sql, $manifest );
		$statements = $this->split_sql_statements( $sql );
		$tables     = array_map( array( $this, 'quote_identifier' ), $this->get_target_table_names( $manifest ) );

		foreach ( $statements as $statement ) {
			if ( '' === $statement ) {
				continue;
			}

			if ( ! $this->is_allowed_restore_statement( $statement, $tables ) ) {
				return new WP_Error( 'zoecloud_restore_sql_rejected', __( 'The database export contains a statement that ZoeCloud did not generate.', 'zoe-cloud' ) );
			}

			$result = $wpdb->query( $statement ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( false === $result ) {
				return new WP_Error(
					'zoecloud_restore_query_failed',
					__( 'A database statement failed during restore.', 'zoe-cloud' ),
					array(
						'statement' => $statement,
						'error'     => $wpdb->last_error,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Split SQL into executable statements while respecting quoted strings.
	 *
	 * @param string $sql SQL dump.
	 * @return array
	 */
	private function split_sql_statements( $sql ) {
		$sql        = preg_replace( '/^\s*--.*$/m', '', $sql );
		$statements = array();
		$current    = '';
		$in_string  = false;
		$length     = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			$prev = $i > 0 ? $sql[ $i - 1 ] : '';

			if ( "'" === $char && '\\' !== $prev ) {
				$in_string = ! $in_string;
			}

			$current .= $char;

			if ( ';' === $char && ! $in_string ) {
				$statement = trim( $current );

				if ( '' !== $statement && 0 !== strpos( $statement, '--' ) ) {
					$statements[] = $statement;
				}

				$current = '';
			}
		}

		$current = trim( $current );

		if ( '' !== $current && 0 !== strpos( $current, '--' ) ) {
			$statements[] = $current;
		}

		return $statements;
	}

	/**
	 * Validate the versioned manifest and restoration requirements.
	 *
	 * @param array $manifest Backup manifest.
	 * @return true|WP_Error
	 */
	private function validate_manifest( array $manifest ) {
		$prefix = (string) ( $manifest['database_prefix'] ?? '' );
		$tables = $manifest['database_table_names'] ?? null;
		$origin = $manifest['origin'] ?? null;
		$requirements = $manifest['requirements'] ?? null;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) || ! is_array( $tables ) || ! is_array( $origin ) || ! is_array( $requirements ) ) {
			return new WP_Error( 'zoecloud_restore_manifest_invalid', __( 'The backup manifest is incomplete.', 'zoe-cloud' ) );
		}
		if ( count( $tables ) !== (int) ( $manifest['database_tables'] ?? -1 ) || count( $tables ) !== count( array_unique( $tables ) ) ) {
			return new WP_Error( 'zoecloud_restore_manifest_invalid', __( 'The backup table manifest is inconsistent.', 'zoe-cloud' ) );
		}
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || 0 !== strpos( $table, $prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				return new WP_Error( 'zoecloud_restore_manifest_invalid', __( 'The backup manifest contains an invalid database table.', 'zoe-cloud' ) );
			}
		}
		if ( ! wp_http_validate_url( (string) ( $origin['home_url'] ?? '' ) ) || ! wp_http_validate_url( (string) ( $origin['site_url'] ?? '' ) ) || $prefix !== (string) ( $origin['table_prefix'] ?? '' ) ) {
			return new WP_Error( 'zoecloud_restore_manifest_invalid', __( 'The backup origin metadata is invalid.', 'zoe-cloud' ) );
		}
		if ( ! isset( $manifest['files_count'], $manifest['files_size'], $manifest['database_rows'] ) || min( (int) $manifest['files_count'], (int) $manifest['files_size'], (int) $manifest['database_rows'] ) < 0 ) {
			return new WP_Error( 'zoecloud_restore_manifest_invalid', __( 'The backup component counts are invalid.', 'zoe-cloud' ) );
		}
		if ( version_compare( PHP_VERSION, (string) ( $requirements['php'] ?? '999' ), '<' ) || version_compare( get_bloginfo( 'version' ), (string) ( $requirements['wordpress'] ?? '999' ), '<' ) || empty( $requirements['ziparchive'] ) ) {
			return new WP_Error( 'zoecloud_restore_requirements_failed', __( 'This site does not meet the backup restoration requirements.', 'zoe-cloud' ) );
		}

		return true;
	}

	/**
	 * Verify database and file payload checksums without extracting the archive.
	 *
	 * @param ZipArchive $zip      Open archive.
	 * @param array      $manifest Backup manifest.
	 * @return true|WP_Error
	 */
	private function validate_archive_checksums( ZipArchive $zip, array $manifest ) {
		$checksums = isset( $manifest['checksums'] ) && is_array( $manifest['checksums'] ) ? $manifest['checksums'] : array();
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $checksums['database.sql'] ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $checksums['checksums.jsonl'] ?? '' ) ) ) {
			return new WP_Error( 'zoecloud_restore_checksums_missing', __( 'Required archive checksums are missing.', 'zoe-cloud' ) );
		}

		foreach ( array( 'database.sql', 'checksums.jsonl' ) as $name ) {
			$actual = $this->hash_zip_entry( $zip, $name );
			if ( is_wp_error( $actual ) || ! hash_equals( $checksums[ $name ], $actual ) ) {
				return new WP_Error( 'zoecloud_restore_checksum_mismatch', __( 'Backup integrity verification failed.', 'zoe-cloud' ), array( 'entry' => $name ) );
			}
		}

		$raw = $zip->getFromName( 'checksums.jsonl' );
		if ( ! is_string( $raw ) || strlen( $raw ) > 67108864 ) {
			return new WP_Error( 'zoecloud_restore_checksums_invalid', __( 'The archive checksum index is invalid.', 'zoe-cloud' ) );
		}
		$seen = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$item = json_decode( $line, true );
			$path = is_array( $item ) ? wp_normalize_path( (string) ( $item['path'] ?? '' ) ) : '';
			$hash = is_array( $item ) ? (string) ( $item['sha256'] ?? '' ) : '';
			if ( 0 !== strpos( $path, 'files/' ) || isset( $seen[ $path ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || false === $zip->locateName( $path ) ) {
				return new WP_Error( 'zoecloud_restore_checksums_invalid', __( 'The archive checksum index is invalid.', 'zoe-cloud' ), array( 'entry' => $path ) );
			}
			$actual = $this->hash_zip_entry( $zip, $path );
			if ( is_wp_error( $actual ) || ! hash_equals( $hash, $actual ) ) {
				return new WP_Error( 'zoecloud_restore_checksum_mismatch', __( 'Backup integrity verification failed.', 'zoe-cloud' ), array( 'entry' => $path ) );
			}
			$seen[ $path ] = true;
		}
		for ( $index = 0; $index < $zip->numFiles; $index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$name = wp_normalize_path( (string) $zip->getNameIndex( $index ) );
			if ( 0 === strpos( $name, 'files/' ) && '/' !== substr( $name, -1 ) && ! isset( $seen[ $name ] ) ) {
				return new WP_Error( 'zoecloud_restore_unindexed_file', __( 'The archive contains a file that is not covered by the checksum index.', 'zoe-cloud' ), array( 'entry' => $name ) );
			}
		}

		return true;
	}

	/**
	 * Hash a ZIP entry through a stream to keep memory bounded.
	 *
	 * @param ZipArchive $zip  Open archive.
	 * @param string     $name Entry name.
	 * @return string|WP_Error
	 */
	private function hash_zip_entry( ZipArchive $zip, $name ) {
		$stream = $zip->getStream( $name );
		if ( false === $stream ) {
			return new WP_Error( 'zoecloud_restore_entry_unreadable', __( 'A backup entry could not be read.', 'zoe-cloud' ) );
		}
		$context = hash_init( 'sha256' );
		hash_update_stream( $context, $stream );
		fclose( $stream );

		return hash_final( $context );
	}

	/**
	 * Return target table names derived from the source prefix in the manifest.
	 *
	 * @param array $manifest Backup manifest.
	 * @return array
	 */
	private function get_target_table_names( array $manifest ) {
		global $wpdb;

		$source_prefix = (string) ( $manifest['database_prefix'] ?? '' );
		if ( '' === $source_prefix || ! preg_match( '/^[A-Za-z0-9_]+$/', $source_prefix ) ) {
			return array();
		}
		$targets = array();
		foreach ( (array) ( $manifest['database_table_names'] ?? array() ) as $table ) {
			$table = (string) $table;
			if ( 0 !== strpos( $table, $source_prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}
			$targets[] = $wpdb->prefix . substr( $table, strlen( $source_prefix ) );
		}

		return array_values( array_unique( $targets ) );
	}

	/**
	 * Rewrite only manifest-declared, backtick-quoted table identifiers.
	 *
	 * @param string $sql      SQL content.
	 * @param array  $manifest Backup manifest.
	 * @return string
	 */
	private function remap_database_prefix( $sql, array $manifest ) {
		global $wpdb;

		$source = (string) ( $manifest['database_prefix'] ?? '' );
		foreach ( (array) ( $manifest['database_table_names'] ?? array() ) as $source_table ) {
			if ( '' !== $source && 0 === strpos( (string) $source_table, $source ) && preg_match( '/^[A-Za-z0-9_]+$/', (string) $source_table ) ) {
				$target = $wpdb->prefix . substr( (string) $source_table, strlen( $source ) );
				$sql    = str_replace( $this->quote_identifier( $source_table ), $this->quote_identifier( $target ), $sql );
			}
		}

		return $sql;
	}

	/**
	 * Restrict imported SQL to the grammar emitted by ZoeCloud.
	 *
	 * @param string $statement     SQL statement.
	 * @param array  $quoted_tables Allowed quoted table names.
	 * @return bool
	 */
	private function is_allowed_restore_statement( $statement, array $quoted_tables ) {
		$statement = ltrim( $statement );
		if ( preg_match( '/^SET\s+foreign_key_checks\s*=\s*[01]\s*;?$/i', $statement ) ) {
			return true;
		}
		if ( ! preg_match( '/^(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE|INSERT\s+INTO)\s+(`[^`]+`)/is', $statement, $matches ) ) {
			return false;
		}

		return in_array( $matches[1], $quoted_tables, true );
	}

	/**
	 * Validate archive entries before extraction to prevent path traversal.
	 *
	 * @param ZipArchive $zip Archive.
	 * @return true|WP_Error
	 */
	private function validate_zip_entries( ZipArchive $zip ) {
		$max_entries           = (int) apply_filters( 'zoecloud_restore_max_zip_entries', $this->max_zip_entries );
		$max_uncompressed      = (int) apply_filters( 'zoecloud_restore_max_uncompressed_bytes', $this->max_uncompressed_bytes );
		$max_compression_ratio = (int) apply_filters( 'zoecloud_restore_max_compression_ratio', $this->max_compression_ratio );
		$total_size            = 0;
		$total_compressed      = 0;
		$num_files             = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		if ( $num_files > $max_entries ) {
			return new WP_Error( 'zoecloud_restore_too_many_entries', __( 'Backup archive contains too many entries.', 'zoe-cloud' ) );
		}

		for ( $index = 0; $index < $num_files; $index++ ) {
			$name = $zip->getNameIndex( $index );

			if ( false === $name || '' === $name ) {
				continue;
			}

			$normalized = wp_normalize_path( $name );

			$segments = explode( '/', $normalized );
			if ( 0 === strpos( $normalized, '/' ) || preg_match( '/^[A-Za-z]:/', $normalized ) || false !== strpos( $normalized, "\0" ) || in_array( '..', $segments, true ) ) {
				return new WP_Error( 'zoecloud_restore_unsafe_archive', __( 'Backup archive contains unsafe paths.', 'zoe-cloud' ), array( 'entry' => $name ) );
			}

			$stats = $zip->statIndex( $index );

			if ( ! is_array( $stats ) ) {
				return new WP_Error( 'zoecloud_restore_invalid_entry', __( 'Backup archive contains an invalid entry.', 'zoe-cloud' ), array( 'entry' => $name ) );
			}

			$opsys = 0;
			$attrs = 0;
			if ( $zip->getExternalAttributesIndex( $index, $opsys, $attrs ) && 0120000 === ( ( $attrs >> 16 ) & 0170000 ) ) {
				return new WP_Error( 'zoecloud_restore_symlink_rejected', __( 'Backup archives may not contain symbolic links.', 'zoe-cloud' ), array( 'entry' => $name ) );
			}

			$total_size       += isset( $stats['size'] ) ? (int) $stats['size'] : 0;
			$total_compressed += isset( $stats['comp_size'] ) ? max( 0, (int) $stats['comp_size'] ) : 0;

			if ( $total_size > $max_uncompressed ) {
				return new WP_Error( 'zoecloud_restore_too_large', __( 'Backup archive expands beyond the restore size limit.', 'zoe-cloud' ) );
			}
		}

		if ( $total_compressed > 0 && $total_size / $total_compressed > $max_compression_ratio ) {
			return new WP_Error( 'zoecloud_restore_suspicious_compression', __( 'Backup archive compression ratio is too high.', 'zoe-cloud' ) );
		}

		return true;
	}

	/**
	 * Replace URLs after import while preserving serialized values.
	 *
	 * @param string $search      Old URL.
	 * @param string $replacement New URL.
	 * @param array  $tables      Database tables to inspect. Empty means all tables.
	 * @return true|WP_Error
	 */
	private function replace_urls_in_database( $search, $replacement, array $tables = array() ) {
		global $wpdb;

		if ( empty( $tables ) ) {
			$tables = $wpdb->get_col( 'SHOW TABLES' );
		}

		foreach ( $tables as $table ) {
			$text_columns = $this->get_text_columns( $table );
			$primary_keys = $this->get_primary_key_columns( $table );

			if ( empty( $text_columns ) || empty( $primary_keys ) ) {
				continue;
			}

			$offset = 0;

			do {
				$table_name = $this->quote_identifier( $table );
				$query      = $wpdb->prepare( "SELECT * FROM {$table_name} LIMIT %d OFFSET %d", $this->replace_batch_size, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows       = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				foreach ( $rows as $row ) {
					$updates = array();

					foreach ( $text_columns as $column ) {
						if ( ! array_key_exists( $column, $row ) || false === strpos( (string) $row[ $column ], $search ) ) {
							continue;
						}

						$updates[ $column ] = $this->replace_preserving_serialized( $row[ $column ], $search, $replacement );
					}

					if ( empty( $updates ) ) {
						continue;
					}

					$where = array();

					foreach ( $primary_keys as $primary_key ) {
						$where[ $primary_key ] = $row[ $primary_key ];
					}

					$updated = $wpdb->update( $table, $updates, $where );

					if ( false === $updated ) {
						return new WP_Error( 'zoecloud_restore_replace_failed', __( 'URL replacement failed during restore.', 'zoe-cloud' ), array( 'table' => $table ) );
					}
				}

				$offset       += $this->replace_batch_size;
					$row_count = count( $rows );
			} while ( $row_count === $this->replace_batch_size );
		}

		return true;
	}

	/**
	 * Get text-like columns for a table.
	 *
	 * @param string $table Table name.
	 * @return array
	 */
	private function get_text_columns( $table ) {
		global $wpdb;

		$table_name = $this->quote_identifier( $table );
		$columns    = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$matches    = array();

		foreach ( $columns as $column ) {
			$type = strtolower( $column['Type'] ?? '' );

			if ( false !== strpos( $type, 'char' ) || false !== strpos( $type, 'text' ) || false !== strpos( $type, 'blob' ) ) {
				$matches[] = $column['Field'];
			}
		}

		return $matches;
	}

	/**
	 * Get primary key columns for a table.
	 *
	 * @param string $table Table name.
	 * @return array
	 */
	private function get_primary_key_columns( $table ) {
		global $wpdb;

		$table_name = $this->quote_identifier( $table );
		$columns    = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$matches    = array();

		foreach ( $columns as $column ) {
			if ( 'PRI' === ( $column['Key'] ?? '' ) ) {
				$matches[] = $column['Field'];
			}
		}

		return $matches;
	}

	/**
	 * Replace strings while preserving serialized payload lengths.
	 *
	 * @param mixed  $value       Raw value.
	 * @param string $search      Search string.
	 * @param string $replacement Replacement string.
	 * @return mixed
	 */
	private function replace_preserving_serialized( $value, $search, $replacement ) {
		if ( is_serialized( $value ) ) {
			$unserialized = unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$replaced     = $this->recursive_replace( $unserialized, $search, $replacement );

			return maybe_serialize( $replaced );
		}

		return str_replace( $search, $replacement, $value );
	}

	/**
	 * Recursively replace values inside arrays/objects.
	 *
	 * @param mixed  $value       Value.
	 * @param string $search      Search string.
	 * @param string $replacement Replacement string.
	 * @return mixed
	 */
	private function recursive_replace( $value, $search, $replacement ) {
		if ( is_string( $value ) ) {
			return str_replace( $search, $replacement, $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->recursive_replace( $item, $search, $replacement );
			}

			return $value;
		}

		if ( is_object( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( '__PHP_Incomplete_Class_Name' === $key ) {
					continue;
				}
				$value->{$key} = $this->recursive_replace( $item, $search, $replacement );
			}
		}

		return $value;
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
	 * Remove a temp directory recursively.
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
