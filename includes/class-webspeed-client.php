<?php
/**
 * Web Speed API client — thin wrapper over the /v1/plugin/* endpoints.
 *
 * All network I/O for the plugin lives here so the contract is defined in exactly
 * one place. Every method returns either a decoded array on success or a WP_Error
 * carrying a machine code + human message the settings UI can surface.
 *
 * @package WebSpeed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WebSpeed_Client {

	/**
	 * Resolve the API base URL (constant, overridable by filter).
	 */
	public static function api_base() {
		$base = defined( 'WEBSPEED_API_BASE' ) ? WEBSPEED_API_BASE : 'https://api.getwebspeed.io';
		/**
		 * Filter the Web Speed API base URL (e.g. to point at staging).
		 *
		 * @param string $base Base URL, no trailing slash.
		 */
		$base = apply_filters( 'webspeed_api_base', $base );
		return untrailingslashit( $base );
	}

	/**
	 * The User-Agent / plugin_version string sent with every request.
	 */
	public static function version() {
		return 'WebSpeed-WP/' . WEBSPEED_VERSION;
	}

	/**
	 * Register this site. Returns the one-time token bundle or WP_Error.
	 *
	 * @param string $domain Registrable domain (no scheme).
	 * @param string $email  Optional contact email.
	 * @return array|WP_Error {site_id, domain, site_token, verify_token, verify_path, next}
	 */
	public static function register( $domain, $email = '' ) {
		$resp = wp_remote_post(
			self::api_base() . '/v1/plugin/register',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'domain' => $domain,
						'email'  => $email,
					)
				),
			)
		);
		return self::handle( $resp, 'register' );
	}

	/**
	 * Ask the server to verify domain ownership via the well-known file.
	 *
	 * @param string $site_token The raw wsp_site_ token.
	 * @return array|WP_Error {verified, domain} on success.
	 */
	public static function verify( $site_token ) {
		$resp = wp_remote_get(
			self::api_base() . '/v1/plugin/verify',
			array(
				'timeout' => 20,
				'headers' => array( 'X-Web-Speed-Site-Key' => $site_token ),
			)
		);
		return self::handle( $resp, 'verify' );
	}

	/**
	 * Push one rendered page to the registry.
	 *
	 * @param string $site_token    Raw site token.
	 * @param string $url           Canonical URL of the page.
	 * @param string $rendered_html Full rendered HTML (server-emitted).
	 * @param string $trigger       'publish' | 'baseline' | 'manual'.
	 * @return array|WP_Error {status:'stored', page_type, actions} on success.
	 */
	public static function ingest( $site_token, $url, $rendered_html, $trigger = 'publish' ) {
		$payload = array(
			'url'            => $url,
			'rendered_html'  => $rendered_html,
			'trigger'        => $trigger,
			'captured_at'    => gmdate( 'c' ),
			'content_hash'   => substr( hash( 'sha256', $rendered_html ), 0, 24 ),
			'plugin_version' => self::version(),
		);
		$resp    = wp_remote_post(
			self::api_base() . '/v1/plugin/ingest',
			array(
				'timeout' => 25,
				'headers' => array(
					'Content-Type'         => 'application/json',
					'X-Web-Speed-Site-Key' => $site_token,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		return self::handle( $resp, 'ingest' );
	}

	/**
	 * Normalize a wp_remote_* result into array|WP_Error.
	 *
	 * A 2xx returns the decoded body. Any other status becomes a WP_Error whose
	 * code is the server's `error`/`reason` field when present, so callers can
	 * branch on e.g. 'unverified_site', 'low_trust', 'rate_limited'.
	 *
	 * @param array|WP_Error $resp    Result of wp_remote_*.
	 * @param string         $context Which call produced it (for messages).
	 * @return array|WP_Error
	 */
	private static function handle( $resp, $context ) {
		if ( is_wp_error( $resp ) ) {
			return new WP_Error(
				'transport',
				/* translators: 1: API call name, 2: transport error message */
				sprintf( __( 'Could not reach Web Speed (%1$s): %2$s', 'web-speed' ), $context, $resp->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( $code >= 200 && $code < 300 ) {
			return $body;
		}

		$reason = '';
		if ( isset( $body['error'] ) ) {
			$reason = (string) $body['error'];
		} elseif ( isset( $body['reason'] ) ) {
			$reason = (string) $body['reason'];
		}
		$detail  = isset( $body['detail'] ) ? (string) $body['detail'] : '';
		$message = $detail ? $detail : ( $reason ? $reason : sprintf( 'HTTP %d', $code ) );

		return new WP_Error(
			$reason ? $reason : 'http_' . $code,
			$message,
			array(
				'status' => $code,
				'body'   => $body,
			)
		);
	}
}
