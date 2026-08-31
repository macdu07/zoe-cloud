<?php
/**
 * Tests schedule calculation and cron reconciliation.
 *
 * @package ZoeCloud
 */

/** Tests reliable scheduling without browser polling. */
class ZoeCloud_Schedule_Test extends WP_UnitTestCase {
	/** A weekly schedule resolves to the requested local weekday and time. */
	public function test_weekly_schedule_calculation() {
		update_option( 'timezone_string', 'UTC' );
		$method = new ReflectionMethod( ZoeCloud_Plugin::class, 'get_next_schedule_timestamp' );
		$method->setAccessible( true );
		$next = $method->invoke(
			null,
			array(
				'schedule'         => 'weekly',
				'schedule_time'    => '04:25',
				'schedule_weekday' => 'wednesday',
			)
		);
		$date = new DateTimeImmutable( '@' . $next );

		$this->assertGreaterThan( time(), $next );
		$this->assertSame( 'Wednesday', $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'l' ) );
		$this->assertSame( '04:25', $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'H:i' ) );
	}

	/** Missing enabled cron events are recreated. */
	public function test_reconcile_restores_missing_backup_event() {
		wp_clear_scheduled_hook( 'zoecloud_run_scheduled_backup' );
		update_option(
			'zoecloud_settings',
			array(
				'schedule_enabled' => true,
				'schedule'         => 'daily',
				'schedule_time'    => '03:00',
			)
		);

		( new ZoeCloud_Plugin() )->reconcile_schedule();

		$this->assertNotFalse( wp_next_scheduled( 'zoecloud_run_scheduled_backup' ) );
	}
}
