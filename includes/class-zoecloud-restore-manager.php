<?php
/**
 * Restore workflows.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZoeCloud_Restore_Manager {
	/**
	 * Number of rows processed per search/replace batch.
	 *
	 * @var int
	 */
	private $replace_batch_size = 250;

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

		$required = array( 'database.sql', 'manifest.json' );

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

		$manifest = json_decode( $zip->getFromName( 'manifest.json' ), true );
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

		$uploads  = wp_upload_dir();
		$temp_dir = trailingslashit( $uploads['basedir'] ) . 'zoecloud-restore-' . wp_generate_password( 8, false, false );
		wp_mkdir_p( $temp_dir );

		$zip = new ZipArchive();
		$opened = $zip->open( $zip_path );

		if ( true !== $opened ) {
			return new WP_Error( 'zoecloud_restore_zip_invalid', __( 'Could not open the backup archive.', 'zoe-cloud' ) );
		}

		$zip->extractTo( $temp_dir );
		$zip->close();

		$preserved_backups = $this->get_existing_backup_records();
		$preserved_backups[] = $this->build_backup_record_from_archive( $zip_path, $validated['manifest'] );
		$sql = file_get_contents( $temp_dir . '/database.sql' );

		$imported = $this->import_sql( $sql );

		if ( is_wp_error( $imported ) ) {
			$this->cleanup_directory( $temp_dir );
			return $imported;
		}

		$this->merge_backup_records( $preserved_backups );

		if ( $search && $replacement && $search !== $replacement ) {
			$tables   = isset( $validated['manifest']['database_table_names'] ) && is_array( $validated['manifest']['database_table_names'] )
				? $validated['manifest']['database_table_names']
				: array();
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

	/**
	 * Capture current local backup records before importing the database.
	 *
	 * @return array
	 */
	private function get_existing_backup_records() {
		$records = get_option( 'zoecloud_backups', array() );

		return is_array( $records ) ? array_values( $records ) : array();
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
	 * @param string $sql SQL content.
	 * @return true|WP_Error
	 */
	private function import_sql( $sql ) {
		global $wpdb;

		$statements = $this->split_sql_statements( $sql );

		foreach ( $statements as $statement ) {
			if ( '' === $statement ) {
				continue;
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
	 * Validate archive entries before extraction to prevent path traversal.
	 *
	 * @param ZipArchive $zip Archive.
	 * @return true|WP_Error
	 */
	private function validate_zip_entries( ZipArchive $zip ) {
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = $zip->getNameIndex( $index );

			if ( false === $name || '' === $name ) {
				continue;
			}

			$normalized = wp_normalize_path( $name );

			if ( 0 === strpos( $normalized, '/' ) || false !== strpos( $normalized, '../' ) || '..' === $normalized ) {
				return new WP_Error( 'zoecloud_restore_unsafe_archive', __( 'Backup archive contains unsafe paths.', 'zoe-cloud' ), array( 'entry' => $name ) );
			}
		}

		return true;
	}

	/**
	 * Replace URLs after import while preserving serialized values.
	 *
	 * @param string $search      Old URL.
	 * @param string $replacement New URL.
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
				$rows       = $wpdb->get_results( $query, ARRAY_A );

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

				$offset += $this->replace_batch_size;
			} while ( count( $rows ) === $this->replace_batch_size );
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
			$unserialized = maybe_unserialize( $value );
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
