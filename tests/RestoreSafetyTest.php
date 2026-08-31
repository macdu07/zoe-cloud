<?php
/**
 * Tests restore SQL and serialization safety.
 *
 * @package ZoeCloud
 */

/** Tests restore input restrictions and safe replacements. */
class ZoeCloud_Restore_Safety_Test extends WP_UnitTestCase {
	/**
	 * Invoke a private restore helper.
	 *
	 * @param string $name      Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	private function invoke( $name, array $arguments ) {
		$method = new ReflectionMethod( ZoeCloud_Restore_Manager::class, $name );
		$method->setAccessible( true );

		return $method->invokeArgs( new ZoeCloud_Restore_Manager(), $arguments );
	}

	/** Prefix remapping only changes complete table identifiers. */
	public function test_database_prefix_is_remapped() {
		$sql      = "INSERT INTO `old_posts` (`post_title`) VALUES ('old_posts remains data');";
		$manifest = array(
			'database_prefix'      => 'old_',
			'database_table_names' => array( 'old_posts' ),
		);
		$result   = $this->invoke( 'remap_database_prefix', array( $sql, $manifest ) );

		global $wpdb;
		$this->assertStringContainsString( '`' . $wpdb->prefix . 'posts`', $result );
		$this->assertStringContainsString( "'old_posts remains data'", $result );
	}

	/** Statements targeting a table outside the manifest are rejected. */
	public function test_sql_outside_manifest_is_rejected() {
		$allowed = array( '`wp_posts`' );

		$this->assertTrue( $this->invoke( 'is_allowed_restore_statement', array( 'INSERT INTO `wp_posts` (`ID`) VALUES (1)', $allowed ) ) );
		$this->assertFalse( $this->invoke( 'is_allowed_restore_statement', array( 'DROP TABLE `wp_users`', $allowed ) ) );
		$this->assertFalse( $this->invoke( 'is_allowed_restore_statement', array( 'GRANT ALL PRIVILEGES ON *.* TO root', $allowed ) ) );
	}

	/** Serialized structures are changed without instantiating source classes. */
	public function test_serialized_replacement_disallows_classes() {
		$value  = 'O:17:"ZoeCloudFakeClass":1:{s:3:"url";s:18:"https://old.test/a";}';
		$result = $this->invoke( 'replace_preserving_serialized', array( $value, 'https://old.test', 'https://new.test' ) );

		$this->assertStringContainsString( 'https://new.test/a', $result );
		$this->assertStringContainsString( '__PHP_Incomplete_Class', print_r( unserialize( $result, array( 'allowed_classes' => false ) ), true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
	}
}
