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
	public function restore_backup( $zip_path, $search = '', $replacement = '' ) {
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

		$this->restore_files( $temp_dir . '/files' );
		$sql = file_get_contents( $temp_dir . '/database.sql' );

		if ( $search && $replacement ) {
			$sql = str_replace( $search, $replacement, $sql );
		}

		$imported = $this->import_sql( $sql );
		$this->cleanup_directory( $temp_dir );

		return is_wp_error( $imported ) ? $imported : true;
	}

	/**
	 * Restore extracted files to ABSPATH.
	 *
	 * @param string $files_root Extracted files root.
	 * @return void
	 */
	private function restore_files( $files_root ) {
		if ( ! is_dir( $files_root ) ) {
			return;
		}

		$items = scandir( $files_root );

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$source = $files_root . '/' . $item;
			$target = trailingslashit( ABSPATH ) . $item;
			$this->copy_directory( $source, $target );
		}
	}

	/**
	 * Copy files or folders recursively.
	 *
	 * @param string $source Source path.
	 * @param string $target Destination path.
	 * @return void
	 */
	private function copy_directory( $source, $target ) {
		if ( is_dir( $source ) ) {
			wp_mkdir_p( $target );
			$items = scandir( $source );

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$this->copy_directory( $source . '/' . $item, $target . '/' . $item );
			}

			return;
		}

		copy( $source, $target );
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
