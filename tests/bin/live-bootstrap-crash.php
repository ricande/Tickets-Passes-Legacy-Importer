<?php
/**
 * Live verification: atomic bootstrap rollback at three crash points, then retry.
 */
require dirname(__DIR__) . '/lib/checkout-code.php';
tpfwli_test_load_wordpress();
tpfwli_test_reject_foreign_importer_if_loaded();

if (!class_exists('TPFWLI_Orchestrator')) {
	fwrite(STDERR, "Importer not loaded\n");
	exit(1);
}

$mail = array();
add_filter('pre_wp_mail', static function ($short, $atts) use (&$mail) {
	$to = $atts['to'] ?? '';
	if (is_array($to)) {
		$to = implode(',', $to);
	}
	$mail[] = array(
		'to'      => (string) $to,
		'subject' => (string) ($atts['subject'] ?? ''),
	);
	return true;
}, 10, 2);

function tpfwli_live3_mail_summary(array $mail, string $billing): array
{
	$billing = strtolower($billing);
	$admin = strtolower((string) get_option('admin_email'));
	$customer = 0;
	$admin_count = 0;
	$other = 0;
	foreach ($mail as $row) {
		$tos = array_map('trim', explode(',', strtolower((string) ($row['to'] ?? ''))));
		if (in_array($billing, $tos, true)) {
			$customer++;
		} elseif ($admin !== '' && in_array($admin, $tos, true)) {
			$admin_count++;
		} else {
			$other++;
		}
	}
	return array(
		'wp_mail_count'       => count($mail),
		'customer_mail_count' => $customer,
		'admin_mail_count'    => $admin_count,
		'other_mail_count'    => $other,
		'mails'               => $mail,
	);
}

function tpfwli_live3_product(): int
{
	$product = new TPFW_Product_Ticket();
	$product->set_name('TPFWLI Live Bootstrap Crash Ticket');
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

function tpfwli_live3_ids(string $import_id): array
{
	global $wpdb;
	$via = TPFWLI_Plugin::created_via($import_id);
	$ids = $wpdb->get_col($wpdb->prepare(
		'SELECT DISTINCT o.id FROM ' . $wpdb->prefix . 'wc_orders o
		LEFT JOIN ' . $wpdb->prefix . 'wc_order_operational_data od ON od.order_id = o.id
		LEFT JOIN ' . $wpdb->prefix . 'wc_orders_meta m ON m.order_id = o.id AND m.meta_key = %s
		WHERE od.created_via = %s OR m.meta_value = %s
		ORDER BY o.id ASC',
		TPFWLI_Plugin::META_IMPORT_ID,
		$via,
		$import_id
	));
	return is_array($ids) ? array_map('intval', $ids) : array();
}

function tpfwli_live3_tickets(int $order_id): int
{
	global $wpdb;
	return (int) $wpdb->get_var($wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE order_id = %d AND deleted IS NULL',
		$wpdb->prefix . 'tpfw_tickets',
		$order_id
	));
}

function tpfwli_live3_row(string $table, string $column, int $id): int
{
	global $wpdb;
	$sql = 'SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '` WHERE `' . str_replace('`', '', $column) . '` = %d';
	return (int) $wpdb->get_var($wpdb->prepare($sql, $id));
}

$pid = tpfwli_live3_product();
$report = array(
	'product_id' => $pid,
	'hpos'       => TPFWLI_Dependencies::hpos_enabled(),
	'scenarios'  => array(),
);

$normal_input = array(
	'product_id' => $pid,
	'first_name' => 'Anna',
	'last_name'  => 'Andersson',
	'email'      => 'live-bootstrap-normal@example.com',
	'phone'      => '0701234567',
	'quantity'   => '2',
	'import_id'  => wp_generate_uuid4(),
);
$mail = array();
$stock_before_normal = (int) wc_get_product($pid)->get_stock_quantity();
$normal = (new TPFWLI_Orchestrator())->run($normal_input, 'confirm');
$report['normal'] = array(
	'ok'           => $normal['ok'],
	'errors'       => $normal['errors'],
	'order_id'     => $normal['order'] instanceof WC_Order ? (int) $normal['order']->get_id() : 0,
	'stock_before' => $stock_before_normal,
	'stock_after'  => (int) wc_get_product($pid)->get_stock_quantity(),
	'tickets'      => $normal['order'] instanceof WC_Order ? tpfwli_live3_tickets((int) $normal['order']->get_id()) : 0,
	'nanos'        => $normal['nanos'],
	'email'        => tpfwli_live3_mail_summary($mail, (string) $normal_input['email']),
	'db_ids'       => tpfwli_live3_ids($normal_input['import_id']),
);

foreach (array('after_order_id', 'after_meta', 'after_line') as $checkpoint) {
	$input = array(
		'product_id' => $pid,
		'first_name' => 'Anna',
		'last_name'  => 'Andersson',
		'email'      => 'live-crash-' . $checkpoint . '@example.com',
		'phone'      => '0701234567',
		'quantity'   => '2',
		'import_id'  => wp_generate_uuid4(),
	);
	$stock_before = (int) wc_get_product($pid)->get_stock_quantity();
	$mail = array();
	$seen_id = 0;
	$crash = function (string $point, $order) use ($checkpoint, &$seen_id): void {
		if (!$order instanceof WC_Order || $point !== $checkpoint) {
			return;
		}
		$seen_id = (int) $order->get_id();
		throw new RuntimeException('injected:' . $checkpoint);
	};
	add_action('tpfwli_bootstrap_checkpoint', $crash, 10, 2);
	$failed = (new TPFWLI_Orchestrator())->run($input, 'confirm');
	remove_action('tpfwli_bootstrap_checkpoint', $crash, 10);

	global $wpdb;
	$after_crash = array(
		'ok'            => $failed['ok'],
		'errors'        => $failed['errors'],
		'seen_order_id' => $seen_id,
		'db_ids'        => tpfwli_live3_ids($input['import_id']),
		'wc_orders'     => tpfwli_live3_row($wpdb->prefix . 'wc_orders', 'id', $seen_id),
		'operational'   => tpfwli_live3_row($wpdb->prefix . 'wc_order_operational_data', 'order_id', $seen_id),
		'meta'          => tpfwli_live3_row($wpdb->prefix . 'wc_orders_meta', 'order_id', $seen_id),
		'addresses'     => tpfwli_live3_row($wpdb->prefix . 'wc_order_addresses', 'order_id', $seen_id),
		'items'         => tpfwli_live3_row($wpdb->prefix . 'woocommerce_order_items', 'order_id', $seen_id),
		'posts'         => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $seen_id)),
		'stock'         => (int) wc_get_product($pid)->get_stock_quantity(),
		'tickets'       => tpfwli_live3_tickets($seen_id),
		'email'         => tpfwli_live3_mail_summary($mail, (string) $input['email']),
	);

	$mail = array();
	$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
	$final_id = $retry['order'] instanceof WC_Order ? (int) $retry['order']->get_id() : 0;
	$report['scenarios'][$checkpoint] = array(
		'stock_before' => $stock_before,
		'after_crash'  => $after_crash,
		'retry'        => array(
			'ok'          => $retry['ok'],
			'errors'      => $retry['errors'],
			'order_id'    => $final_id,
			'db_ids'      => tpfwli_live3_ids($input['import_id']),
			'stock'       => (int) wc_get_product($pid)->get_stock_quantity(),
			'tickets'     => tpfwli_live3_tickets($final_id),
			'nanos'       => $retry['nanos'],
			'email'       => tpfwli_live3_mail_summary($mail, (string) $input['email']),
			'email_stage' => $retry['order'] instanceof WC_Order ? (string) $retry['order']->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) : '',
		),
	);
}

echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
