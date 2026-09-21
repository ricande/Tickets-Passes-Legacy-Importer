<?php
/**
 * Run Confirm and abort at a stock checkpoint. Used to prove MySQL rolls back
 * an uncommitted stock transaction when the PHP process dies.
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$wc_txn = getenv('TPFWLI_WC_USE_TRANSACTIONS');
if ($wc_txn === '0' || $wc_txn === 'false') {
	define('WC_USE_TRANSACTIONS', false);
}

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
$abort      = (string) ($payload['abort'] ?? 'kill');
$ready_file = (string) ($payload['ready_file'] ?? '');
$wait_file  = (string) ($payload['wait_file'] ?? '');

add_action('tpfwli_stock_checkpoint', static function (string $point) use ($checkpoint, $abort, $ready_file, $wait_file): void {
	if ($point !== $checkpoint) {
		return;
	}
	if ($ready_file !== '') {
		file_put_contents($ready_file, (string) getmypid());
	}
	if ($wait_file !== '') {
		$deadline = microtime(true) + 10;
		while (!is_file($wait_file) && microtime(true) < $deadline) {
			usleep(20000);
		}
	}
	if ($abort === 'throw') {
		throw new RuntimeException('stock-checkpoint:' . $checkpoint);
	}
	if (function_exists('posix_kill')) {
		posix_kill(getmypid(), SIGKILL);
	}
	exit(99);
}, 10, 2);

try {
	$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
} catch (Throwable $e) {
	$result = array(
		'ok'     => false,
		'errors' => array($e->getMessage()),
		'order'  => null,
	);
}
$order = $result['order'] ?? null;

echo json_encode(array(
	'survived'             => true,
	'ok'                   => !empty($result['ok']),
	'errors'               => $result['errors'] ?? array(),
	'order_id'             => $order instanceof WC_Order ? (int) $order->get_id() : 0,
	'wc_use_transactions'  => defined('WC_USE_TRANSACTIONS') ? WC_USE_TRANSACTIONS : null,
	'loaded_plugin_file'   => $loaded_plugin_file,
	'pid'                  => getmypid(),
));
