<?php
/**
 * Plugin Name:       Lucky Egg
 * Description:       تخم‌مرغ شانسی تعاملی با کد قرعه‌کشی یکتا، ارسال پیامک، چند کمپینی، شورت‌کد و ویجت المنتور.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Lucky Egg
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lucky-egg
 * Domain Path:       /languages
 * WC tested up to:   9.0
 *
 * @package LuckyEgg
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LUCKY_EGG_VERSION', '1.1.0' );
define( 'LUCKY_EGG_DB_VERSION', '1.1.0' );
define( 'LUCKY_EGG_FILE', __FILE__ );
define( 'LUCKY_EGG_PATH', plugin_dir_path( __FILE__ ) );
define( 'LUCKY_EGG_URL', plugin_dir_url( __FILE__ ) );
define( 'LUCKY_EGG_BASENAME', plugin_basename( __FILE__ ) );

require_once LUCKY_EGG_PATH . 'includes/class-lucky-egg-activator.php';
require_once LUCKY_EGG_PATH . 'includes/class-lucky-egg.php';

register_activation_hook( __FILE__, array( 'Lucky_Egg_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Lucky_Egg_Activator', 'deactivate' ) );

/**
 * Returns the plugin instance.
 *
 * @return Lucky_Egg
 */
function lucky_egg() {
	return Lucky_Egg::instance();
}

add_action( 'plugins_loaded', 'lucky_egg' );

/**
 * Declares compatibility with WooCommerce High-Performance Order Storage.
 * Lucky Egg never reads or writes orders, so it is compatible by design.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LUCKY_EGG_FILE, true );
		}
	}
);
