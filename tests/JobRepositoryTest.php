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

	/** Finished activity can be cleared without touching active jobs. */
	public function test_clear_history_preserves_active_jobs() {
		$repository = new ZoeCloud_Job_Repository();
		$completed  = $repository->create( 'backup', array() );
		$failed     = $repository->create( 'restore', array() );
		$running    = $repository->create( 'backup', array() );
		$repository->update( $completed['id'], array( 'status' => 'completed' ) );
		$repository->update( $failed['id'], array( 'status' => 'failed' ) );
		$repository->update( $running['id'], array( 'status' => 'running' ) );

		$this->assertSame( 2, $repository->clear_history() );
		$this->assertNull( $repository->find( $completed['id'] ) );
		$this->assertNull( $repository->find( $failed['id'] ) );
		$this->assertNotNull( $repository->find( $running['id'] ) );
	}

	/** Automatic pruning retains the newest finished jobs. */
	public function test_prune_history_is_bounded() {
		$repository = new ZoeCloud_Job_Repository();
		foreach ( range( 1, 3 ) as $number ) {
			$job = $repository->create( 'backup', array( 'number' => $number ) );
			$repository->update( $job['id'], array( 'status' => 'completed' ) );
		}

		$this->assertSame( 1, $repository->prune_history( 2 ) );
		$this->assertCount( 2, $repository->all() );
	}
}
