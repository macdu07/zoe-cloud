<?php
/**
 * REST endpoints.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles ZoeCloud REST endpoints.
 */
class ZoeCloud_REST_Controller {
	/**
	 * Backup manager.
	 *
	 * @var ZoeCloud_Backup_Manager
	 */
	private $backup_manager;

	/**
	 * Restore manager.
	 *
	 * @var ZoeCloud_Restore_Manager
	 */
	private $restore_manager;

	/**
	 * Cloud service.
	 *
	 * @var ZoeCloud_R2_Service
	 */
	private $cloud_service;

	/**
	 * Constructor.
	 *
	 * @param ZoeCloud_Backup_Manager  $backup_manager  Backup manager.
	 * @param ZoeCloud_Restore_Manager $restore_manager Restore manager.
	 * @param ZoeCloud_R2_Service      $cloud_service   Cloud service.
	 */
	public function __construct( ZoeCloud_Backup_Manager $backup_manager, ZoeCloud_Restore_Manager $restore_manager, ZoeCloud_R2_Service $cloud_service ) {
		$this->backup_manager  = $backup_manager;
		$this->restore_manager = $restore_manager;
		$this->cloud_service   = $cloud_service;
	}

	/**
	 * Resolve the absolute path for a temp upload key.
	 *
	 * @param string $key Temp key.
	 * @return string|WP_Error
	 */
	private function resolve_temp_path( $key ) {
		if ( ! preg_match( '/^[a-zA-Z0-9]{32}$/', $key ) ) {
			return new WP_Error( 'zoecloud_invalid_temp_key', __( 'Invalid upload key.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$path = $this->get_temp_upload_dir() . '/zoecloud-upload-' . $key . '.zip';

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'zoecloud_temp_not_found', __( 'Uploaded file not found. Please upload again.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}

		return $path;
	}

	/**
	 * Return (and protect) the temp uploads directory.
	 *
	 * @return string
	 */
	private function get_temp_upload_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'zoecloud-uploads';

		wp_mkdir_p( $dir );

		$htaccess = $dir . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, 'deny from all' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'zoecloud/v1',
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/backups',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_backups' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_backup' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/backups/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_backup_direct' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/backups/(?P<id>(?!bulk-delete$)[^/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_backup' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_backup' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/backups/bulk-delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_delete_backups' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			'zoecloud/v1',
			'/storage/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_storage' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			'zoecloud/v1',
			'/activity',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_activity' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			'zoecloud/v1',
			'/activity/(?P<id>[^/]+)/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_activity' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			'zoecloud/v1',
			'/restores',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_restore_job' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/jobs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_jobs' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/jobs/(?P<id>[^/]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/jobs/(?P<id>[^/]+)/tick',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_job_tick' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/restore',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'validate_restore' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore_backup' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/backup-file',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_backup' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/restore/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_restore_file' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/restore/upload/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_uploaded_backup' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
	}

	/**
	 * Return plugin status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		$settings       = wp_parse_args(
			get_option( 'zoecloud_settings', array() ),
			array(
				'schedule_enabled' => 0,
				'schedule'         => 'daily',
				'schedule_time'    => '02:00',
			)
		);
		$next_scheduled = wp_next_scheduled( 'zoecloud_run_scheduled_backup' );
		$preflight      = $this->backup_manager->get_preflight_status();
		return rest_ensure_response(
			array(
				'cloud'     => $this->cloud_service->get_status(),
				'preflight' => $preflight,
				'health'    => array(
					'ready'          => ! empty( $preflight['ready'] ),
					'cron_available' => empty( $preflight['wp_cron_disabled'] ),
				),
				'summary'   => $this->backup_manager->get_summary(),
				'schedule'  => array(
					'enabled'   => ! empty( $settings['schedule_enabled'] ),
					'frequency' => $settings['schedule'],
					'time'      => $settings['schedule_time'],
					'timezone'  => wp_timezone_string(),
					'next_run'  => $next_scheduled ? gmdate( 'c', $next_scheduled ) : null,
				),
				'backups'   => $this->backup_manager->list_backups(),
				'jobs'      => $this->get_all_activity(),
			)
		);
	}

	/**
	 * Return backup list.
	 *
	 * @return WP_REST_Response
	 */
	public function list_backups() {
		return rest_ensure_response( $this->backup_manager->list_backups() );
	}

	/**
	 * Create a new backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_backup( WP_REST_Request $request ) {
		$result = $this->backup_manager->enqueue_backup(
			array(
				'include_core' => (bool) $request->get_param( 'include_core' ),
				'upload_cloud' => (bool) ( $request->get_param( 'upload_cloud' ) ?? $request->get_param( 'upload_drive' ) ),
				'source'       => 'manual',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 202 );
	}

	/**
	 * Delete a backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_backup( WP_REST_Request $request ) {
		$result = $this->backup_manager->delete_backup( sanitize_text_field( (string) $request['id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
			)
		);
	}

	/**
	 * Update lock metadata for one backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_backup( WP_REST_Request $request ) {
		$result = $this->backup_manager->update_backup( sanitize_text_field( (string) $request['id'] ), (bool) $request->get_param( 'locked' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Delete a selection of backups.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function bulk_delete_backups( WP_REST_Request $request ) {
		return rest_ensure_response( $this->backup_manager->bulk_delete_backups( (array) $request->get_param( 'ids' ) ) );
	}

	/** Test the configured cloud destination. */
	public function test_storage() {
		$result = $this->cloud_service->test_connection();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * List jobs.
	 *
	 * @return WP_REST_Response
	 */
	public function list_jobs() {
		return rest_ensure_response( array_values( $this->backup_manager->list_jobs() ) );
	}

	/**
	 * Get a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		$job = $this->backup_manager->get_job( sanitize_key( (string) $request['id'] ) );
		if ( empty( $job ) ) {
			$restore_jobs = $this->get_restore_jobs();
			$job          = $restore_jobs[ sanitize_key( (string) $request['id'] ) ] ?? null;
		}

		if ( empty( $job ) ) {
			return new WP_Error( 'zoecloud_job_missing', __( 'Job not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $job );
	}

	/** Return backup and restore activity ordered newest first. */
	public function list_activity() {
		return rest_ensure_response( $this->get_all_activity() );
	}

	/**
	 * Download a plain-text activity log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void|WP_Error
	 */
	public function download_activity( WP_REST_Request $request ) {
		$id  = sanitize_key( (string) $request['id'] );
		$job = current(
			array_filter(
				$this->get_all_activity(),
				static function ( $item ) use ( $id ) {
					return ( $item['id'] ?? '' ) === $id;
				}
			)
		);
		if ( ! $job ) {
			return new WP_Error( 'zoecloud_job_missing', __( 'Job not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}
		$lines = array( 'ZoeCloud ' . ( $job['type'] ?? 'job' ) . ' ' . $id );
		foreach ( (array) ( $job['events'] ?? array() ) as $event ) {
			$lines[] = sprintf( '[%1$s] %2$s/%3$s: %4$s', $event['time'] ?? '', $event['stage'] ?? '', $event['status'] ?? '', $event['message'] ?? '' );
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="zoecloud-' . $id . '.log"' );
		echo esc_html( implode( "\n", $lines ) );
		exit;
	}

	/**
	 * Queue a protected asynchronous restore.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_restore_job( WP_REST_Request $request ) {
		$hostname = sanitize_text_field( (string) $request->get_param( 'hostname' ) );
		$current  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! hash_equals( strtolower( $current ), strtolower( $hostname ) ) ) {
			return new WP_Error( 'zoecloud_restore_hostname_mismatch', __( 'Type the current site hostname to confirm the restore.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
		$path     = $this->backup_manager->get_backup_path( $filename );
		$plan     = $this->restore_manager->get_restore_plan( $path );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		foreach ( $this->backup_manager->list_backups() as $backup ) {
			if ( ( $backup['filename'] ?? '' ) !== $filename || empty( $backup['checksum'] ) ) {
				continue;
			}
			if ( ! file_exists( $path ) || ! hash_equals( $backup['checksum'], hash_file( 'sha256', $path ) ) ) {
				return new WP_Error( 'zoecloud_restore_checksum_mismatch', __( 'Backup integrity verification failed. Restore was stopped.', 'zoe-cloud' ), array( 'status' => 409 ) );
			}
			break;
		}

		$id          = wp_generate_uuid4();
		$now         = current_time( 'mysql', true );
		$job         = array(
			'id'         => $id,
			'type'       => 'restore',
			'status'     => 'queued',
			'stage'      => 'preflight',
			'progress'   => 0,
			'message'    => __( 'Restore queued.', 'zoe-cloud' ),
			'created_at' => $now,
			'updated_at' => $now,
			'args'       => array(
				'filename'      => $filename,
				'search'        => esc_url_raw( (string) $request->get_param( 'search' ) ),
				'replace'       => esc_url_raw( (string) $request->get_param( 'replace' ) ),
				'safety_backup' => false !== $request->get_param( 'safety_backup' ),
			),
			'events'     => array(
				array(
					'time'    => $now,
					'stage'   => 'preflight',
					'status'  => 'queued',
					'message' => __( 'Restore queued.', 'zoe-cloud' ),
				),
			),
		);
		$jobs        = $this->get_restore_jobs();
		$jobs[ $id ] = $job;
		$this->save_restore_jobs( $jobs );
		wp_schedule_single_event( time() + 1, 'zoecloud_run_restore_job', array( $id ) );
		return new WP_REST_Response( $job, 202 );
	}

	/**
	 * Process the safety-backup and restore orchestration.
	 *
	 * @param string $job_id Restore job UUID.
	 * @return void
	 * @throws RuntimeException When a safety requirement or restore step fails.
	 */
	public function run_restore_job( $job_id ) {
		$jobs = $this->get_restore_jobs();
		if ( empty( $jobs[ $job_id ] ) || in_array( $jobs[ $job_id ]['status'], array( 'completed', 'failed' ), true ) ) {
			return;
		}
		$job = $jobs[ $job_id ];
		try {
			if ( 'preflight' === $job['stage'] ) {
				$path = $this->backup_manager->get_backup_path( $job['args']['filename'] );
				$free = function_exists( 'disk_free_space' ) ? disk_free_space( dirname( $path ) ) : false;
				if ( false !== $free && $free < ( filesize( $path ) * 2 ) ) {
					throw new RuntimeException( __( 'Not enough free disk space to restore safely.', 'zoe-cloud' ) );
				}
				if ( ! empty( $job['args']['safety_backup'] ) ) {
					$safety = $this->backup_manager->enqueue_backup(
						array(
							'include_core' => true,
							'upload_cloud' => false,
							'source'       => 'safety',
						)
					);
					if ( is_wp_error( $safety ) ) {
						throw new RuntimeException( $safety->get_error_message() ); }
					$job['safety_job_id'] = $safety['id'];
					$this->advance_restore_job( $job, 'waiting_safety', 10, __( 'Creating a safety backup before restore.', 'zoe-cloud' ) );
				} else {
					$this->advance_restore_job( $job, 'restoring', 35, __( 'Restore preflight passed.', 'zoe-cloud' ) );
				}
			} elseif ( 'waiting_safety' === $job['stage'] ) {
				$safety = $this->backup_manager->get_job( $job['safety_job_id'] ?? '' );
				if ( ! $safety || 'failed' === $safety['status'] ) {
					throw new RuntimeException( __( 'The safety backup failed; restore was stopped.', 'zoe-cloud' ) ); }
				if ( 'completed' !== $safety['status'] ) {
					wp_schedule_single_event( time() + 5, 'zoecloud_run_restore_job', array( $job_id ) );
					return;
				}
				$this->advance_restore_job( $job, 'restoring', 35, __( 'Safety backup completed. Restoring site.', 'zoe-cloud' ) );
			} else {
				$result = $this->restore_manager->restore_backup( $this->backup_manager->get_backup_path( $job['args']['filename'] ), $job['args']['search'], $job['args']['replace'], true );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() ); }
				$this->advance_restore_job( $job, 'completed', 100, __( 'Restore completed.', 'zoe-cloud' ), 'completed' );
				return;
			}
			wp_schedule_single_event( time() + 1, 'zoecloud_run_restore_job', array( $job_id ) );
		} catch ( Throwable $error ) {
			$this->advance_restore_job( $job, 'failed', 100, sanitize_text_field( $error->getMessage() ), 'failed' );
		}
	}

	/** Return the bounded restore job registry. */
	private function get_restore_jobs() {
		$jobs = get_option( 'zoecloud_restore_jobs', array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * Persist recent restore jobs.
	 *
	 * @param array $jobs Restore jobs.
	 * @return void
	 */
	private function save_restore_jobs( array $jobs ) {
		uasort(
			$jobs,
			static function ( $left, $right ) {
				return strcmp( $right['created_at'] ?? '', $left['created_at'] ?? '' );
			}
		);
		update_option( 'zoecloud_restore_jobs', array_slice( $jobs, 0, 50, true ), false );
	}

	/**
	 * Advance and log a restore job.
	 *
	 * @param array  $job Job record.
	 * @param string $stage Stage key.
	 * @param int    $progress Progress percentage.
	 * @param string $message User-facing status.
	 * @param string $status Job status.
	 * @return void
	 */
	private function advance_restore_job( array $job, $stage, $progress, $message, $status = 'running' ) {
		$jobs               = $this->get_restore_jobs();
		$job['stage']       = sanitize_key( $stage );
		$job['status']      = sanitize_key( $status );
		$job['progress']    = absint( $progress );
		$job['message']     = $message;
		$job['updated_at']  = current_time( 'mysql', true );
		$job['events'][]    = array(
			'time'    => $job['updated_at'],
			'stage'   => $job['stage'],
			'status'  => $job['status'],
			'message' => sanitize_text_field( $message ),
		);
		$jobs[ $job['id'] ] = $job;
		$this->save_restore_jobs( $jobs );
	}

	/** Return merged backup and restore activity. */
	private function get_all_activity() {
		$jobs = array_merge( array_values( $this->backup_manager->list_jobs() ), array_values( $this->get_restore_jobs() ) );
		usort(
			$jobs,
			static function ( $left, $right ) {
				return strcmp( $right['created_at'] ?? '', $left['created_at'] ?? '' );
			}
		);
		return $jobs;
	}

	/**
	 * Run one job step and return the updated job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_job_tick( WP_REST_Request $request ) {
		$job_id = sanitize_key( (string) $request['id'] );
		$this->backup_manager->run_backup_job( $job_id );
		$job = $this->backup_manager->get_job( $job_id );

		if ( empty( $job ) ) {
			return new WP_Error( 'zoecloud_job_missing', __( 'Job not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $job );
	}

	/**
	 * Run a restore flow from an existing backup filename or an uploaded temp file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_backup( WP_REST_Request $request ) {
		$temp_key = (string) $request->get_param( 'temp_key' );

		if ( $temp_key ) {
			$path = $this->resolve_temp_path( $temp_key );

			if ( is_wp_error( $path ) ) {
				return $path;
			}
		} else {
			$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
			$path     = $this->backup_manager->get_backup_path( $filename );
		}

		$result = $this->restore_manager->restore_backup(
			$path,
			(string) $request->get_param( 'search' ),
			(string) $request->get_param( 'replace' ),
			(bool) $request->get_param( 'confirm' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $temp_key && file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		return rest_ensure_response(
			array(
				'restored' => true,
			)
		);
	}

	/**
	 * Validate a backup before restore (supports existing filename or temp_key).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_restore( WP_REST_Request $request ) {
		$temp_key = (string) $request->get_param( 'temp_key' );

		if ( $temp_key ) {
			$path = $this->resolve_temp_path( $temp_key );

			if ( is_wp_error( $path ) ) {
				return $path;
			}
		} else {
			$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );
			$path     = $this->backup_manager->get_backup_path( $filename );
		}

		$result = $this->restore_manager->get_restore_plan( $path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Accept an uploaded ZIP file for restore.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_restore_file() {
		// REST nonce verification is handled by WordPress through the X-WP-Nonce header.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['zip_file'] ) || ! is_array( $_FILES['zip_file'] ) ) {
			return new WP_Error( 'zoecloud_upload_missing', __( 'No file uploaded.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$file = $_FILES['zip_file'];
		// phpcs:enable

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'zoecloud_upload_error', __( 'File upload failed.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'zoecloud_upload_invalid', __( 'Uploaded file is invalid.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		if ( (int) $file['size'] > wp_max_upload_size() ) {
			return new WP_Error( 'zoecloud_upload_too_large', __( 'Uploaded file exceeds the maximum upload size.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$original_name = strtolower( sanitize_file_name( (string) ( $file['name'] ?? '' ) ) );

		if ( '.zip' !== substr( $original_name, -4 ) ) {
			return new WP_Error( 'zoecloud_upload_invalid_type', __( 'Only ZIP archives can be uploaded.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$filetype = wp_check_filetype_and_ext(
			(string) $file['tmp_name'],
			$original_name,
			array(
				'zip' => 'application/zip',
			)
		);

		if ( 'zip' !== ( $filetype['ext'] ?? '' ) ) {
			return new WP_Error( 'zoecloud_upload_invalid_type', __( 'Only valid ZIP archives can be uploaded.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$temp_dir = $this->get_temp_upload_dir();
		$key      = wp_generate_password( 32, false, false );
		$dest     = $temp_dir . '/zoecloud-upload-' . $key . '.zip';

		if ( ! move_uploaded_file( (string) $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'zoecloud_upload_move_failed', __( 'Could not save the uploaded file.', 'zoe-cloud' ), array( 'status' => 500 ) );
		}

		$validated = $this->restore_manager->validate_backup( $dest );

		if ( is_wp_error( $validated ) ) {
			wp_delete_file( $dest );
			return $validated;
		}

		return rest_ensure_response(
			array(
				'temp_key' => $key,
				'size'     => filesize( $dest ),
			)
		);
	}

	/**
	 * Import an uploaded temp ZIP as a registered backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_uploaded_backup( WP_REST_Request $request ) {
		$temp_key = (string) $request->get_param( 'temp_key' );
		$path     = $this->resolve_temp_path( $temp_key );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// Extract the manifest from the ZIP so we can pass it to the record.
		$manifest = array();
		$zip      = new ZipArchive();

		if ( true === $zip->open( $path ) ) {
			$raw = $zip->getFromName( 'manifest.json' );
			$zip->close();

			if ( $raw ) {
				$decoded = json_decode( $raw, true );

				if ( is_array( $decoded ) ) {
					$manifest = $decoded;
				}
			}
		}

		$result = $this->backup_manager->import_uploaded_backup( $path, $manifest );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Stream a backup zip.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void|WP_Error
	 */
	public function download_backup( WP_REST_Request $request ) {
		$filename = sanitize_file_name( (string) $request->get_param( 'filename' ) );

		if ( '' === $filename ) {
			$filename = sanitize_file_name( (string) $request->get_param( 'zoecloud_download' ) );
		}

		$path = $this->backup_manager->get_backup_path( $filename );

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'zoecloud_backup_missing', __( 'Backup file not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Accept a ZIP upload and register it directly as a backup record.
	 *
	 * This is the single-step alternative to the two-step restore/upload +
	 * restore/upload/import flow. The file is validated, moved to the backups
	 * storage directory and stored in the backup registry in one request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_backup_direct() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
		if ( empty( $_FILES['zip_file'] ) || ! is_array( $_FILES['zip_file'] ) ) {
			return new WP_Error( 'zoecloud_upload_missing', __( 'No file uploaded.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$file = $_FILES['zip_file'];
		// phpcs:enable

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'zoecloud_upload_error', __( 'File upload failed.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'zoecloud_upload_invalid', __( 'Uploaded file is invalid.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		if ( (int) $file['size'] > wp_max_upload_size() ) {
			return new WP_Error( 'zoecloud_upload_too_large', __( 'Uploaded file exceeds the maximum upload size.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$original_name = strtolower( sanitize_file_name( (string) ( $file['name'] ?? '' ) ) );

		if ( '.zip' !== substr( $original_name, -4 ) ) {
			return new WP_Error( 'zoecloud_upload_invalid_type', __( 'Only ZIP archives can be uploaded.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		$filetype = wp_check_filetype_and_ext(
			(string) $file['tmp_name'],
			$original_name,
			array(
				'zip' => 'application/zip',
			)
		);

		if ( 'zip' !== ( $filetype['ext'] ?? '' ) ) {
			return new WP_Error( 'zoecloud_upload_invalid_type', __( 'Only valid ZIP archives can be uploaded.', 'zoe-cloud' ), array( 'status' => 400 ) );
		}

		// Write the upload to a temp location so we can validate the ZIP structure.
		$temp_dir  = $this->get_temp_upload_dir();
		$temp_key  = wp_generate_password( 32, false, false );
		$temp_path = $temp_dir . '/zoecloud-upload-' . $temp_key . '.zip';

		if ( ! move_uploaded_file( (string) $file['tmp_name'], $temp_path ) ) {
			return new WP_Error( 'zoecloud_upload_move_failed', __( 'Could not save the uploaded file.', 'zoe-cloud' ), array( 'status' => 500 ) );
		}

		// Validate the ZIP is a well-formed ZoeCloud backup.
		$validated = $this->restore_manager->validate_backup( $temp_path );

		if ( is_wp_error( $validated ) ) {
			wp_delete_file( $temp_path );
			return $validated;
		}

		// Extract the manifest so metadata is stored with the backup record.
		$manifest = is_array( $validated['manifest'] ?? null ) ? $validated['manifest'] : array();

		// Import: move to backups storage dir and register the record.
		$result = $this->backup_manager->import_uploaded_backup( $temp_path, $manifest );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $temp_path );
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Shared capability check.
	 *
	 * @return bool
	 */
	public function permissions() {
		return current_user_can( 'manage_options' );
	}
}
