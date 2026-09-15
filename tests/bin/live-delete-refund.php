<?php
/**
 * Live 7/8 helpers. Phase is: create | verify | refund
 */
$wp_root = getenv('TPFWLI_WP_PATH') ?: '/var/www/woocommerce';
require $wp_root . '/wp-load.php';

$phase = $argv[1] ?? 'create';
$state_file = '/tmp/tpfwli-live78.json';

if ($phase === 'create') {
	if (!class_exists('TPFWLI_Orchestrator')) {
		fwrite(STDERR, "Importer not loaded\n");
		exit(1);
	}
	add_filter('pre_wp_mail', static function () {
		return true;
	});
	$product = new TPFW_Product_Ticket();
	$product->set_name('TPFWLI Live Delete Ticket');
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
	$input = array(
		'product_id' => $pid,
		'first_name' => 'DeleteLive',
		'last_name'  => 'Andersson',
		'email'      => 'live7-delete@example.com',
		'phone'      => '0701234567',
		'quantity'   => '2',
		'import_id'  => wp_generate_uuid4(),
	);
	$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
	$order = $result['order'];
	$state = array(
		'ok' => !empty($result['ok']),
		'errors' => $result['errors'] ?? array(),
		'product_id' => $pid,
		'order_id' => $order instanceof WC_Order ? (int) $order->get_id() : 0,
		'import_id' => $input['import_id'],
		'nanos' => $result['nanos'] ?? array(),
		'stock_after_import' => (int) wc_get_product($pid)->get_stock_quantity(),
		'billing_email' => $order instanceof WC_Order ? $order->get_billing_email() : '',
		'customer_id' => $order instanceof WC_Order ? (int) $order->get_customer_id() : null,
	);
	file_put_contents($state_file, wp_json_encode($state));
	echo wp_json_encode($state, JSON_PRETTY_PRINT) . "\n";
	exit($state['ok'] ? 0 : 1);
}

$state = json_decode((string) file_get_contents($state_file), true);
$order_id = (int) ($state['order_id'] ?? 0);
$pid = (int) ($state['product_id'] ?? 0);
$nanos = $state['nanos'] ?? array();
global $wpdb;

if ($phase === 'verify') {
	$order = wc_get_order($order_id);
	$slug = get_option('tpfw_upload_slug');
	$qr = array();
	if (is_string($slug) && preg_match('/^[a-f0-9]{10}$/', $slug)) {
		$qr_dir = trailingslashit(wp_upload_dir()['basedir']) . 'tpfw-' . $slug . '/qr-codes/';
		foreach ($nanos as $nano) {
			$qr[$nano] = is_readable($qr_dir . $nano . '.webp');
		}
	}
	$rows = $wpdb->get_results($wpdb->prepare(
		'SELECT nano_id, deleted FROM %i WHERE order_id = %d',
		$wpdb->prefix . 'tpfw_tickets',
		$order_id
	));
	$scanner = array();
	foreach ($nanos as $nano) {
		$hit = $wpdb->get_row($wpdb->prepare(
			'SELECT nano_id, deleted FROM %i WHERE nano_id = %s',
			$wpdb->prefix . 'tpfw_tickets',
			$nano
		));
		$scanner[$nano] = $hit && $hit->deleted === null;
	}
	$out = array(
		'importer_class_loaded' => class_exists('TPFWLI_Orchestrator'),
		'order_exists' => $order instanceof WC_Order,
		'order_id' => $order_id,
		'billing_email' => $order instanceof WC_Order ? $order->get_billing_email() : '',
		'import_meta' => $order instanceof WC_Order ? (string) $order->get_meta('_tpfwli_import_id') : '',
		'notes_count' => $order instanceof WC_Order ? count($order->get_customer_order_notes()) + count($order->get_customer_note() ? array($order->get_customer_note()) : array()) : 0,
		'stock' => (int) wc_get_product($pid)->get_stock_quantity(),
		'ticket_rows' => $rows,
		'qr' => $qr,
		'scanner_finds_live_ticket' => $scanner,
	);
	echo wp_json_encode($out, JSON_PRETTY_PRINT) . "\n";
	exit(!empty($out['order_exists']) ? 0 : 1);
}

if ($phase === 'refund') {
	$order = wc_get_order($order_id);
	if (!$order instanceof WC_Order) {
		fwrite(STDERR, "order missing\n");
		exit(1);
	}
	$items = array();
	foreach ($order->get_items('line_item') as $item_id => $item) {
		$items[$item_id] = array(
			'qty' => (int) $item->get_quantity(),
			'refund_total' => (float) $item->get_total(),
			'refund_tax' => array(),
		);
	}
	$stock_before = (int) wc_get_product($pid)->get_stock_quantity();
	$refund = wc_create_refund(array(
		'order_id'       => $order_id,
		'reason'         => 'TPFWLI live 8 post-delete refund',
		'line_items'     => $items,
		'refund_payment' => false,
		'restock_items'  => true,
	));
	wp_cache_flush();
	$order = wc_get_order($order_id);
	$rows = $wpdb->get_results($wpdb->prepare(
		'SELECT nano_id, deleted FROM %i WHERE order_id = %d',
		$wpdb->prefix . 'tpfw_tickets',
		$order_id
	));
	$scanner = array();
	foreach ($nanos as $nano) {
		$hit = $wpdb->get_row($wpdb->prepare(
			'SELECT nano_id, deleted FROM %i WHERE nano_id = %s',
			$wpdb->prefix . 'tpfw_tickets',
			$nano
		));
		$scanner[$nano] = $hit && ($hit->deleted === null || $hit->deleted === '');
	}
	$out = array(
		'importer_class_loaded' => class_exists('TPFWLI_Orchestrator'),
		'refund_ok' => $refund instanceof WC_Order_Refund,
		'refund_error' => is_wp_error($refund) ? $refund->get_error_message() : '',
		'order_status' => $order instanceof WC_Order ? $order->get_status() : '',
		'stock_before' => $stock_before,
		'stock_after' => (int) wc_get_product($pid)->get_stock_quantity(),
		'ticket_rows' => $rows,
		'scanner_still_live' => $scanner,
		'fatal' => false,
	);
	echo wp_json_encode($out, JSON_PRETTY_PRINT) . "\n";
	exit($out['refund_ok'] ? 0 : 1);
}

fwrite(STDERR, "unknown phase\n");
exit(2);
