<?php
/**
 * Google Drive uploads.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZoeCloud_Drive_Service {
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
	 * Upload a backup using a resumable Google Drive session.
	 *
	 * @param string $file_path Backup file path.
	 * @param array  $manifest  Backup manifest.
	 * @return array|WP_Error
	 */
	public function upload_backup( $file_path, array $manifest ) {
		$settings = $this->get_settings();

		if ( empty( $settings['drive_refresh_token'] ) || empty( $settings['drive_client_id'] ) || empty( $settings['drive_client_secret'] ) ) {
			return new WP_Error( 'zoecloud_drive_not_configured', __( 'Google Drive is not configured.', 'zoe-cloud' ) );
		}

		$access_token = $this->get_access_token( $settings );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$domain_folder = $this->ensure_folder_tree( $access_token, $settings, $manifest['domain'] );

		if ( is_wp_error( $domain_folder ) ) {
			return $domain_folder;
		}

		$session_url = $this->start_resumable_session(
			$access_token,
			$domain_folder,
			basename( $file_path ),
			array(
				'manifest' => wp_json_encode( $manifest ),
			)
		);

		if ( is_wp_error( $session_url ) ) {
			return $session_url;
		}

		$upload = wp_remote_request(
			$session_url,
			array(
				'method'  => 'PUT',
				'timeout' => 120,
				'headers' => array(
					'Content-Length' => (string) filesize( $file_path ),
					'Content-Type'   => 'application/zip',
				),
				'body'    => file_get_contents( $file_path ),
			)
		);

		if ( is_wp_error( $upload ) ) {
			return $upload;
		}

		$code = wp_remote_retrieve_response_code( $upload );
		$body = json_decode( wp_remote_retrieve_body( $upload ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'zoecloud_drive_upload_failed', __( 'Drive upload failed.', 'zoe-cloud' ), $body );
		}

		return array(
			'file_id'   => isset( $body['id'] ) ? $body['id'] : '',
			'file_name' => isset( $body['name'] ) ? $body['name'] : basename( $file_path ),
		);
	}

	/**
	 * Return a summary about Drive configuration.
	 *
	 * @return array
	 */
	public function get_status() {
		$settings = $this->get_settings();

		return array(
			'configured'   => ! empty( $settings['drive_client_id'] ) && ! empty( $settings['drive_client_secret'] ) && ! empty( $settings['drive_refresh_token'] ),
			'project_name' => $settings['drive_project_name'],
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
				'drive_client_id'     => '',
				'drive_client_secret' => '',
				'drive_refresh_token' => '',
				'drive_project_name'  => get_bloginfo( 'name' ),
			)
		);

		$settings['drive_client_secret'] = $this->crypto->decrypt( $settings['drive_client_secret'] );
		$settings['drive_refresh_token'] = $this->crypto->decrypt( $settings['drive_refresh_token'] );

		return $settings;
	}

	/**
	 * Refresh OAuth access token.
	 *
	 * @param array $settings Plugin settings.
	 * @return string|WP_Error
	 */
	private function get_access_token( array $settings ) {
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => $settings['drive_client_id'],
					'client_secret' => $settings['drive_client_secret'],
					'refresh_token' => $settings['drive_refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			return new WP_Error( 'zoecloud_drive_token_failed', __( 'Could not refresh Google Drive token.', 'zoe-cloud' ), $data );
		}

		return $data['access_token'];
	}

	/**
	 * Ensure the nested backup folder structure exists.
	 *
	 * @param string $access_token Access token.
	 * @param array  $settings     Plugin settings.
	 * @param string $domain       Site domain.
	 * @return string|WP_Error
	 */
	private function ensure_folder_tree( $access_token, array $settings, $domain ) {
		$root_folder    = $this->ensure_folder( $access_token, 'ZoeCloud Backups' );
		$project_folder = is_wp_error( $root_folder ) ? $root_folder : $this->ensure_folder( $access_token, $settings['drive_project_name'], $root_folder );
		$domain_folder  = is_wp_error( $project_folder ) ? $project_folder : $this->ensure_folder( $access_token, $domain, $project_folder );

		return $domain_folder;
	}

	/**
	 * Create or fetch a folder.
	 *
	 * @param string      $access_token Access token.
	 * @param string      $name         Folder name.
	 * @param string|null $parent_id    Parent folder.
	 * @return string|WP_Error
	 */
	private function ensure_folder( $access_token, $name, $parent_id = null ) {
		$escaped_name = str_replace( "'", "\\'", (string) $name );
		$query = sprintf(
			"name='%s' and mimeType='application/vnd.google-apps.folder' and trashed=false",
			$escaped_name
		);

		if ( $parent_id ) {
			$query .= sprintf( " and '%s' in parents", str_replace( "'", "\\'", (string) $parent_id ) );
		}

		$lookup = wp_remote_get(
			'https://www.googleapis.com/drive/v3/files?q=' . rawurlencode( $query ) . '&fields=files(id,name)',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $lookup ) ) {
			return $lookup;
		}

		$data = json_decode( wp_remote_retrieve_body( $lookup ), true );

		if ( ! empty( $data['files'][0]['id'] ) ) {
			return $data['files'][0]['id'];
		}

		$body = array(
			'name'     => $name,
			'mimeType' => 'application/vnd.google-apps.folder',
		);

		if ( $parent_id ) {
			$body['parents'] = array( $parent_id );
		}

		$create = wp_remote_post(
			'https://www.googleapis.com/drive/v3/files',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $create ) ) {
			return $create;
		}

		$data = json_decode( wp_remote_retrieve_body( $create ), true );

		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'zoecloud_drive_folder_failed', __( 'Could not create Google Drive folder.', 'zoe-cloud' ), $data );
		}

		return $data['id'];
	}

	/**
	 * Create a resumable upload session.
	 *
	 * @param string $access_token Access token.
	 * @param string $folder_id    Drive folder.
	 * @param string $filename     Backup filename.
	 * @param array  $properties   App metadata.
	 * @return string|WP_Error
	 */
	private function start_resumable_session( $access_token, $folder_id, $filename, array $properties ) {
		$response = wp_remote_post(
			'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization'       => 'Bearer ' . $access_token,
					'Content-Type'        => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type' => 'application/zip',
				),
				'body'    => wp_json_encode(
					array(
						'name'       => $filename,
						'parents'    => array( $folder_id ),
						'appProperties' => $properties,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( empty( $location ) ) {
			return new WP_Error( 'zoecloud_drive_session_failed', __( 'Could not create resumable Drive session.', 'zoe-cloud' ) );
		}

		return $location;
	}
}
