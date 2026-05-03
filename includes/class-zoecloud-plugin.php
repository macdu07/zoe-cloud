<?php
/**
 * Core plugin wiring.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		$crypto          = new ZoeCloud_Crypto();
		$drive_service   = new ZoeCloud_Drive_Service( $crypto );
		$this->backup_manager = new ZoeCloud_Backup_Manager( $drive_service );
		$restore_manager = new ZoeCloud_Restore_Manager();
		$rest_controller = new ZoeCloud_REST_Controller( $this->backup_manager, $restore_manager, $drive_service );
		$admin           = new ZoeCloud_Admin( $crypto );

		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
		add_action( 'zoecloud_run_scheduled_backup', array( $this->backup_manager, 'run_scheduled_backup' ) );
		add_action( 'zoecloud_run_backup_job', array( $this->backup_manager, 'run_backup_job' ) );
		add_action( 'admin_post_zoecloud_download_backup', array( $this->backup_manager, 'stream_backup_download' ) );
		add_action( 'update_option_zoecloud_settings', array( $this, 'sync_schedule' ), 10, 2 );

		$admin->hooks();
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		$defaults = array(
			'drive_client_id'     => '',
			'drive_client_secret' => '',
			'drive_refresh_token' => '',
			'drive_project_name'  => get_bloginfo( 'name' ),
			'retention_limit'     => 10,
			'schedule'            => 'daily',
			'auto_upload_drive'   => 1,
			'excluded_paths'      => array(),
		);

		if ( ! get_option( 'zoecloud_settings' ) ) {
			add_option( 'zoecloud_settings', $defaults, '', false );
		}

		if ( ! wp_next_scheduled( 'zoecloud_run_scheduled_backup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'zoecloud_run_scheduled_backup' );
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

		if ( $old_schedule === $new_schedule ) {
			return;
		}

		$timestamp = wp_next_scheduled( 'zoecloud_run_scheduled_backup' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'zoecloud_run_scheduled_backup' );
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, $new_schedule, 'zoecloud_run_scheduled_backup' );
	}
}
