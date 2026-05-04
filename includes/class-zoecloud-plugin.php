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
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot() {
		$crypto               = new ZoeCloud_Crypto();
		$r2_service           = new ZoeCloud_R2_Service( $crypto );
		$this->backup_manager = new ZoeCloud_Backup_Manager( $r2_service );
		$restore_manager      = new ZoeCloud_Restore_Manager();
		$rest_controller      = new ZoeCloud_REST_Controller( $this->backup_manager, $restore_manager, $r2_service );
		$admin                = new ZoeCloud_Admin( $crypto, $r2_service );

		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
		add_action( 'zoecloud_run_scheduled_backup', array( $this->backup_manager, 'run_scheduled_backup' ) );
		add_action( 'zoecloud_run_backup_job', array( $this->backup_manager, 'run_backup_job' ) );
		add_action( 'admin_post_zoecloud_download_backup', array( $this->backup_manager, 'stream_backup_download' ) );
		add_action( 'update_option_zoecloud_settings', array( $this, 'sync_schedule' ), 10, 2 );
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedules' ) );

		$admin->hooks();
	}

	/**
	 * Register custom WP-Cron intervals used by ZoeCloud.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function register_cron_schedules( $schedules ) {
		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'zoe-cloud' ),
		);

		return $schedules;
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		$defaults = array(
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
			'auto_upload_drive'    => 1,
			'excluded_paths'       => array(),
		);

		if ( ! get_option( 'zoecloud_settings' ) ) {
			add_option( 'zoecloud_settings', $defaults, '', false );
		}

		if ( ! wp_next_scheduled( 'zoecloud_run_scheduled_backup' ) ) {
			wp_schedule_event( self::get_next_schedule_timestamp( $defaults ), 'daily', 'zoecloud_run_scheduled_backup' );
		}
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'zoecloud_run_scheduled_backup' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'zoecloud_run_scheduled_backup' );
		}
	}

	/**
	 * Sync WP-Cron with saved schedule.
	 *
	 * @param array $old_value Previous settings.
	 * @param array $value     New settings.
	 * @return void
	 */
	public function sync_schedule( $old_value, $value ) {
		$old_schedule = $old_value['schedule'] ?? 'daily';
		$new_schedule = $value['schedule'] ?? 'daily';
		$old_time     = $old_value['schedule_time'] ?? '02:00';
		$new_time     = $value['schedule_time'] ?? '02:00';
		$old_weekday  = $old_value['schedule_weekday'] ?? 'monday';
		$new_weekday  = $value['schedule_weekday'] ?? 'monday';

		if ( $old_schedule === $new_schedule && $old_time === $new_time && $old_weekday === $new_weekday ) {
			return;
		}

		$timestamp = wp_next_scheduled( 'zoecloud_run_scheduled_backup' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'zoecloud_run_scheduled_backup' );
		}

		wp_schedule_event( self::get_next_schedule_timestamp( $value ), $new_schedule, 'zoecloud_run_scheduled_backup' );
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
