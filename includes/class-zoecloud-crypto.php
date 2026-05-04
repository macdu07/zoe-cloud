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
	/**
	 * Encrypt a string for storage.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function encrypt( $value ) {
		if ( empty( $value ) || ! function_exists( 'openssl_encrypt' ) ) {
			return (string) $value;
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = random_bytes( $iv_length );
		$key       = $this->get_key();
		$cipher    = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return (string) $value;
		}

		return base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public function decrypt( $value ) {
		if ( empty( $value ) || ! function_exists( 'openssl_decrypt' ) ) {
			return (string) $value;
		}

		$payload = base64_decode( (string) $value, true );

		if ( false === $payload ) {
			return (string) $value;
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = substr( $payload, 0, $iv_length );
		$cipher    = substr( $payload, $iv_length );
		$key       = $this->get_key();
		$plain     = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $plain ? (string) $value : $plain;
	}

	/**
	 * Build a symmetric key from WordPress salts.
	 *
	 * @return string
	 */
	private function get_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}
}
