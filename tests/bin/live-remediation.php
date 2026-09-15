<?php
/**
 * Disposable live qualification for the remediation (lives 1–6).
 * Run as www-data against /var/www/woocommerce. Does not delete plugins.
 */
$wp_root = getenv('TPFWLI_WP_PATH') ?: '/var/www/woocommerce';
require $wp_root . '/wp-load.php';

if (!class_exists('TPFWLI_Orchestrator')) {
	fwrite(STDERR, "Importer not loaded\n");
	exit(1);
}

$mail = array();
add_filter('pre_wp_mail', static function ($short, $atts) use (&$mail) {
	$mail[] = $atts;
	return true;
}, 10, 2);

function tpfwli_live_ticket_product(): int
{
	$product = new TPFW_Product_Ticket();
	$product->set_name('TPFWLI Live Remediation Ticket');
	$product->set_status('publish');
	$product->set_catalog_visibility('hidden');
	$product->set_regular_price('50');
	$product->set_virtual(true);
	$product->set_manage_stock(true);
	$product->set_stock_quantity(10);
	$product->set_stock_status('instock');
	$product->update_meta_data('_tpfw_ticket_max_uses', 1);
	$product->update_meta_data('_tpfw_ticket_valid_duration', 172800);
	$product->update_meta_data('_tpfw_ticket_predefined_start_date_enable', 'yes');
	$product->update_meta_data('_tpfw_ticket_predefined_start_date', '2026-10-10');
	$product->update_meta_data('_tpfw_ticket_user_start_date_enable', 'no');
	return $product->save();
}

function tpfwli_live_simple_product(): int
{
	$simple = new WC_Product_Simple();
	$simple->set_name('TPFWLI Live Plain');
	$simple->set_regular_price('10');
	$simple->set_manage_stock(true);
	$simple->set_stock_quantity(10);
	$simple->set_stock_status('instock');
	$simple->set_status('publish');
	return $simple->save();
}

function tpfwli_live_input(int $pid, string $email, string $qty = '2'): array
{
	return array(
		'product_id' => $pid,
		'first_name' => 'Anna',
		'last_name'  => 'Andersson',
		'email'      => $email,
		'phone'      => '0701234567',
		'quantity'   => $qty,
		'import_id'  => wp_generate_uuid4(),
	);
}

function tpfwli_live_tickets(int $order_id): int
{
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE order_id = %d AND deleted IS NULL',
		$wpdb->prefix . 'tpfw_tickets',
		$order_id
	));
}

$pid = tpfwli_live_ticket_product();
$simple_id = tpfwli_live_simple_product();
$report = array('product_id' => $pid, 'simple_id' => $simple_id);

$mail = array();
$input = tpfwli_live_input($pid, 'live1-anna@example.com', '2');
$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
$order = $first['order'];
$report['live1'] = array(
	'ok' => !empty($first['ok']),
	'errors' => $first['errors'] ?? array(),
	'order_id' => $order instanceof WC_Order ? (int) $order->get_id() : 0,
	'customer_id' => $order instanceof WC_Order ? (int) $order->get_customer_id() : null,
	'stock' => (int) wc_get_product($pid)->get_stock_quantity(),
	'tickets' => $first['nanos'] ?? array(),
	'ticket_count' => tpfwli_live_tickets($order instanceof WC_Order ? (int) $order->get_id() : 0),
	'email_count' => count($mail),
	'email_stage' => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'created_via' => $order instanceof WC_Order ? (string) $order->get_created_via() : '',
	'expected_qty' => $order instanceof WC_Order ? (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY) : 0,
);

$mail_before = count($mail);
$second = (new TPFWLI_Orchestrator())->run($input, 'confirm');
$report['live2'] = array(
	'same_order' => $order instanceof WC_Order && $second['order'] instanceof WC_Order && (int) $order->get_id() === (int) $second['order']->get_id(),
	'same_nanos' => ($first['nanos'] ?? null) === ($second['nanos'] ?? null),
	'stock' => (int) wc_get_product($pid)->get_stock_quantity(),
	'extra_email' => count($mail) - $mail_before,
);

$mail = array();
$missing_input = tpfwli_live_input($pid, 'live3-missing@example.com', '2');
$adapter = new TPFWLI_Tpfw_Adapter();
$check = $adapter->validate_ticket_product($pid, 2, false);
$boot = (new TPFWLI_Order_Service())->create_or_resume($missing_input['import_id'], $missing_input, $check['product'], 2, $check['meta']);
$boot_order = $boot['order'];
$stock_before_resume = (int) wc_get_product($pid)->get_stock_quantity();
$resume = (new TPFWLI_Orchestrator())->run($missing_input, 'confirm');
$report['live3'] = array(
	'bootstrap_lines' => $boot_order instanceof WC_Order ? count($boot_order->get_items('line_item')) : -1,
	'same_order' => $boot_order instanceof WC_Order && $resume['order'] instanceof WC_Order && (int) $boot_order->get_id() === (int) $resume['order']->get_id(),
	'lines_after' => $resume['order'] instanceof WC_Order ? count($resume['order']->get_items('line_item')) : -1,
	'stock_before' => $stock_before_resume,
	'stock_after' => (int) wc_get_product($pid)->get_stock_quantity(),
	'tickets' => tpfwli_live_tickets($resume['order'] instanceof WC_Order ? (int) $resume['order']->get_id() : 0),
	'ok' => !empty($resume['ok']),
);

$mail = array();
$mal_input = tpfwli_live_input($pid, 'live4-malformed@example.com', '2');
$mal_check = $adapter->validate_ticket_product($pid, 2, false);
$mal_boot = (new TPFWLI_Order_Service())->create_or_resume($mal_input['import_id'], $mal_input, $mal_check['product'], 2, $mal_check['meta']);
$mal_shape = (new TPFWLI_Order_Shape())->assert_or_repair($mal_boot['order'], wc_get_product($pid), 2);
$mal_order = $mal_shape['order'];
$mal_order->add_product(wc_get_product($simple_id), 1);
$mal_order->save();
$ticket_stock = (int) wc_get_product($pid)->get_stock_quantity();
$simple_stock = (int) wc_get_product($simple_id)->get_stock_quantity();
$mal = (new TPFWLI_Orchestrator())->run($mal_input, 'confirm');
$report['live4'] = array(
	'ok' => !empty($mal['ok']),
	'failed_closed' => empty($mal['ok']),
	'ticket_stock_unchanged' => $ticket_stock === (int) wc_get_product($pid)->get_stock_quantity(),
	'simple_stock_unchanged' => $simple_stock === (int) wc_get_product($simple_id)->get_stock_quantity(),
	'tickets' => tpfwli_live_tickets($mal_order instanceof WC_Order ? (int) $mal_order->get_id() : 0),
	'email_count' => count($mail),
);

$mail = array();
$issue_input = tpfwli_live_input($pid, 'live5-issue@example.com', '2');
$blocker = static function () {
	return false;
};
add_filter('tpfwli_allow_force_issue', $blocker);
$issue_first = (new TPFWLI_Orchestrator())->run($issue_input, 'confirm');
remove_filter('tpfwli_allow_force_issue', $blocker);
$issue_order = $issue_first['order'];
$stock_after_fail = (int) wc_get_product($pid)->get_stock_quantity();
$issue_retry = (new TPFWLI_Orchestrator())->run($issue_input, 'retry_issue');
$report['live5'] = array(
	'first_ok' => !empty($issue_first['ok']),
	'issue_stage_after_fail' => $issue_order instanceof WC_Order ? (string) $issue_order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) : '',
	'email_stage_after_fail' => $issue_order instanceof WC_Order ? (string) $issue_order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'emails_after_fail' => count($mail) === 0,
	'stock_after_fail' => $stock_after_fail,
	'retry_ok' => !empty($issue_retry['ok']),
	'same_order' => $issue_order instanceof WC_Order && $issue_retry['order'] instanceof WC_Order && (int) $issue_order->get_id() === (int) $issue_retry['order']->get_id(),
	'stock_after_retry' => (int) wc_get_product($pid)->get_stock_quantity(),
	'tickets' => tpfwli_live_tickets($issue_order instanceof WC_Order ? (int) $issue_order->get_id() : 0),
	'email_stage_after_retry' => $issue_retry['order'] instanceof WC_Order ? (string) $issue_retry['order']->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'emails_after_retry' => count($mail),
);

$conc_input = tpfwli_live_input($pid, 'live6-conc@example.com', '2');
$worker = dirname(__DIR__) . '/bin/confirm-worker.php';
$payload = wp_json_encode($conc_input);
$cmd = array(PHP_BINARY, $worker);
$spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$stock_before_conc = (int) wc_get_product($pid)->get_stock_quantity();
$p1 = proc_open($cmd, $spec, $pipes1);
$p2 = proc_open($cmd, $spec, $pipes2);
fwrite($pipes1[0], $payload);
fwrite($pipes2[0], $payload);
fclose($pipes1[0]);
fclose($pipes2[0]);
$out1 = stream_get_contents($pipes1[1]);
$out2 = stream_get_contents($pipes2[1]);
fclose($pipes1[1]);
fclose($pipes1[2]);
fclose($pipes2[1]);
fclose($pipes2[2]);
proc_close($p1);
proc_close($p2);
$a = json_decode((string) $out1, true);
$b = json_decode((string) $out2, true);
wp_cache_flush();
$report['live6'] = array(
	'worker1' => $a,
	'worker2' => $b,
	'same_order' => is_array($a) && is_array($b) && (int) $a['order_id'] === (int) $b['order_id'] && (int) $a['order_id'] > 0,
	'stock_before' => $stock_before_conc,
	'stock_after' => (int) wc_get_product($pid)->get_stock_quantity(),
	'tickets' => is_array($a) ? tpfwli_live_tickets((int) $a['order_id']) : 0,
);

$report['live1_expected'] = array('stock' => 8, 'tickets' => 2, 'customer_id' => 0);
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
