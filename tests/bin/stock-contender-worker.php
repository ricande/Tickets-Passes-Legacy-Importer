<?php
/**
 * Wait until a holder signals it has the product row lock, then decrease stock.
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require dirname(__DIR__) . '/lib/checkout-code.php';
$loaded_plugin_file = tpfwli_test_load_wordpress_and_checkout();

$raw = stream_get_contents(STDIN);
$payload = json_decode((string) $raw, true);
if (!is_array($payload) || empty($payload['product_id']) || empty($payload['ready_file'])) {
	fwrite(STDERR, "invalid json\n");
	exit(1);
}

$ready_file = (string) $payload['ready_file'];
$deadline   = microtime(true) + 10;
while (!is_file($ready_file) && microtime(true) < $deadline) {
	usleep(20000);
}
if (!is_file($ready_file)) {
	fwrite(STDERR, "holder never became ready\n");
	exit(2);
}

$product_id = (int) $payload['product_id'];
$qty        = max(1, (int) ($payload['quantity'] ?? 1));
$before     = null;
$product    = wc_get_product($product_id);
if ($product instanceof WC_Product) {
	$before = $product->get_stock_quantity();
	wc_update_product_stock($product, $qty, 'decrease');
}
$after = wc_get_product($product_id);

echo json_encode(array(
	'ok'                 => $after instanceof WC_Product,
	'before'             => $before,
	'after'              => $after instanceof WC_Product ? $after->get_stock_quantity() : null,
	'loaded_plugin_file' => $loaded_plugin_file,
	'pid'                => getmypid(),
));
