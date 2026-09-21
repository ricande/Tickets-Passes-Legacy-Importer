<?php
/**
 * Run Confirm with WooCommerce deferred transactional emails enabled.
 * Dispatches DeferredEmailQueue before exit so Action Scheduler has the jobs.
 */
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require dirname(__DIR__) . '/lib/checkout-code.php';
require dirname(__DIR__) . '/lib/deferred-email.php';

$loaded = tpfwli_test_load_wordpress_and_checkout();
tpfwli_test_enable_deferred_transactional_emails();

$raw   = isset($argv[1]) && is_readable($argv[1]) ? file_get_contents($argv[1]) : stream_get_contents(STDIN);
$input = json_decode((string) $raw, true);
if (!is_array($input) || empty($input['input'])) {
	fwrite(STDERR, "invalid json\n");
	exit(1);
}

$mode     = isset($input['mode']) ? (string) $input['mode'] : 'confirm';
$capture  = !empty($input['capture_mail']);
$abort    = isset($input['abort']) ? (string) $input['abort'] : '';
$mail     = array();
if ($capture) {
	add_filter('pre_wp_mail', static function ($short, $atts) use (&$mail) {
		$mail[] = $atts;
		return true;
	}, 10, 2);
}

if ($abort === 'after_accepted_before_sent') {
	add_action('tpfwli_email_checkpoint', static function (string $point) {
		if ($point === 'after_accepted_before_sent') {
			throw new RuntimeException('email-checkpoint:after_accepted_before_sent');
		}
	}, 10, 1);
} elseif ($abort === 'after_completed') {
	add_action('tpfwli_lifecycle_checkpoint', static function (string $point) {
		if ($point === 'after_completed') {
			throw new RuntimeException('lifecycle-checkpoint:after_completed');
		}
	}, 10, 1);
}

$user = get_user_by('login', 'tpfwli_admin') ?: get_user_by('login', 'admin');
if ($user) {
	wp_set_current_user((int) $user->ID);
}

try {
	$result = (new TPFWLI_Orchestrator())->run($input['input'], $mode);
} catch (Throwable $e) {
	$result = array(
		'ok'     => false,
		'errors' => array($e->getMessage()),
		'order'  => null,
		'nanos'  => array(),
	);
	if (isset($input['input']['import_id'])) {
		$found = (new TPFWLI_Import_Repository())->find_by_import_id((string) $input['input']['import_id']);
		if (!empty($found['order']) && $found['order'] instanceof WC_Order) {
			$result['order'] = $found['order'];
		}
	}
}

tpfwli_test_dispatch_deferred_email_queue();

$order = $result['order'] ?? null;
$order_id = $order instanceof WC_Order ? (int) $order->get_id() : 0;
$queued = $order_id > 0 ? tpfwli_test_pending_queued_emails_for_order($order_id) : array();

echo wp_json_encode(array(
	'ok'                 => !empty($result['ok']) || !empty($result['email_already']),
	'errors'             => $result['errors'] ?? array(),
	'order_id'           => $order_id,
	'nanos'              => $result['nanos'] ?? array(),
	'email_already'      => !empty($result['email_already']),
	'email_unknown'      => !empty($result['email_unknown']),
	'email_stage'        => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'issue_stage'        => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) : '',
	'status'             => $order instanceof WC_Order ? $order->get_status() : '',
	'queued'             => array_map(static function ($job) {
		return array('id' => $job['id'], 'filter' => $job['filter']);
	}, $queued),
	'mail_count'         => count($mail),
	'mail_subjects'      => array_map(static function ($atts) {
		return (string) ($atts['subject'] ?? '');
	}, $mail),
	'loaded_plugin_file' => $loaded,
	'pid'                => getmypid(),
)) . PHP_EOL;
