<?php
/**
 * Tests durable jobs and atomic leases.
 *
 * @package ZoeCloud
 */

/** Tests database-backed job state. */
class ZoeCloud_Job_Repository_Test extends WP_UnitTestCase {
	/** Prepare clean job tables. */
	public function set_up() {
		parent::set_up();
		ZoeCloud_Schema::install();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . ZoeCloud_Schema::table( 'job_events' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'TRUNCATE TABLE ' . ZoeCloud_Schema::table( 'jobs' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/** Only one worker can lease a job at a time. */
	public function test_lease_is_atomic() {
		$repository = new ZoeCloud_Job_Repository();
		$job        = $repository->create( 'backup', array( 'scope' => 'site_data' ) );
		$token      = $repository->acquire( $job['id'] );

		$this->assertIsString( $token );
		$this->assertFalse( $repository->acquire( $job['id'] ) );
		$this->assertTrue( $repository->release( $job['id'], $token ) );
	}
}
