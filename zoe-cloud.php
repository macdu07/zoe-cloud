<?php
/**
 * Plugin Name: ZoeCloud
 * Plugin URI: https://maurouix.com/zoe-cloud
 * Description: WordPress backups with portable archives, restore tooling, and cloud uploads.
 * Version: 1.0.0
 * Author: Mauricio Correa
 * Author URI: https://maurouix.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: zoe-cloud
 * Requires at least: 6.4
 * Requires PHP: 8.1
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZOECLOUD_VERSION', '1.0.0' );
define( 'ZOECLOUD_PLUGIN_FILE', __FILE__ );
define( 'ZOECLOUD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZOECLOUD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-crypto.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-schema.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-storage.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-backup-repository.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-job-repository.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-r2-service.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-backup-manager.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-restore-manager.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-rest-controller.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-admin.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-plugin.php';
require_once ZOECLOUD_PLUGIN_DIR . 'includes/class-zoecloud-cli.php';

/**
 * Return the ZoeCloud plugin instance.
 *
 * @return ZoeCloud_Plugin
 */
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
