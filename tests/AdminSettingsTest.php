<?php
/**
 * Tests v0.2 settings behavior.
 *
 * @package ZoeCloud
 */

/** Tests settings sanitization behavior. */
class ZoeCloud_Admin_Settings_Test extends WP_UnitTestCase {
	/** Automation can be explicitly disabled from the settings form. */
	public function test_schedule_can_be_explicitly_disabled() {
		$admin = new ZoeCloud_Admin( new ZoeCloud_Crypto(), new ZoeCloud_R2_Service( new ZoeCloud_Crypto() ) );
		update_option( 'zoecloud_settings', array( 'schedule_enabled' => 1 ), false );

		$clean = $admin->sanitize_settings(
			array(
				'settings_section' => 'backup',
				'schedule'         => 'daily',
				'schedule_time'    => '02:00',
			)
		);

		$this->assertSame( 0, $clean['schedule_enabled'] );
		$this->assertSame( 'daily', $clean['schedule'] );
	}
}
