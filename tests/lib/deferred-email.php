<?php
/**
 * Enable WooCommerce 11.1 DeferredEmailQueue after WordPress has already booted.
 * Must run in the same process that will create/complete the order.
 */

function tpfwli_test_enable_deferred_transactional_emails(): void
{
	add_filter('woocommerce_defer_transactional_emails', '__return_true', 0);
	if (!function_exists('WC') || !class_exists('WC_Emails')) {
		return;
	}
	WC()->mailer();

	$actions = apply_filters(
		'woocommerce_email_actions',
		array(
			'woocommerce_low_stock',
			'woocommerce_no_stock',
			'woocommerce_product_on_backorder',
			'woocommerce_order_status_pending_to_processing',
			'woocommerce_order_status_pending_to_completed',
			'woocommerce_order_status_processing_to_cancelled',
			'woocommerce_order_status_pending_to_cancelled',
			'woocommerce_order_status_pending_to_failed',
			'woocommerce_order_status_pending_to_on-hold',
			'woocommerce_order_status_failed_to_processing',
			'woocommerce_order_status_failed_to_completed',
			'woocommerce_order_status_failed_to_on-hold',
			'woocommerce_order_status_cancelled_to_processing',
			'woocommerce_order_status_cancelled_to_completed',
			'woocommerce_order_status_cancelled_to_on-hold',
			'woocommerce_order_status_on-hold_to_processing',
			'woocommerce_order_status_on-hold_to_cancelled',
			'woocommerce_order_status_on-hold_to_failed',
			'woocommerce_order_status_completed',
			'woocommerce_order_status_failed',
			'woocommerce_order_fully_refunded',
			'woocommerce_order_partially_refunded',
			'woocommerce_send_review_request',
			'woocommerce_new_customer_note',
			'woocommerce_created_customer',
			'woocommerce_payment_gateway_enabled',
		)
	);

	foreach ($actions as $action) {
		remove_action($action, array('WC_Emails', 'send_transactional_email'), 10);
		remove_action($action, array('WC_Emails', 'queue_transactional_email'), 10);
		add_action($action, array('WC_Emails', 'queue_transactional_email'), 10, 10);
	}

	$ref = new ReflectionClass('WC_Emails');
	$prop = $ref->getProperty('deferred_queue');
	$queue = $prop->getValue();
	if (!$queue instanceof Automattic\WooCommerce\Internal\Email\DeferredEmailQueue) {
		$prop->setValue(null, wc_get_container()->get(Automattic\WooCommerce\Internal\Email\DeferredEmailQueue::class));
	}
}

function tpfwli_test_dispatch_deferred_email_queue(): void
{
	if (!class_exists('WC_Emails')) {
		return;
	}
	$ref = new ReflectionClass('WC_Emails');
	$prop = $ref->getProperty('deferred_queue');
	$queue = $prop->getValue();
	if ($queue instanceof Automattic\WooCommerce\Internal\Email\DeferredEmailQueue) {
		$queue->dispatch();
	}
}

/**
 * Pending Action Scheduler jobs for the WooCommerce deferred email hook
 * that mention this order id. Does not return unrelated shop jobs.
 *
 * @return array<int,array{id:int,filter:string,args:mixed}>
 */
function tpfwli_test_pending_queued_emails_for_order(int $order_id): array
{
	if ($order_id < 1 || !class_exists('ActionScheduler')) {
		return array();
	}
	$store = ActionScheduler::store();
	$ids   = $store->query_actions(array(
		'hook'     => 'woocommerce_send_queued_transactional_email',
		'status'   => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 100,
		'orderby'  => 'date',
		'order'    => 'ASC',
	));
	$out = array();
	foreach ((array) $ids as $id) {
		$action = $store->fetch_action((int) $id);
		if (!$action) {
			continue;
		}
		$args = $action->get_args();
		$blob = wp_json_encode($args);
		if (!is_string($blob) || !str_contains($blob, (string) $order_id)) {
			continue;
		}
		$out[] = array(
			'id'     => (int) $id,
			'filter' => is_array($args) && isset($args[0]) ? (string) $args[0] : '',
			'args'   => $args,
		);
	}
	return $out;
}

/**
 * @param array<int,array{id:int,filter:string,args:mixed}> $jobs
 * @return int[]
 */
function tpfwli_test_run_queued_email_jobs(array $jobs): array
{
	$ran = array();
	if (!class_exists('ActionScheduler')) {
		return $ran;
	}
	$runner = ActionScheduler::runner();
	foreach ($jobs as $job) {
		$id = (int) ($job['id'] ?? 0);
		if ($id < 1) {
			continue;
		}
		$runner->process_action($id, 'tpfwli-queued-email');
		$ran[] = $id;
	}
	return $ran;
}

/**
 * @return array{count:int,ids:string[],subjects:string[]}
 */
function tpfwli_test_mailpit_messages_for_recipient(string $base_url, string $recipient, bool $insecure = false): array
{
	$empty = array('count' => 0, 'ids' => array(), 'subjects' => array());
	$query = $base_url . '/api/v1/search?query=' . rawurlencode('to:' . $recipient);
	$ctx   = stream_context_create(array(
		'http' => array('timeout' => 5, 'ignore_errors' => true),
		'ssl'  => array('verify_peer' => !$insecure, 'verify_peer_name' => !$insecure),
	));
	$raw = @file_get_contents($query, false, $ctx);
	if (!is_string($raw) || $raw === '') {
		return $empty;
	}
	$data = json_decode($raw, true);
	if (!is_array($data) || empty($data['messages']) || !is_array($data['messages'])) {
		return $empty;
	}
	$ids = array();
	$subjects = array();
	foreach ($data['messages'] as $msg) {
		$ids[] = (string) ($msg['ID'] ?? '');
		$subjects[] = (string) ($msg['Subject'] ?? '');
	}
	return array(
		'count'    => count($data['messages']),
		'ids'      => $ids,
		'subjects' => $subjects,
		'messages' => $data['messages'],
	);
}
