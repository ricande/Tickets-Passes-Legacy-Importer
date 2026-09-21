<?php
/**
 * Run Confirm and abort at a stock checkpoint. Used to prove MySQL rolls back
 * an uncommitted stock transaction when the PHP process dies.
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require dirname(__DIR__) . '/lib/checkout-code.php';
$loaded_plugin_file = tpfwli_test_load_wordpress_and_checkout();

add_filter('pre_wp_mail', static function () {
	return true;
}, 10, 2);

$raw = stream_get_contents(STDIN);
$payload = json_decode((string) $raw, true);
if (!is_array($payload) || empty($payload['input']) || empty($payload['checkpoint'])) {
	fwrite(STDERR, "invalid json\n");
	exit(1);
}

$input      = $payload['input'];
$checkpoint = (string) $payload['checkpoint'];

add_action('tpfwli_stock_checkpoint', static function (string $point) use ($checkpoint): void {
	if ($point !== $checkpoint) {
		return;
	}
	if (function_exists('posix_kill')) {
		posix_kill(getmypid(), SIGKILL);
	}
	exit(99);
}, 10, 2);

$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
$order  = $result['order'] ?? null;

echo json_encode(array(
	'survived'           => true,
	'ok'                 => !empty($result['ok']),
	'errors'             => $result['errors'] ?? array(),
	'order_id'           => $order instanceof WC_Order ? (int) $order->get_id() : 0,
	'loaded_plugin_file' => $loaded_plugin_file,
	'pid'                => getmypid(),
));
