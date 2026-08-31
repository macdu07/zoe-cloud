<?php
/**
 * Admin UI.
 *
 * @package ZoeCloud
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the ZoeCloud admin interface.
 */
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
		add_filter( 'plugin_action_links_' . plugin_basename( ZOECLOUD_PLUGIN_FILE ), array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Add a direct dashboard link to the Plugins screen.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$dashboard = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=zoecloud' ) ),
			esc_html__( 'Dashboard', 'zoe-cloud' )
		);

		return array_merge( array( 'zoecloud_dashboard' => $dashboard ), $links );
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
			array( $this, 'render_page_v2' ),
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
		$settings         = is_array( $settings ) ? $settings : array();
		$current          = get_option( 'zoecloud_settings', array() );
		$section          = sanitize_key( $settings['settings_section'] ?? 'backup' );
		$provider         = sanitize_key( $settings['storage_provider'] ?? ( $current['storage_provider'] ?? 'r2' ) );
		$provider         = in_array( $provider, array( 'r2', 's3' ), true ) ? $provider : 'r2';
		$schedule         = sanitize_key( $settings['schedule'] ?? ( $current['schedule'] ?? 'daily' ) );
		$schedule         = in_array( $schedule, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $schedule : 'daily';
		$schedule_time    = sanitize_text_field( $settings['schedule_time'] ?? ( $current['schedule_time'] ?? '02:00' ) );
		$schedule_time    = preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $schedule_time ) ? $schedule_time : '02:00';
		$schedule_weekday = sanitize_key( $settings['schedule_weekday'] ?? ( $current['schedule_weekday'] ?? 'monday' ) );
		$schedule_weekday = in_array( $schedule_weekday, array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ), true ) ? $schedule_weekday : 'monday';
		$clean            = array(
			'schedule_enabled'     => 'backup' === $section ? ( ! empty( $settings['schedule_enabled'] ) ? 1 : 0 ) : absint( $current['schedule_enabled'] ?? 0 ),
			'storage_provider'     => $provider,
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
			'schedule_time'        => $schedule_time,
			'schedule_weekday'     => $schedule_weekday,
			'auto_upload_cloud'    => 'backup' === $section ? ( ! empty( $settings['auto_upload_cloud'] ) ? 1 : 0 ) : absint( $current['auto_upload_cloud'] ?? 1 ),
			'excluded_paths'       => $this->sanitize_excluded_paths( $settings['excluded_paths'] ?? ( $current['excluded_paths'] ?? array() ) ),
			'delete_on_uninstall'  => 'backup' === $section ? ( ! empty( $settings['delete_on_uninstall'] ) ? 1 : 0 ) : absint( $current['delete_on_uninstall'] ?? 0 ),
		);

		$r2_secret = trim( (string) ( $settings['r2_secret_access_key'] ?? '' ) );
		$s3_secret = trim( (string) ( $settings['s3_secret_access_key'] ?? '' ) );

		$clean['r2_secret_access_key'] = $this->encrypt_secret_or_keep( $r2_secret, $current['r2_secret_access_key'] ?? '' );
		$clean['s3_secret_access_key'] = $this->encrypt_secret_or_keep( $s3_secret, $current['s3_secret_access_key'] ?? '' );

		return $clean;
	}

	/**
	 * Encrypt a submitted secret without ever falling back to plaintext.
	 *
	 * @param string $secret  Submitted secret.
	 * @param string $current Existing encrypted secret.
	 * @return string
	 */
	private function encrypt_secret_or_keep( $secret, $current ) {
		if ( '' === $secret ) {
			return (string) $current;
		}

		$encrypted = $this->crypto->encrypt( $secret );
		if ( is_wp_error( $encrypted ) ) {
			add_settings_error( 'zoecloud_settings', $encrypted->get_error_code(), $encrypted->get_error_message(), 'error' );

			return (string) $current;
		}

		return $encrypted;
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
				'root'     => esc_url_raw( rest_url( 'zoecloud/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'hostname' => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				'i18n'     => array(
					'loading'           => __( 'Loading…', 'zoe-cloud' ),
					'noBackups'         => __( 'No backups match these filters.', 'zoe-cloud' ),
					'backupQueued'      => __( 'Backup queued.', 'zoe-cloud' ),
					'backupComplete'    => __( 'Backup completed.', 'zoe-cloud' ),
					'restoreQueued'     => __( 'Protected restore queued.', 'zoe-cloud' ),
					'deleteConfirm'     => __( 'Delete the selected backups? This cannot be undone.', 'zoe-cloud' ),
					'unlockDelete'      => __( 'Locked backups must be unlocked before deletion.', 'zoe-cloud' ),
					'connectionSuccess' => __( 'Connection successful.', 'zoe-cloud' ),
					'uploadComplete'    => __( 'Backup imported successfully.', 'zoe-cloud' ),
					'validBackup'       => __( 'Backup verified and ready to restore.', 'zoe-cloud' ),
					'unknownError'      => __( 'The request could not be completed.', 'zoe-cloud' ),
					'reconnecting'       => __( 'The job is still running. Reconnecting…', 'zoe-cloud' ),
					'restoreCompleted'   => __( 'Restore completed successfully.', 'zoe-cloud' ),
					'restoreReloadConfirm' => __( 'Restore completed successfully. Reload the page now?', 'zoe-cloud' ),
					'restoreCompletedNoReload' => __( 'Restore completed successfully. The page was not reloaded.', 'zoe-cloud' ),
					'copyHostname'       => __( 'Copy hostname', 'zoe-cloud' ),
					'hostnameCopied'     => __( 'Hostname copied.', 'zoe-cloud' ),
					'copyFailed'         => __( 'Could not copy the hostname.', 'zoe-cloud' ),
					'restoreUrlHint'     => __( 'Leave both URLs unchanged for a same-site restore. Change them only when moving to a different domain, protocol, or directory.', 'zoe-cloud' ),
					'download'          => __( 'Download', 'zoe-cloud' ),
					'downloadVerify'    => __( 'Download & verify', 'zoe-cloud' ),
					'restore'           => __( 'Restore', 'zoe-cloud' ),
					'lock'              => __( 'Lock', 'zoe-cloud' ),
					'unlock'            => __( 'Unlock', 'zoe-cloud' ),
					'delete'            => __( 'Delete', 'zoe-cloud' ),
					'healthReady'       => __( 'Ready to create backups.', 'zoe-cloud' ),
					'healthBlocked'     => __( 'Action is required before backups can run.', 'zoe-cloud' ),
					'never'             => __( 'Never', 'zoe-cloud' ),
					'fullSite'          => __( 'Full site', 'zoe-cloud' ),
					'siteData'          => __( 'Site data', 'zoe-cloud' ),
					'createFirst'       => __( 'Create your first recovery point.', 'zoe-cloud' ),
					'notScheduled'      => __( 'Not scheduled', 'zoe-cloud' ),
					'enableAutomation'  => __( 'Enable automation to stay protected.', 'zoe-cloud' ),
					'localOnly'         => __( 'Local only', 'zoe-cloud' ),
					'connectStorage'    => __( 'Connect off-site storage.', 'zoe-cloud' ),
					'ready'             => __( 'Ready', 'zoe-cloud' ),
					'attention'         => __( 'Needs attention', 'zoe-cloud' ),
					'cronAvailable'     => __( 'Background tasks available', 'zoe-cloud' ),
					'cronDisabled'      => __( 'WP-Cron is disabled', 'zoe-cloud' ),
					'protected'         => __( 'Protected', 'zoe-cloud' ),
					'setupNeeded'       => __( 'Setup needed', 'zoe-cloud' ),
					'localBackupHint'   => __( 'Cloud storage is not configured; this backup will remain local.', 'zoe-cloud' ),
					'verified'          => __( 'Verified', 'zoe-cloud' ),
					'notVerified'       => __( 'Not verified', 'zoe-cloud' ),
					'local'             => __( 'Local', 'zoe-cloud' ),
					'uploadFailed'      => __( 'Upload failed', 'zoe-cloud' ),
					'manual'            => __( 'Manual', 'zoe-cloud' ),
					'scheduled'         => __( 'Scheduled', 'zoe-cloud' ),
					'imported'          => __( 'Imported', 'zoe-cloud' ),
					'safety'            => __( 'Safety backup', 'zoe-cloud' ),
					'backup'            => __( 'Backup', 'zoe-cloud' ),
					'completed'         => __( 'Completed', 'zoe-cloud' ),
					'failed'            => __( 'Failed', 'zoe-cloud' ),
					'running'           => __( 'Running', 'zoe-cloud' ),
					'queued'            => __( 'Queued', 'zoe-cloud' ),
					'events'            => __( 'events', 'zoe-cloud' ),
					'noActivity'        => __( 'No activity yet.', 'zoe-cloud' ),
					'clearActivity'     => __( 'Clear activity', 'zoe-cloud' ),
					'clearActivityConfirm' => __( 'Clear completed and failed activity logs? Active jobs will remain.', 'zoe-cloud' ),
					/* translators: %d: Number of activity records removed. */
					'activityCleared'   => __( 'Cleared %d activity records.', 'zoe-cloud' ),
					'activityNothingToClear' => __( 'There are no finished activity records to clear.', 'zoe-cloud' ),
					'downloadLog'       => __( 'Download log', 'zoe-cloud' ),
					'origin'            => __( 'Origin', 'zoe-cloud' ),
					'files'             => __( 'Files', 'zoe-cloud' ),
					'databaseRows'      => __( 'Database rows', 'zoe-cloud' ),
					'archiveSize'       => __( 'Archive size', 'zoe-cloud' ),
					'selectZip'         => __( 'Choose a ZIP first.', 'zoe-cloud' ),
					'chooseZip'         => __( 'Choose a ZIP or drop it here', 'zoe-cloud' ),
					'deleted'           => __( 'Backups deleted.', 'zoe-cloud' ),
					'zipArchive'        => __( 'ZipArchive', 'zoe-cloud' ),
					'uploadsWritable'   => __( 'Uploads writable', 'zoe-cloud' ),
					'backupWritable'    => __( 'Backup files writable', 'zoe-cloud' ),
					'freeDisk'          => __( 'Free disk', 'zoe-cloud' ),
					'memory'            => __( 'Memory', 'zoe-cloud' ),
					'executionTime'     => __( 'Execution time', 'zoe-cloud' ),
					'cron'              => __( 'WP-Cron', 'zoe-cloud' ),
					'available'         => __( 'Available', 'zoe-cloud' ),
					'disabled'          => __( 'Disabled', 'zoe-cloud' ),
					'missing'           => __( 'Missing', 'zoe-cloud' ),
				),
			)
		);
	}

	/**
	 * Render the confidence-focused 1.0 application shell.
	 *
	 * @return void
	 */
	public function render_page_v2() {
		$settings       = wp_parse_args(
			get_option( 'zoecloud_settings', array() ),
			array(
				'schedule_enabled'    => 0,
				'storage_provider'    => 'r2',
				'r2_account_id'       => '',
				'r2_access_key_id'    => '',
				'r2_bucket'           => '',
				'r2_prefix'           => 'zoe-cloud',
				's3_access_key_id'    => '',
				's3_bucket'           => '',
				's3_region'           => 'us-east-1',
				's3_prefix'           => '',
				'retention_limit'     => 10,
				'schedule'            => 'daily',
				'schedule_time'       => '02:00',
				'schedule_weekday'    => 'monday',
				'auto_upload_cloud'   => 1,
				'excluded_paths'      => array(),
				'delete_on_uninstall' => 0,
			)
		);
		$excluded_paths = is_array( $settings['excluded_paths'] ) ? implode( "\n", $settings['excluded_paths'] ) : (string) $settings['excluded_paths'];
		$cloud_status   = $this->cloud_service->get_status();
		?>
		<div class="wrap zoecloud-admin">
			<header class="zoecloud-app-header">
				<div class="zoecloud-brand"><img class="zoecloud-logo" src="<?php echo esc_url( ZOECLOUD_PLUGIN_URL . 'assets/images/zoecloud-logo-horizontal.svg' ); ?>" alt="<?php esc_attr_e( 'ZoeCloud', 'zoe-cloud' ); ?>"><span><?php esc_html_e( 'Backup & Recovery', 'zoe-cloud' ); ?></span></div>
				<div id="zoecloud-global-status" class="zoecloud-status-pill" aria-live="polite"><?php esc_html_e( 'Checking protection…', 'zoe-cloud' ); ?></div>
			</header>

			<nav class="zoecloud-tabs" role="tablist" aria-label="<?php esc_attr_e( 'ZoeCloud sections', 'zoe-cloud' ); ?>">
				<?php
				$tabs = array(
					'overview'   => __( 'Overview', 'zoe-cloud' ),
					'backups'    => __( 'Backups', 'zoe-cloud' ),
					'automation' => __( 'Automation', 'zoe-cloud' ),
					'storage'    => __( 'Storage', 'zoe-cloud' ),
					'activity'   => __( 'Activity', 'zoe-cloud' ),
				);
				foreach ( $tabs as $key => $label ) :
					?>
					<button type="button" id="zoecloud-tab-<?php echo esc_attr( $key ); ?>" class="zoecloud-tab <?php echo 'overview' === $key ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'overview' === $key ? 'true' : 'false'; ?>" aria-controls="zoecloud-panel-<?php echo esc_attr( $key ); ?>" tabindex="<?php echo 'overview' === $key ? '0' : '-1'; ?>" data-zoecloud-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
				<?php endforeach; ?>
			</nav>

			<main>
				<section id="zoecloud-panel-overview" class="zoecloud-tab-panel is-active" role="tabpanel" aria-labelledby="zoecloud-tab-overview" data-zoecloud-panel="overview">
					<div class="zoecloud-section-heading"><div><p class="zoecloud-eyebrow"><?php esc_html_e( 'Protection at a glance', 'zoe-cloud' ); ?></p><h1><?php esc_html_e( 'Your recovery status', 'zoe-cloud' ); ?></h1></div><button type="button" class="button button-primary zoecloud-primary-action" data-zoecloud-go="backups"><?php esc_html_e( 'Create backup', 'zoe-cloud' ); ?></button></div>
					<article id="zoecloud-onboarding" class="zoecloud-card"><div class="zoecloud-card-heading"><div><p class="zoecloud-eyebrow"><?php esc_html_e( 'Start here', 'zoe-cloud' ); ?></p><h2><?php esc_html_e( 'Create your first verified recovery point', 'zoe-cloud' ); ?></h2><p><?php esc_html_e( 'Review server health, optionally connect R2 or S3, then create a backup. ZoeCloud verifies every archive before it can be restored.', 'zoe-cloud' ); ?></p></div><button type="button" class="button button-primary" data-zoecloud-go="backups"><?php esc_html_e( 'Create first backup', 'zoe-cloud' ); ?></button></div></article>
					<div class="zoecloud-summary-grid">
						<article class="zoecloud-summary-card"><span><?php esc_html_e( 'Latest backup', 'zoe-cloud' ); ?></span><strong id="zoecloud-summary-latest">—</strong><small id="zoecloud-summary-latest-detail"><?php esc_html_e( 'Loading…', 'zoe-cloud' ); ?></small></article>
						<article class="zoecloud-summary-card"><span><?php esc_html_e( 'Next automatic backup', 'zoe-cloud' ); ?></span><strong id="zoecloud-summary-next">—</strong><small id="zoecloud-summary-timezone"></small></article>
						<article class="zoecloud-summary-card"><span><?php esc_html_e( 'Storage', 'zoe-cloud' ); ?></span><strong id="zoecloud-summary-storage">—</strong><small id="zoecloud-summary-storage-detail"></small></article>
						<article class="zoecloud-summary-card"><span><?php esc_html_e( 'Server health', 'zoe-cloud' ); ?></span><strong id="zoecloud-summary-health">—</strong><small id="zoecloud-summary-health-detail"></small></article>
					</div>
					<div class="zoecloud-overview-grid">
						<article class="zoecloud-card"><div class="zoecloud-card-heading"><div><h2><?php esc_html_e( 'Protection summary', 'zoe-cloud' ); ?></h2><p><?php esc_html_e( 'A concise view of what is protected and where it is stored.', 'zoe-cloud' ); ?></p></div></div><dl class="zoecloud-metrics"><div><dt><?php esc_html_e( 'Stored backups', 'zoe-cloud' ); ?></dt><dd id="zoecloud-metric-count">0</dd></div><div><dt><?php esc_html_e( 'Local usage', 'zoe-cloud' ); ?></dt><dd id="zoecloud-metric-size">0 B</dd></div><div><dt><?php esc_html_e( 'Last activity', 'zoe-cloud' ); ?></dt><dd id="zoecloud-metric-activity">—</dd></div></dl></article>
						<article class="zoecloud-card"><div class="zoecloud-card-heading"><div><h2><?php esc_html_e( 'Environment checks', 'zoe-cloud' ); ?></h2><p id="zoecloud-health-summary"><?php esc_html_e( 'Checking server requirements…', 'zoe-cloud' ); ?></p></div></div><details class="zoecloud-details"><summary><?php esc_html_e( 'View technical details', 'zoe-cloud' ); ?></summary><ul id="zoecloud-preflight" class="zoecloud-preflight"></ul></details></article>
					</div>
				</section>

				<section id="zoecloud-panel-backups" class="zoecloud-tab-panel" role="tabpanel" aria-labelledby="zoecloud-tab-backups" data-zoecloud-panel="backups" hidden>
					<div class="zoecloud-section-heading"><div><p class="zoecloud-eyebrow"><?php esc_html_e( 'Manual protection', 'zoe-cloud' ); ?></p><h1><?php esc_html_e( 'Backups & restore', 'zoe-cloud' ); ?></h1></div></div>
					<article class="zoecloud-card zoecloud-composer"><div><h2><?php esc_html_e( 'Create a recovery point', 'zoe-cloud' ); ?></h2><p id="zoecloud-cloud-hint"><?php esc_html_e( 'Choose what to protect and where to keep it.', 'zoe-cloud' ); ?></p></div><div class="zoecloud-choice-group" role="radiogroup" aria-label="<?php esc_attr_e( 'Backup scope', 'zoe-cloud' ); ?>"><label class="zoecloud-choice"><input type="radio" name="zoecloud_scope" value="site_data" checked><span><strong><?php esc_html_e( 'Site data', 'zoe-cloud' ); ?></strong><small><?php esc_html_e( 'Database, themes, plugins and uploads', 'zoe-cloud' ); ?></small></span></label><label class="zoecloud-choice"><input type="radio" name="zoecloud_scope" value="full"><span><strong><?php esc_html_e( 'Full site', 'zoe-cloud' ); ?></strong><small><?php esc_html_e( 'Site data plus WordPress core', 'zoe-cloud' ); ?></small></span></label></div><label class="zoecloud-toggle"><input type="checkbox" id="zoecloud-upload-cloud" checked><span><?php esc_html_e( 'Also upload to configured cloud storage', 'zoe-cloud' ); ?></span></label><div class="zoecloud-actions"><button type="button" class="button button-primary" id="zoecloud-create-backup"><?php esc_html_e( 'Create backup', 'zoe-cloud' ); ?></button><div id="zoecloud-feedback" class="zoecloud-feedback" aria-live="polite"></div></div><div id="zoecloud-job-status" class="zoecloud-job-status" aria-live="polite"></div></article>
					<article class="zoecloud-card zoecloud-list"><div class="zoecloud-card-heading"><div><h2><?php esc_html_e( 'Recovery points', 'zoe-cloud' ); ?></h2><p><?php esc_html_e( 'Search, protect, download or restore a backup.', 'zoe-cloud' ); ?></p></div><div class="zoecloud-toolbar"><label class="screen-reader-text" for="zoecloud-backup-search"><?php esc_html_e( 'Search backups', 'zoe-cloud' ); ?></label><input type="search" id="zoecloud-backup-search" placeholder="<?php esc_attr_e( 'Search backups…', 'zoe-cloud' ); ?>"><select id="zoecloud-backup-filter" aria-label="<?php esc_attr_e( 'Filter backups', 'zoe-cloud' ); ?>"><option value="all"><?php esc_html_e( 'All sources', 'zoe-cloud' ); ?></option><option value="manual"><?php esc_html_e( 'Manual', 'zoe-cloud' ); ?></option><option value="scheduled"><?php esc_html_e( 'Scheduled', 'zoe-cloud' ); ?></option><option value="imported"><?php esc_html_e( 'Imported', 'zoe-cloud' ); ?></option><option value="locked"><?php esc_html_e( 'Locked', 'zoe-cloud' ); ?></option></select><button type="button" class="button" id="zoecloud-bulk-delete" disabled><?php esc_html_e( 'Delete selected', 'zoe-cloud' ); ?></button></div></div><div class="zoecloud-table-wrap"><table class="widefat zoecloud-table"><thead><tr><th class="check-column"><input type="checkbox" id="zoecloud-select-all" aria-label="<?php esc_attr_e( 'Select all backups', 'zoe-cloud' ); ?>"></th><th><?php esc_html_e( 'Backup', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Source', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Location', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Integrity', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Actions', 'zoe-cloud' ); ?></th></tr></thead><tbody id="zoecloud-backups-table"><tr><td colspan="6"><?php esc_html_e( 'Loading backups…', 'zoe-cloud' ); ?></td></tr></tbody></table></div></article>
					<article class="zoecloud-card zoecloud-upload-card"><div><h2><?php esc_html_e( 'Import a ZoeCloud backup', 'zoe-cloud' ); ?></h2><p><?php esc_html_e( 'Upload a validated ZIP to add it to your recovery points.', 'zoe-cloud' ); ?></p></div><div class="zoecloud-upload-area" id="zoecloud-upload-area"><input type="file" id="zoecloud-upload-file" accept=".zip" class="zoecloud-upload-input"><label for="zoecloud-upload-file" class="zoecloud-upload-label"><span class="dashicons dashicons-upload" aria-hidden="true"></span><span id="zoecloud-upload-filename"><?php esc_html_e( 'Choose a ZIP or drop it here', 'zoe-cloud' ); ?></span></label></div><button type="button" class="button" id="zoecloud-upload-zip"><?php esc_html_e( 'Import backup', 'zoe-cloud' ); ?></button><div id="zoecloud-upload-feedback" class="zoecloud-feedback" aria-live="polite"></div></article>
				</section>

				<section id="zoecloud-panel-automation" class="zoecloud-tab-panel" role="tabpanel" aria-labelledby="zoecloud-tab-automation" data-zoecloud-panel="automation" hidden>
					<div class="zoecloud-section-heading"><div><p class="zoecloud-eyebrow"><?php esc_html_e( 'Set it and verify it', 'zoe-cloud' ); ?></p><h1><?php esc_html_e( 'Automation', 'zoe-cloud' ); ?></h1></div></div>
					<article class="zoecloud-card"><form method="post" action="options.php"><?php settings_fields( 'zoecloud_settings' ); ?><input type="hidden" name="zoecloud_settings[settings_section]" value="backup"><label class="zoecloud-toggle zoecloud-toggle-prominent"><input type="checkbox" name="zoecloud_settings[schedule_enabled]" value="1" <?php checked( $settings['schedule_enabled'], 1 ); ?>><span><strong><?php esc_html_e( 'Enable automatic backups', 'zoe-cloud' ); ?></strong><small><?php esc_html_e( 'ZoeCloud will use WP-Cron and the site timezone.', 'zoe-cloud' ); ?></small></span></label><div class="zoecloud-form-grid"><label><?php esc_html_e( 'Frequency', 'zoe-cloud' ); ?><select id="zoecloud_schedule" name="zoecloud_settings[schedule]">
					<?php
					foreach ( array(
						'hourly'     => __( 'Hourly', 'zoe-cloud' ),
						'twicedaily' => __( 'Twice daily', 'zoe-cloud' ),
						'daily'      => __( 'Daily', 'zoe-cloud' ),
						'weekly'     => __( 'Weekly', 'zoe-cloud' ),
					) as $value => $label ) :
						?>
																								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['schedule'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Start time', 'zoe-cloud' ); ?><input type="time" name="zoecloud_settings[schedule_time]" value="<?php echo esc_attr( $settings['schedule_time'] ); ?>"></label><label id="zoecloud_schedule_weekday_row"><?php esc_html_e( 'Weekday', 'zoe-cloud' ); ?><select name="zoecloud_settings[schedule_weekday]">
																								<?php
																								foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ) as $day ) :
																									?>
																												<option value="<?php echo esc_attr( $day ); ?>" <?php selected( $settings['schedule_weekday'], $day ); ?>><?php echo esc_html( ucfirst( $day ) ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Retention', 'zoe-cloud' ); ?><input type="number" name="zoecloud_settings[retention_limit]" min="1" value="<?php echo esc_attr( $settings['retention_limit'] ); ?>"></label></div><label class="zoecloud-toggle"><input type="checkbox" name="zoecloud_settings[auto_upload_cloud]" value="1" <?php checked( $settings['auto_upload_cloud'], 1 ); ?>><span><?php esc_html_e( 'Upload scheduled backups to cloud', 'zoe-cloud' ); ?></span></label><label class="zoecloud-field"><?php esc_html_e( 'Excluded paths', 'zoe-cloud' ); ?><textarea name="zoecloud_settings[excluded_paths]" class="large-text code" rows="6"><?php echo esc_textarea( $excluded_paths ); ?></textarea><small><?php esc_html_e( 'One path per line, relative to the WordPress root.', 'zoe-cloud' ); ?></small></label><details class="zoecloud-details"><summary><?php esc_html_e( 'Uninstall behavior', 'zoe-cloud' ); ?></summary><label class="zoecloud-toggle"><input type="checkbox" name="zoecloud_settings[delete_on_uninstall]" value="1" <?php checked( $settings['delete_on_uninstall'], 1 ); ?>><span><?php esc_html_e( 'Permanently delete ZoeCloud backups and data when the plugin is uninstalled', 'zoe-cloud' ); ?></span></label><p><?php esc_html_e( 'Disabled by default to prevent accidental backup loss.', 'zoe-cloud' ); ?></p></details><?php submit_button( __( 'Save automation', 'zoe-cloud' ) ); ?></form></article>
				</section>

				<section id="zoecloud-panel-storage" class="zoecloud-tab-panel" role="tabpanel" aria-labelledby="zoecloud-tab-storage" data-zoecloud-panel="storage" hidden>
					<div class="zoecloud-section-heading"><div><p class="zoecloud-eyebrow"><?php esc_html_e( 'Off-site protection', 'zoe-cloud' ); ?></p><h1><?php esc_html_e( 'Cloud storage', 'zoe-cloud' ); ?></h1></div><span class="zoecloud-provider-status <?php echo $cloud_status['configured'] ? 'is-connected' : ''; ?>"><?php echo esc_html( $cloud_status['configured'] ? __( 'Configured', 'zoe-cloud' ) : __( 'Not configured', 'zoe-cloud' ) ); ?></span></div>
					<article class="zoecloud-card"><form method="post" action="options.php"><?php settings_fields( 'zoecloud_settings' ); ?><input type="hidden" name="zoecloud_settings[settings_section]" value="storage"><label class="zoecloud-field"><?php esc_html_e( 'Provider', 'zoe-cloud' ); ?><select id="zoecloud_storage_provider" name="zoecloud_settings[storage_provider]"><option value="r2" <?php selected( $settings['storage_provider'], 'r2' ); ?>>Cloudflare R2</option><option value="s3" <?php selected( $settings['storage_provider'], 's3' ); ?>>AWS S3</option></select></label><div class="zoecloud-provider-fields zoecloud-form-grid" data-zoecloud-provider-fields="r2"><label>R2 Account ID<input type="text" name="zoecloud_settings[r2_account_id]" value="<?php echo esc_attr( $settings['r2_account_id'] ); ?>"></label><label>Access Key ID<input type="text" name="zoecloud_settings[r2_access_key_id]" value="<?php echo esc_attr( $settings['r2_access_key_id'] ); ?>"></label><label>Secret Access Key<input type="password" name="zoecloud_settings[r2_secret_access_key]" autocomplete="new-password"><small><?php esc_html_e( 'Leave blank to keep the saved secret.', 'zoe-cloud' ); ?></small></label><label><?php esc_html_e( 'Bucket', 'zoe-cloud' ); ?><input type="text" name="zoecloud_settings[r2_bucket]" value="<?php echo esc_attr( $settings['r2_bucket'] ); ?>"></label><label><?php esc_html_e( 'Prefix', 'zoe-cloud' ); ?><input type="text" name="zoecloud_settings[r2_prefix]" value="<?php echo esc_attr( $settings['r2_prefix'] ); ?>"></label></div><div class="zoecloud-provider-fields zoecloud-form-grid" data-zoecloud-provider-fields="s3" hidden><label>Access Key ID<input type="text" name="zoecloud_settings[s3_access_key_id]" value="<?php echo esc_attr( $settings['s3_access_key_id'] ); ?>"></label><label>Secret Access Key<input type="password" name="zoecloud_settings[s3_secret_access_key]" autocomplete="new-password"><small><?php esc_html_e( 'Leave blank to keep the saved secret.', 'zoe-cloud' ); ?></small></label><label><?php esc_html_e( 'Bucket', 'zoe-cloud' ); ?><input type="text" name="zoecloud_settings[s3_bucket]" value="<?php echo esc_attr( $settings['s3_bucket'] ); ?>"></label><label><?php esc_html_e( 'Region', 'zoe-cloud' ); ?><input type="text" name="zoecloud_settings[s3_region]" value="<?php echo esc_attr( $settings['s3_region'] ); ?>"></label><label><?php esc_html_e( 'Prefix', 'zoe-cloud' ); ?><input type="text" name="zoecloud_settings[s3_prefix]" value="<?php echo esc_attr( $settings['s3_prefix'] ); ?>"></label></div><div class="zoecloud-actions"><?php submit_button( __( 'Save storage', 'zoe-cloud' ), 'primary', 'submit', false ); ?><button type="button" class="button" id="zoecloud-test-storage"><?php esc_html_e( 'Test connection', 'zoe-cloud' ); ?></button><div id="zoecloud-storage-feedback" class="zoecloud-feedback" aria-live="polite"></div></div></form></article>
					<article class="zoecloud-card zoecloud-list"><div class="zoecloud-card-heading"><div><h2><?php esc_html_e( 'Cloud recovery points', 'zoe-cloud' ); ?></h2><p><?php esc_html_e( 'Loading this list contacts the configured provider. Downloaded archives are verified before they become restorable.', 'zoe-cloud' ); ?></p></div><button type="button" class="button" id="zoecloud-load-cloud"><?php esc_html_e( 'Load cloud backups', 'zoe-cloud' ); ?></button></div><div class="zoecloud-table-wrap"><table class="widefat zoecloud-table"><thead><tr><th><?php esc_html_e( 'Object', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Modified', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Size', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Action', 'zoe-cloud' ); ?></th></tr></thead><tbody id="zoecloud-cloud-backups"><tr><td colspan="4"><?php esc_html_e( 'Load the remote list when you need to recover from cloud storage.', 'zoe-cloud' ); ?></td></tr></tbody></table></div><div id="zoecloud-cloud-feedback" class="zoecloud-feedback" aria-live="polite"></div></article>
				</section>

				<section id="zoecloud-panel-activity" class="zoecloud-tab-panel" role="tabpanel" aria-labelledby="zoecloud-tab-activity" data-zoecloud-panel="activity" hidden>
					<div class="zoecloud-section-heading"><div><p class="zoecloud-eyebrow"><?php esc_html_e( 'Persistent history', 'zoe-cloud' ); ?></p><h1><?php esc_html_e( 'Activity', 'zoe-cloud' ); ?></h1></div><select id="zoecloud-activity-filter" aria-label="<?php esc_attr_e( 'Filter activity', 'zoe-cloud' ); ?>"><option value="all"><?php esc_html_e( 'All activity', 'zoe-cloud' ); ?></option><option value="backup"><?php esc_html_e( 'Backups', 'zoe-cloud' ); ?></option><option value="restore"><?php esc_html_e( 'Restores', 'zoe-cloud' ); ?></option><option value="failed"><?php esc_html_e( 'Failed', 'zoe-cloud' ); ?></option></select></div><article class="zoecloud-card zoecloud-list"><div class="zoecloud-table-wrap"><table class="widefat zoecloud-table"><thead><tr><th><?php esc_html_e( 'Task', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Started', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Status', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Details', 'zoe-cloud' ); ?></th><th><?php esc_html_e( 'Log', 'zoe-cloud' ); ?></th></tr></thead><tbody id="zoecloud-activity-table"><tr><td colspan="5"><?php esc_html_e( 'Loading activity…', 'zoe-cloud' ); ?></td></tr></tbody></table></div></article>
				</section>
			</main>

			<dialog id="zoecloud-restore-dialog" class="zoecloud-dialog" aria-labelledby="zoecloud-restore-title"><form method="dialog"><button class="zoecloud-dialog-close" value="cancel" aria-label="<?php esc_attr_e( 'Close', 'zoe-cloud' ); ?>"><span class="dashicons dashicons-no-alt"></span></button></form><div class="zoecloud-dialog-content"><p class="zoecloud-eyebrow"><?php esc_html_e( 'Protected restore', 'zoe-cloud' ); ?></p><h2 id="zoecloud-restore-title"><?php esc_html_e( 'Review recovery point', 'zoe-cloud' ); ?></h2><div id="zoecloud-restore-plan" class="zoecloud-restore-plan" aria-live="polite"></div><div class="zoecloud-form-grid"><label><?php esc_html_e( 'Search URL', 'zoe-cloud' ); ?><input type="url" id="zoecloud-restore-search" value="<?php echo esc_attr( home_url() ); ?>"></label><label><?php esc_html_e( 'Replace URL', 'zoe-cloud' ); ?><input type="url" id="zoecloud-restore-replace" value="<?php echo esc_attr( home_url() ); ?>"></label></div><p><?php esc_html_e( 'ZoeCloud will create and verify a full local safety backup before changing the site.', 'zoe-cloud' ); ?></p><label class="zoecloud-field"><?php /* translators: %s: Current site hostname. */ printf( esc_html__( 'Type %s to confirm', 'zoe-cloud' ), '<strong>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</strong>' ); ?><input type="text" id="zoecloud-restore-hostname" autocomplete="off"></label><div id="zoecloud-restore-feedback" class="zoecloud-feedback" aria-live="polite"></div><div class="zoecloud-dialog-actions"><button type="button" class="button" id="zoecloud-cancel-restore"><?php esc_html_e( 'Cancel', 'zoe-cloud' ); ?></button><button type="button" class="button button-primary" id="zoecloud-run-restore" disabled><?php esc_html_e( 'Create safety backup & restore', 'zoe-cloud' ); ?></button></div></div></dialog>
		</div>
		<?php
	}
}
