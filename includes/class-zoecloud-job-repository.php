<?php
/**
 * Durable job repository.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persists jobs and implements atomic leases. */
class ZoeCloud_Job_Repository {
	/**
	 * Acquire a connection-scoped MySQL mutex.
	 *
	 * @param string $name Mutex name.
	 * @return bool
	 */
	public function acquire_mutex( $name ) {
		global $wpdb;

		$key = substr( 'zoecloud:' . sanitize_key( $name ), 0, 64 );

		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Release a connection-scoped MySQL mutex.
	 *
	 * @param string $name Mutex name.
	 * @return void
	 */
	public function release_mutex( $name ) {
		global $wpdb;

		$key = substr( 'zoecloud:' . sanitize_key( $name ), 0, 64 );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Create a job.
	 *
	 * @param string $type         Job type.
	 * @param array  $payload      Job arguments.
	 * @param string $stage        Initial stage.
	 * @param int    $max_attempts Maximum attempts.
	 * @return array|null
	 */
	public function create( $type, array $payload, $stage = 'init', $max_attempts = 5 ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$job = array(
			'id'           => wp_generate_uuid4(),
			'type'         => sanitize_key( $type ),
			'status'       => 'queued',
			'stage'        => sanitize_key( $stage ),
			'progress'     => 0,
			'payload'      => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ),
			'state'        => '{}',
			'result'       => null,
			'attempts'     => 0,
			'max_attempts' => max( 1, absint( $max_attempts ) ),
			'run_after'    => $now,
			'lease_token'  => null,
			'lease_until'  => null,
			'last_error'   => null,
			'created_at'   => $now,
			'updated_at'   => $now,
		);
		$wpdb->insert( ZoeCloud_Schema::table( 'jobs' ), $job ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$this->event( $job['id'], $stage, 'queued', __( 'Job queued.', 'zoe-cloud' ) );

		return $this->find( $job['id'] );
	}

	/**
	 * Return recent jobs.
	 *
	 * @param int $limit Maximum records.
	 * @return array
	 */
	public function all( $limit = 50 ) {
		global $wpdb;

		$table = ZoeCloud_Schema::table( 'jobs' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", absint( $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Find one job.
	 *
	 * @param string $id Job UUID.
	 * @return array|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = ZoeCloud_Schema::table( 'jobs' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Acquire a lease for a due job.
	 *
	 * @param string $id  Job UUID.
	 * @param int    $ttl Lease duration in seconds.
	 * @return string|false
	 */
	public function acquire( $id, $ttl = 90 ) {
		global $wpdb;

		$table   = ZoeCloud_Schema::table( 'jobs' );
		$token   = bin2hex( random_bytes( 24 ) );
		$now     = current_time( 'mysql', true );
		$until   = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $ttl ) ) );
		// The table identifier is generated internally by ZoeCloud_Schema.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$table} SET lease_token = %s, lease_until = %s, status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %s AND status IN ('queued','running','waiting','rolling_back') AND run_after <= %s AND (lease_until IS NULL OR lease_until < %s)",
			$token,
			$until,
			$now,
			$id,
			$now,
			$now
		);
		$updated = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

		return 1 === $updated ? $token : false;
	}

	/**
	 * Save mutable job fields while holding a lease.
	 *
	 * @param string $id          Job UUID.
	 * @param array  $changes     Mutable fields.
	 * @param string $lease_token Optional lease token.
	 * @return bool
	 */
	public function update( $id, array $changes, $lease_token = '' ) {
		global $wpdb;

		$allowed = array( 'status', 'stage', 'progress', 'payload', 'state', 'result', 'run_after', 'lease_token', 'lease_until', 'last_error' );
		$data    = array_intersect_key( $changes, array_flip( $allowed ) );
		foreach ( array( 'payload', 'state', 'result' ) as $json_key ) {
			if ( isset( $data[ $json_key ] ) && is_array( $data[ $json_key ] ) ) {
				$data[ $json_key ] = wp_json_encode( $data[ $json_key ], JSON_UNESCAPED_SLASHES );
			}
		}
		if ( isset( $data['status'] ) ) {
			$data['status'] = sanitize_key( $data['status'] );
		}
		if ( isset( $data['stage'] ) ) {
			$data['stage'] = sanitize_key( $data['stage'] );
		}
		if ( isset( $data['progress'] ) ) {
			$data['progress'] = min( 100, max( 0, absint( $data['progress'] ) ) );
		}
		$data['updated_at'] = current_time( 'mysql', true );

		$where = array( 'id' => $id );
		if ( '' !== $lease_token ) {
			$where['lease_token'] = $lease_token;
		}

		return false !== $wpdb->update( ZoeCloud_Schema::table( 'jobs' ), $data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Release a job lease and optionally delay the next run.
	 *
	 * @param string   $id    Job UUID.
	 * @param string   $token Lease token.
	 * @param int|null $delay Delay in seconds.
	 * @return bool
	 */
	public function release( $id, $token, $delay = null ) {
		$changes = array(
			'lease_token' => null,
			'lease_until' => null,
		);
		if ( null !== $delay ) {
			$changes['run_after'] = gmdate( 'Y-m-d H:i:s', time() + max( 0, absint( $delay ) ) );
		}

		return $this->update(
			$id,
			$changes,
			$token
		);
	}

	/**
	 * Append a bounded user-safe event.
	 *
	 * @param string $job_id  Job UUID.
	 * @param string $stage   Job stage.
	 * @param string $status  Job status.
	 * @param string $message Safe event message.
	 * @return void
	 */
	public function event( $job_id, $stage, $status, $message ) {
		global $wpdb;

		$wpdb->insert(
			ZoeCloud_Schema::table( 'job_events' ),
			array(
				'job_id'     => sanitize_text_field( $job_id ),
				'stage'      => sanitize_key( $stage ),
				'status'     => sanitize_key( $status ),
				'message'    => sanitize_text_field( $message ),
				'created_at' => current_time( 'mysql', true ),
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Return events for a job.
	 *
	 * @param string $job_id Job UUID.
	 * @return array
	 */
	public function events( $job_id ) {
		global $wpdb;

		$table = ZoeCloud_Schema::table( 'job_events' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT stage,status,message,created_at AS time FROM {$table} WHERE job_id = %s ORDER BY id ASC LIMIT 100", $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Return jobs that should be run now.
	 *
	 * @param int $limit Maximum records.
	 * @return array
	 */
	public function due( $limit = 10 ) {
		global $wpdb;

		$table = ZoeCloud_Schema::table( 'jobs' );
		$now   = current_time( 'mysql', true );
		return $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status IN ('queued','running','waiting','rolling_back') AND run_after <= %s AND (lease_until IS NULL OR lease_until < %s) ORDER BY run_after ASC LIMIT %d", $now, $now, absint( $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Decode JSON and attach events.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private function hydrate( array $row ) {
		foreach ( array( 'payload', 'state', 'result' ) as $key ) {
			$decoded     = json_decode( (string) ( $row[ $key ] ?? '' ), true );
			$row[ $key ] = is_array( $decoded ) ? $decoded : array();
		}
		$row['args']     = $row['payload'];
		$row['progress'] = (int) $row['progress'];
		$row['attempts'] = (int) $row['attempts'];
		$row['events']   = $this->events( $row['id'] );
		$last_event      = end( $row['events'] );
		$row['message']  = is_array( $last_event ) ? ( $last_event['message'] ?? '' ) : '';

		return $row;
	}
}
