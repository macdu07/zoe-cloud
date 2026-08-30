<?php
/**
 * Database schema management.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Creates and removes ZoeCloud's operational tables. */
class ZoeCloud_Schema {
	const VERSION = '1.0.0';

	/**
	 * Return a plugin table name.
	 *
	 * @param string $name Logical table name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'zoecloud_' . sanitize_key( $name );
	}

	/** Install or update the operational schema. */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$backups = self::table( 'backups' );
		$jobs    = self::table( 'jobs' );
		$events  = self::table( 'job_events' );

		dbDelta(
			"CREATE TABLE {$backups} (
				id varchar(36) NOT NULL,
				filename varchar(255) NOT NULL,
				storage_key varchar(191) NOT NULL,
				local_status varchar(24) NOT NULL DEFAULT 'available',
				cloud_status varchar(24) NOT NULL DEFAULT 'local',
				verification_status varchar(24) NOT NULL DEFAULT 'pending',
				deletion_status varchar(24) NOT NULL DEFAULT 'active',
				source varchar(24) NOT NULL DEFAULT 'manual',
				scope varchar(24) NOT NULL DEFAULT 'site_data',
				size bigint unsigned NOT NULL DEFAULT 0,
				checksum char(64) NOT NULL DEFAULT '',
				locked tinyint(1) NOT NULL DEFAULT 0,
				manifest longtext NULL,
				cloud longtext NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY storage_key (storage_key),
				KEY created_at (created_at),
				KEY lifecycle (deletion_status,locked,created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$jobs} (
				id varchar(36) NOT NULL,
				type varchar(24) NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'queued',
				stage varchar(48) NOT NULL DEFAULT 'init',
				progress smallint unsigned NOT NULL DEFAULT 0,
				payload longtext NULL,
				state longtext NULL,
				result longtext NULL,
				attempts smallint unsigned NOT NULL DEFAULT 0,
				max_attempts smallint unsigned NOT NULL DEFAULT 5,
				run_after datetime NOT NULL,
				lease_token varchar(64) NULL,
				lease_until datetime NULL,
				last_error text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY runnable (status,run_after,lease_until),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				job_id varchar(36) NOT NULL,
				stage varchar(48) NOT NULL,
				status varchar(24) NOT NULL,
				message text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id,id)
			) {$charset};"
		);

		update_option( 'zoecloud_db_version', self::VERSION, false );
	}

	/** Drop plugin tables after explicit uninstall consent. */
	public static function uninstall() {
		global $wpdb;

		foreach ( array( 'job_events', 'jobs', 'backups' ) as $name ) {
			$table = self::table( $name );
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		delete_option( 'zoecloud_db_version' );
	}
}
