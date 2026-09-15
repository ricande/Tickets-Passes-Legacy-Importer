<?php
/**
 * Boot the local WordPress install so importer tests run against real WooCommerce + TPFW.
 */
$wp = getenv('TPFWLI_WP_PATH') ?: '/var/www/woocommerce';
if (!is_readable($wp . '/wp-load.php')) {
	fwrite(STDERR, "WordPress not found at {$wp}. Set TPFWLI_WP_PATH.\n");
	exit(1);
}

define('WP_USE_THEMES', false);
require $wp . '/wp-load.php';

if (!class_exists('WooCommerce') || !defined('TPFW_VERSION')) {
	fwrite(STDERR, "WooCommerce and Tickets & Passes for WooCommerce must be active.\n");
	exit(1);
}

if (!class_exists('TPFWLI_Plugin')) {
	require dirname(__DIR__) . '/tickets-passes-legacy-importer.php';
	TPFWLI_Plugin::instance()->boot();
}

if (!class_exists('TPFWLI_Orchestrator')) {
	fwrite(STDERR, "Legacy importer classes failed to load.\n");
	exit(1);
}
