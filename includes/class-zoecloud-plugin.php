<?php
/**
 * Core plugin wiring.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires plugin services and lifecycle hooks.
 */
class ZoeCloud_Plugin {
	/**
	 * Backup manager.
	 *
	 * @var ZoeCloud_Backup_Manager
	 */
	private $backup_manager;

	/**
	 * Durable job repository.
	 *
	 * @var ZoeCloud_Job_Repository
	 */
	private $jobs;

	/**
	 * REST and restore job controller.
	 *
	 * @var ZoeCloud_REST_Controller
	 */
	private $rest_controller;

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot() {
		$this->maybe_install_schema();
		$crypto                = new ZoeCloud_Crypto();
		$storage               = new ZoeCloud_Storage();
		$backup_repository     = new ZoeCloud_Backup_Repository();
		$this->jobs            = new ZoeCloud_Job_Repository();
		$r2_service            = new ZoeCloud_R2_Service( $crypto );
		$this->backup_manager  = new ZoeCloud_Backup_Manager( $r2_service, $backup_repository, $this->jobs, $storage );
		$restore_manager       = new ZoeCloud_Restore_Manager( $storage, $backup_repository );
		$rest_controller       = new ZoeCloud_REST_Controller( $this->backup_manager, $restore_manager, $r2_service, $this->jobs, $backup_repository, $storage );
		$this->rest_controller = $rest_controller;
		$admin                 = new ZoeCloud_Admin( $crypto, $r2_service );

		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
		add_action( 'zoecloud_run_scheduled_backup', array( $this->backup_manager, 'run_scheduled_backup' ) );
		add_action( 'zoecloud_run_backup_job', array( $this->backup_manager, 'run_backup_job' ) );
		add_action( 'zoecloud_run_restore_job', array( $rest_controller, 'run_restore_job' ) );
		add_action( 'zoecloud_run_cloud_download_job', array( $rest_controller, 'run_cloud_download_job' ) );
		add_action( 'admin_post_zoecloud_download_backup', array( $this->backup_manager, 'stream_backup_download' ) );
		add_action( 'update_option_zoecloud_settings', array( $this, 'sync_schedule' ), 10, 2 );
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );
		add_action( 'init', array( $this, 'reconcile_schedule' ), 20 );
		add_action( 'init', array( $this, 'reconcile_job_runner' ), 21 );
		add_action( 'zoecloud_run_jobs', array( $this, 'run_due_jobs' ) );
		add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );

		$admin->hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli = new ZoeCloud_CLI( $this->backup_manager, $this->jobs, $rest_controller, $this );
			WP_CLI::add_command( 'zoecloud backup create', array( $cli, 'backup_create' ) );
			WP_CLI::add_command( 'zoecloud backup list', array( $cli, 'backup_list' ) );
			WP_CLI::add_command( 'zoecloud backup verify', array( $cli, 'backup_verify' ) );
			WP_CLI::add_command( 'zoecloud restore', array( $cli, 'restore' ) );
			WP_CLI::add_command( 'zoecloud jobs run', array( $cli, 'jobs_run' ) );
			WP_CLI::add_command( 'zoecloud doctor', array( $cli, 'doctor' ) );
		}
	}

	/** Add transparent suggested text to WordPress privacy policy tooling. */
	public function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . esc_html__( 'This site uses ZoeCloud to create backup archives that may contain the complete WordPress database and files, including personal data already stored by the site. Local copies are restricted to administrators.', 'zoe-cloud' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If optional Cloudflare R2 or Amazon S3 storage is enabled, backup contents are sent to the administrator-selected account. ZoeCloud itself does not collect telemetry or contact the plugin author.', 'zoe-cloud' ) . '</p>';
		wp_add_privacy_policy_content( 'ZoeCloud', wp_kses_post( wpautop( $content ) ) );
	}

	/** Ensure fresh installations have the current operational schema. */
	private function maybe_install_schema() {
		if ( ZoeCloud_Schema::VERSION !== get_option( 'zoecloud_db_version' ) ) {
			ZoeCloud_Schema::install();
		}
	}

	/**
	 * Register custom WP-Cron intervals used by ZoeCloud.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function register_cron_schedules( $schedules ) {
		$schedules['zoecloud_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (ZoeCloud jobs)', 'zoe-cloud' ),
		);
		$schedules['weekly']          = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'zoe-cloud' ),
		);

		return $schedules;
	}

	/** Ensure the independent background runner always has a recurring trigger. */
	public function reconcile_job_runner() {
		if ( ! wp_next_scheduled( 'zoecloud_run_jobs' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'zoecloud_minute', 'zoecloud_run_jobs' );
		}
	}

	/** Run a bounded set of due jobs without relying on an open admin page. */
	public function run_due_jobs() {
		if ( ! get_transient( 'zoecloud_cleanup_recent' ) ) {
			( new ZoeCloud_Storage() )->cleanup_expired();
			set_transient( 'zoecloud_cleanup_recent', 1, HOUR_IN_SECONDS );
		}
		if ( ! get_transient( 'zoecloud_history_cleanup_recent' ) ) {
			$this->jobs->prune_history( 100 );
			set_transient( 'zoecloud_history_cleanup_recent', 1, HOUR_IN_SECONDS );
		}
		foreach ( $this->jobs->due( 5 ) as $job_id ) {
			$job = $this->jobs->find( $job_id );
			if ( ! $job ) {
				continue;
			}
			if ( 'backup' === $job['type'] ) {
				$this->backup_manager->run_backup_job( $job_id, 10, 20 );
			} elseif ( 'restore' === $job['type'] ) {
				$this->rest_controller->run_restore_job( $job_id );
			} elseif ( 'cloud_download' === $job['type'] ) {
				$this->rest_controller->run_cloud_download_job( $job_id );
			}
		}
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		ZoeCloud_Schema::install();
		$defaults = array(
			'schedule_enabled'     => 0,
			'storage_provider'     => 'r2',
			'r2_account_id'        => '',
			'r2_access_key_id'     => '',
			'r2_secret_access_key' => '',
			'r2_bucket'            => '',
			'r2_prefix'            => 'zoe-cloud',
			's3_access_key_id'     => '',
			's3_secret_access_key' => '',
			's3_bucket'            => '',
			's3_region'            => 'us-east-1',
			's3_prefix'            => '',
			'retention_limit'      => 10,
			'schedule'             => 'daily',
			'schedule_time'        => '02:00',
			'schedule_weekday'     => 'monday',
			'auto_upload_cloud'    => 1,
			'excluded_paths'       => array(),
			'delete_on_uninstall'  => 0,
		);

		if ( ! get_option( 'zoecloud_settings' ) ) {
			add_option( 'zoecloud_settings', $defaults, '', false );
		}

		// New installations opt in to automation from the guided setup.
	}

	/** Recreate a missing recurring event and remove disabled schedules. */
	public function reconcile_schedule() {
		$settings  = get_option( 'zoecloud_settings', array() );
		$enabled   = ! empty( $settings['schedule_enabled'] );
		$scheduled = wp_next_scheduled( 'zoecloud_run_scheduled_backup' );

		if ( $enabled && ! $scheduled ) {
			$schedule = $settings['schedule'] ?? 'daily';
			if ( ! wp_schedule_event( self::get_next_schedule_timestamp( $settings ), $schedule, 'zoecloud_run_scheduled_backup' ) ) {
				update_option( 'zoecloud_schedule_error', current_time( 'mysql', true ), false );
			} else {
				delete_option( 'zoecloud_schedule_error' );
			}
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( 'zoecloud_run_scheduled_backup' );
		}
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'zoecloud_run_scheduled_backup' );
		wp_clear_scheduled_hook( 'zoecloud_run_backup_job' );
		wp_clear_scheduled_hook( 'zoecloud_run_restore_job' );
		wp_clear_scheduled_hook( 'zoecloud_run_cloud_download_job' );
		wp_clear_scheduled_hook( 'zoecloud_run_jobs' );
	}

	/**
	 * Sync WP-Cron with saved schedule.
	 *
	 * @param array $old_value Previous settings.
	 * @param array $value     New settings.
	 * @return void
	 */
	public function sync_schedule( $old_value, $value ) {
		$old_enabled  = ! empty( $old_value['schedule_enabled'] );
		$new_enabled  = ! empty( $value['schedule_enabled'] );
		$old_schedule = $old_value['schedule'] ?? 'daily';
		$new_schedule = $value['schedule'] ?? 'daily';
		$old_time     = $old_value['schedule_time'] ?? '02:00';
		$new_time     = $value['schedule_time'] ?? '02:00';
		$old_weekday  = $old_value['schedule_weekday'] ?? 'monday';
		$new_weekday  = $value['schedule_weekday'] ?? 'monday';

		if ( $old_enabled === $new_enabled && $old_schedule === $new_schedule && $old_time === $new_time && $old_weekday === $new_weekday ) {
			return;
		}

		wp_clear_scheduled_hook( 'zoecloud_run_scheduled_backup' );

		if ( $new_enabled ) {
			wp_schedule_event( self::get_next_schedule_timestamp( $value ), $new_schedule, 'zoecloud_run_scheduled_backup' );
		}
	}

	/**
	 * Calculate the next scheduled backup timestamp.
	 *
	 * @param array $settings Plugin settings.
	 * @return int
	 */
	private static function get_next_schedule_timestamp( array $settings ) {
		$schedule = $settings['schedule'] ?? 'daily';
		$time     = $settings['schedule_time'] ?? '02:00';
		$weekday  = $settings['schedule_weekday'] ?? 'monday';

		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $matches ) ) {
			$matches = array( '', '02', '00' );
		}

		$hour   = (int) $matches[1];
		$minute = (int) $matches[2];
		$now    = new DateTimeImmutable( 'now', wp_timezone() );

		if ( 'weekly' === $schedule ) {
			$weekdays = array(
				'sunday'    => 0,
				'monday'    => 1,
				'tuesday'   => 2,
				'wednesday' => 3,
				'thursday'  => 4,
				'friday'    => 5,
				'saturday'  => 6,
			);

			$target_day  = $weekdays[ $weekday ] ?? $weekdays['monday'];
			$current_day = (int) $now->format( 'w' );
			$days_ahead  = ( $target_day - $current_day + 7 ) % 7;
			$next        = $now->setTime( $hour, $minute, 0 )->modify( '+' . $days_ahead . ' days' );

			if ( $next <= $now ) {
				$next = $next->modify( '+7 days' );
			}

			return $next->getTimestamp();
		}

		$next = $now->setTime( $hour, $minute, 0 );

		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}
}
