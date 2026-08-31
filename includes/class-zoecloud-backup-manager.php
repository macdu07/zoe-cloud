<?php
/**
 * Backup generation.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Database exports, checksum indexes, and downloads require bounded streaming I/O for multi-gigabyte backups.

/**
 * Creates, stores, streams, and deletes backup archives.
 */
class ZoeCloud_Backup_Manager {
	/**
	 * Cloud service.
	 *
	 * @var ZoeCloud_R2_Service
	 */
	private $cloud_service;

	/**
	 * Backup metadata repository.
	 *
	 * @var ZoeCloud_Backup_Repository
	 */
	private $backup_repository;

	/**
	 * Durable job repository.
	 *
	 * @var ZoeCloud_Job_Repository
	 */
	private $job_repository;

	/**
	 * Private storage service.
	 *
	 * @var ZoeCloud_Storage
	 */
	private $storage;

	/**
	 * Active lease tokens keyed by job UUID.
	 *
	 * @var array
	 */
	private $leases = array();

	/**
	 * Number of rows read per database batch.
	 *
	 * @var int
	 */
	private $db_batch_size = 1000;

	/**
	 * Number of database batches processed per cron tick.
	 *
	 * @var int
	 */
	private $db_batches_per_run = 10;

	/**
	 * Number of files added to the zip per cron tick.
	 *
	 * @var int
	 */
	private $file_batch_size = 500;

	/**
	 * Whether the current runner should avoid scheduling from each step.
	 *
	 * @var bool
	 */
	private $defer_scheduling = false;

	/**
	 * Constructor.
	 *
	 * @param ZoeCloud_R2_Service        $cloud_service      Cloud service.
	 * @param ZoeCloud_Backup_Repository $backup_repository Backup repository.
	 * @param ZoeCloud_Job_Repository    $job_repository    Job repository.
	 * @param ZoeCloud_Storage           $storage           Private storage.
	 */
	public function __construct( ZoeCloud_R2_Service $cloud_service, $backup_repository = null, $job_repository = null, $storage = null ) {
		$this->cloud_service     = $cloud_service;
		$this->backup_repository = $backup_repository instanceof ZoeCloud_Backup_Repository ? $backup_repository : new ZoeCloud_Backup_Repository();
		$this->job_repository    = $job_repository instanceof ZoeCloud_Job_Repository ? $job_repository : new ZoeCloud_Job_Repository();
		$this->storage           = $storage instanceof ZoeCloud_Storage ? $storage : new ZoeCloud_Storage();
	}

	/**
	 * List stored backups.
	 *
	 * @return array
	 */
	public function list_backups() {
		return array_values( array_map( array( $this, 'normalize_backup_record' ), $this->backup_repository->all() ) );
	}

	/**
	 * Normalize repository metadata for the authenticated API.
	 *
	 * @param array $record Backup record.
	 * @return array
	 */
	private function normalize_backup_record( array $record ) {
		$manifest               = isset( $record['manifest'] ) && is_array( $record['manifest'] ) ? $record['manifest'] : array();
		$record['source']       = sanitize_key( $record['source'] ?? 'manual' );
		$record['scope']        = sanitize_key( $record['scope'] ?? ( ! empty( $manifest['include_core'] ) ? 'full' : 'site_data' ) );
		$record['locked']       = ! empty( $record['locked'] );
		$record['checksum']     = (string) ( $record['checksum'] ?? '' );
		$record['verified_at']  = (string) ( $record['verified_at'] ?? ( 'verified' === ( $record['verification_status'] ?? '' ) ? ( $record['updated_at'] ?? '' ) : '' ) );
		$record['cloud_status'] = sanitize_key( $record['cloud_status'] ?? ( ! empty( $record['cloud'] ) ? 'available' : ( ! empty( $record['cloud_error'] ) ? 'failed' : 'local' ) ) );
		$record['download_url'] = $this->build_download_url( $record['id'] );

		return $record;
	}

	/**
	 * Create and enqueue a backup job.
	 *
	 * @param array $args Backup arguments.
	 * @return array|WP_Error
	 */
	public function enqueue_backup( array $args = array() ) {
		$preflight = $this->get_preflight_status();

		if ( ! $preflight['ready'] ) {
			return new WP_Error( 'zoecloud_preflight_failed', __( 'Server requirements are not met for backups.', 'zoe-cloud' ), $preflight );
		}

		$payload = array(
			'include_core' => ! empty( $args['include_core'] ),
			'upload_cloud' => ! empty( $args['upload_cloud'] ),
			'source'       => in_array( $args['source'] ?? 'manual', array( 'manual', 'scheduled', 'safety' ), true ) ? $args['source'] : 'manual',
			'scope'        => ! empty( $args['include_core'] ) ? 'full' : 'site_data',
		);
		$job     = $this->job_repository->create( 'backup', $payload );

		if ( empty( $job ) ) {
			return new WP_Error( 'zoecloud_job_create_failed', __( 'Could not create the backup job.', 'zoe-cloud' ) );
		}

		$scheduled = wp_schedule_single_event( time() + 1, 'zoecloud_run_backup_job', array( $job['id'] ) );

		if ( ! $scheduled ) {
			$this->job_repository->update(
				$job['id'],
				array(
					'status'     => 'failed',
					'last_error' => __( 'Could not schedule the backup job.', 'zoe-cloud' ),
				)
			);
			return new WP_Error( 'zoecloud_job_schedule_failed', __( 'Could not schedule the backup job.', 'zoe-cloud' ) );
		}

		return $job;
	}

	/**
	 * Run a queued backup job.
	 *
	 * @param string $job_id      Job ID.
	 * @param int    $max_steps   Maximum stage steps to run in this request.
	 * @param int    $time_budget Maximum runtime budget in seconds.
	 * @return void
	 */
	public function run_backup_job( $job_id, $max_steps = 25, $time_budget = 20 ) {
		$job_id = sanitize_text_field( $job_id );
		$token  = $this->job_repository->acquire( $job_id, max( 90, absint( $time_budget ) + 30 ) );
		if ( ! $token ) {
			return;
		}
		if ( ! $this->job_repository->acquire_mutex( 'backup' ) ) {
			$this->job_repository->release( $job_id, $token, 10 );
			return;
		}
		$this->leases[ $job_id ] = $token;
		$this->defer_scheduling  = true;
		$started_at              = time();
		$steps                   = 0;

		do {
			$job = $this->get_job( $job_id );

			if ( empty( $job ) || in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
				break;
			}

			$stage = isset( $job['stage'] ) ? $job['stage'] : 'init';

			try {
				switch ( $stage ) {
					case 'init':
						$result = $this->process_job_init( $job );
						break;
					case 'export_database':
						$result = $this->process_job_database( $job );
						break;
					case 'scan_files':
						$result = $this->process_job_scan_files( $job );
						break;
					case 'zip_files':
						$result = $this->process_job_zip_files( $job );
						break;
					case 'finalize':
						$result = $this->process_job_finalize( $job );
						break;
					case 'cloud_upload':
						$result = $this->process_job_cloud_upload( $job );
						break;
					case 'cleanup':
						$result = $this->process_job_cleanup( $job );
						break;
					default:
						$result = new WP_Error( 'zoecloud_job_stage_invalid', __( 'Backup job stage is invalid.', 'zoe-cloud' ) );
						break;
				}
			} catch ( Throwable $exception ) {
				$result = new WP_Error( 'zoecloud_job_exception', $exception->getMessage() );
			}

			if ( is_wp_error( $result ) ) {
				$this->fail_job( $job_id, $result->get_error_message() );
				break;
			}

			++$steps;
		} while ( $steps < $max_steps && ( time() - $started_at ) < $time_budget );

		$this->defer_scheduling = false;
		$job                    = $this->get_job( $job_id );

		if ( ! empty( $job ) && ! in_array( $job['status'], array( 'completed', 'failed' ), true ) ) {
			$this->schedule_next_job_run( $job_id );
		}

		$this->job_repository->release( $job_id, $token );
		$this->job_repository->release_mutex( 'backup' );
		unset( $this->leases[ $job_id ] );
	}

	/**
	 * List backup jobs.
	 *
	 * @return array
	 */
	public function list_jobs() {
		$jobs = array();
		foreach ( $this->job_repository->all() as $job ) {
			$jobs[ $job['id'] ] = $job;
		}

		return $jobs;
	}

	/**
	 * Return a dashboard summary derived from authoritative records.
	 *
	 * @return array
	 */
	public function get_summary() {
		$backups  = $this->list_backups();
		$jobs     = array_values( $this->list_jobs() );
		$latest   = $backups[0] ?? null;
		$last_job = $jobs[0] ?? null;
		$total    = 0;

		foreach ( $backups as $backup ) {
			$total += absint( $backup['size'] ?? 0 );
		}

		return array(
			'backup_count'      => count( $backups ),
			'local_total_bytes' => $total,
			'latest_backup'     => $latest,
			'latest_job'        => $last_job,
		);
	}

	/**
	 * Lock or unlock a backup so retention cannot remove it.
	 *
	 * @param string $backup_id Backup UUID.
	 * @param bool   $locked    New lock state.
	 * @return array|WP_Error
	 */
	public function update_backup( $backup_id, $locked ) {
		$record = $this->backup_repository->find( $backup_id );
		if ( ! $record ) {
			return new WP_Error( 'zoecloud_backup_missing', __( 'Backup file not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}
		$record['locked'] = (bool) $locked;

		return $this->backup_repository->save( $record );
	}

	/**
	 * Delete multiple unlocked backups.
	 *
	 * @param array $ids Backup IDs.
	 * @return array
	 */
	public function bulk_delete_backups( array $ids ) {
		$deleted = array();
		$errors  = array();

		foreach ( array_unique( array_map( 'sanitize_text_field', $ids ) ) as $id ) {
			$record = current(
				array_filter(
					$this->list_backups(),
					static function ( $item ) use ( $id ) {
						return ( $item['id'] ?? '' ) === $id;
					}
				)
			);
			if ( $record && ! empty( $record['locked'] ) ) {
				$errors[ $id ] = __( 'Locked backups cannot be deleted.', 'zoe-cloud' );
				continue;
			}
			$result = $this->delete_backup( $id );
			if ( is_wp_error( $result ) ) {
				$errors[ $id ] = $result->get_error_message();
			} else {
				$deleted[] = $id;
			}
		}

		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/**
	 * Get a single job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	public function get_job( $job_id ) {
		return $this->job_repository->find( $job_id );
	}

	/**
	 * Initialize a staged backup job.
	 *
	 * @param array $job Job data.
	 * @return true|WP_Error
	 */
	private function process_job_init( array $job ) {
		global $wpdb;

		$preflight = $this->get_preflight_status();

		if ( ! $preflight['ready'] ) {
			return new WP_Error( 'zoecloud_preflight_failed', __( 'Server requirements are not met for backups.', 'zoe-cloud' ), $preflight );
		}

		$args        = wp_parse_args(
			$job['args'],
			array(
				'include_core' => false,
				'upload_cloud' => true,
			)
		);
		$settings    = $this->get_settings();
		$domain      = wp_parse_url( home_url(), PHP_URL_HOST );
		$timestamp   = gmdate( 'Y-m-d-H-i' );
		$slug        = sanitize_title_with_dashes( (string) $domain );
		$filename    = sprintf( 'zoe-cloud-backup-%1$s-%2$s.zip', $slug, $timestamp );
		$storage_key = $this->storage->create_archive_key();
		$storage_dir = $this->get_storage_dir();
		$working_dir = trailingslashit( $storage_dir ) . 'tmp-' . wp_generate_password( 12, false, false );

		if ( ! wp_mkdir_p( $working_dir ) ) {
			return new WP_Error( 'zoecloud_job_workspace_failed', __( 'Could not create the backup workspace.', 'zoe-cloud' ) );
		}

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$tables = array_values( array_diff( $tables, array( ZoeCloud_Schema::table( 'backups' ), ZoeCloud_Schema::table( 'jobs' ), ZoeCloud_Schema::table( 'job_events' ) ) ) );

		if ( empty( $tables ) ) {
			$this->cleanup_directory( $working_dir );
			return new WP_Error( 'zoecloud_db_tables_missing', __( 'No database tables were found.', 'zoe-cloud' ) );
		}

		$database_file = trailingslashit( $working_dir ) . 'database.sql';

		if ( false === file_put_contents( $database_file, "-- ZoeCloud database export\nSET foreign_key_checks = 0;\n\n" ) ) {
			$this->cleanup_directory( $working_dir );
			return new WP_Error( 'zoecloud_db_dump_failed', __( 'Could not write the database dump.', 'zoe-cloud' ) );
		}

		$manifest = array(
			'format'               => 'zoecloud-backup',
			'format_version'       => 2,
			'plugin_version'       => ZOECLOUD_VERSION,
			'display_filename'     => $filename,
			'generated_at'         => gmdate( 'c' ),
			'domain'               => (string) $domain,
			'home_url'             => home_url(),
			'site_url'             => site_url(),
			'origin'               => array(
				'host'         => (string) $domain,
				'home_url'     => home_url(),
				'site_url'     => site_url(),
				'table_prefix' => $wpdb->prefix,
			),
			'scope'                => ! empty( $args['include_core'] ) ? 'full' : 'site_data',
			'include_core'         => (bool) $args['include_core'],
			'wordpress'            => get_bloginfo( 'version' ),
			'requirements'         => array(
				'wordpress'  => '6.4',
				'php'        => '8.1',
				'ziparchive' => true,
			),
			'exclusions'           => $settings['excluded_paths'],
			'files_count'          => 0,
			'files_size'           => 0,
			'database_tables'      => count( $tables ),
			'database_rows'        => 0,
			'database_table_names' => array_values( $tables ),
			'database_prefix'      => $wpdb->prefix,
			'checksums'            => array(),
		);

		$state = array(
			'storage_dir'    => $storage_dir,
			'working_dir'    => $working_dir,
			'database_file'  => $database_file,
			'manifest_file'  => trailingslashit( $working_dir ) . 'manifest.json',
			'filelist_file'  => trailingslashit( $working_dir ) . 'files.jsonl',
			'checksums_file' => trailingslashit( $working_dir ) . 'checksums.jsonl',
			'archive_path'   => $this->storage->resolve( $storage_key ),
			'filename'       => $filename,
			'storage_key'    => $storage_key,
			'tables'         => array_values( $tables ),
			'table_index'    => 0,
			'table_offset'   => 0,
			'table_started'  => false,
			'database_rows'  => 0,
			'files_count'    => 0,
			'files_size'     => 0,
			'zip_index'      => 0,
			'manifest'       => $manifest,
			'excluded_paths' => $settings['excluded_paths'],
		);

		$this->write_manifest_file( $state );
		$this->advance_job( $job['id'], 'export_database', 8, __( 'Exporting database.', 'zoe-cloud' ), $state );

		return $this->schedule_next_job_run( $job['id'] );
	}

	/**
	 * Process a slice of the database export.
	 *
	 * @param array $job Job data.
	 * @return true|WP_Error
	 */
	private function process_job_database( array $job ) {
		global $wpdb;

		$state  = $job['state'];
		$tables = isset( $state['tables'] ) ? (array) $state['tables'] : array();

		if ( empty( $tables ) ) {
			return new WP_Error( 'zoecloud_db_tables_missing', __( 'No database tables were found.', 'zoe-cloud' ) );
		}

		$handle = fopen( $state['database_file'], 'ab' );

		if ( false === $handle ) {
			return new WP_Error( 'zoecloud_db_dump_failed', __( 'Could not write the database dump.', 'zoe-cloud' ) );
		}

		$batches     = 0;
		$table_count = count( $tables );

		while ( $batches < $this->db_batches_per_run && $state['table_index'] < $table_count ) {
			$table      = $tables[ $state['table_index'] ];
			$table_name = $this->quote_identifier( $table );

			if ( empty( $state['table_started'] ) ) {
				$create = $wpdb->get_row( "SHOW CREATE TABLE {$table_name}", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				if ( empty( $create[1] ) ) {
					fclose( $handle );
					return new WP_Error( 'zoecloud_db_schema_failed', __( 'Could not export a database table schema.', 'zoe-cloud' ), array( 'table' => $table ) );
				}

				fwrite( $handle, "DROP TABLE IF EXISTS {$table_name};\n" );
				fwrite( $handle, $create[1] . ";\n\n" );
				$state['table_started']   = true;
				$state['table_offset']    = 0;
				$state['table_row_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$order = $this->get_table_order_clause( $table );
			$query = $wpdb->prepare( "SELECT * FROM {$table_name}{$order} LIMIT %d OFFSET %d", $this->db_batch_size, $state['table_offset'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			foreach ( $rows as $row ) {
				$this->write_insert_statement( $handle, $table_name, $row );
				++$state['database_rows'];
			}

			$state['table_offset'] += $this->db_batch_size;
			++$batches;

			if ( count( $rows ) < $this->db_batch_size ) {
				$current_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( (int) ( $state['table_row_count'] ?? $current_count ) !== $current_count ) {
					$state['manifest']['consistency_warnings'][] = $table;
				}
				fwrite( $handle, "\n" );
				++$state['table_index'];
				$state['table_offset']  = 0;
				$state['table_started'] = false;
			}
		}

		fclose( $handle );

		if ( $state['table_index'] >= $table_count ) {
			file_put_contents( $state['database_file'], "SET foreign_key_checks = 1;\n", FILE_APPEND );
			$state['manifest']['database_rows']             = $state['database_rows'];
			$state['manifest']['checksums']['database.sql'] = hash_file( 'sha256', $state['database_file'] );
			$this->write_manifest_file( $state );
			$this->advance_job( $job['id'], 'scan_files', 40, __( 'Database exported. Scanning files.', 'zoe-cloud' ), $state );
		} else {
			$progress = 10 + (int) floor( ( $state['table_index'] / max( 1, $table_count ) ) * 30 );
			/* translators: 1: Current database table number. 2: Total database table count. */
			$this->advance_job( $job['id'], 'export_database', $progress, sprintf( __( 'Exporting database table %1$d of %2$d.', 'zoe-cloud' ), $state['table_index'] + 1, $table_count ), $state );
		}

		return $this->schedule_next_job_run( $job['id'] );
	}

	/**
	 * Scan files into a durable file list.
	 *
	 * @param array $job Job data.
	 * @return true|WP_Error
	 */
	private function process_job_scan_files( array $job ) {
		$state          = $job['state'];
		$args           = wp_parse_args( $job['args'], array( 'include_core' => false ) );
		$excluded_roots = $this->build_excluded_roots( $state['storage_dir'], (array) $state['excluded_paths'] );

		if ( empty( $state['scan_initialized'] ) ) {
			$state['scan_queue'] = array(
				array(
					'path'    => wp_normalize_path( WP_CONTENT_DIR ),
					'archive' => 'files/wp-content',
				),
			);
			if ( ! empty( $args['include_core'] ) ) {
				foreach ( (array) scandir( ABSPATH ) as $item ) {
					if ( in_array( $item, array( '.', '..', 'wp-content' ), true ) ) {
						continue;
					}
					$state['scan_queue'][] = array(
						'path'    => wp_normalize_path( trailingslashit( ABSPATH ) . $item ),
						'archive' => 'files/' . $item,
					);
				}
			}
			$state['scan_index']       = 0;
			$state['files_count']      = 0;
			$state['files_size']       = 0;
			$state['scan_initialized'] = true;
			file_put_contents( $state['filelist_file'], '' );
			file_put_contents( $state['checksums_file'], '' );
		}

		$handle          = fopen( $state['filelist_file'], 'ab' );
		$checksum_handle = fopen( $state['checksums_file'], 'ab' );

		if ( false === $handle || false === $checksum_handle ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			if ( is_resource( $checksum_handle ) ) {
				fclose( $checksum_handle );
			}
			return new WP_Error( 'zoecloud_filelist_failed', __( 'Could not create the file list.', 'zoe-cloud' ) );
		}

		$processed   = 0;
		$queue_count = count( $state['scan_queue'] );
		while ( $state['scan_index'] < $queue_count && $processed < 250 ) {
			$entry = $state['scan_queue'][ $state['scan_index'] ];
			++$state['scan_index'];
			++$processed;
			$path = wp_normalize_path( $entry['path'] );
			if ( $this->is_excluded_path( $path, $excluded_roots ) || is_link( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			if ( is_dir( $path ) ) {
				$children = scandir( $path );
				if ( false === $children ) {
					continue;
				}
				foreach ( $children as $child ) {
					if ( '.' === $child || '..' === $child ) {
						continue;
					}
					$state['scan_queue'][] = array(
						'path'    => $path . '/' . $child,
						'archive' => untrailingslashit( $entry['archive'] ) . '/' . $child,
					);
					++$queue_count;
				}
				continue;
			}
			$this->write_filelist_entry( $handle, $path, $entry['archive'], $checksum_handle );
			++$state['files_count'];
			$state['files_size'] += (int) filesize( $path );
		}

		fclose( $handle );
		fclose( $checksum_handle );

		if ( $state['scan_index'] < $queue_count ) {
			/* translators: %d: Number of files indexed. */
			$this->advance_job( $job['id'], 'scan_files', 45, sprintf( __( 'Scanning files: %d indexed.', 'zoe-cloud' ), $state['files_count'] ), $state );

			return $this->schedule_next_job_run( $job['id'] );
		}

		$state['manifest']['files_count']                  = $state['files_count'];
		$state['manifest']['files_size']                   = $state['files_size'];
		$state['manifest']['checksums']['checksums.jsonl'] = hash_file( 'sha256', $state['checksums_file'] );
		unset( $state['scan_queue'], $state['scan_index'], $state['scan_initialized'] );
		$this->write_manifest_file( $state );

		$zip = new ZipArchive();

		if ( true !== $zip->open( $state['archive_path'], ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zoecloud_zip_failed', __( 'Could not create the backup archive.', 'zoe-cloud' ) );
		}

		$zip->addEmptyDir( 'files' );
		$zip->close();

		$this->advance_job( $job['id'], 'zip_files', 50, __( 'File scan complete. Creating archive.', 'zoe-cloud' ), $state );

		return $this->schedule_next_job_run( $job['id'] );
	}

	/**
	 * Add a slice of files to the archive.
	 *
	 * @param array $job Job data.
	 * @return true|WP_Error
	 */
	private function process_job_zip_files( array $job ) {
		$state = $job['state'];
		$zip   = new ZipArchive();

		if ( true !== $zip->open( $state['archive_path'] ) ) {
			return new WP_Error( 'zoecloud_zip_failed', __( 'Could not open the backup archive.', 'zoe-cloud' ) );
		}

		$file      = new SplFileObject( $state['filelist_file'], 'rb' );
		$processed = 0;
		$zip_index = absint( $state['zip_index'] );
		$total     = max( 1, absint( $state['files_count'] ) );

		$file->seek( $zip_index );

		while ( ! $file->eof() && $processed < $this->file_batch_size ) {
			$line = trim( (string) $file->current() );
			$file->next();

			if ( '' === $line ) {
				++$zip_index;
				continue;
			}

			$entry = json_decode( $line, true );

			if ( is_array( $entry ) && ! empty( $entry['path'] ) && ! empty( $entry['archive'] ) && is_file( $entry['path'] ) ) {
				$unchanged = (int) filesize( $entry['path'] ) === (int) ( $entry['size'] ?? -1 )
					&& (int) filemtime( $entry['path'] ) === (int) ( $entry['mtime'] ?? -1 )
					&& hash_equals( (string) ( $entry['sha256'] ?? '' ), hash_file( 'sha256', $entry['path'] ) );
				if ( ! $unchanged ) {
					$zip->close();

					return new WP_Error( 'zoecloud_source_changed', __( 'A source file changed while the backup was being created. The job will retry.', 'zoe-cloud' ) );
				}
				$zip->addFile( $entry['path'], $entry['archive'] );
			}

			++$processed;
			++$zip_index;
		}

		$zip->close();
		$state['zip_index'] = $zip_index;

		if ( $zip_index >= $state['files_count'] || $file->eof() ) {
			$this->advance_job( $job['id'], 'finalize', 85, __( 'Archive files added. Finalizing backup.', 'zoe-cloud' ), $state );
		} else {
			$progress = 50 + (int) floor( ( $zip_index / $total ) * 35 );
			/* translators: 1: Number of files added to archive. 2: Total file count. */
			$this->advance_job( $job['id'], 'zip_files', $progress, sprintf( __( 'Added %1$d of %2$d files to archive.', 'zoe-cloud' ), $zip_index, $state['files_count'] ), $state );
		}

		return $this->schedule_next_job_run( $job['id'] );
	}

	/**
	 * Add manifest/database files and register the backup.
	 *
	 * @param array $job Job data.
	 * @return true|WP_Error
	 */
	private function process_job_finalize( array $job ) {
		$state = $job['state'];
		$this->write_manifest_file( $state );

		$zip = new ZipArchive();

		if ( true !== $zip->open( $state['archive_path'] ) ) {
			return new WP_Error( 'zoecloud_zip_failed', __( 'Could not open the backup archive.', 'zoe-cloud' ) );
		}

		$zip->addFile( $state['database_file'], 'database.sql' );
		$zip->addFile( $state['manifest_file'], 'manifest.json' );
		$zip->addFile( $state['checksums_file'], 'checksums.jsonl' );
		$zip->close();
		$validated = ( new ZoeCloud_Restore_Manager( $this->storage, $this->backup_repository ) )->validate_backup( $state['archive_path'] );
		if ( is_wp_error( $validated ) ) {
			wp_delete_file( $state['archive_path'] );

			return $validated;
		}

		$record_id = wp_generate_uuid4();
		$record    = array(
			'id'           => $record_id,
			'created_at'   => current_time( 'mysql', true ),
			'filename'     => $state['filename'],
			'storage_key'  => $state['storage_key'],
			'path'         => $state['archive_path'],
			'download_url' => $this->build_download_url( $record_id ),
			'size'         => file_exists( $state['archive_path'] ) ? filesize( $state['archive_path'] ) : 0,
			'manifest'     => $state['manifest'],
			'cloud'        => null,
			'source'       => $job['args']['source'] ?? 'manual',
			'scope'        => $job['args']['scope'] ?? ( ! empty( $job['args']['include_core'] ) ? 'full' : 'site_data' ),
			'locked'       => false,
			'checksum'     => file_exists( $state['archive_path'] ) ? hash_file( 'sha256', $state['archive_path'] ) : '',
			'verified_at'  => current_time( 'mysql', true ),
		);

		$this->store_record( $record );
		$this->apply_retention_policy();
		$state['backup_id'] = $record['id'];

		if ( ! empty( $job['args']['upload_cloud'] ) ) {
			$this->advance_job( $job['id'], 'cloud_upload', 90, __( 'Uploading backup to cloud storage.', 'zoe-cloud' ), $state );
		} else {
			$this->advance_job( $job['id'], 'cleanup', 95, __( 'Cleaning up temporary files.', 'zoe-cloud' ), $state, array( 'backup_id' => $record['id'] ) );
		}

		return $this->schedule_next_job_run( $job['id'] );
	}

	/**
	 * Upload the finished archive to cloud storage when configured.
	 *
	 * @param array $job Job data.
	 * @return true|WP_Error
	 */
	private function process_job_cloud_upload( array $job ) {
		$state        = $job['state'];
		$cloud_upload = $this->cloud_service->upload_backup( $state['archive_path'], $state['manifest'] );

		if ( is_wp_error( $cloud_upload ) ) {
			$this->attach_cloud_error_to_backup( $state['backup_id'], $cloud_upload->get_error_message() );
		} else {
			$this->attach_cloud_upload_to_backup( $state['backup_id'], $cloud_upload );
		}

		$this->advance_job( $job['id'], 'cleanup', 95, __( 'Cleaning up temporary files.', 'zoe-cloud' ), $state, array( 'backup_id' => $state['backup_id'] ) );

		return $this->schedule_next_job_run( $job['id'] );
	}

	/**
	 * Remove temporary files and complete the job.
	 *
	 * @param array $job Job data.
	 * @return true
	 */
	private function process_job_cleanup( array $job ) {
		$state = $job['state'];

		if ( ! empty( $state['working_dir'] ) ) {
			$this->cleanup_directory( $state['working_dir'] );
		}

		$this->update_job( $job['id'], 'completed', 100, __( 'Backup completed.', 'zoe-cloud' ), array( 'backup_id' => $state['backup_id'] ?? '' ) );

		return true;
	}

	/**
	 * Delete a backup record and its local file.
	 *
	 * @param string $backup_id Backup UUID.
	 * @return true|WP_Error
	 */
	public function delete_backup( $backup_id ) {
		$record = $this->backup_repository->find( $backup_id );
		if ( ! $record ) {
			return new WP_Error( 'zoecloud_backup_missing', __( 'Backup file not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $record['locked'] ) ) {
			return new WP_Error( 'zoecloud_backup_locked', __( 'Unlock this backup before deleting it.', 'zoe-cloud' ), array( 'status' => 409 ) );
		}

		$record['deletion_status'] = 'deleting';
		$this->backup_repository->save( $record );
		$delete_result = $this->delete_backup_files( $record );
		if ( is_wp_error( $delete_result ) ) {
			$record['deletion_status'] = 'failed';
			$record['last_error']      = $delete_result->get_error_message();
			$this->backup_repository->save( $record );

			return $delete_result;
		}
		$this->backup_repository->delete( $backup_id );

		return true;
	}

	/**
	 * Import an uploaded backup ZIP into the backups list.
	 *
	 * Moves the temporary file into the storage directory and registers a
	 * backup record so the archive appears in the backup list and can be
	 * selected for restore.
	 *
	 * @param string $temp_path   Absolute path to the uploaded temp ZIP.
	 * @param array  $manifest    Manifest data already extracted from the ZIP.
	 * @return array|WP_Error     Backup record on success or WP_Error on failure.
	 */
	public function import_uploaded_backup( $temp_path, array $manifest = array() ) {
		if ( ! file_exists( $temp_path ) ) {
			return new WP_Error( 'zoecloud_import_missing', __( 'Uploaded file not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}

		$storage_dir = $this->get_storage_dir();
		$original    = basename( $temp_path );

		// Build a final filename: keep original name when it matches the
		// plugin naming convention, otherwise prefix it with a timestamp.
		if ( preg_match( '/^zoe-cloud-backup-.+\.zip$/i', $original ) ) {
			$filename = sanitize_file_name( $original );
		} else {
			$timestamp = gmdate( 'Y-m-d-H-i' );
			$slug      = sanitize_title_with_dashes( (string) ( $manifest['domain'] ?? wp_parse_url( home_url(), PHP_URL_HOST ) ) );
			$filename  = sprintf( 'zoe-cloud-backup-%1$s-%2$s.zip', $slug, $timestamp );
		}

		$storage_key = $this->storage->create_archive_key();
		$dest        = $this->storage->resolve( $storage_key );

		if ( ! rename( $temp_path, $dest ) ) {
			return new WP_Error( 'zoecloud_import_move_failed', __( 'Could not move the uploaded file to the backups folder.', 'zoe-cloud' ) );
		}

		$record_id = wp_generate_uuid4();
		$record    = array(
			'id'           => $record_id,
			'created_at'   => current_time( 'mysql', true ),
			'filename'     => $filename,
			'storage_key'  => $storage_key,
			'path'         => $dest,
			'download_url' => $this->build_download_url( $record_id ),
			'size'         => filesize( $dest ),
			'manifest'     => $manifest,
			'cloud'        => null,
			'imported'     => true,
			'source'       => 'imported',
			'scope'        => ! empty( $manifest['include_core'] ) ? 'full' : 'site_data',
			'locked'       => false,
			'checksum'     => hash_file( 'sha256', $dest ),
			'verified_at'  => current_time( 'mysql', true ),
		);

		$this->store_record( $record );

		return $record;
	}

	/**
	 * Inspect server requirements before a backup runs.
	 *
	 * @return array
	 */
	public function get_preflight_status() {
		$storage_dir = $this->get_storage_dir();
		$checks      = array(
			'ziparchive'         => class_exists( 'ZipArchive' ),
			'uploads_writable'   => wp_is_writable( $storage_dir ),
			'can_create_files'   => $this->can_create_storage_file( $storage_dir ),
			'disk_free_bytes'    => function_exists( 'disk_free_space' ) ? (int) disk_free_space( $storage_dir ) : null,
			'memory_limit'       => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'wp_cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);

		$checks['ready'] = ! empty( $checks['ziparchive'] ) && ! empty( $checks['uploads_writable'] ) && ! empty( $checks['can_create_files'] );

		return $checks;
	}

	/**
	 * Resolve a backup file by ID.
	 *
	 * @param string $backup_id Backup UUID.
	 * @return string
	 */
	public function get_backup_path( $backup_id ) {
		$record = $this->backup_repository->find( $backup_id );

		return $record ? $this->storage->resolve( $record['storage_key'] ) : '';
	}

	/**
	 * Verify a stored backup's archive checksum and v2 payload.
	 *
	 * @param string $backup_id Backup UUID.
	 * @return array|WP_Error
	 */
	public function verify_backup( $backup_id ) {
		$record = $this->backup_repository->find( $backup_id );
		if ( ! $record ) {
			return new WP_Error( 'zoecloud_backup_missing', __( 'Backup file not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}
		$path = $this->storage->resolve( $record['storage_key'] );
		if ( ! is_readable( $path ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $record['checksum'] ) || ! hash_equals( $record['checksum'], hash_file( 'sha256', $path ) ) ) {
			$record['verification_status'] = 'failed';
			$record['last_error']          = __( 'Backup archive checksum mismatch.', 'zoe-cloud' );
			$this->backup_repository->save( $record );

			return new WP_Error( 'zoecloud_backup_checksum_mismatch', __( 'Backup archive checksum mismatch.', 'zoe-cloud' ), array( 'status' => 409 ) );
		}
		$validated = ( new ZoeCloud_Restore_Manager( $this->storage, $this->backup_repository ) )->validate_backup( $path );
		if ( is_wp_error( $validated ) ) {
			$record['verification_status'] = 'failed';
			$record['last_error']          = $validated->get_error_message();
			$this->backup_repository->save( $record );

			return $validated;
		}
		$record['verification_status'] = 'verified';
		$record['last_error']          = '';
		$record                        = $this->backup_repository->save( $record );

		return $record;
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

		$backup_id = isset( $_GET['backup_id'] ) ? sanitize_text_field( wp_unslash( $_GET['backup_id'] ) ) : '';
		$record    = $this->backup_repository->find( $backup_id );
		$path      = $record ? $this->storage->resolve( $record['storage_key'] ) : '';

		if ( empty( $record ) || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'zoe-cloud' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $record['filename'] ) . '"' );
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
				'auto_upload_cloud' => 1,
			)
		);

		$this->enqueue_backup(
			array(
				'include_core' => false,
				'upload_cloud' => ! empty( $settings['auto_upload_cloud'] ),
				'source'       => 'scheduled',
			)
		);
	}

	/**
	 * Store a backup record.
	 *
	 * @param array $record Backup metadata.
	 * @return void
	 */
	private function store_record( array $record ) {
		$this->backup_repository->save( $record );
	}

	/**
	 * Update a job status.
	 *
	 * @param string $job_id   Job ID.
	 * @param string $status   Status.
	 * @param int    $progress Progress from 0 to 100.
	 * @param string $message  User-facing message.
	 * @param array  $result   Result payload.
	 * @return void
	 */
	private function update_job( $job_id, $status, $progress, $message, array $result = array() ) {
		$job = $this->get_job( $job_id );
		if ( empty( $job ) ) {
			return;
		}
		$changes = array(
			'status'     => $status,
			'progress'   => $progress,
			'last_error' => 'failed' === $status ? sanitize_text_field( $message ) : null,
		);
		if ( ! empty( $result ) ) {
			$changes['result'] = $result;
		}
		$this->job_repository->update( $job_id, $changes );
		$this->job_repository->event( $job_id, $job['stage'] ?? '', $status, $message );
	}

	/**
	 * Move a job to the next stage.
	 *
	 * @param string $job_id   Job ID.
	 * @param string $stage    Next stage.
	 * @param int    $progress Progress.
	 * @param string $message  Message.
	 * @param array  $state    Job state.
	 * @param array  $result   Result payload.
	 * @return void
	 */
	private function advance_job( $job_id, $stage, $progress, $message, array $state, array $result = array() ) {
		if ( ! $this->get_job( $job_id ) ) {
			return;
		}
		$changes = array(
			'status'   => 'running',
			'stage'    => $stage,
			'progress' => $progress,
			'state'    => $state,
		);
		if ( ! empty( $result ) ) {
			$changes['result'] = $result;
		}
		$this->job_repository->update( $job_id, $changes );
		$this->job_repository->event( $job_id, $stage, 'running', $message );
	}

	/**
	 * Append a bounded, user-safe event to a job.
	 *
	 * @param array  $job     Job passed by reference.
	 * @param string $stage   Stage key.
	 * @param string $status  Status key.
	 * @param string $message Message.
	 * @return void
	 */
	private function append_job_event( array &$job, $stage, $status, $message ) {
		$job['events']   = isset( $job['events'] ) && is_array( $job['events'] ) ? $job['events'] : array();
		$job['events'][] = array(
			'time'    => current_time( 'mysql', true ),
			'stage'   => sanitize_key( $stage ),
			'status'  => sanitize_key( $status ),
			'message' => sanitize_text_field( $message ),
		);
		$job['events']   = array_slice( $job['events'], -100 );
	}

	/**
	 * Mark a job as failed and clean its temporary directory.
	 *
	 * @param string $job_id  Job ID.
	 * @param string $message Failure message.
	 * @return void
	 */
	private function fail_job( $job_id, $message ) {
		$job = $this->get_job( $job_id );
		if ( $job && (int) $job['attempts'] < (int) $job['max_attempts'] ) {
			$delay = min( HOUR_IN_SECONDS, 30 * ( 2 ** max( 0, (int) $job['attempts'] - 1 ) ) );
			$this->job_repository->update(
				$job_id,
				array(
					'status'     => 'waiting',
					'run_after'  => gmdate( 'Y-m-d H:i:s', time() + $delay ),
					'last_error' => sanitize_text_field( $message ),
				)
			);
			$this->job_repository->event( $job_id, $job['stage'] ?? '', 'waiting', __( 'The job will be retried after a temporary failure.', 'zoe-cloud' ) );

			return;
		}

		if ( ! empty( $job['state']['working_dir'] ) ) {
			$this->cleanup_directory( $job['state']['working_dir'] );
		}

		$this->update_job( $job_id, 'failed', 100, $message );
	}

	/**
	 * Schedule the next job tick.
	 *
	 * @param string $job_id Job ID.
	 * @return true|WP_Error
	 */
	private function schedule_next_job_run( $job_id ) {
		if ( $this->defer_scheduling ) {
			return true;
		}
		$scheduled = wp_schedule_single_event( time() + 1, 'zoecloud_run_backup_job', array( $job_id ) );
		if ( ! $scheduled ) {
			return new WP_Error( 'zoecloud_job_schedule_failed', __( 'Could not schedule the next backup job step.', 'zoe-cloud' ) );
		}

		return true;
	}

	/**
	 * Remove old unlocked backups according to retention.
	 *
	 * @return void
	 */
	private function apply_retention_policy() {
		$settings  = wp_parse_args( get_option( 'zoecloud_settings', array() ), array( 'retention_limit' => 10 ) );
		$retention = max( 1, absint( $settings['retention_limit'] ) );
		$unlocked  = array_values(
			array_filter(
				$this->list_backups(),
				static function ( $record ) {
					return empty( $record['locked'] );
				}
			)
		);
		foreach ( array_slice( $unlocked, $retention ) as $record ) {
			$result = $this->delete_backup_files( $record );
			if ( ! is_wp_error( $result ) ) {
				$this->backup_repository->delete( $record['id'] );
			}
		}
	}

	/**
	 * Delete cloud and local copies as separately persisted operations.
	 *
	 * @param array $record Backup record.
	 * @return true|WP_Error
	 */
	private function delete_backup_files( array $record ) {
		if ( ! empty( $record['cloud'] ) && is_array( $record['cloud'] ) && 'deleted' !== ( $record['cloud_status'] ?? '' ) ) {
			$record['cloud_status'] = 'deleting';
			$this->backup_repository->save( $record );
			$result = $this->cloud_service->delete_backup( $record['cloud'] );
			if ( is_wp_error( $result ) ) {
				$record['cloud_status'] = 'delete_failed';
				$record['last_error']   = $result->get_error_message();
				$this->backup_repository->save( $record );

				return $result;
			}
			$record['cloud_status'] = 'deleted';
			$record['cloud']        = null;
			$this->backup_repository->save( $record );
		}

		$path = ! empty( $record['storage_key'] ) ? $this->storage->resolve( $record['storage_key'] ) : '';
		if ( $path && is_file( $path ) && ! wp_delete_file( $path ) ) {
			$record['local_status'] = 'delete_failed';
			$record['last_error']   = __( 'Could not delete the local backup file.', 'zoe-cloud' );
			$this->backup_repository->save( $record );

			return new WP_Error( 'zoecloud_backup_delete_failed', $record['last_error'] );
		}
		$record['local_status'] = 'deleted';
		$this->backup_repository->save( $record );

		return true;
	}

	/**
	 * Return the private backups directory.
	 *
	 * @return string
	 */
	private function get_storage_dir() {
		return $this->storage->get_subdirectory( 'backups' );
	}

	/**
	 * Build an authenticated download URL.
	 *
	 * @param string $backup_id Backup UUID.
	 * @return string
	 */
	private function build_download_url( $backup_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'zoecloud_download_backup',
					'backup_id' => $backup_id,
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
				'\\'   => '\\\\',
				"\0"   => "\\0",
				"\n"   => "\\n",
				"\r"   => "\\r",
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
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
	 * Build deterministic ordering from a table primary key.
	 *
	 * @param string $table Database table.
	 * @return string
	 */
	private function get_table_order_clause( $table ) {
		global $wpdb;

		$table_name = $this->quote_identifier( $table );
		$keys       = $wpdb->get_results( "SHOW KEYS FROM {$table_name} WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		usort(
			$keys,
			static function ( $left, $right ) {
				return (int) ( $left['Seq_in_index'] ?? 0 ) <=> (int) ( $right['Seq_in_index'] ?? 0 );
			}
		);
		$columns = array();
		foreach ( (array) $keys as $key ) {
			if ( ! empty( $key['Column_name'] ) ) {
				$columns[] = $this->quote_identifier( $key['Column_name'] );
			}
		}

		return $columns ? ' ORDER BY ' . implode( ',', $columns ) : '';
	}

	/**
	 * Write one staged file and its authenticated checksum entry.
	 *
	 * @param resource      $handle          File list handle.
	 * @param string        $path            Absolute source path.
	 * @param string        $archive_path    Archive path.
	 * @param resource|null $checksum_handle Checksum index handle.
	 * @return void
	 */
	private function write_filelist_entry( $handle, $path, $archive_path, $checksum_handle = null ) {
		$hash = hash_file( 'sha256', $path );
		fwrite(
			$handle,
			wp_json_encode(
				array(
					'path'    => $path,
					'archive' => $archive_path,
					'size'    => (int) filesize( $path ),
					'mtime'   => (int) filemtime( $path ),
					'sha256'  => $hash,
				),
				JSON_UNESCAPED_SLASHES
			) . "\n"
		);
		if ( is_resource( $checksum_handle ) ) {
			fwrite(
				$checksum_handle,
				wp_json_encode(
					array(
						'path'   => $archive_path,
						'size'   => (int) filesize( $path ),
						'sha256' => $hash,
					),
					JSON_UNESCAPED_SLASHES
				) . "\n"
			);
		}
	}

	/**
	 * Write the current manifest to disk.
	 *
	 * @param array $state Job state.
	 * @return void
	 */
	private function write_manifest_file( array $state ) {
		file_put_contents( $state['manifest_file'], wp_json_encode( $state['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Attach cloud upload metadata to a stored backup.
	 *
	 * @param string $backup_id Backup UUID.
	 * @param array  $upload    Cloud upload payload.
	 * @return void
	 */
	private function attach_cloud_upload_to_backup( $backup_id, array $upload ) {
		$record = $this->backup_repository->find( $backup_id );
		if ( $record ) {
			$record['cloud']        = $upload;
			$record['cloud_status'] = 'available';
			$record['last_error']   = '';
			$this->backup_repository->save( $record );
		}
	}

	/**
	 * Attach cloud upload error to a stored backup.
	 *
	 * @param string $backup_id Backup ID.
	 * @param string $message   Error message.
	 * @return void
	 */
	private function attach_cloud_error_to_backup( $backup_id, $message ) {
		$record = $this->backup_repository->find( $backup_id );
		if ( $record ) {
			$record['cloud_status'] = 'failed';
			$record['last_error']   = $message;
			$this->backup_repository->save( $record );
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
			wp_normalize_path( $this->storage->get_directory() ),
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
