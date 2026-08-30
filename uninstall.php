<?php
/**
 * ZoeCloud uninstall handler.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$zoecloud_settings = get_option( 'zoecloud_settings', array() );
if ( empty( $zoecloud_settings['delete_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/includes/class-zoecloud-schema.php';
require_once __DIR__ . '/includes/class-zoecloud-storage.php';

$zoecloud_storage = new ZoeCloud_Storage();
$zoecloud_storage->purge();
ZoeCloud_Schema::uninstall();

foreach ( array(
	'zoecloud_settings',
	'zoecloud_storage_token',
	'zoecloud_schedule_error',
	'zoecloud_backups',
	'zoecloud_jobs',
	'zoecloud_restore_jobs',
) as $zoecloud_option ) {
	delete_option( $zoecloud_option );
}

foreach ( array(
	'zoecloud_run_scheduled_backup',
	'zoecloud_run_backup_job',
	'zoecloud_run_restore_job',
	'zoecloud_run_cloud_download_job',
	'zoecloud_run_jobs',
) as $zoecloud_hook ) {
	wp_clear_scheduled_hook( $zoecloud_hook );
}
