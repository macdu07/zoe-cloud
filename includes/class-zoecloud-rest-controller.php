<?php
/**
 * REST endpoints.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Drive service.
	 *
	 * @var ZoeCloud_Drive_Service
	 */
	private $drive_service;

	/**
	 * Constructor.
	 *
	 * @param ZoeCloud_Backup_Manager  $backup_manager  Backup manager.
	 * @param ZoeCloud_Restore_Manager $restore_manager Restore manager.
	 * @param ZoeCloud_Drive_Service   $drive_service   Drive service.
	 */
	public function __construct( ZoeCloud_Backup_Manager $backup_manager, ZoeCloud_Restore_Manager $restore_manager, ZoeCloud_Drive_Service $drive_service ) {
		$this->backup_manager  = $backup_manager;
		$this->restore_manager = $restore_manager;
		$this->drive_service   = $drive_service;
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
			'/backups/(?P<id>[^/]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_backup' ),
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
	}

	/**
	 * Return plugin status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return rest_ensure_response(
			array(
				'drive'     => $this->drive_service->get_status(),
				'preflight' => $this->backup_manager->get_preflight_status(),
				'backups'   => $this->backup_manager->list_backups(),
				'jobs'      => array_values( $this->backup_manager->list_jobs() ),
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
				'upload_drive' => (bool) $request->get_param( 'upload_drive' ),
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
		$result = $this->backup_manager->delete_backup( (string) $request['id'] );

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
		$job = $this->backup_manager->get_job( (string) $request['id'] );

		if ( empty( $job ) ) {
			return new WP_Error( 'zoecloud_job_missing', __( 'Job not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $job );
	}

	/**
	 * Run a restore flow from an existing backup filename.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_backup( WP_REST_Request $request ) {
		$filename = (string) $request->get_param( 'filename' );
		$path     = $this->backup_manager->get_backup_path( $filename );
		$result   = $this->restore_manager->restore_backup(
			$path,
			(string) $request->get_param( 'search' ),
			(string) $request->get_param( 'replace' ),
			(bool) $request->get_param( 'confirm' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'restored' => true,
			)
		);
	}

	/**
	 * Validate a backup before restore.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function validate_restore( WP_REST_Request $request ) {
		$filename = (string) $request->get_param( 'filename' );
		$path     = $this->backup_manager->get_backup_path( $filename );
		$result   = $this->restore_manager->get_restore_plan( $path );

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
		$filename = (string) $request->get_param( 'filename' );

		if ( '' === $filename ) {
			$filename = (string) $request->get_param( 'zoecloud_download' );
		}

		$path     = $this->backup_manager->get_backup_path( $filename );

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
	 * Shared capability check.
	 *
	 * @return bool
	 */
	public function permissions() {
		return current_user_can( 'manage_options' );
	}
}
