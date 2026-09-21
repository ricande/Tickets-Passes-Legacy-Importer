<?php
/**
 * VM-only: run pending deferred emails for one order in a fresh process.
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$wp_path = getenv('TPFWLI_WP_PATH') ?: '/var/www/tickets-test';
require $wp_path . '/wp-load.php';
require dirname(__DIR__) . '/lib/deferred-email.php';

$order_id = (int) ($argv[1] ?? 0);
if ($order_id < 1) {
	fwrite(STDERR, "usage: vm-mailpit-queue.php ORDER_ID\n");
	exit(2);
}

$before = tpfwli_test_pending_queued_emails_for_order($order_id);
$ran    = tpfwli_test_run_queued_email_jobs($before);
$order  = wc_get_order($order_id);

echo wp_json_encode(array(
	'order_id'    => $order_id,
	'queued'      => array_map(static function ($job) {
		return $job['filter'];
	}, $before),
	'ran'         => $ran,
	'email_stage' => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'plugin'      => defined('TPFWLI_PLUGIN_FILE') ? TPFWLI_PLUGIN_FILE : '',
)) . PHP_EOL;
