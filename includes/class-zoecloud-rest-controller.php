<?php
/**
 * REST endpoints.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Authenticated backup downloads must be streamed without loading archives into memory.
// phpcs:disable Generic.PHP.ForbiddenFunctions.Found -- Uploads are validated with is_uploaded_file() and moved into opaque private storage before archive validation.

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
	 * Durable job repository.
	 *
	 * @var ZoeCloud_Job_Repository
	 */
	private $jobs;

	/**
	 * Backup metadata repository.
	 *
	 * @var ZoeCloud_Backup_Repository
	 */
	private $backups;

	/**
	 * Private storage service.
	 *
	 * @var ZoeCloud_Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param ZoeCloud_Backup_Manager    $backup_manager  Backup manager.
	 * @param ZoeCloud_Restore_Manager   $restore_manager Restore manager.
	 * @param ZoeCloud_R2_Service        $cloud_service   Cloud service.
	 * @param ZoeCloud_Job_Repository    $jobs            Job repository.
	 * @param ZoeCloud_Backup_Repository $backups       Backup repository.
	 * @param ZoeCloud_Storage           $storage         Private storage.
	 */
	public function __construct( ZoeCloud_Backup_Manager $backup_manager, ZoeCloud_Restore_Manager $restore_manager, ZoeCloud_R2_Service $cloud_service, $jobs = null, $backups = null, $storage = null ) {
		$this->backup_manager  = $backup_manager;
		$this->restore_manager = $restore_manager;
		$this->cloud_service   = $cloud_service;
		$this->jobs            = $jobs instanceof ZoeCloud_Job_Repository ? $jobs : new ZoeCloud_Job_Repository();
		$this->backups         = $backups instanceof ZoeCloud_Backup_Repository ? $backups : new ZoeCloud_Backup_Repository();
		$this->storage         = $storage instanceof ZoeCloud_Storage ? $storage : new ZoeCloud_Storage();
	}

	/**
	 * Return (and protect) the temp uploads directory.
	 *
	 * @return string
	 */
	private function get_temp_upload_dir() {
		return $this->storage->get_subdirectory( 'uploads' );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'zoecloud/v1',
			'/health',
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
					'args'                => array(
						'include_core' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'upload_cloud' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/backups/(?P<id>[0-9a-fA-F-]{36})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download_backup' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_backup' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_backup' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'locked' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			'zoecloud/v1',
			'/backups/(?P<id>[0-9a-fA-F-]{36})/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verify_backup' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);

		register_rest_route(
			'zoecloud/v1',
			'/backups/bulk-delete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_delete_backups' ),
				'permission_callback' => array( $this, 'permissions' ),
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
					),
				),
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
			'/activity/(?P<id>[0-9a-fA-F-]{36})/download',
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
				'args'                => array(
					'backup_id' => array(
						'type'     => 'string',
						'format'   => 'uuid',
						'required' => true,
					),
					'hostname'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'search'    => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'replace'   => array(
						'type'   => 'string',
						'format' => 'uri',
					),
				),
			)
		);
		register_rest_route(
			'zoecloud/v1',
			'/restores/(?P<id>[0-9a-fA-F-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			'zoecloud/v1',
			'/cloud/backups',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_cloud_backups' ),
				'permission_callback' => array( $this, 'permissions' ),
			)
		);
		register_rest_route(
			'zoecloud/v1',
			'/cloud/backups/(?P<id>[a-fA-F0-9]{64})/download',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'download_cloud_backup' ),
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
			'/jobs/(?P<id>[0-9a-fA-F-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
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
		$job_runner     = wp_next_scheduled( 'zoecloud_run_jobs' );
		$preflight      = $this->backup_manager->get_preflight_status();
		return rest_ensure_response(
			array(
				'cloud'     => $this->cloud_service->get_status(),
				'preflight' => $preflight,
				'health'    => array(
					'ready'          => ! empty( $preflight['ready'] ) && (bool) $job_runner && ( empty( $settings['schedule_enabled'] ) || (bool) $next_scheduled ),
					'cron_available' => empty( $preflight['wp_cron_disabled'] ),
					'job_runner'     => (bool) $job_runner,
					'schedule_ok'    => empty( $settings['schedule_enabled'] ) || (bool) $next_scheduled,
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
	 * Verify one local backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verify_backup( WP_REST_Request $request ) {
		$result = $this->backup_manager->verify_backup( sanitize_text_field( (string) $request['id'] ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * List remote backups from the explicitly configured provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_cloud_backups( WP_REST_Request $request ) {
		$result = $this->cloud_service->list_backups( sanitize_text_field( (string) $request->get_param( 'continuation_token' ) ) );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Queue an authenticated remote download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function download_cloud_backup( WP_REST_Request $request ) {
		$remote_id = sanitize_text_field( (string) $request['id'] );
		$token     = '';
		$remote    = null;
		do {
			$page = $this->cloud_service->list_backups( $token );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			foreach ( $page['objects'] as $object ) {
				if ( hash_equals( $object['id'], $remote_id ) ) {
					$remote = $object;
					break 2;
				}
			}
			$token = (string) $page['next_token'];
		} while ( ! empty( $page['is_truncated'] ) && '' !== $token );

		if ( ! $remote ) {
			return new WP_Error( 'zoecloud_cloud_backup_missing', __( 'Cloud backup not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}
		$job = $this->jobs->create( 'cloud_download', array( 'cloud' => $remote ), 'download' );
		wp_schedule_single_event( time() + 1, 'zoecloud_run_cloud_download_job', array( $job['id'] ) );

		return new WP_REST_Response( $job, 202 );
	}

	/**
	 * Create a new backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_backup( WP_REST_Request $request ) {
		if ( ! empty( $_FILES['zip_file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $this->upload_backup_direct();
		}
		$result = $this->backup_manager->enqueue_backup(
			array(
				'include_core' => (bool) $request->get_param( 'include_core' ),
				'upload_cloud' => (bool) $request->get_param( 'upload_cloud' ),
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
		$job = $this->jobs->find( sanitize_text_field( (string) $request['id'] ) );

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

		$backup_id = sanitize_text_field( (string) $request->get_param( 'backup_id' ) );
		$record    = $this->backups->find( $backup_id );
		if ( ! $record || 'verified' !== ( $record['verification_status'] ?? '' ) ) {
			return new WP_Error( 'zoecloud_restore_backup_unverified', __( 'Choose a verified local backup before restoring.', 'zoe-cloud' ), array( 'status' => 409 ) );
		}
		$path = $this->backup_manager->get_backup_path( $backup_id );
		$plan = $this->restore_manager->get_restore_plan( $path );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( ! file_exists( $path ) || empty( $record['checksum'] ) || ! hash_equals( $record['checksum'], hash_file( 'sha256', $path ) ) ) {
			return new WP_Error( 'zoecloud_restore_checksum_mismatch', __( 'Backup integrity verification failed. Restore was stopped.', 'zoe-cloud' ), array( 'status' => 409 ) );
		}

		$job = $this->jobs->create(
			'restore',
			array(
				'backup_id' => $backup_id,
				'search'    => esc_url_raw( (string) $request->get_param( 'search' ) ),
				'replace'   => esc_url_raw( (string) $request->get_param( 'replace' ) ),
			),
			'preflight',
			3
		);
		wp_schedule_single_event( time() + 1, 'zoecloud_run_restore_job', array( $job['id'] ) );
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
		$token = $this->jobs->acquire( $job_id, 600 );
		if ( ! $token ) {
			return;
		}
		$job = $this->jobs->find( $job_id );
		try {
			if ( 'preflight' === $job['stage'] ) {
				$path = $this->backup_manager->get_backup_path( $job['args']['backup_id'] );
				$free = function_exists( 'disk_free_space' ) ? disk_free_space( dirname( $path ) ) : false;
				if ( false !== $free && $free < ( filesize( $path ) * 3 ) ) {
					throw new RuntimeException( __( 'Not enough free disk space to restore safely.', 'zoe-cloud' ) );
				}
				$safety = $this->backup_manager->enqueue_backup(
					array(
						'include_core' => true,
						'upload_cloud' => false,
						'source'       => 'safety',
					)
				);
				if ( is_wp_error( $safety ) ) {
					throw new RuntimeException( $safety->get_error_message() );
				}
				$job['state']['safety_job_id'] = $safety['id'];
				$this->advance_restore_job( $job, 'waiting_safety', 10, __( 'Creating a mandatory safety backup before restore.', 'zoe-cloud' ) );
			} elseif ( 'waiting_safety' === $job['stage'] ) {
				$safety = $this->backup_manager->get_job( $job['state']['safety_job_id'] ?? '' );
				if ( ! $safety || 'failed' === $safety['status'] ) {
					throw new RuntimeException( __( 'The safety backup failed; restore was stopped.', 'zoe-cloud' ) );
				}
				if ( 'completed' !== $safety['status'] ) {
					$this->jobs->release( $job_id, $token, 5 );
					wp_schedule_single_event( time() + 5, 'zoecloud_run_restore_job', array( $job_id ) );
					return;
				}
				$job['state']['safety_backup_id'] = $safety['result']['backup_id'] ?? '';
				$verified                         = $this->backup_manager->verify_backup( $job['state']['safety_backup_id'] );
				if ( is_wp_error( $verified ) ) {
					throw new RuntimeException( __( 'The safety backup could not be verified; restore was stopped.', 'zoe-cloud' ) );
				}
				$this->advance_restore_job( $job, 'restoring', 35, __( 'Safety backup completed. Restoring site.', 'zoe-cloud' ) );
			} elseif ( 'rolling_back' === $job['stage'] ) {
				$rollback = $this->run_restore_rollback( $job );
				if ( is_wp_error( $rollback ) ) {
					throw new RuntimeException( $rollback->get_error_message() );
				}
				throw new RuntimeException( sprintf( 'Restore failed and was rolled back: %s', $job['state']['restore_error'] ?? __( 'Unknown restore error.', 'zoe-cloud' ) ) );
			} else {
				$result = $this->restore_manager->restore_backup( $this->backup_manager->get_backup_path( $job['args']['backup_id'] ), $job['args']['search'], $job['args']['replace'], true );
				if ( is_wp_error( $result ) ) {
					$job['state']['restore_error'] = $result->get_error_message();
					$this->advance_restore_job( $job, 'rolling_back', 80, __( 'Restore failed. Rolling back from the safety backup.', 'zoe-cloud' ), 'rolling_back' );
					$rollback = $this->run_restore_rollback( $job );
					if ( is_wp_error( $rollback ) ) {
						throw new RuntimeException( $rollback->get_error_message() );
					}
					throw new RuntimeException( sprintf( 'Restore failed and was rolled back: %s', $result->get_error_message() ) );
				}
				$this->advance_restore_job( $job, 'completed', 100, __( 'Restore completed.', 'zoe-cloud' ), 'completed' );
				$this->jobs->release( $job_id, $token );
				return;
			}
			$this->jobs->release( $job_id, $token, 1 );
			wp_schedule_single_event( time() + 1, 'zoecloud_run_restore_job', array( $job_id ) );
		} catch ( Throwable $error ) {
			$this->set_maintenance_mode( false );
			$this->advance_restore_job( $job, 'failed', 100, sanitize_text_field( $error->getMessage() ), 'failed' );
			$this->jobs->release( $job_id, $token );
		}
	}

	/**
	 * Restore the mandatory safety archive after a failed destructive stage.
	 *
	 * @param array $job Restore job.
	 * @return true|WP_Error
	 */
	private function run_restore_rollback( array $job ) {
		$rollback_id = $job['state']['safety_backup_id'] ?? '';
		$rollback = $this->restore_manager->restore_backup( $this->backup_manager->get_backup_path( $rollback_id ), '', '', true );
		if ( is_wp_error( $rollback ) ) {
			return new WP_Error(
				'zoecloud_restore_rollback_failed',
				sprintf(
					/* translators: 1: Original restore error. 2: Rollback error. 3: Safety backup UUID. */
					__( 'Restore failed: %1$s Rollback also failed: %2$s Run wp zoecloud restore %3$s.', 'zoe-cloud' ),
					$job['state']['restore_error'] ?? __( 'Unknown restore error.', 'zoe-cloud' ),
					$rollback->get_error_message(),
					$rollback_id
				)
			);
		}

		return true;
	}

	/**
	 * Download, verify, and register a cloud backup in private storage.
	 *
	 * @param string $job_id Cloud download job UUID.
	 * @return void
	 * @throws RuntimeException When a cloud step fails.
	 */
	public function run_cloud_download_job( $job_id ) {
		$token = $this->jobs->acquire( $job_id, 600 );
		if ( ! $token ) {
			return;
		}
		$job = $this->jobs->find( $job_id );
		try {
			$key         = $this->storage->create_archive_key();
			$destination = $this->storage->resolve( $key, 'uploads' );
			$result      = $this->cloud_service->download_backup( (array) $job['args']['cloud'], $destination );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
			$validated = $this->restore_manager->validate_backup( $destination );
			if ( is_wp_error( $validated ) ) {
				wp_delete_file( $destination );
				throw new RuntimeException( $validated->get_error_message() );
			}
			$record = $this->backup_manager->import_uploaded_backup( $destination, $validated['manifest'] );
			if ( is_wp_error( $record ) ) {
				throw new RuntimeException( $record->get_error_message() );
			}
			$record['cloud']        = (array) $job['args']['cloud'];
			$record['cloud_status'] = 'available';
			$this->backups->save( $record );
			$this->jobs->update(
				$job_id,
				array(
					'status'   => 'completed',
					'stage'    => 'completed',
					'progress' => 100,
					'result'   => array( 'backup_id' => $record['id'] ),
				)
			);
			$this->jobs->event( $job_id, 'completed', 'completed', __( 'Cloud backup downloaded and verified.', 'zoe-cloud' ) );
		} catch ( Throwable $error ) {
			$this->jobs->update(
				$job_id,
				array(
					'status'     => 'failed',
					'stage'      => 'failed',
					'progress'   => 100,
					'last_error' => sanitize_text_field( $error->getMessage() ),
				)
			);
			$this->jobs->event( $job_id, 'failed', 'failed', $error->getMessage() );
		}
		$this->jobs->release( $job_id, $token );
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
		$this->jobs->update(
			$job['id'],
			array(
				'stage'      => $stage,
				'status'     => $status,
				'progress'   => $progress,
				'state'      => $job['state'] ?? array(),
				'last_error' => 'failed' === $status ? sanitize_text_field( $message ) : null,
			)
		);
		$this->jobs->event( $job['id'], $stage, $status, $message );
	}

	/** Return merged backup and restore activity. */
	private function get_all_activity() {
		return $this->jobs->all();
	}

	/**
	 * Toggle WordPress maintenance mode during the destructive swap.
	 *
	 * @param bool $enabled Whether maintenance mode is enabled.
	 * @return void
	 */
	private function set_maintenance_mode( $enabled ) {
		$file = trailingslashit( ABSPATH ) . '.maintenance';
		if ( $enabled ) {
			file_put_contents( $file, '<?php $upgrading = ' . time() . ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		} elseif ( file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Stream a backup zip.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void|WP_Error
	 */
	public function download_backup( WP_REST_Request $request ) {
		$backup_id = sanitize_text_field( (string) $request['id'] );
		$record    = $this->backups->find( $backup_id );
		$path      = $this->backup_manager->get_backup_path( $backup_id );

		if ( ! $record || ! is_file( $path ) ) {
			return new WP_Error( 'zoecloud_backup_missing', __( 'Backup file not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}
		$download_name = sanitize_file_name( $record['filename'] ?? ( 'zoecloud-' . $backup_id . '.zip' ) );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Accept a ZIP upload and register it directly as a backup record.
	 *
	 * The file is validated, moved to private storage, and registered in one
	 * authenticated request.
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

		if ( ! move_uploaded_file( (string) $file['tmp_name'], $temp_path ) ) { // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- Verified HTTP upload is moved into opaque private storage.
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
