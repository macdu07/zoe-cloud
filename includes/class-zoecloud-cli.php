<?php
/**
 * WP-CLI commands.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Manage ZoeCloud from cron or a terminal. */
class ZoeCloud_CLI {
	/**
	 * Backup manager.
	 *
	 * @var ZoeCloud_Backup_Manager
	 */
	private $backups;

	/**
	 * Job repository.
	 *
	 * @var ZoeCloud_Job_Repository
	 */
	private $jobs;

	/**
	 * Restore job controller.
	 *
	 * @var ZoeCloud_REST_Controller
	 */
	private $controller;

	/**
	 * Plugin runtime.
	 *
	 * @var ZoeCloud_Plugin
	 */
	private $plugin;

	/**
	 * Create CLI commands.
	 *
	 * @param ZoeCloud_Backup_Manager  $backups    Backup manager.
	 * @param ZoeCloud_Job_Repository  $jobs       Job repository.
	 * @param ZoeCloud_REST_Controller $controller Restore controller.
	 * @param ZoeCloud_Plugin          $plugin     Plugin runtime.
	 */
	public function __construct( $backups, $jobs, $controller, $plugin ) {
		$this->backups    = $backups;
		$this->jobs       = $jobs;
		$this->controller = $controller;
		$this->plugin     = $plugin;
	}

	/**
	 * Create and wait for a backup.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function backup_create( $args, $assoc_args ) {
		$job = $this->backups->enqueue_backup(
			array(
				'include_core' => ! empty( $assoc_args['full'] ),
				'upload_cloud' => ! empty( $assoc_args['cloud'] ),
				'source'       => 'manual',
			)
		);
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}
		$this->wait_for_job( $job['id'] );
	}

	/** List registered backups. */
	public function backup_list() {
		$rows = array_map(
			static function ( $backup ) {
				return array(
					'id'       => $backup['id'],
					'created'  => $backup['created_at'],
					'size'     => $backup['size'],
					'scope'    => $backup['scope'],
					'local'    => $backup['local_status'],
					'cloud'    => $backup['cloud_status'],
					'verified' => $backup['verification_status'],
				);
			},
			$this->backups->list_backups()
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'created', 'size', 'scope', 'local', 'cloud', 'verified' ) );
	}

	/**
	 * Verify a local backup archive.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function backup_verify( $args ) {
		$result = $this->backups->verify_backup( $args[0] ?? '' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( 'Backup verified: ' . $result['id'] );
	}

	/**
	 * Restore a verified backup after an explicit CLI confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$id       = $args[0] ?? '';
		$hostname = sanitize_text_field( (string) ( $assoc_args['hostname'] ?? '' ) );
		$current  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $hostname || ! hash_equals( strtolower( $current ), strtolower( $hostname ) ) ) {
			WP_CLI::error( 'Pass --hostname=' . $current . ' to confirm the restore target.' );
		}
		$result = $this->backups->verify_backup( $id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::confirm( 'Create a mandatory safety backup and restore this site?', $assoc_args );
		$job = $this->jobs->create(
			'restore',
			array(
				'backup_id' => $id,
				'search'    => isset( $assoc_args['search'] ) ? esc_url_raw( $assoc_args['search'] ) : '',
				'replace'   => isset( $assoc_args['replace'] ) ? esc_url_raw( $assoc_args['replace'] ) : '',
			),
			'preflight',
			3
		);
		$this->wait_for_job( $job['id'] );
	}

	/** Run due background jobs once; suitable for system cron. */
	public function jobs_run() {
		$this->plugin->run_due_jobs();
		WP_CLI::success( 'Due ZoeCloud jobs processed.' );
	}

	/** Print environment and scheduler diagnostics without secrets. */
	public function doctor() {
		$status                         = $this->backups->get_preflight_status();
		$status['schema_version']       = get_option( 'zoecloud_db_version', 'missing' );
		$status['job_runner_scheduled'] = (bool) wp_next_scheduled( 'zoecloud_run_jobs' );
		$status['backup_scheduled']     = (bool) wp_next_scheduled( 'zoecloud_run_scheduled_backup' );
		foreach ( $status as $name => $value ) {
			WP_CLI::line( $name . ': ' . ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value ) );
		}
	}

	/**
	 * Run a job until a terminal state is reached.
	 *
	 * @param string $id Job UUID.
	 * @return void
	 */
	private function wait_for_job( $id ) {
		for ( $iteration = 0; $iteration < 10000; $iteration++ ) {
			$job = $this->jobs->find( $id );
			if ( ! $job ) {
				WP_CLI::error( 'Job disappeared.' );
			}
			if ( 'backup' === $job['type'] ) {
				$this->backups->run_backup_job( $id, 25, 30 );
			} elseif ( 'restore' === $job['type'] ) {
				$this->controller->run_restore_job( $id );
				$restore = $this->jobs->find( $id );
				$safety  = $restore['state']['safety_job_id'] ?? '';
				if ( $safety ) {
					$this->backups->run_backup_job( $safety, 25, 30 );
				}
			}
			$job = $this->jobs->find( $id );
			WP_CLI::log( sprintf( '%d%% %s', $job['progress'], $job['message'] ) );
			if ( 'completed' === $job['status'] ) {
				WP_CLI::success( 'Job completed: ' . $id );
				return;
			}
			if ( 'failed' === $job['status'] ) {
				WP_CLI::error( $job['last_error'] ? $job['last_error'] : 'Job failed.' );
			}
			if ( 'waiting' === $job['status'] ) {
				sleep( 1 );
			} elseif ( 'restore' === $job['type'] ) {
				sleep( 1 );
			}
		}
		WP_CLI::error( 'Job exceeded the CLI iteration limit.' );
	}
}
