<?php
/**
 * Plugin Name: Tickets & Passes – Legacy Ticket Importer
 * Description: Temporary admin tool that turns a historical ticket sale into a real WooCommerce guest order and lets Tickets &amp; Passes for WooCommerce issue the QR tickets.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: ricande
 * Text Domain: tickets-passes-legacy-importer
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

define('TPFWLI_VERSION', '1.0.0');
define('TPFWLI_PLUGIN_FILE', __FILE__);
define('TPFWLI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TPFWLI_MIN_TPFW', '1.3.0');

require_once TPFWLI_PLUGIN_DIR . 'includes/class-tpfwli-plugin.php';

add_action('plugins_loaded', static function () {
	TPFWLI_Plugin::instance()->boot();
}, 20);

add_action('before_woocommerce_init', static function () {
	if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		return;
	}
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', TPFWLI_PLUGIN_FILE, true);
});
