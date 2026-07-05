<?php
/**
 * Admin settings page + option storage for Web Speed.
 *
 * Flow the UI walks the publisher through:
 *   1. Connect  — registers the site, stores the one-time token.
 *   2. Verify   — server checks the auto-served /.well-known file (one click).
 *   3. Publish  — toggles for on-publish push and the weekly baseline re-scan.
 *
 * @package WebSpeed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebSpeed_Settings {

	/**
	 * Default settings shape.
	 */
	public static function defaults() {
		return array(
			'site_id'         => 0,
			'domain'          => '',
			'site_token'      => '',
			'verify_token'    => '',
			'verified'        => false,
			'push_on_publish' => true,
			'weekly_baseline' => true,
			'last_error'      => '',
			'last_baseline'   => '',
		);
	}

	/**
	 * Read effective settings (defaults + stored overrides).
	 */
	public static function get() {
		$stored = get_option( WEBSPEED_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Merge a partial update into settings. Token/options are secrets, so the
	 * option is stored with autoload disabled.
	 *
	 * @param array $partial Keys to overwrite.
	 */
	public static function update( array $partial ) {
		$new = array_merge( self::get(), $partial );
		// add_option is a no-op if the option already exists, so this only sets
		// autoload='no' on first creation — which is exactly what we want.
		add_option( WEBSPEED_OPTION, $new, '', 'no' );
		update_option( WEBSPEED_OPTION, $new );
		return $new;
	}

	/**
	 * The site's own registrable domain (what we register + verify).
	 */
	public static function site_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = strtolower( (string) $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return $host;
	}

	// ── wiring ────────────────────────────────────────────────────────────────

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_webspeed_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_webspeed_verify', array( __CLASS__, 'handle_verify' ) );
		add_action( 'admin_post_webspeed_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_webspeed_rescan', array( __CLASS__, 'handle_rescan' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( WEBSPEED_PLUGIN_FILE ),
			array( __CLASS__, 'action_links' )
		);
	}

	public static function menu() {
		add_options_page(
			__( 'Web Speed', 'web-speed' ),
			__( 'Web Speed', 'web-speed' ),
			'manage_options',
			'web-speed',
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=web-speed' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'web-speed' ) . '</a>'
		);
		return $links;
	}

	// ── notices (one-shot, stored in a transient keyed to the user) ─────────────

	private static function flash( $type, $message ) {
		set_transient(
			'webspeed_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			30
		);
	}

	public static function notice() {
		$key    = 'webspeed_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		$class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $notice['message'] )
		);
	}

	private static function redirect_back() {
		wp_safe_redirect( admin_url( 'options-general.php?page=web-speed' ) );
		exit;
	}

	// ── action handlers ─────────────────────────────────────────────────────────

	public static function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'web-speed' ) );
		}
		check_admin_referer( 'webspeed_connect' );

		$domain = self::site_domain();
		$email  = isset( $_POST['webspeed_email'] )
			? sanitize_email( wp_unslash( $_POST['webspeed_email'] ) )
			: '';

		$res = WebSpeed_Client::register( $domain, $email );
		if ( is_wp_error( $res ) ) {
			self::flash(
				'error',
				sprintf(
				/* translators: %s: error detail */
					__( 'Could not connect: %s', 'web-speed' ),
					$res->get_error_message()
				)
			);
			self::redirect_back();
		}

		self::update(
			array(
				'site_id'      => isset( $res['site_id'] ) ? (int) $res['site_id'] : 0,
				'domain'       => isset( $res['domain'] ) ? (string) $res['domain'] : $domain,
				'site_token'   => isset( $res['site_token'] ) ? (string) $res['site_token'] : '',
				'verify_token' => isset( $res['verify_token'] ) ? (string) $res['verify_token'] : '',
				'verified'     => false,
				'last_error'   => '',
			)
		);
		self::flash( 'success', __( 'Connected. Now click “Verify domain” — Web Speed will check the file this plugin serves automatically.', 'web-speed' ) );
		self::redirect_back();
	}

	public static function handle_verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'web-speed' ) );
		}
		check_admin_referer( 'webspeed_verify' );

		$settings = self::get();
		if ( empty( $settings['site_token'] ) ) {
			self::flash( 'error', __( 'Connect your site first.', 'web-speed' ) );
			self::redirect_back();
		}

		$res = WebSpeed_Client::verify( $settings['site_token'] );
		if ( is_wp_error( $res ) || empty( $res['verified'] ) ) {
			$reason = is_wp_error( $res ) ? $res->get_error_message() : __( 'token not found at the well-known path', 'web-speed' );
			self::update(
				array(
					'verified'   => false,
					'last_error' => $reason,
				)
			);
			self::flash(
				'error',
				sprintf(
				/* translators: 1: failure reason, 2: well-known file path */
					__( 'Verification failed: %1$s. Make sure %2$s is publicly reachable.', 'web-speed' ),
					$reason,
					WEBSPEED_WELL_KNOWN_PATH
				)
			);
			self::redirect_back();
		}

		self::update(
			array(
				'verified'   => true,
				'last_error' => '',
			)
		);
		self::flash( 'success', __( 'Domain verified. Your pages will now publish to Web Speed on change, plus a weekly re-scan.', 'web-speed' ) );
		self::redirect_back();
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'web-speed' ) );
		}
		check_admin_referer( 'webspeed_save' );

		self::update(
			array(
				'push_on_publish' => ! empty( $_POST['push_on_publish'] ),
				'weekly_baseline' => ! empty( $_POST['weekly_baseline'] ),
			)
		);
		self::flash( 'success', __( 'Settings saved.', 'web-speed' ) );
		self::redirect_back();
	}

	public static function handle_rescan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'web-speed' ) );
		}
		check_admin_referer( 'webspeed_rescan' );

		$settings = self::get();
		if ( empty( $settings['verified'] ) ) {
			self::flash( 'error', __( 'Verify your domain before running a re-scan.', 'web-speed' ) );
			self::redirect_back();
		}

		$count = WebSpeed_Hooks::enqueue_all();
		self::flash(
			'success',
			sprintf(
			/* translators: %d: number of pages */
				_n( 'Queued %d page for re-scan. It will upload in the background.', 'Queued %d pages for re-scan. They will upload in the background.', $count, 'web-speed' ),
				$count
			)
		);
		self::redirect_back();
	}

	// ── page ────────────────────────────────────────────────────────────────────

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s          = self::get();
		$connected  = ! empty( $s['site_token'] );
		$verified   = ! empty( $s['verified'] );
		$domain     = self::site_domain();
		$dash_url   = WebSpeed_Client::api_base() . '/v1/plugin/dashboard?token=' . rawurlencode( $s['site_token'] );
		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Web Speed', 'web-speed' ); ?></h1>
			<p class="description" style="max-width:640px;">
				<?php esc_html_e( 'Web Speed publishes a fresh, first-party map of your pages to the agentic web, so AI agents read your content accurately and never quote a stale price or headline.', 'web-speed' ); ?>
			</p>

			<h2><?php esc_html_e( 'Status', 'web-speed' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th><?php esc_html_e( 'Site', 'web-speed' ); ?></th>
					<td><code><?php echo esc_html( $domain ); ?></code></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Connection', 'web-speed' ); ?></th>
					<td>
						<?php if ( $verified ) : ?>
							<span style="color:#008a20;font-weight:600;">● <?php esc_html_e( 'Verified & publishing', 'web-speed' ); ?></span>
						<?php elseif ( $connected ) : ?>
							<span style="color:#b26a00;font-weight:600;">● <?php esc_html_e( 'Connected — needs verification', 'web-speed' ); ?></span>
						<?php else : ?>
							<span style="color:#777;font-weight:600;">● <?php esc_html_e( 'Not connected', 'web-speed' ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $s['last_error'] ) ) : ?>
							<br><span style="color:#b32d2e;"><?php echo esc_html( $s['last_error'] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</tbody></table>

			<?php if ( ! $connected ) : ?>
				<hr>
				<h2><?php esc_html_e( '1. Connect your site', 'web-speed' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<input type="hidden" name="action" value="webspeed_connect">
					<?php wp_nonce_field( 'webspeed_connect' ); ?>
					<table class="form-table" role="presentation"><tbody>
						<tr>
							<th><label for="webspeed_email"><?php esc_html_e( 'Contact email (optional)', 'web-speed' ); ?></label></th>
							<td><input type="email" name="webspeed_email" id="webspeed_email" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
						</tr>
					</tbody></table>
					<?php submit_button( __( 'Connect to Web Speed', 'web-speed' ) ); ?>
				</form>

			<?php elseif ( ! $verified ) : ?>
				<hr>
				<h2><?php esc_html_e( '2. Verify your domain', 'web-speed' ); ?></h2>
				<p style="max-width:640px;">
					<?php
					printf(
						/* translators: %s: well-known path */
						esc_html__( 'This plugin already serves the verification file at %s — you should not need to upload anything. Click below and Web Speed will confirm it.', 'web-speed' ),
						'<code>' . esc_html( WEBSPEED_WELL_KNOWN_PATH ) . '</code>'
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<input type="hidden" name="action" value="webspeed_verify">
					<?php wp_nonce_field( 'webspeed_verify' ); ?>
					<?php submit_button( __( 'Verify domain', 'web-speed' ) ); ?>
				</form>

			<?php else : ?>
				<hr>
				<h2><?php esc_html_e( 'Publishing', 'web-speed' ); ?></h2>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>">
					<input type="hidden" name="action" value="webspeed_save">
					<?php wp_nonce_field( 'webspeed_save' ); ?>
					<table class="form-table" role="presentation"><tbody>
						<tr>
							<th><?php esc_html_e( 'Push on publish', 'web-speed' ); ?></th>
							<td><label><input type="checkbox" name="push_on_publish" value="1" <?php checked( $s['push_on_publish'] ); ?>> <?php esc_html_e( 'Update a page’s map whenever it is published or edited', 'web-speed' ); ?></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Weekly baseline', 'web-speed' ); ?></th>
							<td>
								<label><input type="checkbox" name="weekly_baseline" value="1" <?php checked( $s['weekly_baseline'] ); ?>> <?php esc_html_e( 'Re-scan all public pages once a week (catches anything missed)', 'web-speed' ); ?></label>
								<?php if ( ! empty( $s['last_baseline'] ) ) : ?>
									<p class="description">
									<?php
										/* translators: %s: date */
										printf( esc_html__( 'Last baseline: %s', 'web-speed' ), esc_html( $s['last_baseline'] ) );
									?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					</tbody></table>
					<?php submit_button( __( 'Save settings', 'web-speed' ) ); ?>
				</form>

				<hr>
				<h2><?php esc_html_e( 'Tools', 'web-speed' ); ?></h2>
				<p>
					<a class="button" href="<?php echo esc_url( $dash_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open analytics dashboard ↗', 'web-speed' ); ?></a>
				</p>
				<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="webspeed_rescan">
					<?php wp_nonce_field( 'webspeed_rescan' ); ?>
					<?php submit_button( __( 'Run a full re-scan now', 'web-speed' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
