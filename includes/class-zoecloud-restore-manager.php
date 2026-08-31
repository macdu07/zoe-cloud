<?php
/**
 * Restore workflows.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Restore journals and large archives require bounded streaming I/O and private recursive cleanup.

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
		$manifest = is_string( $manifest_raw ) ? json_decode( $manifest_raw, true ) : null;
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
	 * @param string        $zip_path          Archive path.
	 * @param string        $search            Old URL.
	 * @param string        $replacement       New URL.
	 * @param bool          $confirmed         Whether the destructive restore was confirmed.
	 * @param callable|null $progress_callback Optional progress callback receiving (int $progress, string $message).
	 * @return true|WP_Error
	 */
	public function restore_backup( $zip_path, $search = '', $replacement = '', $confirmed = false, $progress_callback = null ) {
		if ( ! $confirmed ) {
			return new WP_Error( 'zoecloud_restore_confirmation_required', __( 'Restore confirmation is required.', 'zoe-cloud' ) );
		}

		$validated = $this->validate_backup( $zip_path );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$this->report_progress( $progress_callback, 40, __( 'Backup validated. Preparing temporary restore areas.', 'zoe-cloud' ) );

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
		$this->report_progress( $progress_callback, 50, __( 'Archive extracted. Importing database into staging tables.', 'zoe-cloud' ) );

		$journal_dir = $temp_dir . '/journal';
		wp_mkdir_p( $journal_dir );
		$preserved_options = $this->get_preserved_options();
		$sql               = file_get_contents( $temp_dir . '/database.sql' );

		$table_journal = $this->build_table_journal( $validated['manifest'] );
		file_put_contents( $journal_dir . '/tables.json', wp_json_encode( $table_journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$imported = $this->import_sql( $sql, $validated['manifest'], $table_journal['staging'] );

		if ( is_wp_error( $imported ) ) {
			$this->cleanup_directory( $temp_dir );
			return $imported;
		}
		$this->report_progress( $progress_callback, 68, __( 'Database staged. Exchanging tables atomically.', 'zoe-cloud' ) );
		$this->set_maintenance_mode( true );
		try {
			$activated = $this->activate_staged_tables( $table_journal );
		} finally {
			$this->set_maintenance_mode( false );
		}
		if ( is_wp_error( $activated ) ) {
			$this->cleanup_staged_tables( $table_journal );
			$this->cleanup_directory( $temp_dir );
			return $activated;
		}

		$this->restore_preserved_options( $preserved_options );
		$this->report_progress( $progress_callback, 74, __( 'Database restored. Applying URL replacements.', 'zoe-cloud' ) );

		if ( $search && $replacement && $search !== $replacement ) {
			$tables   = $this->get_target_table_names( $validated['manifest'] );
			$replaced = $this->replace_urls_in_database( $search, $replacement, $tables );

			if ( is_wp_error( $replaced ) ) {
				$this->rollback_tables( $table_journal );
				$this->set_maintenance_mode( false );
				$this->cleanup_directory( $temp_dir );
				return $replaced;
			}
		}

		$files_restored = $this->restore_files( $temp_dir . '/files', $journal_dir );
		$this->report_progress( $progress_callback, 92, __( 'Files restored. Finalizing recovery point.', 'zoe-cloud' ) );
		if ( is_wp_error( $files_restored ) ) {
			$this->rollback_files( $journal_dir );
			$this->rollback_tables( $table_journal );
		} else {
			$this->finalize_tables( $table_journal );
		}
		$this->set_maintenance_mode( false );
		$this->cleanup_directory( $temp_dir );

		return is_wp_error( $files_restored ) ? $files_restored : true;
	}

	/**
	 * Report a restore milestone without making progress persistence mandatory.
	 *
	 * @param callable|null $callback Progress callback.
	 * @param int           $progress Progress percentage.
	 * @param string        $message Human-readable status.
	 * @return void
	 */
	private function report_progress( $callback, $progress, $message ) {
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, $progress, $message );
		}
	}

	/**
	 * Limit maintenance mode to the destructive table/file exchange window.
	 *
	 * @param bool $enabled Whether maintenance mode is enabled.
	 * @return void
	 */
	private function set_maintenance_mode( $enabled ) {
		$file = trailingslashit( ABSPATH ) . '.maintenance';
		if ( $enabled ) {
			file_put_contents( $file, '<?php $upgrading = ' . time() . ';' );
		} elseif ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
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
	 * Restore extracted files to ABSPATH.
	 *
	 * @param string $files_root  Extracted files root.
	 * @param string $journal_dir Private rollback journal directory.
	 * @return true|WP_Error
	 */
	private function restore_files( $files_root, $journal_dir ) {
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
			$copied = $this->copy_directory( $source, $target, $journal_dir );

			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
		}

		return true;
	}

	/**
	 * Copy files or folders recursively.
	 *
	 * @param string $source      Source path.
	 * @param string $target      Destination path.
	 * @param string $journal_dir Private rollback journal directory.
	 * @return true|WP_Error
	 */
	private function copy_directory( $source, $target, $journal_dir ) {
		if ( is_dir( $source ) ) {
			if ( ! wp_mkdir_p( $target ) ) {
				return new WP_Error( 'zoecloud_restore_directory_failed', __( 'Could not create a restore directory.', 'zoe-cloud' ), array( 'target' => $target ) );
			}

			$items = scandir( $source );

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$copied = $this->copy_directory( $source . '/' . $item, $target . '/' . $item, $journal_dir );

				if ( is_wp_error( $copied ) ) {
					return $copied;
				}
			}

			return true;
		}

		$relative = ltrim( substr( wp_normalize_path( $target ), strlen( wp_normalize_path( trailingslashit( ABSPATH ) ) ) ), '/' );
		$backup   = trailingslashit( $journal_dir ) . 'files/' . $relative;
		$existed  = is_file( $target );
		if ( $existed ) {
			wp_mkdir_p( dirname( $backup ) );
			if ( ! copy( $target, $backup ) ) {
				return new WP_Error( 'zoecloud_restore_journal_failed', __( 'Could not journal a file before replacement.', 'zoe-cloud' ), array( 'target' => $target ) );
			}
		}
		file_put_contents(
			trailingslashit( $journal_dir ) . 'files.jsonl',
			wp_json_encode(
				array(
					'target'  => $target,
					'backup'  => $backup,
					'existed' => $existed,
				),
				JSON_UNESCAPED_SLASHES
			) . "\n",
			FILE_APPEND
		);

		if ( ! copy( $source, $target ) ) {
			return new WP_Error( 'zoecloud_restore_file_failed', __( 'Could not restore a file.', 'zoe-cloud' ), array( 'target' => $target ) );
		}

		return true;
	}

	/**
	 * Reverse file replacements recorded in the private journal.
	 *
	 * @param string $journal_dir Private rollback journal directory.
	 * @return bool
	 */
	private function rollback_files( $journal_dir ) {
		$journal = trailingslashit( $journal_dir ) . 'files.jsonl';
		if ( ! is_readable( $journal ) ) {
			return true;
		}
		$entries = array_reverse( file( $journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
		foreach ( $entries as $line ) {
			$entry = json_decode( $line, true );
			if ( ! is_array( $entry ) || empty( $entry['target'] ) ) {
				continue;
			}
			if ( ! empty( $entry['existed'] ) && is_file( $entry['backup'] ?? '' ) ) {
				copy( $entry['backup'], $entry['target'] );
			} elseif ( is_file( $entry['target'] ) ) {
				wp_delete_file( $entry['target'] );
			}
		}

		return true;
	}

	/**
	 * Build deterministic auxiliary names for an atomic database exchange.
	 *
	 * @param array $manifest Backup manifest.
	 * @return array
	 */
	private function build_table_journal( array $manifest ) {
		$suffix  = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
		$journal = array(
			'staging' => array(),
			'old'     => array(),
			'existed' => array(),
		);
		foreach ( $this->get_target_table_names( $manifest ) as $target ) {
			$base                          = substr( $target, 0, 42 );
			$journal['staging'][ $target ] = $base . '_zcstage_' . $suffix;
			$journal['old'][ $target ]     = $base . '_zcold_' . $suffix;
			$journal['existed'][ $target ] = $this->table_exists( $target );
		}

		return $journal;
	}

	/**
	 * Atomically exchange staged tables with current site tables.
	 *
	 * @param array $journal Table exchange journal.
	 * @return true|WP_Error
	 */
	private function activate_staged_tables( array $journal ) {
		global $wpdb;

		$renames = array();
		foreach ( $journal['staging'] as $target => $staging ) {
			if ( ! $this->table_exists( $staging ) ) {
				return new WP_Error( 'zoecloud_restore_staging_missing', __( 'A staged restore table is missing.', 'zoe-cloud' ) );
			}
			if ( ! empty( $journal['existed'][ $target ] ) ) {
				$renames[] = $this->quote_identifier( $target ) . ' TO ' . $this->quote_identifier( $journal['old'][ $target ] );
			}
			$renames[] = $this->quote_identifier( $staging ) . ' TO ' . $this->quote_identifier( $target );
		}
		if ( empty( $renames ) ) {
			return new WP_Error( 'zoecloud_restore_tables_missing', __( 'The restore manifest contains no database tables.', 'zoe-cloud' ) );
		}
		$result = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange

		return false === $result ? new WP_Error( 'zoecloud_restore_table_swap_failed', __( 'The atomic database table exchange failed.', 'zoe-cloud' ) ) : true;
	}

	/**
	 * Roll back an activated database exchange.
	 *
	 * @param array $journal Table exchange journal.
	 * @return bool
	 */
	private function rollback_tables( array $journal ) {
		global $wpdb;

		$renames = array();
		foreach ( $journal['staging'] as $target => $staging ) {
			if ( ! empty( $journal['existed'][ $target ] ) && $this->table_exists( $journal['old'][ $target ] ) && $this->table_exists( $target ) ) {
				$renames[] = $this->quote_identifier( $target ) . ' TO ' . $this->quote_identifier( $staging );
				$renames[] = $this->quote_identifier( $journal['old'][ $target ] ) . ' TO ' . $this->quote_identifier( $target );
			} elseif ( empty( $journal['existed'][ $target ] ) && $this->table_exists( $target ) ) {
				$wpdb->query( 'DROP TABLE ' . $this->quote_identifier( $target ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
		if ( $renames ) {
			$wpdb->query( 'RENAME TABLE ' . implode( ', ', $renames ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		$this->cleanup_staged_tables( $journal );

		return true;
	}

	/**
	 * Drop old tables after a successful restore.
	 *
	 * @param array $journal Table exchange journal.
	 * @return void
	 */
	private function finalize_tables( array $journal ) {
		global $wpdb;

		foreach ( $journal['old'] as $old ) {
			if ( $this->table_exists( $old ) ) {
				$wpdb->query( 'DROP TABLE ' . $this->quote_identifier( $old ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
	}

	/**
	 * Remove unactivated or rolled-back staging tables.
	 *
	 * @param array $journal Table exchange journal.
	 * @return void
	 */
	private function cleanup_staged_tables( array $journal ) {
		global $wpdb;

		foreach ( array_merge( array_values( $journal['staging'] ), array_values( $journal['old'] ) ) as $table ) {
			if ( $this->table_exists( $table ) ) {
				$wpdb->query( 'DROP TABLE ' . $this->quote_identifier( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
	}

	/**
	 * Determine whether a database table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Import SQL statements.
	 *
	 * @param string $sql       SQL content.
	 * @param array  $manifest  Backup manifest.
	 * @param array  $table_map Optional target-to-staging table map.
	 * @return true|WP_Error
	 */
	private function import_sql( $sql, array $manifest, array $table_map = array() ) {
		global $wpdb;

		if ( ! is_string( $sql ) ) {
			return new WP_Error( 'zoecloud_restore_sql_missing', __( 'The database export is missing.', 'zoe-cloud' ) );
		}
		$sql = $this->remap_database_prefix( $sql, $manifest );
		foreach ( $table_map as $target => $staging ) {
			$sql = str_replace( $this->quote_identifier( $target ), $this->quote_identifier( $staging ), $sql );
		}
		$statements = $this->split_sql_statements( $sql );
		$tables     = array_map( array( $this, 'quote_identifier' ), $table_map ? array_values( $table_map ) : $this->get_target_table_names( $manifest ) );

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
		$prefix       = (string) ( $manifest['database_prefix'] ?? '' );
		$tables       = $manifest['database_table_names'] ?? null;
		$origin       = $manifest['origin'] ?? null;
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
		if ( ! wp_http_validate_url( (string) ( $origin['home_url'] ?? '' ) ) || ! wp_http_validate_url( (string) ( $origin['site_url'] ?? '' ) ) || (string) ( $origin['table_prefix'] ?? '' ) !== $prefix ) {
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
			if ( 0 === strpos( strtolower( $normalized ), 'files/wp-content/.zoecloud-private/' ) ) {
				return new WP_Error( 'zoecloud_restore_protected_path', __( 'Backup archive targets ZoeCloud private storage.', 'zoe-cloud' ), array( 'entry' => $name ) );
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
			if ( $this->contains_incomplete_object( $unserialized ) ) {
				return $this->replace_serialized_string_tokens( trim( $value ), $search, $replacement );
			}
			$replaced     = $this->recursive_replace( $unserialized, $search, $replacement );

			return maybe_serialize( $replaced );
		}

		return str_replace( $search, $replacement, $value );
	}

	/**
	 * Determine whether decoded data contains a class that was deliberately not loaded.
	 *
	 * @param mixed $value Decoded serialized value.
	 * @return bool
	 */
	private function contains_incomplete_object( $value ) {
		if ( is_object( $value ) ) {
			return '__PHP_Incomplete_Class' === get_class( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->contains_incomplete_object( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Replace serialized string tokens while recalculating byte lengths.
	 *
	 * This parser uses the declared token length instead of a regular expression,
	 * so embedded quotes and semicolons cannot truncate a value.
	 *
	 * @param string $serialized  Serialized payload.
	 * @param string $search      Search string.
	 * @param string $replacement Replacement string.
	 * @return string
	 */
	private function replace_serialized_string_tokens( $serialized, $search, $replacement ) {
		$output = '';
		$offset = 0;
		$total  = strlen( $serialized );

		while ( $offset < $total ) {
			$start = strpos( $serialized, 's:', $offset );
			if ( false === $start ) {
				$output .= substr( $serialized, $offset );
				break;
			}

			$output .= substr( $serialized, $offset, $start - $offset );
			if ( ! preg_match( '/\Gs:(\d+):"/A', $serialized, $matches, 0, $start ) ) {
				$output .= 's:';
				$offset  = $start + 2;
				continue;
			}

			$header_length = strlen( $matches[0] );
			$value_length  = (int) $matches[1];
			$value_start   = $start + $header_length;
			$value_end     = $value_start + $value_length;
			if ( $value_end + 2 > $total || '";' !== substr( $serialized, $value_end, 2 ) ) {
				return $serialized;
			}

			$value   = substr( $serialized, $value_start, $value_length );
			$value   = str_replace( $search, $replacement, $value );
			$output .= 's:' . strlen( $value ) . ':"' . $value . '";';
			$offset  = $value_end + 2;
		}

		return $output;
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
