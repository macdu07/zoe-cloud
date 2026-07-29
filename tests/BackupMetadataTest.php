<?php
/**
 * Tests v0.2 backup metadata compatibility.
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
		$this->manager = new ZoeCloud_Backup_Manager( new ZoeCloud_R2_Service( new ZoeCloud_Crypto() ) );
		delete_option( 'zoecloud_backups' );
	}

	/** Legacy records receive safe, backwards-compatible defaults. */
	public function test_legacy_records_receive_v02_defaults() {
		update_option(
			'zoecloud_backups',
			array(
				array(
					'id'         => 'legacy',
					'filename'   => 'legacy.zip',
					'created_at' => '2026-01-01 00:00:00',
					'manifest'   => array( 'include_core' => true ),
				),
			),
			false
		);

		$record = $this->manager->list_backups()[0];
		$this->assertSame( 'manual', $record['source'] );
		$this->assertSame( 'full', $record['scope'] );
		$this->assertFalse( $record['locked'] );
		$this->assertSame( 'local', $record['cloud_status'] );
	}

	/** Locked recovery points cannot be removed. */
	public function test_locked_record_cannot_be_deleted() {
		update_option(
			'zoecloud_backups',
			array(
				array(
					'id'         => 'protected',
					'filename'   => 'protected.zip',
					'created_at' => '2026-01-01 00:00:00',
					'locked'     => true,
				),
			),
			false
		);

		$result = $this->manager->delete_backup( 'protected' );
		$this->assertWPError( $result );
		$this->assertSame( 'zoecloud_backup_locked', $result->get_error_code() );
	}
}
