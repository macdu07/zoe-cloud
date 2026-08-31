<?php
/**
 * Tests the Plugins screen action link.
 *
 * @package ZoeCloud
 */

/** Tests the direct dashboard link. */
class ZoeCloud_Admin_Links_Test extends WP_UnitTestCase {
	/** Administrators see a direct link to the ZoeCloud dashboard. */
	public function test_admin_sees_dashboard_action_link() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$links = apply_filters( 'plugin_action_links_' . plugin_basename( ZOECLOUD_PLUGIN_FILE ), array( 'deactivate' => 'Deactivate' ) );

		$this->assertArrayHasKey( 'zoecloud_dashboard', $links );
		$this->assertStringContainsString( 'page=zoecloud', $links['zoecloud_dashboard'] );
	}

	/** Users without manage_options do not receive an unusable dashboard link. */
	public function test_non_admin_does_not_see_dashboard_action_link() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$links = apply_filters( 'plugin_action_links_' . plugin_basename( ZOECLOUD_PLUGIN_FILE ), array( 'deactivate' => 'Deactivate' ) );

		$this->assertArrayNotHasKey( 'zoecloud_dashboard', $links );
	}
}
