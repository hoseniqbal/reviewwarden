<?php
/**
 * Plugin Name:       ReviewWarden: Stop Fake and Spam Product Reviews for WooCommerce
 * Plugin URI:        https://github.com/hoseniqbal/reviewwarden
 * Description:       Stops review bots with an invisible check, sends link and keyword spam to the spam folder, holds reviews from non-buyers and warns you about review attacks.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Iqbal Hossen
 * Author URI:        https://profiles.wordpress.org/iqbal511138/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reviewwarden
 * WC requires at least: 7.6
 * WC tested up to:   11.3
 *
 * @package ReviewWarden
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REVIEWWARDEN_VERSION', '1.0.0' );
define( 'REVIEWWARDEN_DB_VERSION', '1' );
define( 'REVIEWWARDEN_FILE', __FILE__ );
define( 'REVIEWWARDEN_PATH', plugin_dir_path( __FILE__ ) );
define( 'REVIEWWARDEN_URL', plugin_dir_url( __FILE__ ) );

require_once REVIEWWARDEN_PATH . 'includes/class-reviewwarden-settings.php';
require_once REVIEWWARDEN_PATH . 'includes/class-reviewwarden-log.php';
require_once REVIEWWARDEN_PATH . 'includes/class-reviewwarden-features.php';
require_once REVIEWWARDEN_PATH . 'includes/class-reviewwarden-admin.php';
require_once REVIEWWARDEN_PATH . 'includes/class-reviewwarden-plugin.php';

register_activation_hook( __FILE__, array( 'ReviewWarden_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ReviewWarden_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'ReviewWarden_Plugin', 'init' ) );
