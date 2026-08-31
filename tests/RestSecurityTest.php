<?php
/**
 * Tests REST route authorization and public surface.
 *
 * @package ZoeCloud
 */

/** Tests capability enforcement on ZoeCloud REST resources. */
class ZoeCloud_Rest_Security_Test extends WP_UnitTestCase {
	/** Anonymous and low-privilege users cannot read health information. */
	public function test_health_requires_manage_options() {
		$request = new WP_REST_Request( 'GET', '/zoecloud/v1/health' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	/** Removed synchronous execution routes are not registered. */
	public function test_legacy_execution_routes_are_absent() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayNotHasKey( '/zoecloud/v1/tick', $routes );
		$this->assertArrayNotHasKey( '/zoecloud/v1/restore', $routes );
	}
}
