<?php
/**
 * Admin UI.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ZoeCloud_Admin {
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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add plugin page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'ZoeCloud', 'zoe-cloud' ),
			__( 'ZoeCloud', 'zoe-cloud' ),
			'manage_options',
			'zoecloud',
			array( $this, 'render_page' ),
			'dashicons-cloud',
			58
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'zoecloud_settings',
			'zoecloud_settings',
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Secure settings before storage.
	 *
	 * @param array $settings Raw settings.
	 * @return array
	 */
	public function sanitize_settings( $settings ) {
		$current = get_option( 'zoecloud_settings', array() );
		$clean   = array(
			'drive_client_id'     => sanitize_text_field( $settings['drive_client_id'] ?? '' ),
			'drive_client_secret' => '',
			'drive_refresh_token' => '',
			'drive_project_name'  => sanitize_text_field( $settings['drive_project_name'] ?? get_bloginfo( 'name' ) ),
			'retention_limit'     => max( 1, absint( $settings['retention_limit'] ?? 10 ) ),
			'schedule'            => sanitize_text_field( $settings['schedule'] ?? 'daily' ),
			'auto_upload_drive'   => ! empty( $settings['auto_upload_drive'] ) ? 1 : 0,
		);

		$client_secret = trim( (string) ( $settings['drive_client_secret'] ?? '' ) );
		$refresh_token = trim( (string) ( $settings['drive_refresh_token'] ?? '' ) );

		$clean['drive_client_secret'] = '' !== $client_secret
			? $this->crypto->encrypt( $client_secret )
			: ( $current['drive_client_secret'] ?? '' );

		$clean['drive_refresh_token'] = '' !== $refresh_token
			? $this->crypto->encrypt( $refresh_token )
			: ( $current['drive_refresh_token'] ?? '' );

		return $clean;
	}

	/**
	 * Load admin assets.
	 *
	 * @param string $hook_suffix Page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_zoecloud' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'zoecloud-admin',
			ZOECLOUD_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ZOECLOUD_VERSION
		);

		wp_enqueue_script(
			'zoecloud-admin',
			ZOECLOUD_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			ZOECLOUD_VERSION,
			true
		);

		wp_localize_script(
			'zoecloud-admin',
			'zoecloudAdmin',
			array(
				'root'  => esc_url_raw( rest_url( 'zoecloud/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Render plugin screen.
	 *
	 * @return void
	 */
	public function render_page() {
		$settings = wp_parse_args(
			get_option( 'zoecloud_settings', array() ),
			array(
				'drive_client_id'    => '',
				'drive_project_name' => get_bloginfo( 'name' ),
				'retention_limit'    => 10,
				'schedule'           => 'daily',
				'auto_upload_drive'  => 1,
			)
		);
		?>
		<div class="wrap zoecloud-admin">
			<h1><?php esc_html_e( 'ZoeCloud', 'zoe-cloud' ); ?></h1>
			<p><?php esc_html_e( 'Create portable backups, send them to Google Drive, and prepare restores from a single dashboard.', 'zoe-cloud' ); ?></p>

			<div class="zoecloud-grid">
				<section class="zoecloud-card">
					<h2><?php esc_html_e( 'Backup Dashboard', 'zoe-cloud' ); ?></h2>
					<p id="zoecloud-status-text"><?php esc_html_e( 'Loading status…', 'zoe-cloud' ); ?></p>
					<div class="zoecloud-actions">
						<button type="button" class="button button-primary" id="zoecloud-create-backup"><?php esc_html_e( 'Create Backup', 'zoe-cloud' ); ?></button>
						<label><input type="checkbox" id="zoecloud-include-core"> <?php esc_html_e( 'Include WordPress core', 'zoe-cloud' ); ?></label>
						<label><input type="checkbox" id="zoecloud-upload-drive" checked> <?php esc_html_e( 'Upload to Drive', 'zoe-cloud' ); ?></label>
					</div>
					<div id="zoecloud-feedback" class="zoecloud-feedback"></div>
				</section>

				<section class="zoecloud-card">
					<h2><?php esc_html_e( 'Settings', 'zoe-cloud' ); ?></h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'zoecloud_settings' ); ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="zoecloud_drive_client_id"><?php esc_html_e( 'Google Client ID', 'zoe-cloud' ); ?></label></th>
								<td><input type="text" id="zoecloud_drive_client_id" name="zoecloud_settings[drive_client_id]" class="regular-text" value="<?php echo esc_attr( $settings['drive_client_id'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="zoecloud_drive_client_secret"><?php esc_html_e( 'Google Client Secret', 'zoe-cloud' ); ?></label></th>
								<td><input type="password" id="zoecloud_drive_client_secret" name="zoecloud_settings[drive_client_secret]" class="regular-text" value=""></td>
							</tr>
							<tr>
								<th scope="row"><label for="zoecloud_drive_refresh_token"><?php esc_html_e( 'Google Refresh Token', 'zoe-cloud' ); ?></label></th>
								<td><input type="password" id="zoecloud_drive_refresh_token" name="zoecloud_settings[drive_refresh_token]" class="regular-text" value=""></td>
							</tr>
							<tr>
								<th scope="row"><label for="zoecloud_drive_project_name"><?php esc_html_e( 'Drive Project Folder', 'zoe-cloud' ); ?></label></th>
								<td><input type="text" id="zoecloud_drive_project_name" name="zoecloud_settings[drive_project_name]" class="regular-text" value="<?php echo esc_attr( $settings['drive_project_name'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="zoecloud_retention_limit"><?php esc_html_e( 'Retention Limit', 'zoe-cloud' ); ?></label></th>
								<td><input type="number" id="zoecloud_retention_limit" name="zoecloud_settings[retention_limit]" min="1" value="<?php echo esc_attr( $settings['retention_limit'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="zoecloud_schedule"><?php esc_html_e( 'Schedule', 'zoe-cloud' ); ?></label></th>
								<td>
									<select id="zoecloud_schedule" name="zoecloud_settings[schedule]">
										<option value="hourly" <?php selected( $settings['schedule'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'zoe-cloud' ); ?></option>
										<option value="twicedaily" <?php selected( $settings['schedule'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'zoe-cloud' ); ?></option>
										<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'zoe-cloud' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Auto-upload to Drive', 'zoe-cloud' ); ?></th>
								<td><label><input type="checkbox" name="zoecloud_settings[auto_upload_drive]" value="1" <?php checked( $settings['auto_upload_drive'], 1 ); ?>> <?php esc_html_e( 'Upload every scheduled backup', 'zoe-cloud' ); ?></label></td>
							</tr>
						</table>
						<?php submit_button(); ?>
					</form>
				</section>
			</div>

			<section class="zoecloud-card zoecloud-list">
				<h2><?php esc_html_e( 'Backups', 'zoe-cloud' ); ?></h2>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'zoe-cloud' ); ?></th>
							<th><?php esc_html_e( 'File', 'zoe-cloud' ); ?></th>
							<th><?php esc_html_e( 'Drive', 'zoe-cloud' ); ?></th>
							<th><?php esc_html_e( 'Download', 'zoe-cloud' ); ?></th>
						</tr>
					</thead>
					<tbody id="zoecloud-backups-table">
						<tr><td colspan="4"><?php esc_html_e( 'Loading backups…', 'zoe-cloud' ); ?></td></tr>
					</tbody>
				</table>
			</section>
		</div>
		<?php
	}
}
