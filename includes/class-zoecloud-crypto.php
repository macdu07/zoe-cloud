<?php
/**
 * Crypto helpers for ZoeCloud.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts and decrypts sensitive option values.
 */
class ZoeCloud_Crypto {
	const PREFIX = 'zcv1:';
	const CIPHER = 'aes-256-gcm';
	const AAD    = 'zoe-cloud/credentials/v1';

	/** Whether authenticated encryption is available. */
	public function is_supported() {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Encrypt a string for storage.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function encrypt( $value ) {
		if ( '' === (string) $value ) {
			return '';
		}
		if ( ! $this->is_supported() ) {
			return new WP_Error( 'zoecloud_crypto_unavailable', __( 'Authenticated encryption is unavailable. Cloud credentials were not saved.', 'zoe-cloud' ) );
		}

		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( (string) $value, self::CIPHER, $this->get_key(), OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, 16 );
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new WP_Error( 'zoecloud_crypto_failed', __( 'Cloud credentials could not be encrypted and were not saved.', 'zoe-cloud' ) );
		}

		$payload = wp_json_encode(
			array(
				'n' => base64_encode( $nonce ),
				't' => base64_encode( $tag ),
				'c' => base64_encode( $ciphertext ),
			)
		);

		return self::PREFIX . base64_encode( $payload );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public function decrypt( $value ) {
		if ( '' === (string) $value ) {
			return '';
		}
		if ( ! $this->is_supported() ) {
			return new WP_Error( 'zoecloud_crypto_unavailable', __( 'Authenticated encryption is unavailable.', 'zoe-cloud' ) );
		}
		if ( 0 !== strpos( (string) $value, self::PREFIX ) ) {
			return new WP_Error( 'zoecloud_crypto_format_invalid', __( 'Stored cloud credentials use an unsupported or unsafe format. Enter them again.', 'zoe-cloud' ) );
		}

		$json    = base64_decode( substr( (string) $value, strlen( self::PREFIX ) ), true );
		$payload = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $payload ) || ! isset( $payload['n'], $payload['t'], $payload['c'] ) ) {
			return new WP_Error( 'zoecloud_crypto_payload_invalid', __( 'Stored cloud credentials are invalid. Enter them again.', 'zoe-cloud' ) );
		}

		$nonce      = base64_decode( (string) $payload['n'], true );
		$tag        = base64_decode( (string) $payload['t'], true );
		$ciphertext = base64_decode( (string) $payload['c'], true );
		if ( false === $nonce || 12 !== strlen( $nonce ) || false === $tag || 16 !== strlen( $tag ) || false === $ciphertext ) {
			return new WP_Error( 'zoecloud_crypto_payload_invalid', __( 'Stored cloud credentials are invalid. Enter them again.', 'zoe-cloud' ) );
		}

		$plain = openssl_decrypt( $ciphertext, self::CIPHER, $this->get_key(), OPENSSL_RAW_DATA, $nonce, $tag, self::AAD );

		return false === $plain
			? new WP_Error( 'zoecloud_crypto_authentication_failed', __( 'Stored cloud credentials could not be authenticated. Enter them again.', 'zoe-cloud' ) )
			: $plain;
	}

	/**
	 * Build a symmetric key from WordPress salts.
	 *
	 * @return string
	 */
	private function get_key() {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );

		return hash_hkdf( 'sha256', $material, 32, 'zoe-cloud/credentials', wp_salt( 'nonce' ) );
	}
}
