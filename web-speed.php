<?php
/**
 * Plugin Name:       Web Speed
 * Plugin URI:        https://getwebspeed.io/wordpress
 * Description:        Publishes fresh, first-party maps of your site to the Web Speed registry so AI agents read your pages accurately and never see a stale price or headline. Pushes on publish and re-scans weekly.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Web Speed
 * Author URI:        https://getwebspeed.io
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       web-speed
 *
 * @package WebSpeed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'WEBSPEED_VERSION', '1.0.0' );
define( 'WEBSPEED_PLUGIN_FILE', __FILE__ );
define( 'WEBSPEED_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBSPEED_OPTION', 'webspeed_settings' );
define( 'WEBSPEED_WELL_KNOWN_PATH', '/.well-known/webspeed-verify.txt' );

// Cron hook names.
define( 'WEBSPEED_CRON_BASELINE', 'webspeed_baseline_cron' );
define( 'WEBSPEED_CRON_DRAIN', 'webspeed_drain_cron' );
define( 'WEBSPEED_CRON_PUSH', 'webspeed_push_url' );

/**
 * Production API base. Override with the WEBSPEED_API_BASE constant (wp-config.php)
 * or the `webspeed_api_base` filter for staging / self-hosted registries.
 */
if ( ! defined( 'WEBSPEED_API_BASE' ) ) {
	define( 'WEBSPEED_API_BASE', 'https://api.getwebspeed.io' );
}

require_once WEBSPEED_PLUGIN_DIR . 'includes/class-webspeed-client.php';
require_once WEBSPEED_PLUGIN_DIR . 'includes/class-webspeed-settings.php';
require_once WEBSPEED_PLUGIN_DIR . 'includes/class-webspeed-hooks.php';

// Register the custom cron intervals at load time (NOT on a hook). During plugin
// activation `plugins_loaded` has already fired, so a filter added there would not
// run — registering here guarantees the schedules exist when webspeed_activate()
// schedules the recurring baseline/drain events.
add_filter( 'cron_schedules', array( 'WebSpeed_Hooks', 'cron_intervals' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- interval (min 900s / 15 min) is defined in WebSpeed_Hooks::cron_intervals; the sniff cannot follow a cross-file callback.

/**
 * Boot the plugin once WordPress is ready.
 */
function webspeed_boot() {
	WebSpeed_Settings::init();
	WebSpeed_Hooks::init();
}
add_action( 'plugins_loaded', 'webspeed_boot' );

/**
 * Activation: register the recurring cron events. Verification is intercepted in
 * `init` by request path, so no rewrite-rule flush is needed.
 */
function webspeed_activate() {
	if ( ! wp_next_scheduled( WEBSPEED_CRON_BASELINE ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'webspeed_weekly', WEBSPEED_CRON_BASELINE );
	}
	if ( ! wp_next_scheduled( WEBSPEED_CRON_DRAIN ) ) {
		wp_schedule_event( time() + 900, 'webspeed_15min', WEBSPEED_CRON_DRAIN );
	}
}
register_activation_hook( __FILE__, 'webspeed_activate' );

/**
 * Deactivation: clear all scheduled events (data/options are left intact; a full
 * teardown happens in uninstall.php).
 */
function webspeed_deactivate() {
	wp_clear_scheduled_hook( WEBSPEED_CRON_BASELINE );
	wp_clear_scheduled_hook( WEBSPEED_CRON_DRAIN );
	wp_clear_scheduled_hook( WEBSPEED_CRON_PUSH );
}
register_deactivation_hook( __FILE__, 'webspeed_deactivate' );
