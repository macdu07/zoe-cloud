<?php
/**
 * Cloud storage uploads for S3-compatible providers.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles S3-compatible cloud uploads and deletes.
 */
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
	 * Return a summary about cloud storage configuration.
	 *
	 * @return array
	 */
	public function get_status() {
		$settings = $this->get_settings();
		$provider = $this->get_provider( $settings );
		$config   = $this->get_provider_config( $settings, $provider );

		return array(
			'provider'   => $provider,
			'label'      => $config['label'],
			'configured' => $this->is_configured( $settings, $provider ),
			'bucket'     => $config['bucket'],
			'prefix'     => $config['prefix'],
			'endpoint'   => $config['endpoint'],
		);
	}

	/**
	 * Upload a backup to the configured cloud provider.
	 *
	 * @param string $file_path Backup file path.
	 * @param array  $manifest  Backup manifest.
	 * @return array|WP_Error
	 */
	public function upload_backup( $file_path, array $manifest ) {
		$settings = $this->get_settings();
		$provider = $this->get_provider( $settings );
		$config   = $this->get_provider_config( $settings, $provider );

		if ( ! $this->is_configured( $settings, $provider ) ) {
			/* translators: %s: Cloud storage provider label. */
			return new WP_Error( 'zoecloud_cloud_not_configured', sprintf( __( '%s is not configured.', 'zoe-cloud' ), $config['label'] ) );
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'zoecloud_cloud_file_missing', __( 'Backup archive is not readable.', 'zoe-cloud' ) );
		}

		$filename = basename( $file_path );
		$key      = $this->build_object_key( $config['prefix'], $manifest, $filename );
		$body     = file_get_contents( $file_path );

		if ( false === $body ) {
			return new WP_Error( 'zoecloud_cloud_file_read_failed', __( 'Could not read the backup archive for upload.', 'zoe-cloud' ) );
		}

		$headers = $this->build_signed_headers( $config, 'PUT', $key, $body );
		$url     = $this->build_upload_url( $config, $key );

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
			return new WP_Error(
				'zoecloud_cloud_upload_failed',
				$this->format_cloud_error( $config, $response, __( 'upload failed.', 'zoe-cloud' ) ),
				wp_remote_retrieve_body( $response )
			);
		}

		return array(
			'provider' => $provider,
			'bucket'   => $config['bucket'],
			'key'      => $key,
			'filename' => $filename,
			'endpoint' => $config['endpoint'],
		);
	}

	/**
	 * Delete a stored cloud backup.
	 *
	 * @param array $cloud Cloud metadata saved with the backup record.
	 * @return true|WP_Error
	 */
	public function delete_backup( array $cloud ) {
		$settings = $this->get_settings();
		$provider = $this->get_provider_from_cloud_record( $cloud );
		$config   = $this->get_provider_config( $settings, $provider );

		if ( empty( $cloud['key'] ) || empty( $cloud['bucket'] ) ) {
			return new WP_Error( 'zoecloud_cloud_delete_missing_metadata', __( 'Cloud backup metadata is incomplete.', 'zoe-cloud' ) );
		}

		if ( $cloud['bucket'] !== $config['bucket'] ) {
			$config['bucket'] = sanitize_text_field( (string) $cloud['bucket'] );
			if ( 's3' === $provider ) {
				$config['endpoint'] = $config['bucket'] ? 'https://' . $config['bucket'] . '.s3.' . $config['region'] . '.amazonaws.com' : '';
			}
		}

		if ( ! $this->is_provider_configured( $config ) ) {
			/* translators: %s: Cloud storage provider label. */
			return new WP_Error( 'zoecloud_cloud_delete_not_configured', sprintf( __( '%s is not configured.', 'zoe-cloud' ), $config['label'] ) );
		}

		$key     = ltrim( (string) $cloud['key'], '/' );
		$headers = $this->build_signed_headers( $config, 'DELETE', $key, '', true );
		$url     = $this->build_upload_url( $config, $key );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 60,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, array( 200, 202, 204, 404 ), true ) ) {
			return true;
		}

		return new WP_Error(
			'zoecloud_cloud_delete_failed',
			$this->format_cloud_error( $config, $response, __( 'delete failed.', 'zoe-cloud' ) ),
			wp_remote_retrieve_body( $response )
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
				'storage_provider'     => 'r2',
				'r2_account_id'        => '',
				'r2_access_key_id'     => '',
				'r2_secret_access_key' => '',
				'r2_bucket'            => '',
				'r2_prefix'            => 'zoe-cloud',
				's3_access_key_id'     => '',
				's3_secret_access_key' => '',
				's3_bucket'            => '',
				's3_region'            => 'us-east-1',
				's3_prefix'            => '',
			)
		);

		$settings['r2_secret_access_key'] = $this->crypto->decrypt( $settings['r2_secret_access_key'] );
		$settings['s3_secret_access_key'] = $this->crypto->decrypt( $settings['s3_secret_access_key'] );

		return $settings;
	}

	/**
	 * Return the selected provider.
	 *
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	private function get_provider( array $settings ) {
		return 's3' === ( $settings['storage_provider'] ?? 'r2' ) ? 's3' : 'r2';
	}

	/**
	 * Resolve the provider used by a saved cloud backup record.
	 *
	 * @param array $cloud Cloud metadata.
	 * @return string
	 */
	private function get_provider_from_cloud_record( array $cloud ) {
		return 's3' === ( $cloud['provider'] ?? 'r2' ) ? 's3' : 'r2';
	}

	/**
	 * Return provider-specific connection config.
	 *
	 * @param array  $settings Plugin settings.
	 * @param string $provider Provider key.
	 * @return array
	 */
	private function get_provider_config( array $settings, $provider ) {
		if ( 's3' === $provider ) {
			$region = $this->sanitize_region( $settings['s3_region'] );
			$bucket = sanitize_text_field( (string) $settings['s3_bucket'] );

			return array(
				'provider'   => 's3',
				'label'      => __( 'AWS S3', 'zoe-cloud' ),
				'access_key' => (string) $settings['s3_access_key_id'],
				'secret_key' => (string) $settings['s3_secret_access_key'],
				'bucket'     => $bucket,
				'prefix'     => (string) $settings['s3_prefix'],
				'region'     => $region,
				'endpoint'   => $bucket ? 'https://' . $bucket . '.s3.' . $region . '.amazonaws.com' : '',
				'path_style' => false,
			);
		}

		$account_id = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $settings['r2_account_id'] );

		return array(
			'provider'   => 'r2',
			'label'      => __( 'Cloudflare R2', 'zoe-cloud' ),
			'access_key' => (string) $settings['r2_access_key_id'],
			'secret_key' => (string) $settings['r2_secret_access_key'],
			'bucket'     => (string) $settings['r2_bucket'],
			'prefix'     => (string) $settings['r2_prefix'],
			'region'     => 'auto',
			'endpoint'   => $account_id ? 'https://' . $account_id . '.r2.cloudflarestorage.com' : '',
			'path_style' => true,
		);
	}

	/**
	 * Check whether a provider has the minimum required settings.
	 *
	 * @param array  $settings Plugin settings.
	 * @param string $provider Provider key.
	 * @return bool
	 */
	private function is_configured( array $settings, $provider ) {
		$config = $this->get_provider_config( $settings, $provider );

		return $this->is_provider_configured( $config );
	}

	/**
	 * Check whether provider config can make signed requests.
	 *
	 * @param array $config Provider config.
	 * @return bool
	 */
	private function is_provider_configured( array $config ) {
		return ! empty( $config['endpoint'] ) && ! empty( $config['access_key'] ) && ! empty( $config['secret_key'] ) && ! empty( $config['bucket'] );
	}

	/**
	 * Build object key.
	 *
	 * @param string $prefix   Object prefix.
	 * @param array  $manifest Backup manifest.
	 * @param string $filename Backup filename.
	 * @return string
	 */
	private function build_object_key( $prefix, array $manifest, $filename ) {
		$prefix = trim( (string) $prefix, '/' );
		$domain = sanitize_title_with_dashes( (string) ( $manifest['domain'] ?? wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$parts  = array_filter( array( $prefix, $domain, $filename ) );

		return implode( '/', $parts );
	}

	/**
	 * Build the upload URL.
	 *
	 * @param array  $config Provider config.
	 * @param string $key    Object key.
	 * @return string
	 */
	private function build_upload_url( array $config, $key ) {
		if ( ! empty( $config['path_style'] ) ) {
			return trailingslashit( $config['endpoint'] ) . rawurlencode( $config['bucket'] ) . '/' . $this->encode_key_path( $key );
		}

		return trailingslashit( $config['endpoint'] ) . $this->encode_key_path( $key );
	}

	/**
	 * Build AWS Signature V4 headers.
	 *
	 * @param array  $config   Provider config.
	 * @param string $method   HTTP method.
	 * @param string $key      Object key.
	 * @param string $body     Request body.
	 * @param bool   $unsigned Whether to use S3's unsigned payload marker.
	 * @return array
	 */
	private function build_signed_headers( array $config, $method, $key, $body, $unsigned = false ) {
		$timestamp     = gmdate( 'Ymd\THis\Z' );
		$date          = gmdate( 'Ymd' );
		$region        = $config['region'];
		$service       = 's3';
		$host          = wp_parse_url( $config['endpoint'], PHP_URL_HOST );
		$payload_hash  = $unsigned ? 'UNSIGNED-PAYLOAD' : hash( 'sha256', $body );
		$canonical_uri = ! empty( $config['path_style'] )
			? '/' . rawurlencode( $config['bucket'] ) . '/' . $this->encode_key_path( $key )
			: '/' . $this->encode_key_path( $key );

		$headers = array(
			'host'                 => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $timestamp,
		);

		// Only include content-type for requests that carry a body; omitting it for
		// DELETE avoids a SignatureDoesNotMatch because AWS won't see that header.
		if ( '' !== $body ) {
			$headers['content-type'] = 'application/zip';
		}

		ksort( $headers );

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
		$signing_key       = $this->get_signing_key( $config['secret_key'], $date, $region, $service );
		$signature         = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$result = array(
			'Authorization'        => 'AWS4-HMAC-SHA256 Credential=' . $config['access_key'] . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
			'Host'                 => $headers['host'],
			'X-Amz-Content-Sha256' => $headers['x-amz-content-sha256'],
			'X-Amz-Date'           => $headers['x-amz-date'],
		);

		if ( isset( $headers['content-type'] ) ) {
			$result['Content-Type'] = $headers['content-type'];
		}

		return $result;
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

	/**
	 * Build a useful cloud error from an S3-compatible XML response.
	 *
	 * @param array  $config   Provider config.
	 * @param array  $response HTTP response.
	 * @param string $action  Failed action.
	 * @return string
	 */
	private function format_cloud_error( array $config, array $response, $action ) {
		$status          = wp_remote_retrieve_response_code( $response );
		$body            = wp_remote_retrieve_body( $response );
		$aws_code        = $this->extract_xml_value( $body, 'Code' );
		$aws_message     = $this->extract_xml_value( $body, 'Message' );
		$expected_region = wp_remote_retrieve_header( $response, 'x-amz-bucket-region' );
		$parts           = array( trim( $config['label'] . ' ' . $action ) );

		if ( $status ) {
			/* translators: %d: HTTP status code. */
			$parts[] = sprintf( __( 'HTTP %d.', 'zoe-cloud' ), $status );
		}

		if ( $aws_code ) {
			$parts[] = $aws_code . '.';
		}

		if ( $aws_message ) {
			$parts[] = $aws_message;
		}

		if ( $expected_region && $expected_region !== $config['region'] ) {
			/* translators: 1: Expected bucket region. 2: Configured bucket region. */
			$parts[] = sprintf( __( 'Bucket region appears to be %1$s, but ZoeCloud is configured with %2$s.', 'zoe-cloud' ), $expected_region, $config['region'] );
		}

		return implode( ' ', array_filter( $parts ) );
	}

	/**
	 * Extract a simple XML element value without requiring SimpleXML.
	 *
	 * @param string $xml XML body.
	 * @param string $tag Element name.
	 * @return string
	 */
	private function extract_xml_value( $xml, $tag ) {
		if ( ! is_string( $xml ) || '' === $xml ) {
			return '';
		}

		if ( ! preg_match( '/<' . preg_quote( $tag, '/' ) . '>(.*?)<\/' . preg_quote( $tag, '/' ) . '>/s', $xml, $matches ) ) {
			return '';
		}

		return trim( html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Normalize an AWS region string.
	 *
	 * @param string $region Raw region.
	 * @return string
	 */
	private function sanitize_region( $region ) {
		$region = strtolower( preg_replace( '/[^a-z0-9-]/', '', (string) $region ) );

		return $region ? $region : 'us-east-1';
	}
}
