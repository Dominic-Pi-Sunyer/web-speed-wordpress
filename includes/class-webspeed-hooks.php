<?php
/**
 * Web Speed runtime hooks.
 *
 * Three jobs:
 *   • Serve /.well-known/webspeed-verify.txt so domain verification is one click.
 *   • Push a page's rendered HTML to the registry when it is published/edited.
 *   • Run a weekly baseline that re-scans every public page (a rate-limit-safe
 *     background queue drains it), so nothing an edit-hook missed goes stale.
 *
 * Only PUBLIC, PUBLISHED, non-password content is ever sent. The server re-checks
 * shareability regardless, but we never send private/draft/personalized pages.
 *
 * @package WebSpeed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebSpeed_Hooks {

	const QUEUE_OPTION   = 'webspeed_queue';
	const MAX_HTML_BYTES = 5242880; // 5 MB — matches the server's ingest cap.

	public static function init() {
		// Priority 1 so we answer the well-known request before anything else routes.
		add_action( 'init', array( __CLASS__, 'maybe_serve_well_known' ), 1 );

		// The `cron_schedules` filter is registered at load time in the main plugin
		// file (web-speed.php) so the custom intervals also exist during activation.

		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );

		add_action( WEBSPEED_CRON_PUSH, array( __CLASS__, 'cron_push' ), 10, 2 );
		add_action( WEBSPEED_CRON_BASELINE, array( __CLASS__, 'run_baseline' ) );
		add_action( WEBSPEED_CRON_DRAIN, array( __CLASS__, 'drain_queue' ) );
	}

	// ── domain verification (auto-served file) ──────────────────────────────────

	/**
	 * If this request is for the well-known verification path, print the token
	 * and stop. Runs on every request but no-ops instantly unless the path matches.
	 */
	public static function maybe_serve_well_known() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( WEBSPEED_WELL_KNOWN_PATH !== $path ) {
			return;
		}
		$settings = WebSpeed_Settings::get();
		if ( empty( $settings['verify_token'] ) ) {
			return; // nothing to prove yet — let WP 404 it normally.
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		// The token is a server-generated URL-safe random string; emit it verbatim
		// so it byte-matches what the server expects.
		echo esc_html( $settings['verify_token'] );
		exit;
	}

	// ── cron schedules ──────────────────────────────────────────────────────────

	public static function cron_intervals( $schedules ) {
		$schedules['webspeed_15min']  = array(
			'interval' => 900,
			'display'  => __( 'Every 15 minutes (Web Speed queue)', 'web-speed' ),
		);
		$schedules['webspeed_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once weekly (Web Speed baseline)', 'web-speed' ),
		);
		return $schedules;
	}

	// ── on-publish push ─────────────────────────────────────────────────────────

	/**
	 * When a post enters 'publish' (new or edited), schedule a deferred push so the
	 * editor save is never blocked by a network call.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			return;
		}
		$settings = WebSpeed_Settings::get();
		if ( empty( $settings['verified'] ) || empty( $settings['push_on_publish'] ) ) {
			return;
		}
		if ( ! self::is_pushable_post( $post ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( ! $url ) {
			return;
		}
		$args = array( $url, 'publish' );
		if ( ! wp_next_scheduled( WEBSPEED_CRON_PUSH, $args ) ) {
			wp_schedule_single_event( time() + 5, WEBSPEED_CRON_PUSH, $args );
		}
	}

	/**
	 * Cron target for a single deferred push. Never throws.
	 */
	public static function cron_push( $url, $trigger = 'publish' ) {
		self::push_url( $url, $trigger );
	}

	// ── weekly baseline + queue drain ───────────────────────────────────────────

	/**
	 * Weekly: enqueue every public page for a re-scan (drained in the background).
	 */
	public static function run_baseline() {
		$settings = WebSpeed_Settings::get();
		if ( empty( $settings['verified'] ) || empty( $settings['weekly_baseline'] ) ) {
			return;
		}
		self::enqueue_all();
		WebSpeed_Settings::update( array( 'last_baseline' => gmdate( 'Y-m-d H:i' ) . ' UTC' ) );
	}

	/**
	 * Populate the push queue with every public, published URL. Used by the weekly
	 * baseline and the manual "Run a full re-scan" button. Returns the count added.
	 */
	public static function enqueue_all() {
		$types = self::public_post_types();
		if ( empty( $types ) ) {
			return 0;
		}
		$urls     = array();
		$paged    = 1;
		$per_page = 100;
		do {
			$ids = get_posts(
				array(
					'post_type'      => $types,
					'post_status'    => 'publish',
					'posts_per_page' => $per_page,
					'paged'          => $paged,
					'fields'         => 'ids',
					'has_password'   => false,
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $ids as $id ) {
				$url = get_permalink( $id );
				if ( $url ) {
					$urls[] = $url;
				}
			}
			$found = count( $ids );
			++$paged;
		} while ( $per_page === $found && $paged < 1000 ); // safety cap ~100k pages.

		$queue = array_values( array_unique( array_merge( self::get_queue(), $urls ) ) );
		self::set_queue( $queue );

		if ( ! wp_next_scheduled( WEBSPEED_CRON_DRAIN ) ) {
			wp_schedule_event( time() + 30, 'webspeed_15min', WEBSPEED_CRON_DRAIN );
		}
		return count( $urls );
	}

	/**
	 * Process a small batch from the queue. Backs off on a server rate-limit so we
	 * never exceed the ingest window (120/60s/site); the next tick resumes.
	 */
	public static function drain_queue() {
		$settings = WebSpeed_Settings::get();
		if ( empty( $settings['verified'] ) ) {
			return;
		}
		$queue = self::get_queue();
		if ( empty( $queue ) ) {
			return;
		}
		$batch     = (int) apply_filters( 'webspeed_drain_batch', 15 );
		$processed = 0;
		while ( $processed < $batch && ! empty( $queue ) ) {
			$url = array_shift( $queue );
			$res = self::push_url( $url, 'baseline' );
			if ( is_wp_error( $res ) && 'rate_limited' === $res->get_error_code() ) {
				array_unshift( $queue, $url ); // put it back; retry next tick.
				break;
			}
			++$processed;
		}
		self::set_queue( $queue );
	}

	// ── core push ───────────────────────────────────────────────────────────────

	/**
	 * Fetch a page's rendered HTML and send it to the registry.
	 *
	 * @return array|WP_Error Server response, or a WP_Error describing the failure.
	 */
	public static function push_url( $url, $trigger = 'publish' ) {
		$settings = WebSpeed_Settings::get();
		if ( empty( $settings['verified'] ) || empty( $settings['site_token'] ) ) {
			return new WP_Error( 'not_verified', 'site is not verified' );
		}
		$html = self::fetch_rendered_html( $url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}
		if ( null === $html ) {
			return new WP_Error( 'empty', 'page returned no HTML' );
		}

		$res = WebSpeed_Client::ingest( $settings['site_token'], $url, $html, $trigger );

		if ( is_wp_error( $res ) ) {
			$code = $res->get_error_code();
			// Surface auth/trust problems in the admin UI so the publisher notices.
			if ( 'unverified_site' === $code ) {
				WebSpeed_Settings::update(
					array(
						'verified'   => false,
						'last_error' => $res->get_error_message(),
					)
				);
			} elseif ( 'low_trust' === $code ) {
				WebSpeed_Settings::update( array( 'last_error' => $res->get_error_message() ) );
			}
		}
		return $res;
	}

	/**
	 * GET the page as a normal client would, returning the fully rendered HTML
	 * (theme, menus and all). WordPress is server-rendered, so this is faithful.
	 *
	 * @return string|null|WP_Error HTML, null if empty, WP_Error on failure.
	 */
	private static function fetch_rendered_html( $url ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => true,
				'user-agent'  => WebSpeed_Client::version() . ' (+https://getwebspeed.io)',
				'headers'     => array( 'X-Web-Speed-Plugin' => WEBSPEED_VERSION ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			return new WP_Error( 'bad_status', sprintf( 'origin returned HTTP %d', $code ) );
		}
		$body = wp_remote_retrieve_body( $resp );
		if ( '' === $body ) {
			return null;
		}
		if ( strlen( $body ) > self::MAX_HTML_BYTES ) {
			return new WP_Error( 'too_large', 'page exceeds the 5 MB limit' );
		}
		return $body;
	}

	// ── helpers ─────────────────────────────────────────────────────────────────

	/**
	 * Public, agent-relevant post types (posts, pages, public CPTs), minus system
	 * types. Filterable via `webspeed_post_types` / `webspeed_excluded_post_types`.
	 */
	public static function public_post_types() {
		$types    = get_post_types( array( 'public' => true ), 'names' );
		$excluded = apply_filters(
			'webspeed_excluded_post_types',
			array(
				'attachment',
				'nav_menu_item',
				'custom_css',
				'customize_changeset',
				'revision',
				'wp_block',
				'wp_template',
				'wp_template_part',
				'wp_navigation',
			)
		);
		$types    = array_filter(
			$types,
			static function ( $t ) {
				return is_post_type_viewable( $t );
			}
		);
		$types    = array_values( array_diff( $types, $excluded ) );
		/**
		 * Filter the final list of post types Web Speed publishes.
		 *
		 * @param string[] $types Post type names.
		 */
		return apply_filters( 'webspeed_post_types', $types );
	}

	private static function is_pushable_post( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( ! empty( $post->post_password ) ) {
			return false;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		return in_array( $post->post_type, self::public_post_types(), true );
	}

	private static function get_queue() {
		$q = get_option( self::QUEUE_OPTION, array() );
		return is_array( $q ) ? $q : array();
	}

	private static function set_queue( $queue ) {
		$queue = array_values( $queue );
		add_option( self::QUEUE_OPTION, $queue, '', 'no' );
		update_option( self::QUEUE_OPTION, $queue );
	}
}
