<?php
/**
 * Backup generation.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * @param ZoeCloud_R2_Service $cloud_service Cloud service.
	 */
	public function __construct( ZoeCloud_R2_Service $cloud_service ) {
		$this->cloud_service = $cloud_service;
	}

	/**
	 * Create a full site backup.
	 *
	 * @param array $args Backup arguments.
	 * @return array|WP_Error
	 */
	public function create_backup( array $args = array() ) {
		$job_id    = isset( $args['job_id'] ) ? sanitize_key( $args['job_id'] ) : '';
		$preflight = $this->get_preflight_status();

		if ( ! $preflight['ready'] ) {
			if ( $job_id ) {
				$this->update_job( $job_id, 'failed', 100, __( 'Server requirements are not met for backups.', 'zoe-cloud' ) );
			}

			return new WP_Error( 'zoecloud_preflight_failed', __( 'Server requirements are not met for backups.', 'zoe-cloud' ), $preflight );
		}

		if ( $job_id ) {
			$this->update_job( $job_id, 'running', 5, __( 'Preparing backup.', 'zoe-cloud' ) );
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
		$filename     = sprintf( 'zoe-cloud-backup-%1$s-%2$s.zip', $slug, $timestamp );
		$storage_dir  = $this->get_storage_dir();
		$working_dir  = trailingslashit( $storage_dir ) . 'tmp-' . wp_generate_password( 12, false, false );
		$archive_path = trailingslashit( $storage_dir ) . $filename;

		wp_mkdir_p( $working_dir );

		$manifest = array(
			'plugin_version'       => ZOECLOUD_VERSION,
			'generated_at'         => gmdate( 'c' ),
			'domain'               => (string) $domain,
			'home_url'             => home_url(),
			'site_url'             => site_url(),
			'include_core'         => (bool) $args['include_core'],
			'wordpress'            => get_bloginfo( 'version' ),
			'exclusions'           => $settings['excluded_paths'],
			'files_count'          => 0,
			'files_size'           => 0,
			'database_tables'      => 0,
			'database_rows'        => 0,
			'database_table_names' => array(),
		);

		$database_result = $this->export_database( $working_dir . '/database.sql' );

		if ( is_wp_error( $database_result ) ) {
			$this->cleanup_directory( $working_dir );
			if ( $job_id ) {
				$this->update_job( $job_id, 'failed', 100, $database_result->get_error_message() );
			}
			return $database_result;
		}

		if ( $job_id ) {
			$this->update_job( $job_id, 'running', 35, __( 'Database exported.', 'zoe-cloud' ) );
		}

		$manifest['database_tables']      = $database_result['tables'];
		$manifest['database_rows']        = $database_result['rows'];
		$manifest['database_table_names'] = $database_result['table_names'];

		file_put_contents( $working_dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$zip = new ZipArchive();

		if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->cleanup_directory( $working_dir );
			if ( $job_id ) {
				$this->update_job( $job_id, 'failed', 100, __( 'Could not create the backup archive.', 'zoe-cloud' ) );
			}
			return new WP_Error( 'zoecloud_zip_failed', __( 'Could not create the backup archive.', 'zoe-cloud' ) );
		}

		if ( $job_id ) {
			$this->update_job( $job_id, 'running', 45, __( 'Adding files to archive.', 'zoe-cloud' ) );
		}

		$zip->addEmptyDir( 'files' );
		$excluded_roots = $this->build_excluded_roots( $storage_dir, $settings['excluded_paths'] );
		$file_stats     = $this->add_path_to_zip( $zip, WP_CONTENT_DIR, 'files/wp-content', $excluded_roots );

		if ( ! empty( $args['include_core'] ) ) {
			$core_stats           = $this->add_core_to_zip( $zip, array_merge( $excluded_roots, array( wp_normalize_path( WP_CONTENT_DIR ) ) ) );
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

		if ( $job_id ) {
			$this->update_job( $job_id, 'running', 80, __( 'Archive created.', 'zoe-cloud' ) );
		}

		$record = array(
			'id'           => wp_generate_uuid4(),
			'created_at'   => current_time( 'mysql', true ),
			'filename'     => $filename,
			'path'         => $archive_path,
			'download_url' => $this->build_download_url( $filename ),
			'size'         => file_exists( $archive_path ) ? filesize( $archive_path ) : 0,
			'manifest'     => $manifest,
			'cloud'        => null,
		);

		if ( ! empty( $args['upload_drive'] ) ) {
			if ( $job_id ) {
				$this->update_job( $job_id, 'running', 85, __( 'Uploading backup to cloud storage.', 'zoe-cloud' ) );
			}

			$cloud_upload = $this->cloud_service->upload_backup( $archive_path, $manifest );

			if ( ! is_wp_error( $cloud_upload ) ) {
				$record['cloud'] = $cloud_upload;
			} else {
				$record['cloud_error'] = $cloud_upload->get_error_message();
			}
		}

		$this->store_record( $record );
		$this->apply_retention_policy();

		if ( $job_id ) {
			$this->update_job( $job_id, 'completed', 100, __( 'Backup completed.', 'zoe-cloud' ), array( 'backup_id' => $record['id'] ) );
		}

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

		$job = array(
			'id'         => wp_generate_uuid4(),
			'type'       => 'backup',
			'status'     => 'queued',
			'progress'   => 0,
			'message'    => __( 'Backup queued.', 'zoe-cloud' ),
			'stage'      => 'init',
			'args'       => array(
				'include_core' => ! empty( $args['include_core'] ),
				'upload_drive' => ! empty( $args['upload_drive'] ),
			),
			'state'      => array(),
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
			'result'     => null,
		);

		$jobs               = $this->list_jobs();
		$jobs[ $job['id'] ] = $job;
		$this->save_jobs( $jobs );

		$scheduled = wp_schedule_single_event( time() + 1, 'zoecloud_run_backup_job', array( $job['id'] ) );

		if ( ! $scheduled ) {
			unset( $jobs[ $job['id'] ] );
			$this->save_jobs( $jobs );

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
		$lock_key = 'zoecloud_job_lock_' . sanitize_key( $job_id );

		if ( get_transient( $lock_key ) ) {
			return;
		}

		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );
		$this->defer_scheduling = true;
		$started_at             = time();
		$steps                  = 0;

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
					case 'upload_drive':
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

		delete_transient( $lock_key );
	}

	/**
	 * List backup jobs.
	 *
	 * @return array
	 */
	public function list_jobs() {
		$jobs = get_option( 'zoecloud_jobs', array() );

		if ( ! is_array( $jobs ) ) {
			return array();
		}

		return $this->expire_stale_jobs( $jobs );
	}

	/**
	 * Get a single job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null
	 */
	public function get_job( $job_id ) {
		$jobs = $this->list_jobs();

		return isset( $jobs[ $job_id ] ) && is_array( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : null;
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
				'upload_drive' => true,
			)
		);
		$settings    = $this->get_settings();
		$domain      = wp_parse_url( home_url(), PHP_URL_HOST );
		$timestamp   = gmdate( 'Y-m-d-H-i' );
		$slug        = sanitize_title_with_dashes( (string) $domain );
		$filename    = sprintf( 'zoe-cloud-backup-%1$s-%2$s.zip', $slug, $timestamp );
		$storage_dir = $this->get_storage_dir();
		$working_dir = trailingslashit( $storage_dir ) . 'tmp-' . wp_generate_password( 12, false, false );

		if ( ! wp_mkdir_p( $working_dir ) ) {
			return new WP_Error( 'zoecloud_job_workspace_failed', __( 'Could not create the backup workspace.', 'zoe-cloud' ) );
		}

		$tables = $wpdb->get_col( 'SHOW TABLES' );

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
			'plugin_version'       => ZOECLOUD_VERSION,
			'generated_at'         => gmdate( 'c' ),
			'domain'               => (string) $domain,
			'home_url'             => home_url(),
			'site_url'             => site_url(),
			'include_core'         => (bool) $args['include_core'],
			'wordpress'            => get_bloginfo( 'version' ),
			'exclusions'           => $settings['excluded_paths'],
			'files_count'          => 0,
			'files_size'           => 0,
			'database_tables'      => count( $tables ),
			'database_rows'        => 0,
			'database_table_names' => array_values( $tables ),
		);

		$state = array(
			'storage_dir'    => $storage_dir,
			'working_dir'    => $working_dir,
			'database_file'  => $database_file,
			'manifest_file'  => trailingslashit( $working_dir ) . 'manifest.json',
			'filelist_file'  => trailingslashit( $working_dir ) . 'files.jsonl',
			'archive_path'   => trailingslashit( $storage_dir ) . $filename,
			'filename'       => $filename,
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
				$state['table_started'] = true;
				$state['table_offset']  = 0;
			}

			$query = $wpdb->prepare( "SELECT * FROM {$table_name} LIMIT %d OFFSET %d", $this->db_batch_size, $state['table_offset'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			foreach ( $rows as $row ) {
				$this->write_insert_statement( $handle, $table_name, $row );
				++$state['database_rows'];
			}

			$state['table_offset'] += $this->db_batch_size;
			++$batches;

			if ( count( $rows ) < $this->db_batch_size ) {
				fwrite( $handle, "\n" );
				++$state['table_index'];
				$state['table_offset']  = 0;
				$state['table_started'] = false;
			}
		}

		fclose( $handle );

		if ( $state['table_index'] >= $table_count ) {
			file_put_contents( $state['database_file'], "SET foreign_key_checks = 1;\n", FILE_APPEND );
			$state['manifest']['database_rows'] = $state['database_rows'];
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
		$handle         = fopen( $state['filelist_file'], 'wb' );

		if ( false === $handle ) {
			return new WP_Error( 'zoecloud_filelist_failed', __( 'Could not create the file list.', 'zoe-cloud' ) );
		}

		$stats = $this->write_path_filelist( $handle, WP_CONTENT_DIR, 'files/wp-content', $excluded_roots );

		if ( ! empty( $args['include_core'] ) ) {
			$core_stats      = $this->write_core_filelist( $handle, array_merge( $excluded_roots, array( wp_normalize_path( WP_CONTENT_DIR ) ) ) );
			$stats['count'] += $core_stats['count'];
			$stats['size']  += $core_stats['size'];
		}

		fclose( $handle );

		$state['files_count']             = $stats['count'];
		$state['files_size']              = $stats['size'];
		$state['manifest']['files_count'] = $stats['count'];
		$state['manifest']['files_size']  = $stats['size'];
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

			if ( is_array( $entry ) && ! empty( $entry['path'] ) && ! empty( $entry['archive'] ) && file_exists( $entry['path'] ) ) {
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
		$zip->close();

		$record = array(
			'id'           => wp_generate_uuid4(),
			'created_at'   => current_time( 'mysql', true ),
			'filename'     => $state['filename'],
			'path'         => $state['archive_path'],
			'download_url' => $this->build_download_url( $state['filename'] ),
			'size'         => file_exists( $state['archive_path'] ) ? filesize( $state['archive_path'] ) : 0,
			'manifest'     => $state['manifest'],
			'cloud'        => null,
		);

		$this->store_record( $record );
		$this->apply_retention_policy();
		$state['backup_id'] = $record['id'];

		if ( ! empty( $job['args']['upload_drive'] ) ) {
			$this->advance_job( $job['id'], 'upload_drive', 90, __( 'Uploading backup to cloud storage.', 'zoe-cloud' ), $state );
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
	 * @param string $backup_id Backup ID or filename.
	 * @return true|WP_Error
	 */
	public function delete_backup( $backup_id ) {
		$records = $this->list_backups();
		$deleted = false;

		foreach ( $records as $index => $record ) {
			$matches_id       = isset( $record['id'] ) && $record['id'] === $backup_id;
			$matches_filename = isset( $record['filename'] ) && $record['filename'] === $backup_id;

			if ( ! $matches_id && ! $matches_filename ) {
				continue;
			}

			$delete_result = $this->delete_backup_files( $record );
			if ( is_wp_error( $delete_result ) ) {
				return $delete_result;
			}

			unset( $records[ $index ] );
			$deleted = true;
			break;
		}

		if ( ! $deleted ) {
			return new WP_Error( 'zoecloud_backup_missing', __( 'Backup file not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}

		update_option( 'zoecloud_backups', array_values( $records ), false );

		return true;
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

		$this->enqueue_backup(
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
				$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				foreach ( $rows as $row ) {
					$this->write_insert_statement( $handle, $table_name, $row );
					++$total_rows;
				}

				$offset   += $this->db_batch_size;
				$row_count = count( $rows );
			} while ( $row_count === $this->db_batch_size );

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
		$jobs = $this->list_jobs();

		if ( empty( $jobs[ $job_id ] ) ) {
			return;
		}

		$jobs[ $job_id ]['status']     = sanitize_key( $status );
		$jobs[ $job_id ]['progress']   = max( 0, min( 100, absint( $progress ) ) );
		$jobs[ $job_id ]['message']    = (string) $message;
		$jobs[ $job_id ]['updated_at'] = current_time( 'mysql', true );

		if ( ! empty( $result ) ) {
			$jobs[ $job_id ]['result'] = $result;
		}

		$this->save_jobs( $jobs );
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
		$jobs = $this->list_jobs();

		if ( empty( $jobs[ $job_id ] ) ) {
			return;
		}

		$jobs[ $job_id ]['status']     = 'running';
		$jobs[ $job_id ]['stage']      = sanitize_key( $stage );
		$jobs[ $job_id ]['progress']   = max( 0, min( 100, absint( $progress ) ) );
		$jobs[ $job_id ]['message']    = (string) $message;
		$jobs[ $job_id ]['state']      = $state;
		$jobs[ $job_id ]['updated_at'] = current_time( 'mysql', true );

		if ( ! empty( $result ) ) {
			$jobs[ $job_id ]['result'] = $result;
		}

		$this->save_jobs( $jobs );
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
	 * Save jobs while keeping recent history bounded.
	 *
	 * @param array $jobs Jobs keyed by ID.
	 * @return void
	 */
	private function save_jobs( array $jobs ) {
		uasort(
			$jobs,
			static function ( $left, $right ) {
				return strcmp( $right['created_at'] ?? '', $left['created_at'] ?? '' );
			}
		);

		update_option( 'zoecloud_jobs', array_slice( $jobs, 0, 25, true ), false );
	}

	/**
	 * Mark abandoned jobs as failed so the UI does not show stale progress.
	 *
	 * @param array $jobs Jobs keyed by ID.
	 * @return array
	 */
	private function expire_stale_jobs( array $jobs ) {
		$changed   = false;
		$threshold = time() - ( 15 * MINUTE_IN_SECONDS );

		foreach ( $jobs as $job_id => &$job ) {
			if ( empty( $job['status'] ) || ! in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				continue;
			}

			$updated_at        = ! empty( $job['updated_at'] ) ? strtotime( $job['updated_at'] . ' UTC' ) : 0;
			$working_dir       = $job['state']['working_dir'] ?? '';
			$missing_workspace = ! empty( $working_dir ) && ! is_dir( $working_dir );

			if ( ! $missing_workspace && $updated_at && $updated_at > $threshold ) {
				continue;
			}

			if ( ! empty( $working_dir ) ) {
				$this->cleanup_directory( $working_dir );
			}

			$job['status']     = 'failed';
			$job['progress']   = 100;
			$job['message']    = __( 'Backup job expired before completion.', 'zoe-cloud' );
			$job['updated_at'] = current_time( 'mysql', true );
			$changed           = true;
		}
		unset( $job );

		if ( $changed ) {
			$this->save_jobs( $jobs );
		}

		return $jobs;
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
			$delete_result = $this->delete_backup_files( $record );

			if ( is_wp_error( $delete_result ) ) {
				$active[] = $record;
			}
		}

		update_option( 'zoecloud_backups', $active, false );
	}

	/**
	 * Delete local and cloud files for a backup record.
	 *
	 * @param array $record Backup record.
	 * @return true|WP_Error
	 */
	private function delete_backup_files( array $record ) {
		if ( ! empty( $record['cloud'] ) && is_array( $record['cloud'] ) ) {
			$cloud_delete = $this->cloud_service->delete_backup( $record['cloud'] );

			if ( is_wp_error( $cloud_delete ) ) {
				return $cloud_delete;
			}
		}

		if ( ! empty( $record['path'] ) && file_exists( $record['path'] ) && ! unlink( $record['path'] ) ) {
			return new WP_Error( 'zoecloud_backup_delete_failed', __( 'Could not delete the backup file.', 'zoe-cloud' ) );
		}

		return true;
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
			++$stats['count'];
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
				$path_stats      = $this->add_path_to_zip( $zip, $source_path, 'files/' . $item, $excluded_roots );
				$stats['count'] += $path_stats['count'];
				$stats['size']  += $path_stats['size'];
				continue;
			}

			$zip->addFile( $source_path, 'files/' . $item );
			++$stats['count'];
			$stats['size'] += filesize( $source_path );
		}

		return $stats;
	}

	/**
	 * Write a source tree to the staged file list.
	 *
	 * @param resource $handle         File list handle.
	 * @param string   $source_path    Absolute source path.
	 * @param string   $archive_root   Target root inside zip.
	 * @param array    $excluded_roots Excluded paths.
	 * @return array
	 */
	private function write_path_filelist( $handle, $source_path, $archive_root, array $excluded_roots ) {
		$source_path = wp_normalize_path( $source_path );
		$stats       = array(
			'count' => 0,
			'size'  => 0,
		);

		if ( ! is_dir( $source_path ) ) {
			return $stats;
		}

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
			if ( $item->isDir() ) {
				continue;
			}

			$item_path    = wp_normalize_path( $item->getPathname() );
			$relative     = ltrim( substr( $item_path, strlen( $source_path ) ), '/' );
			$archive_path = trim( $archive_root, '/' ) . '/' . $relative;

			$this->write_filelist_entry( $handle, $item_path, $archive_path );
			++$stats['count'];
			$stats['size'] += $item->getSize();
		}

		return $stats;
	}

	/**
	 * Write core files to the staged file list.
	 *
	 * @param resource $handle         File list handle.
	 * @param array    $excluded_roots Excluded paths.
	 * @return array
	 */
	private function write_core_filelist( $handle, array $excluded_roots ) {
		$root_items = scandir( ABSPATH );
		$stats      = array(
			'count' => 0,
			'size'  => 0,
		);

		foreach ( $root_items as $item ) {
			if ( '.' === $item || '..' === $item || 'wp-content' === $item ) {
				continue;
			}

			$source_path = wp_normalize_path( trailingslashit( ABSPATH ) . $item );

			if ( $this->is_excluded_path( $source_path, $excluded_roots ) ) {
				continue;
			}

			if ( is_dir( $source_path ) ) {
				$path_stats      = $this->write_path_filelist( $handle, $source_path, 'files/' . $item, $excluded_roots );
				$stats['count'] += $path_stats['count'];
				$stats['size']  += $path_stats['size'];
				continue;
			}

			if ( is_readable( $source_path ) && ! is_link( $source_path ) ) {
				$this->write_filelist_entry( $handle, $source_path, 'files/' . $item );
				++$stats['count'];
				$stats['size'] += filesize( $source_path );
			}
		}

		return $stats;
	}

	/**
	 * Write one file list entry.
	 *
	 * @param resource $handle       File list handle.
	 * @param string   $path         Absolute file path.
	 * @param string   $archive_path Archive path.
	 * @return void
	 */
	private function write_filelist_entry( $handle, $path, $archive_path ) {
		fwrite(
			$handle,
			wp_json_encode(
				array(
					'path'    => $path,
					'archive' => $archive_path,
				),
				JSON_UNESCAPED_SLASHES
			) . "\n"
		);
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
	 * @param string $backup_id Backup ID.
	 * @param array  $upload    Cloud upload payload.
	 * @return void
	 */
	private function attach_cloud_upload_to_backup( $backup_id, array $upload ) {
		$records = $this->list_backups();

		foreach ( $records as &$record ) {
			if ( isset( $record['id'] ) && $record['id'] === $backup_id ) {
				$record['cloud'] = $upload;
				unset( $record['cloud_error'] );
				break;
			}
		}

		update_option( 'zoecloud_backups', $records, false );
	}

	/**
	 * Attach cloud upload error to a stored backup.
	 *
	 * @param string $backup_id Backup ID.
	 * @param string $message   Error message.
	 * @return void
	 */
	private function attach_cloud_error_to_backup( $backup_id, $message ) {
		$records = $this->list_backups();

		foreach ( $records as &$record ) {
			if ( isset( $record['id'] ) && $record['id'] === $backup_id ) {
				$record['cloud_error'] = $message;
				break;
			}
		}

		update_option( 'zoecloud_backups', $records, false );
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
