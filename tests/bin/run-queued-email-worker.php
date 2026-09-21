<?php
/**
 * Fresh WordPress bootstrap that runs only this order's pending deferred emails.
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require dirname(__DIR__) . '/lib/checkout-code.php';
require dirname(__DIR__) . '/lib/deferred-email.php';

$loaded = tpfwli_test_load_wordpress_and_checkout();

$raw   = isset($argv[1]) && is_readable($argv[1]) ? file_get_contents($argv[1]) : stream_get_contents(STDIN);
$input = json_decode((string) $raw, true);
if (!is_array($input) || empty($input['order_id'])) {
	fwrite(STDERR, "invalid json\n");
	exit(1);
}

$order_id = (int) $input['order_id'];
$capture  = !empty($input['capture_mail']);
$mail     = array();
if ($capture) {
	add_filter('pre_wp_mail', static function ($short, $atts) use (&$mail) {
		$mail[] = $atts;
		return true;
	}, 10, 2);
}

$user = get_user_by('login', 'tpfwli_admin') ?: get_user_by('login', 'admin');
if ($user) {
	wp_set_current_user((int) $user->ID);
}

$before = tpfwli_test_pending_queued_emails_for_order($order_id);
$ran    = tpfwli_test_run_queued_email_jobs($before);
$after  = tpfwli_test_pending_queued_emails_for_order($order_id);
$order  = wc_get_order($order_id);

echo wp_json_encode(array(
	'order_id'           => $order_id,
	'queued_before'      => array_map(static function ($job) {
		return array('id' => $job['id'], 'filter' => $job['filter']);
	}, $before),
	'ran'                => $ran,
	'queued_after'       => array_map(static function ($job) {
		return array('id' => $job['id'], 'filter' => $job['filter']);
	}, $after),
	'email_stage'        => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'status'             => $order instanceof WC_Order ? $order->get_status() : '',
	'mail_count'         => count($mail),
	'mail_subjects'      => array_map(static function ($atts) {
		return (string) ($atts['subject'] ?? '');
	}, $mail),
	'loaded_plugin_file' => $loaded,
	'pid'                => getmypid(),
)) . PHP_EOL;
