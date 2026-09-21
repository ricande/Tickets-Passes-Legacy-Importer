<?php
/**
 * Local live verification for one Anna Andersson qty-2 import. Not a PHPUnit test.
 */
require dirname(__DIR__) . '/lib/checkout-code.php';
tpfwli_test_load_wordpress();
tpfwli_test_reject_foreign_importer_if_loaded();

if (!class_exists('TPFWLI_Orchestrator')) {
	fwrite(STDERR, "Importer plugin not loaded\n");
	exit(1);
}

$product = new TPFW_Product_Ticket();
$product->set_name('Festival Ticket 2026 LIVE');
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
$pid = $product->save();

$stock_before = (int) wc_get_product($pid)->get_stock_quantity();
$import_id = wp_generate_uuid4();
$input = array(
	'product_id' => $pid,
	'first_name' => 'Anna',
	'last_name'  => 'Andersson',
	'email'      => 'anna@example.com',
	'phone'      => '0701234567',
	'quantity'   => '2',
	'import_id'  => $import_id,
);

$preview_orders = count(wc_get_orders(array('limit' => -1, 'status' => 'any', 'return' => 'ids')));
$preview = (new TPFWLI_Orchestrator())->preview($input);
$preview_orders_after = count(wc_get_orders(array('limit' => -1, 'status' => 'any', 'return' => 'ids')));

$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
$order = $first['order'];
$second = (new TPFWLI_Orchestrator())->run($input, 'confirm');
$retry_issue = (new TPFWLI_Orchestrator())->run($input, 'retry_issue');

$stock_after = (int) wc_get_product($pid)->get_stock_quantity();
$same_order = ($order && $second['order'] && (int) $order->get_id() === (int) $second['order']->get_id());
$same_nanos = $first['nanos'] === $retry_issue['nanos'];

$plugin = plugin_basename(TPFWLI_PLUGIN_FILE);
deactivate_plugins($plugin, true);
$after_deact_order = wc_get_order($order ? $order->get_id() : 0);
global $wpdb;
$ticket_count = (int) $wpdb->get_var($wpdb->prepare(
	'SELECT COUNT(*) FROM %i WHERE order_id = %d AND deleted IS NULL',
	$wpdb->prefix . 'tpfw_tickets',
	$order ? $order->get_id() : 0
));
activate_plugin($plugin);

$report = array(
	'product_id' => $pid,
	'stock_before' => $stock_before,
	'stock_after' => $stock_after,
	'preview_ok' => !empty($preview['ok']),
	'preview_did_not_create_order' => $preview_orders === $preview_orders_after,
	'import_ok' => !empty($first['ok']),
	'errors' => $first['errors'] ?? array(),
	'order_id' => $order ? $order->get_id() : 0,
	'customer_id' => $order ? (int) $order->get_customer_id() : null,
	'billing' => $order ? array(
		'first' => $order->get_billing_first_name(),
		'last' => $order->get_billing_last_name(),
		'email' => $order->get_billing_email(),
		'phone' => $order->get_billing_phone(),
	) : null,
	'tickets' => $first['nanos'] ?? array(),
	'ticket_count' => count($first['nanos'] ?? array()),
	'email_stage' => $order ? $order->get_meta('_tpfwli_email_stage') : '',
	'repeat_same_order' => $same_order,
	'repeat_same_nanos' => $same_nanos,
	'repeat_stock_unchanged' => $stock_after === (int) wc_get_product($pid)->get_stock_quantity(),
	'deactivated_order_remains' => $after_deact_order instanceof WC_Order,
	'deactivated_tickets_remain' => $ticket_count === 2,
	'import_id' => $import_id,
);
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
