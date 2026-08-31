<?php
/**
 * Cloud storage uploads for S3-compatible providers.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- Multipart uploads require bounded streaming and cannot load backup archives into memory.
// phpcs:disable PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- User-configured S3/R2 backup storage is the plugin's disclosed primary feature, never an automatic asset CDN.

/**
 * Handles S3-compatible cloud uploads and deletes.
 */
class ZoeCloud_R2_Service {
	/** Multipart chunk size (8 MiB; S3 requires at least 5 MiB). */
	const MULTIPART_CHUNK_SIZE = 8388608;
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
	 * Verify credentials by sending an authenticated HEAD request to the bucket.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$settings = $this->get_settings();
		$provider = $this->get_provider( $settings );
		$config   = $this->get_provider_config( $settings, $provider );

		if ( ! $this->is_provider_configured( $config ) ) {
			return new WP_Error( 'zoecloud_cloud_not_configured', __( 'Complete all required storage fields first.', 'zoe-cloud' ) );
		}

		$url      = ! empty( $config['path_style'] ) ? trailingslashit( $config['endpoint'] ) . rawurlencode( $config['bucket'] ) . '/' : trailingslashit( $config['endpoint'] );
		$headers  = $this->build_signed_headers( $config, 'HEAD', '', '' );
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'HEAD',
				'timeout' => 20,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'zoecloud_cloud_test_failed', $this->format_cloud_error( $config, $response, __( 'connection test failed.', 'zoe-cloud' ) ) );
		}

		return array(
			'connected' => true,
			'provider'  => $provider,
			'bucket'    => $config['bucket'],
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
		$checksum = hash_file( 'sha256', $file_path );
		$metadata = array(
			'x-amz-meta-zoecloud-sha256'         => $checksum,
			'x-amz-meta-zoecloud-format-version' => (string) ( $manifest['format_version'] ?? 2 ),
		);

		if ( filesize( $file_path ) > self::MULTIPART_CHUNK_SIZE ) {
			return $this->upload_multipart( $config, $key, $file_path, $provider, $filename, $metadata );
		}

		$body = file_get_contents( $file_path );
		if ( false === $body ) {
			return new WP_Error( 'zoecloud_cloud_file_read_failed', __( 'Could not read the backup archive for upload.', 'zoe-cloud' ) );
		}

		$headers = $this->build_signed_headers( $config, 'PUT', $key, $body, false, '', '', $metadata );
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
			'checksum' => $checksum,
		);
	}

	/**
	 * Upload a large archive with the S3-compatible multipart protocol.
	 *
	 * @param array  $config   Provider config.
	 * @param string $key      Object key.
	 * @param string $path     Local file.
	 * @param string $provider Provider key.
	 * @param string $filename Filename.
	 * @param array  $metadata Object metadata.
	 * @return array|WP_Error
	 */
	private function upload_multipart( array $config, $key, $path, $provider, $filename, array $metadata = array() ) {
		$base_url = $this->build_upload_url( $config, $key );
		$query    = 'uploads=';
		$headers  = $this->build_signed_headers( $config, 'POST', $key, '', false, $query, '', $metadata );
		$created  = wp_remote_request(
			$base_url . '?uploads',
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $created ) || wp_remote_retrieve_response_code( $created ) >= 300 ) {
			return is_wp_error( $created ) ? $created : new WP_Error( 'zoecloud_multipart_start_failed', $this->format_cloud_error( $config, $created, __( 'multipart upload could not start.', 'zoe-cloud' ) ) );
		}

		$upload_id = $this->extract_xml_value( wp_remote_retrieve_body( $created ), 'UploadId' );
		if ( ! $upload_id ) {
			return new WP_Error( 'zoecloud_multipart_id_missing', __( 'Cloud storage did not return a multipart upload ID.', 'zoe-cloud' ) );
		}

		$handle = fopen( $path, 'rb' );
		$parts  = array();
		$number = 1;
		$error  = null;

		if ( false === $handle ) {
			$error = new WP_Error( 'zoecloud_cloud_file_read_failed', __( 'Could not read the backup archive for upload.', 'zoe-cloud' ) );
		} else {
			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, self::MULTIPART_CHUNK_SIZE );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$query    = 'partNumber=' . $number . '&uploadId=' . rawurlencode( $upload_id );
				$headers  = $this->build_signed_headers( $config, 'PUT', $key, $chunk, false, $query, 'application/octet-stream' );
				$response = wp_remote_request(
					$base_url . '?' . $query,
					array(
						'method'  => 'PUT',
						'timeout' => 120,
						'headers' => $headers,
						'body'    => $chunk,
					)
				);
				$code     = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
				if ( is_wp_error( $response ) || $code < 200 || $code >= 300 ) {
					$error = is_wp_error( $response ) ? $response : new WP_Error( 'zoecloud_multipart_part_failed', $this->format_cloud_error( $config, $response, __( 'multipart part failed.', 'zoe-cloud' ) ) );
					break;
				}
				$parts[] = array(
					'number' => $number,
					'etag'   => trim( (string) wp_remote_retrieve_header( $response, 'etag' ), '"' ),
				);
				++$number;
			}
			fclose( $handle );
		}

		if ( $error ) {
			$this->abort_multipart( $config, $key, $base_url, $upload_id );
			return $error;
		}

		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $part ) {
			$xml .= '<Part><PartNumber>' . absint( $part['number'] ) . '</PartNumber><ETag>"' . esc_html( $part['etag'] ) . '"</ETag></Part>';
		}
		$xml     .= '</CompleteMultipartUpload>';
		$query    = 'uploadId=' . rawurlencode( $upload_id );
		$headers  = $this->build_signed_headers( $config, 'POST', $key, $xml, false, $query, 'application/xml' );
		$complete = wp_remote_request(
			$base_url . '?' . $query,
			array(
				'method'  => 'POST',
				'timeout' => 120,
				'headers' => $headers,
				'body'    => $xml,
			)
		);

		if ( is_wp_error( $complete ) || wp_remote_retrieve_response_code( $complete ) >= 300 ) {
			$this->abort_multipart( $config, $key, $base_url, $upload_id );
			return is_wp_error( $complete ) ? $complete : new WP_Error( 'zoecloud_multipart_complete_failed', $this->format_cloud_error( $config, $complete, __( 'multipart upload could not complete.', 'zoe-cloud' ) ) );
		}

		return array(
			'provider'  => $provider,
			'bucket'    => $config['bucket'],
			'key'       => $key,
			'filename'  => $filename,
			'endpoint'  => $config['endpoint'],
			'multipart' => true,
			'checksum'  => $metadata['x-amz-meta-zoecloud-sha256'] ?? '',
		);
	}

	/**
	 * Abort an incomplete multipart upload.
	 *
	 * @param array  $config    Provider config.
	 * @param string $key       Object key.
	 * @param string $base_url  Object URL.
	 * @param string $upload_id Multipart upload ID.
	 * @return void
	 */
	private function abort_multipart( array $config, $key, $base_url, $upload_id ) {
		$query   = 'uploadId=' . rawurlencode( $upload_id );
		$headers = $this->build_signed_headers( $config, 'DELETE', $key, '', false, $query );
		wp_remote_request(
			$base_url . '?' . $query,
			array(
				'method'  => 'DELETE',
				'timeout' => 20,
				'headers' => $headers,
			)
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
	 * List ZoeCloud objects available in the active bucket.
	 *
	 * @param string $continuation_token S3 pagination token.
	 * @return array|WP_Error
	 */
	public function list_backups( $continuation_token = '' ) {
		$settings = $this->get_settings();
		$provider = $this->get_provider( $settings );
		$config   = $this->get_provider_config( $settings, $provider );
		if ( ! $this->is_provider_configured( $config ) ) {
			return new WP_Error( 'zoecloud_cloud_not_configured', __( 'Complete all required storage fields first.', 'zoe-cloud' ) );
		}

		$query = 'list-type=2&max-keys=1000';
		if ( '' !== $config['prefix'] ) {
			$query .= '&prefix=' . rawurlencode( trim( $config['prefix'], '/' ) . '/' );
		}
		if ( '' !== $continuation_token ) {
			$query .= '&continuation-token=' . rawurlencode( $continuation_token );
		}
		$headers  = $this->build_signed_headers( $config, 'GET', '', '', true, $query );
		$response = wp_remote_get(
			$this->build_upload_url( $config, '' ) . '?' . $query,
			array(
				'timeout' => 30,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return is_wp_error( $response ) ? $response : new WP_Error( 'zoecloud_cloud_list_failed', $this->format_cloud_error( $config, $response, __( 'list failed.', 'zoe-cloud' ) ) );
		}

		$body    = wp_remote_retrieve_body( $response );
		$objects = array();
		if ( preg_match_all( '/<Contents>(.*?)<\/Contents>/s', $body, $matches ) ) {
			foreach ( $matches[1] as $content ) {
				$key = $this->extract_xml_value( $content, 'Key' );
				if ( '.zip' !== strtolower( substr( $key, -4 ) ) ) {
					continue;
				}
				$objects[] = array(
					'id'            => hash( 'sha256', $provider . '|' . $config['bucket'] . '|' . $key ),
					'provider'      => $provider,
					'bucket'        => $config['bucket'],
					'key'           => $key,
					'filename'      => basename( $key ),
					'size'          => (int) $this->extract_xml_value( $content, 'Size' ),
					'last_modified' => $this->extract_xml_value( $content, 'LastModified' ),
				);
			}
		}

		return array(
			'objects'      => $objects,
			'next_token'   => $this->extract_xml_value( $body, 'NextContinuationToken' ),
			'is_truncated' => 'true' === strtolower( $this->extract_xml_value( $body, 'IsTruncated' ) ),
		);
	}

	/**
	 * Download and authenticate one cloud object into a caller-owned private path.
	 *
	 * @param array  $cloud             Cloud object metadata.
	 * @param string $destination       Private destination path.
	 * @param string $expected_checksum Optional expected SHA-256 checksum.
	 * @return array|WP_Error
	 */
	public function download_backup( array $cloud, $destination, $expected_checksum = '' ) {
		$settings = $this->get_settings();
		$provider = $this->get_provider_from_cloud_record( $cloud );
		$config   = $this->get_provider_config( $settings, $provider );
		if ( ( $cloud['bucket'] ?? '' ) !== $config['bucket'] || empty( $cloud['key'] ) || ! $this->is_provider_configured( $config ) ) {
			return new WP_Error( 'zoecloud_cloud_download_invalid', __( 'Cloud backup metadata does not match the configured destination.', 'zoe-cloud' ) );
		}

		$key          = ltrim( (string) $cloud['key'], '/' );
		$head_headers = $this->build_signed_headers( $config, 'HEAD', $key, '', true );
		$head         = wp_remote_request(
			$this->build_upload_url( $config, $key ),
			array(
				'method'  => 'HEAD',
				'timeout' => 30,
				'headers' => $head_headers,
			)
		);
		if ( is_wp_error( $head ) || wp_remote_retrieve_response_code( $head ) >= 300 ) {
			return is_wp_error( $head ) ? $head : new WP_Error( 'zoecloud_cloud_head_failed', $this->format_cloud_error( $config, $head, __( 'metadata verification failed.', 'zoe-cloud' ) ) );
		}
		$head_checksum = (string) wp_remote_retrieve_header( $head, 'x-amz-meta-zoecloud-sha256' );
		$head_size     = (int) wp_remote_retrieve_header( $head, 'content-length' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $head_checksum ) || ( ! empty( $cloud['size'] ) && (int) $cloud['size'] !== $head_size ) ) {
			return new WP_Error( 'zoecloud_cloud_metadata_invalid', __( 'Cloud backup metadata is missing or inconsistent.', 'zoe-cloud' ) );
		}

		$headers  = $this->build_signed_headers( $config, 'GET', $key, '', true );
		$response = wp_remote_get(
			$this->build_upload_url( $config, $key ),
			array(
				'timeout'  => 300,
				'headers'  => $headers,
				'stream'   => true,
				'filename' => $destination,
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			wp_delete_file( $destination );
			return is_wp_error( $response ) ? $response : new WP_Error( 'zoecloud_cloud_download_failed', $this->format_cloud_error( $config, $response, __( 'download failed.', 'zoe-cloud' ) ) );
		}

		$remote_checksum = (string) wp_remote_retrieve_header( $response, 'x-amz-meta-zoecloud-sha256' );
		$expected        = preg_match( '/^[a-f0-9]{64}$/', $expected_checksum ) ? $expected_checksum : $head_checksum;
		if ( ! hash_equals( $head_checksum, $remote_checksum ) ) {
			wp_delete_file( $destination );
			return new WP_Error( 'zoecloud_cloud_metadata_changed', __( 'Cloud backup metadata changed during download.', 'zoe-cloud' ) );
		}
		$actual = is_readable( $destination ) ? hash_file( 'sha256', $destination ) : '';
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, $actual ) ) {
			wp_delete_file( $destination );
			return new WP_Error( 'zoecloud_cloud_checksum_mismatch', __( 'The downloaded cloud backup failed integrity verification.', 'zoe-cloud' ) );
		}

		return array(
			'path'     => $destination,
			'checksum' => $actual,
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

		$r2_secret                        = $this->crypto->decrypt( $settings['r2_secret_access_key'] );
		$s3_secret                        = $this->crypto->decrypt( $settings['s3_secret_access_key'] );
		$settings['r2_secret_access_key'] = is_wp_error( $r2_secret ) ? '' : $r2_secret;
		$settings['s3_secret_access_key'] = is_wp_error( $s3_secret ) ? '' : $s3_secret;
		$settings['credential_error']     = is_wp_error( $r2_secret ) ? $r2_secret : ( is_wp_error( $s3_secret ) ? $s3_secret : null );

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
	 * @param string $query    Canonical query string.
	 * @param string $content_type  Optional body content type.
	 * @param array  $extra_headers Additional signed headers.
	 * @return array
	 */
	private function build_signed_headers( array $config, $method, $key, $body, $unsigned = false, $query = '', $content_type = '', array $extra_headers = array() ) {
		$timestamp       = gmdate( 'Ymd\THis\Z' );
		$date            = gmdate( 'Ymd' );
		$region          = $config['region'];
		$service         = 's3';
		$host            = wp_parse_url( $config['endpoint'], PHP_URL_HOST );
		$payload_hash    = $unsigned ? 'UNSIGNED-PAYLOAD' : hash( 'sha256', $body );
		$canonical_uri   = ! empty( $config['path_style'] )
			? '/' . rawurlencode( $config['bucket'] ) . '/' . $this->encode_key_path( $key )
			: '/' . $this->encode_key_path( $key );
		$canonical_query = $this->canonicalize_query( $query );

		$headers = array(
			'host'                 => $host,
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $timestamp,
		);
		foreach ( $extra_headers as $name => $value ) {
			$name = strtolower( preg_replace( '/[^a-z0-9-]/', '', (string) $name ) );
			if ( 0 === strpos( $name, 'x-amz-meta-' ) ) {
				$headers[ $name ] = trim( preg_replace( '/\s+/', ' ', (string) $value ) );
			}
		}

		// Only include content-type for requests that carry a body; omitting it for
		// DELETE avoids a SignatureDoesNotMatch because AWS won't see that header.
		if ( '' !== $body ) {
			$headers['content-type'] = $content_type ? $content_type : 'application/zip';
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
				$canonical_query,
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
		foreach ( $extra_headers as $name => $value ) {
			$result[ $name ] = $value;
		}

		return $result;
	}

	/**
	 * Canonicalize an AWS query string.
	 *
	 * @param string $query Query string.
	 * @return string
	 */
	private function canonicalize_query( $query ) {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $part ) {
			list( $key, $value )                           = array_pad( explode( '=', $part, 2 ), 2, '' );
			$pairs[ rawurlencode( rawurldecode( $key ) ) ] = rawurlencode( rawurldecode( $value ) );
		}
		ksort( $pairs );
		$built = array();
		foreach ( $pairs as $key => $value ) {
			$built[] = $key . '=' . $value;
		}
		return implode( '&', $built );
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
