<?php
/**
 * Cloudflare R2 uploads.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZoeCloud_R2_Service {
	/**
	 * Option key.
	 *
	 * @var string
	 */
	private $option_name = 'zoecloud_settings';

	/**
	 * Crypto service.
	 *
	 * @var ZoeCloud_Crypto
	 */
	private $crypto;

	/**
	 * Constructor.
	 *
	 * @param ZoeCloud_Crypto $crypto Crypto service.
	 */
	public function __construct( ZoeCloud_Crypto $crypto ) {
		$this->crypto = $crypto;
	}

	/**
	 * Return a summary about R2 configuration.
	 *
	 * @return array
	 */
	public function get_status() {
		$settings = $this->get_settings();

		return array(
			'provider'     => 'r2',
			'configured'   => ! empty( $settings['r2_account_id'] ) && ! empty( $settings['r2_access_key_id'] ) && ! empty( $settings['r2_secret_access_key'] ) && ! empty( $settings['r2_bucket'] ),
			'bucket'       => $settings['r2_bucket'],
			'prefix'       => $settings['r2_prefix'],
			'endpoint'     => $this->get_endpoint( $settings ),
		);
	}

	/**
	 * Upload a backup to Cloudflare R2.
	 *
	 * @param string $file_path Backup file path.
	 * @param array  $manifest  Backup manifest.
	 * @return array|WP_Error
	 */
	public function upload_backup( $file_path, array $manifest ) {
		$settings = $this->get_settings();

		if ( empty( $settings['r2_account_id'] ) || empty( $settings['r2_access_key_id'] ) || empty( $settings['r2_secret_access_key'] ) || empty( $settings['r2_bucket'] ) ) {
			return new WP_Error( 'zoecloud_r2_not_configured', __( 'Cloudflare R2 is not configured.', 'zoe-cloud' ) );
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'zoecloud_r2_file_missing', __( 'Backup archive is not readable.', 'zoe-cloud' ) );
		}

		$filename = basename( $file_path );
		$key      = $this->build_object_key( $settings, $manifest, $filename );
		$body     = file_get_contents( $file_path );

		if ( false === $body ) {
			return new WP_Error( 'zoecloud_r2_file_read_failed', __( 'Could not read the backup archive for upload.', 'zoe-cloud' ) );
		}

		$headers = $this->build_signed_headers( $settings, 'PUT', $key, $body );
		$url     = trailingslashit( $this->get_endpoint( $settings ) ) . rawurlencode( $settings['r2_bucket'] ) . '/' . $this->encode_key_path( $key );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 120,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'zoecloud_r2_upload_failed', __( 'R2 upload failed.', 'zoe-cloud' ), wp_remote_retrieve_body( $response ) );
		}

		return array(
			'provider' => 'r2',
			'bucket'   => $settings['r2_bucket'],
			'key'      => $key,
			'filename' => $filename,
			'endpoint' => $this->get_endpoint( $settings ),
		);
	}

	/**
	 * Parse settings and decrypt stored secrets.
	 *
	 * @return array
	 */
	private function get_settings() {
		$settings = wp_parse_args(
			get_option( $this->option_name, array() ),
			array(
				'r2_account_id'        => '',
				'r2_access_key_id'     => '',
				'r2_secret_access_key' => '',
				'r2_bucket'            => '',
				'r2_prefix'            => 'zoe-cloud',
			)
		);

		$settings['r2_secret_access_key'] = $this->crypto->decrypt( $settings['r2_secret_access_key'] );

		return $settings;
	}

	/**
	 * Build the R2 S3-compatible endpoint.
	 *
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function get_endpoint( array $settings ) {
		$account_id = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $settings['r2_account_id'] );

		return $account_id ? 'https://' . $account_id . '.r2.cloudflarestorage.com' : '';
	}

	/**
	 * Build object key.
	 *
	 * @param array  $settings Plugin settings.
	 * @param array  $manifest Backup manifest.
	 * @param string $filename Backup filename.
	 * @return string
	 */
	private function build_object_key( array $settings, array $manifest, $filename ) {
		$prefix = trim( (string) $settings['r2_prefix'], '/' );
		$domain = sanitize_title_with_dashes( (string) ( $manifest['domain'] ?? wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$parts  = array_filter( array( $prefix, $domain, $filename ) );

		return implode( '/', $parts );
	}

	/**
	 * Build AWS Signature V4 headers for R2.
	 *
	 * @param array  $settings Plugin settings.
	 * @param string $method   HTTP method.
	 * @param string $key      Object key.
	 * @param string $body     Request body.
	 * @return array
	 */
	private function build_signed_headers( array $settings, $method, $key, $body ) {
		$timestamp     = gmdate( 'Ymd\THis\Z' );
		$date          = gmdate( 'Ymd' );
		$region        = 'auto';
		$service       = 's3';
		$host          = wp_parse_url( $this->get_endpoint( $settings ), PHP_URL_HOST );
		$payload_hash  = hash( 'sha256', $body );
		$canonical_uri = '/' . rawurlencode( $settings['r2_bucket'] ) . '/' . $this->encode_key_path( $key );

		$headers = array(
			'content-type'         => 'application/zip',
			'host'                 => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $timestamp,
		);

		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( (string) $value ) . "\n";
		}

		$signed_headers    = implode( ';', array_keys( $headers ) );
		$credential_scope  = $date . '/' . $region . '/' . $service . '/aws4_request';
		$canonical_request = implode(
			"\n",
			array(
				$method,
				$canonical_uri,
				'',
				$canonical_headers,
				$signed_headers,
				$payload_hash,
			)
		);
		$string_to_sign    = implode(
			"\n",
			array(
				'AWS4-HMAC-SHA256',
				$timestamp,
				$credential_scope,
				hash( 'sha256', $canonical_request ),
			)
		);
		$signing_key       = $this->get_signing_key( $settings['r2_secret_access_key'], $date, $region, $service );
		$signature         = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return array(
			'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . $settings['r2_access_key_id'] . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
			'Content-Type'         => $headers['content-type'],
			'Host'                 => $headers['host'],
			'X-Amz-Content-Sha256' => $headers['x-amz-content-sha256'],
			'X-Amz-Date'           => $headers['x-amz-date'],
		);
	}

	/**
	 * Build AWS signing key.
	 *
	 * @param string $secret  Secret access key.
	 * @param string $date    Date.
	 * @param string $region  Region.
	 * @param string $service Service.
	 * @return string
	 */
	private function get_signing_key( $secret, $date, $region, $service ) {
		$date_key    = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$region_key  = hash_hmac( 'sha256', $region, $date_key, true );
		$service_key = hash_hmac( 'sha256', $service, $region_key, true );

		return hash_hmac( 'sha256', 'aws4_request', $service_key, true );
	}

	/**
	 * URL-encode an object key preserving path separators.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	private function encode_key_path( $key ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( (string) $key, '/' ) ) ) );
	}
}
