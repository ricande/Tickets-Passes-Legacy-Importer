<?php
/**
 * Live verification: issue-retry config drift, then email-retry after successful issue.
 */
$wp_root = getenv('TPFWLI_WP_PATH') ?: '/var/www/woocommerce';
require $wp_root . '/wp-load.php';

if (!class_exists('TPFWLI_Orchestrator')) {
	fwrite(STDERR, "Importer not loaded\n");
	exit(1);
}

$mail = array();
$mail_fail = false;
add_filter('pre_wp_mail', static function ($short, $atts) use (&$mail, &$mail_fail) {
	$mail[] = $atts;
	return $mail_fail ? false : true;
}, 10, 2);

function tpfwli_live2_product(): int
{
	$product = new TPFW_Product_Ticket();
	$product->set_name('TPFWLI Live Drift Ticket');
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

function tpfwli_live2_input(int $pid, string $email): array
{
	return array(
		'product_id' => $pid,
		'first_name' => 'Anna',
		'last_name'  => 'Andersson',
		'email'      => $email,
		'phone'      => '0701234567',
		'quantity'   => '2',
		'import_id'  => wp_generate_uuid4(),
	);
}

function tpfwli_live2_tickets(int $order_id): int
{
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE order_id = %d AND deleted IS NULL',
		$wpdb->prefix . 'tpfw_tickets',
		$order_id
	));
}

function tpfwli_live2_restore(int $pid): void
{
	$product = wc_get_product($pid);
	$product->update_meta_data('_tpfw_ticket_max_uses', 1);
	$product->update_meta_data('_tpfw_ticket_predefined_start_date_enable', 'yes');
	$product->update_meta_data('_tpfw_ticket_valid_duration', 172800);
	$product->save();
}

$pid = tpfwli_live2_product();
$report = array('product_id' => $pid, 'hpos' => TPFWLI_Dependencies::hpos_enabled());

$mail = array();
$input1 = tpfwli_live2_input($pid, 'live-drift-issue@example.com');
$blocker = static function () {
	return false;
};
add_filter('tpfwli_allow_force_issue', $blocker);
$first = (new TPFWLI_Orchestrator())->run($input1, 'confirm');
remove_filter('tpfwli_allow_force_issue', $blocker);
$order1 = $first['order'];
$order1 = $order1 instanceof WC_Order ? wc_get_order($order1->get_id()) : null;
$stock_after_fail = (int) wc_get_product($pid)->get_stock_quantity();
$stage_after_fail = $order1 instanceof WC_Order ? (string) $order1->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) : '';
$email_after_fail = $order1 instanceof WC_Order ? (string) $order1->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '';
$tickets_after_fail = $order1 instanceof WC_Order ? tpfwli_live2_tickets((int) $order1->get_id()) : 0;

$product = wc_get_product($pid);
$product->update_meta_data('_tpfw_ticket_max_uses', 9);
$product->save();
$mail = array();
$drift_retry = (new TPFWLI_Orchestrator())->run($input1, 'retry_issue');
$stock_after_drift = (int) wc_get_product($pid)->get_stock_quantity();
$tickets_after_drift = $order1 instanceof WC_Order ? tpfwli_live2_tickets((int) $order1->get_id()) : 0;
$mail_after_drift = count($mail);

tpfwli_live2_restore($pid);
$mail = array();
$recovered = (new TPFWLI_Orchestrator())->run($input1, 'retry_issue');

$report['scenario1'] = array(
	'first_ok' => !empty($first['ok']),
	'stock_reduced' => $order1 instanceof WC_Order && (new TPFWLI_Stock_Service())->is_reduced($order1),
	'issue_stage_after_fail' => $stage_after_fail,
	'email_after_fail' => $email_after_fail,
	'tickets_after_fail' => $tickets_after_fail,
	'drift_retry_ok' => !empty($drift_retry['ok']),
	'drift_retry_errors' => $drift_retry['errors'] ?? array(),
	'stock_after_drift_retry' => $stock_after_drift,
	'stock_unchanged_on_drift' => $stock_after_drift === $stock_after_fail,
	'tickets_after_drift_retry' => $tickets_after_drift,
	'mail_after_drift_retry' => $mail_after_drift,
	'recovered_ok' => !empty($recovered['ok']),
	'recovered_tickets' => $recovered['nanos'] ?? array(),
	'recovered_count' => $order1 instanceof WC_Order ? tpfwli_live2_tickets((int) $order1->get_id()) : 0,
	'recovered_email' => $recovered['order'] instanceof WC_Order ? (string) $recovered['order']->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
	'same_order' => $order1 instanceof WC_Order && $recovered['order'] instanceof WC_Order && (int) $order1->get_id() === (int) $recovered['order']->get_id(),
);

$mail = array();
$mail_fail = true;
$input2 = tpfwli_live2_input($pid, 'live-drift-email@example.com');
$issued = (new TPFWLI_Orchestrator())->run($input2, 'confirm');
$mail_fail = false;
$order2 = $issued['order'];
$order2 = $order2 instanceof WC_Order ? wc_get_order($order2->get_id()) : null;
$nanos = $issued['nanos'] ?? array();
$stock_after_issue = (int) wc_get_product($pid)->get_stock_quantity();
$email_before_retry = $order2 instanceof WC_Order ? (string) $order2->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '';

$product = wc_get_product($pid);
$product->update_meta_data('_tpfw_ticket_max_uses', 9);
$product->update_meta_data('_tpfw_ticket_predefined_start_date_enable', 'no');
$product->save();
$mail = array();
$email_retry = (new TPFWLI_Orchestrator())->run($input2, 'retry_email');
tpfwli_live2_restore($pid);

$report['scenario2'] = array(
	'issue_ok_but_email_failed' => empty($issued['ok']) && $order2 instanceof WC_Order && (string) $order2->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) === 'issued',
	'email_stage_before_retry' => $email_before_retry,
	'email_retry_ok' => !empty($email_retry['ok']),
	'email_retry_errors' => $email_retry['errors'] ?? array(),
	'same_nanos' => $nanos === ($email_retry['nanos'] ?? null),
	'stock_unchanged' => (int) wc_get_product($pid)->get_stock_quantity() === $stock_after_issue,
	'ticket_count' => $order2 instanceof WC_Order ? tpfwli_live2_tickets((int) $order2->get_id()) : 0,
	'mail_count' => count($mail),
	'same_order' => $order2 instanceof WC_Order && $email_retry['order'] instanceof WC_Order && (int) $order2->get_id() === (int) $email_retry['order']->get_id(),
);

echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
