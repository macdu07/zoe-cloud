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
	 * Cloud service.
	 *
	 * @var ZoeCloud_R2_Service
	 */
	private $cloud_service;

	/**
	 * Constructor.
	 *
	 * @param ZoeCloud_Crypto     $crypto        Crypto service.
	 * @param ZoeCloud_R2_Service $cloud_service Cloud service.
	 */
	public function __construct( ZoeCloud_Crypto $crypto, ZoeCloud_R2_Service $cloud_service ) {
		$this->crypto        = $crypto;
		$this->cloud_service = $cloud_service;
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
		$section = sanitize_key( $settings['settings_section'] ?? 'backup' );
		$provider = sanitize_key( $settings['storage_provider'] ?? ( $current['storage_provider'] ?? 'r2' ) );
		$provider = in_array( $provider, array( 'r2', 's3' ), true ) ? $provider : 'r2';
		$schedule = sanitize_key( $settings['schedule'] ?? ( $current['schedule'] ?? 'daily' ) );
		$schedule = in_array( $schedule, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $schedule : 'daily';
		$clean   = array(
			'drive_client_id'     => sanitize_text_field( $settings['drive_client_id'] ?? ( $current['drive_client_id'] ?? '' ) ),
			'drive_client_secret' => '',
			'drive_refresh_token' => '',
			'drive_project_name'  => sanitize_text_field( $settings['drive_project_name'] ?? ( $current['drive_project_name'] ?? get_bloginfo( 'name' ) ) ),
			'storage_provider'    => $provider,
			'r2_account_id'        => sanitize_text_field( $settings['r2_account_id'] ?? ( $current['r2_account_id'] ?? '' ) ),
			'r2_access_key_id'     => sanitize_text_field( $settings['r2_access_key_id'] ?? ( $current['r2_access_key_id'] ?? '' ) ),
			'r2_secret_access_key' => '',
			'r2_bucket'            => sanitize_text_field( $settings['r2_bucket'] ?? ( $current['r2_bucket'] ?? '' ) ),
			'r2_prefix'            => $this->sanitize_storage_prefix( $settings['r2_prefix'] ?? ( $current['r2_prefix'] ?? 'zoe-cloud' ) ),
			's3_access_key_id'     => sanitize_text_field( $settings['s3_access_key_id'] ?? ( $current['s3_access_key_id'] ?? '' ) ),
			's3_secret_access_key' => '',
			's3_bucket'            => sanitize_text_field( $settings['s3_bucket'] ?? ( $current['s3_bucket'] ?? '' ) ),
			's3_region'            => $this->sanitize_s3_region( $settings['s3_region'] ?? ( $current['s3_region'] ?? 'us-east-1' ) ),
			's3_prefix'            => $this->sanitize_storage_prefix( $settings['s3_prefix'] ?? ( $current['s3_prefix'] ?? '' ) ),
			'retention_limit'      => max( 1, absint( $settings['retention_limit'] ?? ( $current['retention_limit'] ?? 10 ) ) ),
			'schedule'             => $schedule,
			'auto_upload_drive'    => 'backup' === $section ? ( ! empty( $settings['auto_upload_drive'] ) ? 1 : 0 ) : absint( $current['auto_upload_drive'] ?? 1 ),
			'excluded_paths'       => $this->sanitize_excluded_paths( $settings['excluded_paths'] ?? ( $current['excluded_paths'] ?? array() ) ),
		);

		$client_secret = trim( (string) ( $settings['drive_client_secret'] ?? '' ) );
		$refresh_token = trim( (string) ( $settings['drive_refresh_token'] ?? '' ) );
		$r2_secret     = trim( (string) ( $settings['r2_secret_access_key'] ?? '' ) );
		$s3_secret     = trim( (string) ( $settings['s3_secret_access_key'] ?? '' ) );

		$clean['drive_client_secret'] = '' !== $client_secret
			? $this->crypto->encrypt( $client_secret )
			: ( $current['drive_client_secret'] ?? '' );

		$clean['drive_refresh_token'] = '' !== $refresh_token
			? $this->crypto->encrypt( $refresh_token )
			: ( $current['drive_refresh_token'] ?? '' );

		$clean['r2_secret_access_key'] = '' !== $r2_secret
			? $this->crypto->encrypt( $r2_secret )
			: ( $current['r2_secret_access_key'] ?? '' );

		$clean['s3_secret_access_key'] = '' !== $s3_secret
			? $this->crypto->encrypt( $s3_secret )
			: ( $current['s3_secret_access_key'] ?? '' );

		return $clean;
	}

	/**
	 * Sanitize a cloud object prefix.
	 *
	 * @param string $prefix Raw prefix.
	 * @return string
	 */
	private function sanitize_storage_prefix( $prefix ) {
		$prefix = trim( wp_normalize_path( (string) $prefix ), '/' );

		if ( false !== strpos( $prefix, '..' ) ) {
			return 'zoe-cloud';
		}

		return sanitize_text_field( $prefix );
	}

	/**
	 * Sanitize an AWS region.
	 *
	 * @param string $region Raw region.
	 * @return string
	 */
	private function sanitize_s3_region( $region ) {
		$region = strtolower( preg_replace( '/[^a-z0-9-]/', '', (string) $region ) );

		return $region ? $region : 'us-east-1';
	}

	/**
	 * Sanitize newline-delimited backup exclusions.
	 *
	 * @param string|array $paths Raw paths.
	 * @return array
	 */
	private function sanitize_excluded_paths( $paths ) {
		if ( is_string( $paths ) ) {
			$paths = preg_split( '/\r\n|\r|\n/', $paths );
		}

		$clean = array();

		foreach ( (array) $paths as $path ) {
			$path = trim( wp_normalize_path( (string) $path ) );
			$path = ltrim( $path, '/' );

			if ( '' === $path || false !== strpos( $path, '..' ) ) {
				continue;
			}

			$clean[] = sanitize_text_field( $path );
		}

		return array_values( array_unique( $clean ) );
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
				'storage_provider'   => 'r2',
				'r2_account_id'       => '',
				'r2_access_key_id'    => '',
				'r2_bucket'           => '',
				'r2_prefix'           => 'zoe-cloud',
				's3_access_key_id'    => '',
				's3_bucket'           => '',
				's3_region'           => 'us-east-1',
				's3_prefix'           => '',
				'retention_limit'    => 10,
				'schedule'           => 'daily',
				'auto_upload_drive'  => 1,
				'excluded_paths'     => array(),
			)
		);
		$excluded_paths = is_array( $settings['excluded_paths'] ) ? implode( "\n", $settings['excluded_paths'] ) : (string) $settings['excluded_paths'];
		$cloud_status   = $this->cloud_service->get_status();
		?>
		<div class="wrap zoecloud-admin">
			<header class="zoecloud-hero">
				<div>
					<img class="zoecloud-logo" src="<?php echo esc_url( ZOECLOUD_PLUGIN_URL . 'assets/images/zoecloud-logo-horizontal.svg' ); ?>" alt="<?php esc_attr_e( 'ZoeCloud', 'zoe-cloud' ); ?>">
					<p><?php esc_html_e( 'Portable backups, secure restoration, and cloud synchronization from WordPress.', 'zoe-cloud' ); ?></p>
				</div>
				<div class="zoecloud-hero-badge">
					<span><?php esc_html_e( 'Backup Engine', 'zoe-cloud' ); ?></span>
					<strong><?php esc_html_e( 'Local + Cloud', 'zoe-cloud' ); ?></strong>
				</div>
			</header>

			<nav class="zoecloud-tabs" aria-label="<?php esc_attr_e( 'ZoeCloud sections', 'zoe-cloud' ); ?>">
				<button type="button" class="zoecloud-tab is-active" data-zoecloud-tab="backups"><?php esc_html_e( 'Backups', 'zoe-cloud' ); ?></button>
				<button type="button" class="zoecloud-tab" data-zoecloud-tab="storage"><?php esc_html_e( 'Storage', 'zoe-cloud' ); ?></button>
			</nav>

			<div class="zoecloud-tab-panel is-active" data-zoecloud-panel="backups">
				<div class="zoecloud-grid">
					<section class="zoecloud-card">
						<h2><?php esc_html_e( 'Backup Dashboard', 'zoe-cloud' ); ?></h2>
						<p id="zoecloud-status-text"><?php esc_html_e( 'Loading status…', 'zoe-cloud' ); ?></p>
						<div class="zoecloud-actions">
							<button type="button" class="button button-primary zoecloud-primary-action" id="zoecloud-create-backup"><?php esc_html_e( 'Create Backup', 'zoe-cloud' ); ?></button>
							<label><input type="checkbox" id="zoecloud-include-core"> <?php esc_html_e( 'Include WordPress core', 'zoe-cloud' ); ?></label>
							<label><input type="checkbox" id="zoecloud-upload-drive" checked> <?php esc_html_e( 'Upload to cloud storage', 'zoe-cloud' ); ?></label>
						</div>
						<div id="zoecloud-feedback" class="zoecloud-feedback"></div>
						<div id="zoecloud-job-status" class="zoecloud-job-status"></div>
						<ul id="zoecloud-preflight" class="zoecloud-preflight"></ul>
					</section>

					<section class="zoecloud-card">
						<h2><?php esc_html_e( 'Backup Preferences', 'zoe-cloud' ); ?></h2>
						<form method="post" action="options.php">
							<?php settings_fields( 'zoecloud_settings' ); ?>
							<input type="hidden" name="zoecloud_settings[settings_section]" value="backup">
							<table class="form-table">
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
											<option value="weekly" <?php selected( $settings['schedule'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'zoe-cloud' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Auto-upload to cloud', 'zoe-cloud' ); ?></th>
									<td><label><input type="checkbox" name="zoecloud_settings[auto_upload_drive]" value="1" <?php checked( $settings['auto_upload_drive'], 1 ); ?>> <?php esc_html_e( 'Upload every scheduled backup', 'zoe-cloud' ); ?></label></td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_excluded_paths"><?php esc_html_e( 'Excluded Paths', 'zoe-cloud' ); ?></label></th>
									<td>
										<textarea id="zoecloud_excluded_paths" name="zoecloud_settings[excluded_paths]" class="large-text code" rows="5"><?php echo esc_textarea( $excluded_paths ); ?></textarea>
										<p class="description"><?php esc_html_e( 'One path per line, relative to the WordPress root. Cache and ZoeCloud backup folders are excluded automatically.', 'zoe-cloud' ); ?></p>
									</td>
								</tr>
							</table>
							<?php submit_button( __( 'Save Backup Preferences', 'zoe-cloud' ) ); ?>
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
								<th><?php esc_html_e( 'Size', 'zoe-cloud' ); ?></th>
								<th><?php esc_html_e( 'Cloud', 'zoe-cloud' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'zoe-cloud' ); ?></th>
							</tr>
						</thead>
						<tbody id="zoecloud-backups-table">
							<tr><td colspan="5"><?php esc_html_e( 'Loading backups…', 'zoe-cloud' ); ?></td></tr>
						</tbody>
					</table>
				</section>

				<section class="zoecloud-card zoecloud-list">
					<h2><?php esc_html_e( 'Restore', 'zoe-cloud' ); ?></h2>

					<div class="zoecloud-restore-mode">
						<button type="button" class="zoecloud-restore-mode-btn is-active" data-zoecloud-restore-mode="existing"><?php esc_html_e( 'From existing backup', 'zoe-cloud' ); ?></button>
						<button type="button" class="zoecloud-restore-mode-btn" data-zoecloud-restore-mode="upload"><?php esc_html_e( 'Upload ZIP', 'zoe-cloud' ); ?></button>
					</div>

					<div data-zoecloud-restore-panel="existing">
						<div class="zoecloud-restore-grid">
							<label>
								<?php esc_html_e( 'Backup', 'zoe-cloud' ); ?>
								<select id="zoecloud-restore-filename"></select>
							</label>
						</div>
					</div>

					<div data-zoecloud-restore-panel="upload" hidden>
						<div class="zoecloud-upload-area" id="zoecloud-upload-area">
							<input type="file" id="zoecloud-upload-file" accept=".zip" class="zoecloud-upload-input">
							<label for="zoecloud-upload-file" class="zoecloud-upload-label">
								<span class="zoecloud-upload-icon">&#8679;</span>
								<span id="zoecloud-upload-filename"><?php esc_html_e( 'Choose a ZIP file or drop it here', 'zoe-cloud' ); ?></span>
							</label>
						</div>
						<div class="zoecloud-actions">
							<button type="button" class="button button-primary" id="zoecloud-upload-zip"><?php esc_html_e( 'Upload ZIP', 'zoe-cloud' ); ?></button>
						</div>
						<div id="zoecloud-upload-feedback" class="zoecloud-feedback"></div>
					</div>

					<div class="zoecloud-restore-grid zoecloud-restore-urls">
						<label>
							<?php esc_html_e( 'Search URL', 'zoe-cloud' ); ?>
							<input type="url" id="zoecloud-restore-search" class="regular-text" value="<?php echo esc_attr( home_url() ); ?>">
						</label>
						<label>
							<?php esc_html_e( 'Replace URL', 'zoe-cloud' ); ?>
							<input type="url" id="zoecloud-restore-replace" class="regular-text" value="<?php echo esc_attr( home_url() ); ?>">
						</label>
					</div>

					<div class="zoecloud-actions">
						<button type="button" class="button" id="zoecloud-validate-restore"><?php esc_html_e( 'Validate Restore', 'zoe-cloud' ); ?></button>
						<button type="button" class="button button-secondary" id="zoecloud-run-restore"><?php esc_html_e( 'Run Restore', 'zoe-cloud' ); ?></button>
					</div>
					<div id="zoecloud-restore-feedback" class="zoecloud-feedback"></div>
				</section>
			</div>

			<div class="zoecloud-tab-panel" data-zoecloud-panel="storage">
				<section class="zoecloud-card">
					<h2><?php esc_html_e( 'Storage Providers', 'zoe-cloud' ); ?></h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'zoecloud_settings' ); ?>
						<input type="hidden" name="zoecloud_settings[settings_section]" value="storage">
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Active Provider Status', 'zoe-cloud' ); ?></th>
								<td>
									<div class="zoecloud-drive-connection">
										<span class="zoecloud-drive-status <?php echo $cloud_status['configured'] ? 'is-connected' : 'is-disconnected'; ?>">
											<?php echo esc_html( $cloud_status['configured'] ? __( 'Configured', 'zoe-cloud' ) : __( 'Not configured', 'zoe-cloud' ) ); ?>
										</span>
										<strong><?php echo esc_html( $cloud_status['label'] ?? strtoupper( $cloud_status['provider'] ?? 'r2' ) ); ?></strong>
										<?php if ( ! empty( $cloud_status['endpoint'] ) ) : ?>
											<code><?php echo esc_html( $cloud_status['endpoint'] ); ?></code>
										<?php endif; ?>
									</div>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="zoecloud_storage_provider"><?php esc_html_e( 'Provider', 'zoe-cloud' ); ?></label></th>
								<td>
									<select id="zoecloud_storage_provider" name="zoecloud_settings[storage_provider]">
										<option value="r2" <?php selected( $settings['storage_provider'], 'r2' ); ?>><?php esc_html_e( 'Cloudflare R2', 'zoe-cloud' ); ?></option>
										<option value="s3" <?php selected( $settings['storage_provider'], 's3' ); ?>><?php esc_html_e( 'AWS S3', 'zoe-cloud' ); ?></option>
									</select>
								</td>
							</tr>
						</table>

						<div class="zoecloud-provider-fields" data-zoecloud-provider-fields="r2" <?php echo 'r2' === $settings['storage_provider'] ? '' : 'hidden'; ?>>
							<h3><?php esc_html_e( 'Cloudflare R2', 'zoe-cloud' ); ?></h3>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="zoecloud_r2_account_id"><?php esc_html_e( 'R2 Account ID', 'zoe-cloud' ); ?></label></th>
									<td><input type="text" id="zoecloud_r2_account_id" name="zoecloud_settings[r2_account_id]" class="regular-text" value="<?php echo esc_attr( $settings['r2_account_id'] ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_r2_access_key_id"><?php esc_html_e( 'R2 Access Key ID', 'zoe-cloud' ); ?></label></th>
									<td><input type="text" id="zoecloud_r2_access_key_id" name="zoecloud_settings[r2_access_key_id]" class="regular-text" value="<?php echo esc_attr( $settings['r2_access_key_id'] ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_r2_secret_access_key"><?php esc_html_e( 'R2 Secret Access Key', 'zoe-cloud' ); ?></label></th>
									<td>
										<input type="password" id="zoecloud_r2_secret_access_key" name="zoecloud_settings[r2_secret_access_key]" class="regular-text" value="">
										<p class="description"><?php esc_html_e( 'Leave blank to keep the saved secret.', 'zoe-cloud' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_r2_bucket"><?php esc_html_e( 'R2 Bucket', 'zoe-cloud' ); ?></label></th>
									<td><input type="text" id="zoecloud_r2_bucket" name="zoecloud_settings[r2_bucket]" class="regular-text" value="<?php echo esc_attr( $settings['r2_bucket'] ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_r2_prefix"><?php esc_html_e( 'R2 Prefix', 'zoe-cloud' ); ?></label></th>
									<td>
										<input type="text" id="zoecloud_r2_prefix" name="zoecloud_settings[r2_prefix]" class="regular-text" value="<?php echo esc_attr( $settings['r2_prefix'] ); ?>">
										<p class="description"><?php esc_html_e( 'Optional folder prefix inside the bucket. Leave blank to store each site folder at the bucket root.', 'zoe-cloud' ); ?></p>
									</td>
								</tr>
							</table>
						</div>

						<div class="zoecloud-provider-fields" data-zoecloud-provider-fields="s3" <?php echo 's3' === $settings['storage_provider'] ? '' : 'hidden'; ?>>
							<h3><?php esc_html_e( 'AWS S3', 'zoe-cloud' ); ?></h3>
							<table class="form-table">
								<tr>
									<th scope="row"><label for="zoecloud_s3_access_key_id"><?php esc_html_e( 'S3 Access Key ID', 'zoe-cloud' ); ?></label></th>
									<td><input type="text" id="zoecloud_s3_access_key_id" name="zoecloud_settings[s3_access_key_id]" class="regular-text" value="<?php echo esc_attr( $settings['s3_access_key_id'] ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_s3_secret_access_key"><?php esc_html_e( 'S3 Secret Access Key', 'zoe-cloud' ); ?></label></th>
									<td>
										<input type="password" id="zoecloud_s3_secret_access_key" name="zoecloud_settings[s3_secret_access_key]" class="regular-text" value="">
										<p class="description"><?php esc_html_e( 'Leave blank to keep the saved secret.', 'zoe-cloud' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_s3_bucket"><?php esc_html_e( 'S3 Bucket', 'zoe-cloud' ); ?></label></th>
									<td><input type="text" id="zoecloud_s3_bucket" name="zoecloud_settings[s3_bucket]" class="regular-text" value="<?php echo esc_attr( $settings['s3_bucket'] ); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_s3_region"><?php esc_html_e( 'S3 Region', 'zoe-cloud' ); ?></label></th>
									<td>
										<input type="text" id="zoecloud_s3_region" name="zoecloud_settings[s3_region]" class="regular-text" value="<?php echo esc_attr( $settings['s3_region'] ); ?>" placeholder="us-east-1">
										<p class="description"><?php esc_html_e( 'Use the AWS region where the bucket exists, for example us-east-1 or eu-west-1.', 'zoe-cloud' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="zoecloud_s3_prefix"><?php esc_html_e( 'S3 Prefix', 'zoe-cloud' ); ?></label></th>
									<td>
										<input type="text" id="zoecloud_s3_prefix" name="zoecloud_settings[s3_prefix]" class="regular-text" value="<?php echo esc_attr( $settings['s3_prefix'] ); ?>">
										<p class="description"><?php esc_html_e( 'Optional folder prefix inside the bucket, for example zoe-cloud.', 'zoe-cloud' ); ?></p>
									</td>
								</tr>
							</table>
						</div>
						<?php submit_button( __( 'Save Storage Settings', 'zoe-cloud' ) ); ?>
					</form>
				</section>
			</div>
		</div>
		<?php
	}

}
