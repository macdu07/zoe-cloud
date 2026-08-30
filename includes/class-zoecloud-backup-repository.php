<?php
/**
 * Backup metadata repository.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persists backup metadata separately from WordPress options. */
class ZoeCloud_Backup_Repository {
	/**
	 * Store a backup record.
	 *
	 * @param array $record Backup metadata.
	 * @return array|null
	 */
	public function save( array $record ) {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$id       = sanitize_text_field( $record['id'] ?? wp_generate_uuid4() );
		$existing = $this->find( $id );
		$data     = array(
			'id'                  => $id,
			'filename'            => sanitize_file_name( $record['filename'] ?? '' ),
			'storage_key'         => sanitize_file_name( $record['storage_key'] ?? ( $record['filename'] ?? '' ) ),
			'local_status'        => sanitize_key( $record['local_status'] ?? 'available' ),
			'cloud_status'        => sanitize_key( $record['cloud_status'] ?? ( empty( $record['cloud'] ) ? 'local' : 'available' ) ),
			'verification_status' => sanitize_key( $record['verification_status'] ?? ( empty( $record['checksum'] ) ? 'pending' : 'verified' ) ),
			'deletion_status'     => sanitize_key( $record['deletion_status'] ?? 'active' ),
			'source'              => sanitize_key( $record['source'] ?? 'manual' ),
			'scope'               => sanitize_key( $record['scope'] ?? 'site_data' ),
			'size'                => max( 0, (int) ( $record['size'] ?? 0 ) ),
			'checksum'            => preg_match( '/^[a-f0-9]{64}$/', (string) ( $record['checksum'] ?? '' ) ) ? $record['checksum'] : '',
			'locked'              => empty( $record['locked'] ) ? 0 : 1,
			'manifest'            => wp_json_encode( $record['manifest'] ?? array(), JSON_UNESCAPED_SLASHES ),
			'cloud'               => wp_json_encode( $record['cloud'] ?? null, JSON_UNESCAPED_SLASHES ),
			'last_error'          => sanitize_text_field( $record['last_error'] ?? ( $record['cloud_error'] ?? '' ) ),
			'created_at'          => $record['created_at'] ?? ( $existing['created_at'] ?? $now ),
			'updated_at'          => $now,
		);

		$wpdb->replace( ZoeCloud_Schema::table( 'backups' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return $this->find( $id );
	}

	/** Return all active backup records newest first. */
	public function all() {
		global $wpdb;

		$table = ZoeCloud_Schema::table( 'backups' );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE deletion_status <> 'deleted' ORDER BY created_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Find one backup by UUID.
	 *
	 * @param string $id Backup UUID.
	 * @return array|null
	 */
	public function find( $id ) {
		global $wpdb;

		$table = ZoeCloud_Schema::table( 'backups' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Find one backup by opaque storage key.
	 *
	 * @param string $key Opaque storage key.
	 * @return array|null
	 */
	public function find_by_storage_key( $key ) {
		global $wpdb;

		$table = ZoeCloud_Schema::table( 'backups' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE storage_key = %s LIMIT 1", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Remove a record after its resources have been deleted.
	 *
	 * @param string $id Backup UUID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		return false !== $wpdb->delete( ZoeCloud_Schema::table( 'backups' ), array( 'id' => $id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Convert JSON columns and compatibility fields.
	 *
	 * @param array $row Database row.
	 * @return array
	 */
	private function hydrate( array $row ) {
		$row['manifest'] = json_decode( (string) ( $row['manifest'] ?? '' ), true );
		$row['manifest'] = is_array( $row['manifest'] ) ? $row['manifest'] : array();
		$row['cloud']    = json_decode( (string) ( $row['cloud'] ?? '' ), true );
		$row['cloud']    = is_array( $row['cloud'] ) ? $row['cloud'] : null;
		$row['size']     = (int) $row['size'];
		$row['locked']   = ! empty( $row['locked'] );
		if ( ! empty( $row['last_error'] ) ) {
			$row['cloud_error'] = $row['last_error'];
		}

		return $row;
	}
}
