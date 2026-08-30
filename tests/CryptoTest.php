<?php
/**
 * Tests authenticated credential encryption.
 *
 * @package ZoeCloud
 */

/** Tests encryption round trips and tamper detection. */
class ZoeCloud_Crypto_Test extends WP_UnitTestCase {
	/** Secrets round-trip through the authenticated v1 envelope. */
	public function test_authenticated_round_trip() {
		$crypto    = new ZoeCloud_Crypto();
		$encrypted = $crypto->encrypt( 'not-a-real-secret' );

		$this->assertIsString( $encrypted );
		$this->assertStringStartsWith( 'zcv1:', $encrypted );
		$this->assertSame( 'not-a-real-secret', $crypto->decrypt( $encrypted ) );
	}

	/** Modified ciphertext is never returned as plaintext. */
	public function test_tampering_is_rejected() {
		$crypto    = new ZoeCloud_Crypto();
		$encrypted = $crypto->encrypt( 'not-a-real-secret' );
		$last      = substr( $encrypted, -1 );
		$tampered  = substr( $encrypted, 0, -1 ) . ( 'A' === $last ? 'B' : 'A' );

		$this->assertWPError( $crypto->decrypt( $tampered ) );
	}

	/** Unsafe legacy plaintext is rejected. */
	public function test_plaintext_is_rejected() {
		$this->assertWPError( ( new ZoeCloud_Crypto() )->decrypt( 'plaintext-secret' ) );
	}
}
