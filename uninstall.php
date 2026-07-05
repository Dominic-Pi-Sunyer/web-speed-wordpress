<?php
/**
 * Uninstall cleanup for Web Speed.
 *
 * Runs only when the plugin is deleted from the Plugins screen. The main plugin
 * file is NOT loaded here, so hook names are referenced as literals rather than
 * via the WEBSPEED_CRON_* constants.
 *
 * @package WebSpeed
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'webspeed_settings' );
delete_option( 'webspeed_queue' );

wp_clear_scheduled_hook( 'webspeed_baseline_cron' );
wp_clear_scheduled_hook( 'webspeed_drain_cron' );
wp_clear_scheduled_hook( 'webspeed_push_url' );
