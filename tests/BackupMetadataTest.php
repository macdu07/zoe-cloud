<?php
/**
 * Tests backup metadata persistence.
 *
 * @package ZoeCloud
 */

/** Tests normalized backup metadata and locking behavior. */
class ZoeCloud_Backup_Metadata_Test extends WP_UnitTestCase {
	/**
	 * Backup manager under test.
	 *
	 * @var ZoeCloud_Backup_Manager
	 */
	private $manager;

	/** Prepare isolated backup records. */
	public function set_up() {
		parent::set_up();
		ZoeCloud_Schema::install();
		$this->manager = new ZoeCloud_Backup_Manager( new ZoeCloud_R2_Service( new ZoeCloud_Crypto() ) );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . ZoeCloud_Schema::table( 'backups' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/** New records receive explicit lifecycle defaults. */
	public function test_new_records_receive_lifecycle_defaults() {
		$repository = new ZoeCloud_Backup_Repository();
		$repository->save(
			array(
				'id'          => wp_generate_uuid4(),
				'filename'    => 'backup.zip',
				'storage_key' => 'opaque.zip',
				'created_at'  => '2026-01-01 00:00:00',
				'manifest'    => array( 'include_core' => true ),
			)
		);

		$record = $this->manager->list_backups()[0];
		$this->assertSame( 'manual', $record['source'] );
		$this->assertSame( 'site_data', $record['scope'] );
		$this->assertFalse( $record['locked'] );
		$this->assertSame( 'local', $record['cloud_status'] );
	}

	/** Locked recovery points cannot be removed. */
	public function test_locked_record_cannot_be_deleted() {
		$repository = new ZoeCloud_Backup_Repository();
		$repository->save(
			array(
				'id'          => 'protected',
				'filename'    => 'protected.zip',
				'storage_key' => 'protected.zip',
				'created_at'  => '2026-01-01 00:00:00',
				'locked'      => true,
			)
		);

		$result = $this->manager->delete_backup( 'protected' );
		$this->assertWPError( $result );
		$this->assertSame( 'zoecloud_backup_locked', $result->get_error_code() );
	}
}
