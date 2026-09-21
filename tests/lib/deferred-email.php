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

function tpfwli_test_loaded_revision(): string
{
	$file = defined('TPFWLI_PLUGIN_FILE') ? TPFWLI_PLUGIN_FILE : '';
	$root = $file !== '' ? dirname($file) : dirname(__DIR__, 2);
	$head = $root . '/.git/HEAD';
	if (is_readable($head)) {
		$ref = trim((string) file_get_contents($head));
		if (str_starts_with($ref, 'ref: ')) {
			$path = $root . '/.git/' . substr($ref, 5);
			if (is_readable($path)) {
				return trim((string) file_get_contents($path));
			}
		}
		return $ref;
	}
	$marker = $root . '/.tpfwli-revision';
	return is_readable($marker) ? trim((string) file_get_contents($marker)) : '';
}

function tpfwli_test_is_exact_order_id($value): bool
{
	if (is_int($value)) {
		return $value > 0;
	}
	return is_string($value) && $value !== '' && ctype_digit($value) && (int) $value > 0;
}

function tpfwli_test_filter_has_leading_order_id(?string $filter): bool
{
	if (!is_string($filter) || $filter === '') {
		return false;
	}
	return str_starts_with($filter, 'woocommerce_order_status_')
		|| $filter === 'woocommerce_order_fully_refunded'
		|| $filter === 'woocommerce_order_partially_refunded';
}

/**
 * Order IDs that this queued transactional email actually belongs to.
 *
 * @param mixed $args
 * @return int[]
 */
function tpfwli_test_queued_email_order_ids($args): array
{
	$ids = array();
	tpfwli_test_collect_queued_email_order_ids($args, $ids, null, true);
	return array_values(array_unique($ids));
}

/**
 * @param mixed  $node
 * @param int[]  $ids
 */
function tpfwli_test_collect_queued_email_order_ids($node, array &$ids, ?string $filter, bool $is_root): void
{
	if (!is_array($node)) {
		return;
	}

	if ($is_root && isset($node[0]) && is_string($node[0]) && array_key_exists(1, $node)) {
		tpfwli_test_collect_queued_email_order_ids($node[1], $ids, (string) $node[0], false);
		return;
	}

	if (isset($node['__woocommerce_deferred_email_object']) && is_array($node['__woocommerce_deferred_email_object'])) {
		$ref = $node['__woocommerce_deferred_email_object'];
		if (($ref['type'] ?? '') === 'order' && tpfwli_test_is_exact_order_id($ref['id'] ?? null)) {
			$ids[] = (int) $ref['id'];
		}
		return;
	}

	if (array_key_exists('order_id', $node) && tpfwli_test_is_exact_order_id($node['order_id'])) {
		$ids[] = (int) $node['order_id'];
	}

	foreach ($node as $key => $value) {
		if (is_array($value)) {
			tpfwli_test_collect_queued_email_order_ids($value, $ids, $filter, false);
			continue;
		}
		if ($key === 0 && tpfwli_test_filter_has_leading_order_id($filter) && tpfwli_test_is_exact_order_id($value)) {
			$ids[] = (int) $value;
		}
	}
}

function tpfwli_test_queued_email_belongs_to_order($args, int $order_id): bool
{
	return $order_id > 0 && in_array($order_id, tpfwli_test_queued_email_order_ids($args), true);
}

/**
 * Pending Action Scheduler jobs for the WooCommerce deferred email hook
 * that belong to this order. Pages past the first 100 shop jobs.
 *
 * @return array<int,array{id:int,filter:string,args:mixed}>
 */
function tpfwli_test_pending_queued_emails_for_order(int $order_id): array
{
	if ($order_id < 1 || !class_exists('ActionScheduler')) {
		return array();
	}
	$store    = ActionScheduler::store();
	$per_page = 100;
	$offset   = 0;
	$out      = array();
	do {
		$ids = $store->query_actions(array(
			'hook'     => 'woocommerce_send_queued_transactional_email',
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => $per_page,
			'offset'   => $offset,
			'orderby'  => 'date',
			'order'    => 'ASC',
		));
		$ids   = array_map('intval', (array) $ids);
		$count = count($ids);
		foreach ($ids as $id) {
			if ($id < 1) {
				continue;
			}
			$action = $store->fetch_action($id);
			if (!$action) {
				continue;
			}
			$args = $action->get_args();
			if (!tpfwli_test_queued_email_belongs_to_order($args, $order_id)) {
				continue;
			}
			$out[] = array(
				'id'     => $id,
				'filter' => is_array($args) && isset($args[0]) ? (string) $args[0] : '',
				'args'   => $args,
			);
		}
		$offset += $count;
	} while ($count === $per_page);
	return $out;
}

/**
 * @param array<int,array{id:int,filter:string,args:mixed}> $jobs
 * @return int[]
 */
function tpfwli_test_run_queued_email_jobs(array $jobs, int $order_id = 0): array
{
	$ran = array();
	if (!class_exists('ActionScheduler')) {
		return $ran;
	}
	$store  = ActionScheduler::store();
	$runner = ActionScheduler::runner();
	foreach ($jobs as $job) {
		$id = (int) ($job['id'] ?? 0);
		if ($id < 1) {
			continue;
		}
		$action = $store->fetch_action($id);
		if (!$action) {
			continue;
		}
		if ($order_id > 0 && !tpfwli_test_queued_email_belongs_to_order($action->get_args(), $order_id)) {
			continue;
		}
		if ($store->get_status($id) !== ActionScheduler_Store::STATUS_PENDING) {
			continue;
		}
		$runner->process_action($id, 'tpfwli-queued-email');
		if ($store->get_status($id) !== ActionScheduler_Store::STATUS_COMPLETE) {
			continue;
		}
		$ran[] = $id;
	}
	return $ran;
}

function tpfwli_test_enqueue_completed_notification(int $order_id): int
{
	if ($order_id < 1 || !function_exists('WC') || !WC()->queue()) {
		return 0;
	}
	return (int) WC()->queue()->add(
		'woocommerce_send_queued_transactional_email',
		array(
			'woocommerce_order_status_completed',
			array(
				$order_id,
				array(
					'__woocommerce_deferred_email_object' => array(
						'type' => 'order',
						'id'   => $order_id,
					),
				),
			),
		),
		'woocommerce-emails'
	);
}

/**
 * @param int[] $ids
 */
function tpfwli_test_cancel_queued_email_jobs(array $ids): void
{
	if (!class_exists('ActionScheduler')) {
		return;
	}
	$store = ActionScheduler::store();
	foreach ($ids as $id) {
		$id = (int) $id;
		if ($id < 1) {
			continue;
		}
		try {
			$store->cancel_action($id);
		} catch (Throwable $e) {
			unset($e);
		}
	}
}

/**
 * @param array<int,array<string,mixed>> $mail
 * @return array<int,array<string,mixed>>
 */
function tpfwli_test_mail_atts_to_recipient(array $mail, string $recipient): array
{
	$recipient = strtolower($recipient);
	$out       = array();
	foreach ($mail as $atts) {
		$to = $atts['to'] ?? '';
		if (is_array($to)) {
			$to = implode(',', $to);
		}
		$tos = array_map('trim', explode(',', strtolower((string) $to)));
		if (in_array($recipient, $tos, true)) {
			$out[] = $atts;
		}
	}
	return $out;
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
