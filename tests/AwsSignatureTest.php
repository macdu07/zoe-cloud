<?php
/**
 * Tests the auditable AWS Signature V4 implementation.
 *
 * @package ZoeCloud
 */

/** Tests signed S3-compatible requests. */
class ZoeCloud_Aws_Signature_Test extends WP_UnitTestCase {
	/** Signed metadata is covered without exposing the secret. */
	public function test_checksum_metadata_is_signed() {
		$config = array(
			'endpoint'   => 'https://s3.example.test',
			'bucket'     => 'backups',
			'region'     => 'us-east-1',
			'path_style' => true,
			'access_key' => 'ACCESS123',
			'secret_key' => 'never-return-this-secret',
		);
		$method = new ReflectionMethod( ZoeCloud_R2_Service::class, 'build_signed_headers' );
		$method->setAccessible( true );
		$headers = $method->invokeArgs(
			new ZoeCloud_R2_Service( new ZoeCloud_Crypto() ),
			array( $config, 'PUT', 'site/archive.zip', 'payload', false, '', 'application/zip', array( 'x-amz-meta-zoecloud-sha256' => str_repeat( 'a', 64 ) ) )
		);

		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 Credential=ACCESS123/', $headers['Authorization'] );
		$this->assertStringContainsString( 'x-amz-meta-zoecloud-sha256', $headers['Authorization'] );
		$this->assertArrayHasKey( 'x-amz-meta-zoecloud-sha256', $headers );
		$this->assertStringNotContainsString( $config['secret_key'], wp_json_encode( $headers ) );
	}
}
