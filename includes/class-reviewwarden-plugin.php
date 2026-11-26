<?php
/**
 * Bootstrap.
 *
 * @package ReviewWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires everything together.
 */
class ReviewWarden_Plugin {

	/**
	 * Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( get_option( 'reviewwarden_db_version' ) !== REVIEWWARDEN_DB_VERSION ) {
			ReviewWarden_Log::install();
			ReviewWarden_Features::install();
		}
		add_action( 'reviewwarden_hourly', array( 'ReviewWarden_Features', 'hourly' ) );
		add_action( 'reviewwarden_daily', array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		ReviewWarden_Features::init();
		if ( is_admin() ) {
			ReviewWarden_Admin::init();
		}
	}

	/**
	 * Make sure the jobs are scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'reviewwarden_hourly' ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'reviewwarden_hourly' );
		}
		if ( ! wp_next_scheduled( 'reviewwarden_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'reviewwarden_daily' );
		}
	}

	/**
	 * Daily housekeeping.
	 *
	 * @return void
	 */
	public static function daily() {
		ReviewWarden_Log::purge();
		ReviewWarden_Features::daily();
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		ReviewWarden_Log::install();
		if ( class_exists( 'WooCommerce' ) ) {
			ReviewWarden_Features::install();
			ReviewWarden_Features::activate();
		}
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'reviewwarden_hourly' );
		wp_clear_scheduled_hook( 'reviewwarden_daily' );
	}
}
