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
	}

	/**
	 * Return plugin status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return rest_ensure_response(
			array(
				'cloud'     => $this->cloud_service->get_status(),
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
			return new WP_Error( 'zoecloud_job_missing', __( 'Job not found.', 'zoe-cloud' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $job );
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
	 * Shared capability check.
	 *
	 * @return bool
	 */
	public function permissions() {
		return current_user_can( 'manage_options' );
	}
}
