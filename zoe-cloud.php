<?php
/**
 * Plugin Name: ZoeCloud
 * Plugin URI: https://example.com/zoe-cloud
 * Description: WordPress backups with portable archives, restore tooling, and cloud uploads.
 * Version: 0.1.0
 * Author: ZoeCloud
 * Text Domain: zoe-cloud
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZOECLOUD_VERSION', '0.1.0' );
define( 'ZOECLOUD_PLUGIN_FILE', __FILE__ );
define( 'ZOECLOUD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZOECLOUD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-crypto.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-r2-service.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-drive-service.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-backup-manager.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-restore-manager.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-rest-controller.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-admin.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-plugin.php';

function zoecloud() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new ZoeCloud_Plugin();
	}

	return $plugin;
}

register_activation_hook( __FILE__, array( 'ZoeCloud_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ZoeCloud_Plugin', 'deactivate' ) );

zoecloud()->boot();
