<?php
/**
 * VM-only: deferred import against /var/www/tickets-test. Does not assert host checkout.
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$wp_path = getenv('TPFWLI_WP_PATH') ?: '/var/www/tickets-test';
require $wp_path . '/wp-load.php';
require dirname(__DIR__) . '/lib/deferred-email.php';

tpfwli_test_enable_deferred_transactional_emails();
$user = get_user_by('login', 'admin');
if ($user) {
	wp_set_current_user((int) $user->ID);
}

$product_id = (int) ($argv[1] ?? 0);
$email      = (string) ($argv[2] ?? '');
$import_id  = (string) ($argv[3] ?? wp_generate_uuid4());
if ($product_id < 1 || $email === '') {
	fwrite(STDERR, "usage: vm-mailpit-import.php PRODUCT_ID EMAIL [IMPORT_ID]\n");
	exit(2);
}

$result = (new TPFWLI_Orchestrator())->run(array(
	'product_id' => $product_id,
	'first_name' => 'VmMail',
	'last_name'  => 'Pit',
	'email'      => $email,
	'phone'      => '0701234567',
	'quantity'   => '1',
	'import_id'  => $import_id,
), 'confirm');
tpfwli_test_dispatch_deferred_email_queue();
$order = $result['order'] ?? null;
$order_id = $order instanceof WC_Order ? (int) $order->get_id() : 0;
$queued = $order_id > 0 ? tpfwli_test_pending_queued_emails_for_order($order_id) : array();

echo wp_json_encode(array(
	'ok'          => !empty($result['ok']),
	'errors'      => $result['errors'] ?? array(),
	'order_id'    => $order_id,
	'nanos'       => $result['nanos'] ?? array(),
	'email_stage' => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'queued'      => array_map(static function ($job) {
		return array('id' => $job['id'], 'filter' => $job['filter']);
	}, $queued),
	'plugin'      => defined('TPFWLI_PLUGIN_FILE') ? TPFWLI_PLUGIN_FILE : '',
	'revision'    => tpfwli_test_loaded_revision(),
)) . PHP_EOL;
