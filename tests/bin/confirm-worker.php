<?php
/**
 * Concurrent Confirm worker. Reads one JSON input from stdin and runs confirm().
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
register_shutdown_function(static function () {
	$last = error_get_last();
	if (is_array($last) && in_array((int) $last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
		fwrite(STDERR, $last['message'] . ' in ' . $last['file'] . ':' . $last['line'] . "\n");
	}
});

$wp_root = getenv('TPFWLI_WP_PATH') ?: '/var/www/woocommerce';
if (!is_readable($wp_root . '/wp-load.php')) {
	fwrite(STDERR, "WordPress not found at {$wp_root}\n");
	exit(1);
}

define('WP_USE_THEMES', false);
require $wp_root . '/wp-load.php';

if (!class_exists('TPFWLI_Orchestrator')) {
	require dirname(__DIR__, 2) . '/tickets-passes-legacy-importer.php';
	TPFWLI_Plugin::instance()->boot();
}

$raw = stream_get_contents(STDIN);
$input = json_decode((string) $raw, true);
if (!is_array($input)) {
	fwrite(STDERR, "invalid json\n");
	exit(1);
}

add_filter('pre_wp_mail', static function () {
	return true;
}, 10, 2);

$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
$order  = $result['order'] ?? null;

echo json_encode(array(
	'ok'            => !empty($result['ok']) || !empty($result['email_already']),
	'errors'        => $result['errors'] ?? array(),
	'order_id'      => $order instanceof WC_Order ? (int) $order->get_id() : 0,
	'nanos'         => $result['nanos'] ?? array(),
	'email_already' => !empty($result['email_already']),
	'stock_stage'   => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_STOCK_STAGE) : '',
	'email_stage'   => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'pid'           => getmypid(),
));
