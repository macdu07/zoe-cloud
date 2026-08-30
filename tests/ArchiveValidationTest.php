<?php
/**
 * Tests ZoeCloud v2 archive validation.
 *
 * @package ZoeCloud
 */

/** Tests integrity and hostile ZIP handling. */
class ZoeCloud_Archive_Validation_Test extends WP_UnitTestCase {
	/**
	 * Temporary test directory.
	 *
	 * @var string
	 */
	private $directory;

	/** Prepare an isolated directory. */
	public function set_up() {
		parent::set_up();
		$this->directory = sys_get_temp_dir() . '/zoecloud-test-' . wp_generate_uuid4();
		wp_mkdir_p( $this->directory );
	}

	/** Remove generated archives. */
	public function tear_down() {
		foreach ( glob( $this->directory . '/*' ) as $file ) {
			wp_delete_file( $file );
		}
		rmdir( $this->directory );
		parent::tear_down();
	}

	/** A complete v2 archive validates. */
	public function test_valid_v2_archive() {
		$path     = $this->directory . '/valid.zip';
		$sql      = "SET foreign_key_checks = 0;\nSET foreign_key_checks = 1;\n";
		$index    = '';
		$manifest = array(
			'format'               => 'zoecloud-backup',
			'format_version'       => 2,
			'origin'               => array(
				'host'         => 'example.org',
				'home_url'     => 'https://example.org',
				'site_url'     => 'https://example.org',
				'table_prefix' => 'wp_',
			),
			'requirements'         => array(
				'wordpress'  => '6.4',
				'php'        => '8.1',
				'ziparchive' => true,
			),
			'files_count'         => 0,
			'files_size'          => 0,
			'database_tables'     => 0,
			'database_rows'       => 0,
			'database_prefix'      => 'wp_',
			'database_table_names' => array(),
			'checksums'            => array(
				'database.sql'    => hash( 'sha256', $sql ),
				'checksums.jsonl' => hash( 'sha256', $index ),
			),
		);
		$zip      = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addEmptyDir( 'files' );
		$zip->addFromString( 'database.sql', $sql );
		$zip->addFromString( 'checksums.jsonl', $index );
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest ) );
		$zip->close();

		$result = ( new ZoeCloud_Restore_Manager() )->validate_backup( $path );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['valid'] );
	}

	/** Traversal paths are rejected before extraction. */
	public function test_traversal_is_rejected() {
		$path = $this->directory . '/traversal.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addFromString( '../outside.php', '<?php' );
		$zip->close();

		$result = ( new ZoeCloud_Restore_Manager() )->validate_backup( $path );
		$this->assertWPError( $result );
		$this->assertSame( 'zoecloud_restore_unsafe_archive', $result->get_error_code() );
	}

	/** An altered manifest/table count is rejected. */
	public function test_inconsistent_manifest_is_rejected() {
		$path     = $this->directory . '/altered.zip';
		$sql      = "SET foreign_key_checks = 0;\nSET foreign_key_checks = 1;\n";
		$manifest = array(
			'format'               => 'zoecloud-backup',
			'format_version'       => 2,
			'origin'               => array(
				'home_url'     => 'https://example.org',
				'site_url'     => 'https://example.org',
				'table_prefix' => 'wp_',
			),
			'requirements'         => array( 'wordpress' => '6.4', 'php' => '8.1', 'ziparchive' => true ),
			'files_count'          => 0,
			'files_size'           => 0,
			'database_tables'      => 2,
			'database_rows'        => 0,
			'database_prefix'      => 'wp_',
			'database_table_names' => array( 'wp_posts' ),
			'checksums'            => array(
				'database.sql'    => hash( 'sha256', $sql ),
				'checksums.jsonl' => hash( 'sha256', '' ),
			),
		);
		$zip      = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		$zip->addEmptyDir( 'files' );
		$zip->addFromString( 'database.sql', $sql );
		$zip->addFromString( 'checksums.jsonl', '' );
		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest ) );
		$zip->close();

		$result = ( new ZoeCloud_Restore_Manager() )->validate_backup( $path );
		$this->assertWPError( $result );
		$this->assertSame( 'zoecloud_restore_manifest_invalid', $result->get_error_code() );
	}
}
