<?php
defined('ABSPATH') || exit;

/**
 * Import state machine. Each POST re-enters from the last successful stage.
 */
final class TPFWLI_Orchestrator
{
	private TPFWLI_Input_Validator $validator;
	private TPFWLI_Tpfw_Adapter $adapter;
	private TPFWLI_Order_Service $orders;
	private TPFWLI_Order_Shape $shape;
	private TPFWLI_Stock_Service $stock;
	private TPFWLI_Email_Service $email;
	private TPFWLI_Import_Repository $repository;

	public function __construct()
	{
		$this->validator  = new TPFWLI_Input_Validator();
		$this->adapter    = new TPFWLI_Tpfw_Adapter();
		$this->orders     = new TPFWLI_Order_Service();
		$this->stock      = new TPFWLI_Stock_Service();
		$this->shape      = new TPFWLI_Order_Shape($this->stock);
		$this->email      = TPFWLI_Email_Service::instance();
		$this->repository = new TPFWLI_Import_Repository();
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{ok:bool,errors:string[],customer:array,product:array,import_id:string}
	 */
	public function preview(array $input): array
	{
		$customer = $this->validator->validate_customer($input);
		if (!$customer['ok']) {
			return array(
				'ok'        => false,
				'errors'    => $customer['errors'],
				'customer'  => $customer['data'],
				'product'   => array(),
				'import_id' => (string) ($customer['data']['import_id'] ?? ''),
			);
		}

		$product = $this->adapter->validate_ticket_product(
			(int) $customer['data']['product_id'],
			(int) $customer['data']['quantity']
		);
		if (!$product['ok']) {
			return array(
				'ok'        => false,
				'errors'    => $product['errors'],
				'customer'  => $customer['data'],
				'product'   => $product['meta'],
				'import_id' => $customer['data']['import_id'],
			);
		}

		return array(
			'ok'        => true,
			'errors'    => array(),
			'customer'  => $customer['data'],
			'product'   => $product['meta'],
			'import_id' => $customer['data']['import_id'],
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @param string              $mode confirm|retry_issue|retry_email
	 * @return array<string,mixed>
	 */
	public function run(array $input, string $mode = 'confirm'): array
	{
		$customer = $this->validator->validate_customer($input);
		if (!$customer['ok']) {
			return $this->fail_result($customer['errors'], null, $customer['data']['import_id'] ?? '');
		}

		$data      = $customer['data'];
		$import_id = $data['import_id'];

		global $wpdb;
		$lock = new TPFWLI_Import_Lock($wpdb, $import_id);
		if (!$lock->acquire(15)) {
			return $this->fail_result(
				array(__('Could not obtain an import lock. Try again in a moment.', 'tickets-passes-legacy-importer')),
				null,
				$import_id
			);
		}

		try {
			$found = $this->repository->find_by_import_id($import_id);
			if (!$found['ok']) {
				return $this->fail_result(array($found['error']), null, $import_id);
			}
			$existing = $found['order'];

			if ($mode !== 'confirm' && !$existing instanceof WC_Order) {
				return $this->fail_result(
					array(__('No imported order exists for this import ID.', 'tickets-passes-legacy-importer')),
					null,
					$import_id
				);
			}

			$product_id = (int) $data['product_id'];
			$quantity   = (int) $data['quantity'];
			if ($existing instanceof WC_Order) {
				$locked_product_id = (int) $existing->get_meta(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID);
				$locked_quantity   = (int) $existing->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY);
				if ($locked_product_id > 0 && $locked_quantity > 0) {
					$product_id = $locked_product_id;
					$quantity   = $locked_quantity;
				}
			}

			if ($mode === 'retry_email' && $existing instanceof WC_Order) {
				$quantity = (int) $existing->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY);
				if ($quantity < 1) {
					return $this->fail_result(
						array(__('This importer order is missing its locked quantity snapshot.', 'tickets-passes-legacy-importer')),
						$existing,
						$import_id
					);
				}
				if ((string) $existing->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) !== 'issued') {
					return $this->fail_result(
						array(__('Tickets are not issued yet; email retry is not available.', 'tickets-passes-legacy-importer')),
						$existing,
						$import_id
					);
				}
				return $this->maybe_send_email($existing, $quantity);
			}

			$need_stock_headroom = true;
			if ($existing instanceof WC_Order) {
				$need_stock_headroom = !$this->stock->is_reduced($existing);
			}

			$product = $this->adapter->validate_ticket_product($product_id, $quantity, $need_stock_headroom);
			if (!$product['ok'] || !$product['product'] instanceof WC_Product) {
				return $this->fail_result($product['errors'], $existing, $import_id);
			}

			$created = $this->orders->create_or_resume($import_id, $data, $product['product'], $quantity, $product['meta']);
			if (!$created['ok'] || !$created['order'] instanceof WC_Order) {
				return $this->fail_result(
					array($created['error'] !== '' ? $created['error'] : __('Order creation failed.', 'tickets-passes-legacy-importer')),
					$created['order'],
					$import_id
				);
			}
			$order = $created['order'];

			$quantity     = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY);
			$expected_pid = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID);
			$locked_product = wc_get_product($expected_pid);
			if ($quantity < 1 || !$locked_product instanceof WC_Product) {
				return $this->fail_result(
					array(__('This importer order is missing its locked product/quantity snapshot.', 'tickets-passes-legacy-importer')),
					$order,
					$import_id
				);
			}

			$shaped = $this->shape->assert_or_repair($order, $locked_product, $quantity);
			if (!$shaped['ok'] || !$shaped['order'] instanceof WC_Order) {
				return $this->fail_result(
					array($shaped['error'] !== '' ? $shaped['error'] : __('Order shape is not valid for stock reduction.', 'tickets-passes-legacy-importer')),
					$shaped['order'] instanceof WC_Order ? $shaped['order'] : $order,
					$import_id
				);
			}
			$order = $shaped['order'];

			$issue_stage = (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE);
			if ($issue_stage !== 'issued') {
				$drift = $this->adapter->current_matches_snapshot($locked_product, $order);
				if (!$drift['ok']) {
					foreach ($drift['errors'] as $note) {
						$order->add_order_note($note);
					}
					$order->save();
					return $this->fail_result($drift['errors'], $order, $import_id);
				}
			}

			$stock_ok = $this->ensure_stock($order, $locked_product, $quantity);
			if (!$stock_ok['ok']) {
				return $stock_ok;
			}
			$order = wc_get_order($order->get_id());
			return $this->ensure_issue_then_email($order, $quantity, $product['meta'], true);
		} finally {
			$lock->release();
		}
	}

	/**
	 * Persistent Result/overview reconstruction. Transients are not the source of truth.
	 *
	 * @return array<string,mixed>
	 */
	public function inspect(WC_Order $order): array
	{
		$order = wc_get_order($order->get_id());
		$qty   = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY);
		$nanos = array();
		$verify_ok = false;
		$items = array_values($order->get_items('line_item'));
		if ($qty > 0 && count($items) === 1) {
			$verified  = $this->adapter->verify_issue($order, $qty);
			$nanos     = $verified['nanos'];
			$verify_ok = $verified['ok'];
		}

		$product_name  = '';
		$current_stock = null;
		if (isset($items[0])) {
			$product_name = $items[0]->get_name();
			$product      = wc_get_product($items[0]->get_product_id());
			$current_stock = $product ? $product->get_stock_quantity() : null;
		}

		return array(
			'order'         => $order,
			'nanos'         => $nanos,
			'ticket_count'  => count($nanos),
			'quantity'      => $qty,
			'product_name'  => $product_name,
			'current_stock' => $current_stock,
			'stock_label'   => $this->stock->accounted_label($order),
			'stock_qty'     => $this->stock->reduced_qty($order),
			'stock_reduced' => $this->stock->is_reduced($order),
			'stock_stage'   => (string) $order->get_meta(TPFWLI_Plugin::META_STOCK_STAGE),
			'issue_stage'   => (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE),
			'email_stage'   => (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE),
			'import_id'     => (string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID),
			'verify_ok'     => $verify_ok,
		);
	}

	/**
	 * @param array<string,mixed> $product_meta
	 * @return array<string,mixed>
	 */
	private function ensure_stock(WC_Order $order, WC_Product $product, int $quantity): array
	{
		$result = $this->stock->reduce_if_needed($order, $product, $quantity);
		$order  = wc_get_order($order->get_id());
		if (!$result['ok']) {
			$this->orders->set_stage(
				$order,
				TPFWLI_Plugin::META_STOCK_STAGE,
				'failed',
				$result['error']
			);
			return $this->result(false, array($result['error']), $order, array(), $result['current_stock']);
		}

		if ((string) $order->get_meta(TPFWLI_Plugin::META_STOCK_STAGE) !== 'reduced') {
			$this->orders->set_stage(
				$order,
				TPFWLI_Plugin::META_STOCK_STAGE,
				'reduced',
				sprintf(
					/* translators: %d: quantity reduced */
					__('Legacy import stock reduced by %d via WooCommerce.', 'tickets-passes-legacy-importer'),
					(int) $result['reduced_qty']
				)
			);
		}

		return $this->result(true, array(), $order, array(), $result['current_stock']);
	}

	/**
	 * @param array<string,mixed> $product_meta
	 * @return array<string,mixed>
	 */
	private function ensure_issue_then_email(WC_Order $order, int $quantity, array $product_meta, bool $send_email): array
	{
		$email_stage = (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE);
		$issue_stage = (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE);

		if ($issue_stage !== 'issued') {
			$expected_pid = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID);
			$live_product = wc_get_product($expected_pid);
			if (!$live_product instanceof WC_Product) {
				return $this->fail_result(
					array(__('The locked Ticket product no longer exists. Ticket issue was not run.', 'tickets-passes-legacy-importer')),
					$order,
					(string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID)
				);
			}
			$drift = $this->adapter->current_matches_snapshot($live_product, $order);
			if (!$drift['ok']) {
				foreach ($drift['errors'] as $note) {
					$order->add_order_note($note);
				}
				$order->save();
				return $this->fail_result($drift['errors'], $order, (string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID));
			}

			$this->orders->set_stage($order, TPFWLI_Plugin::META_ISSUE_STAGE, 'issuing');
			if (!$this->adapter->force_issue((int) $order->get_id())) {
				$this->orders->set_stage(
					$order,
					TPFWLI_Plugin::META_ISSUE_STAGE,
					'failed',
					$this->adapter->last_error()
				);
				return $this->result(false, array($this->adapter->last_error()), $order);
			}

			$order    = wc_get_order($order->get_id());
			$verified = $this->adapter->verify_issue($order, $quantity);
			if (!$verified['ok']) {
				$this->orders->set_stage(
					$order,
					TPFWLI_Plugin::META_ISSUE_STAGE,
					'failed',
					implode(' ', $verified['errors'])
				);
				return $this->result(false, $verified['errors'], $order, $verified['nanos']);
			}

			$this->orders->set_stage(
				$order,
				TPFWLI_Plugin::META_ISSUE_STAGE,
				'issued',
				sprintf(
					/* translators: %d: ticket count */
					__('Legacy import issued %d ticket(s) via TPFW.', 'tickets-passes-legacy-importer'),
					$verified['count']
				)
			);
			$order = wc_get_order($order->get_id());
		} else {
			$verified = $this->adapter->verify_issue($order, $quantity);
			if (!$verified['ok']) {
				return $this->result(false, $verified['errors'], $order, $verified['nanos']);
			}
		}

		$this->mark_completed($order);

		if (!$send_email && $email_stage === 'sent') {
			$order = wc_get_order($order->get_id());
			return $this->result(true, array(), $order, $verified['nanos']);
		}

		return $this->maybe_send_email($order, $quantity, $verified['nanos']);
	}

	/**
	 * @param string[] $nanos
	 * @return array<string,mixed>
	 */
	private function maybe_send_email(WC_Order $order, int $quantity, array $nanos = array()): array
	{
		$order = wc_get_order($order->get_id());
		if ((string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) !== 'issued') {
			$verified = $this->adapter->verify_issue($order, $quantity);
			if (!$verified['ok']) {
				return $this->result(
					false,
					array_merge(
						array(__('Tickets are not verified; email was not sent.', 'tickets-passes-legacy-importer')),
						$verified['errors']
					),
					$order,
					$verified['nanos']
				);
			}
			$nanos = $verified['nanos'];
		} elseif ($nanos === array()) {
			$verified = $this->adapter->verify_issue($order, $quantity);
			$nanos    = $verified['nanos'];
			if (!$verified['ok']) {
				return $this->result(false, $verified['errors'], $order, $nanos);
			}
		}

		$stage = (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE);
		if ($stage === 'sent') {
			return $this->result(
				true,
				array(__('Email was already sent for this import. Normal retry will not send again.', 'tickets-passes-legacy-importer')),
				$order,
				$nanos,
				null,
				true
			);
		}

		if ($stage === 'sending' || $stage === 'unknown') {
			return $this->result(
				false,
				array(__('Previous email attempt has an unknown outcome. Verify before forcing a resend.', 'tickets-passes-legacy-importer')),
				$order,
				$nanos,
				null,
				false,
				true
			);
		}

		$this->orders->set_stage($order, TPFWLI_Plugin::META_EMAIL_STAGE, 'sending');
		$sent = $this->email->send_completed_email($order);

		if (!empty($sent['unknown'])) {
			$this->orders->set_stage(
				$order,
				TPFWLI_Plugin::META_EMAIL_STAGE,
				'unknown',
				__('Legacy import email outcome is unknown (process may have died after the mailer accepted the message).', 'tickets-passes-legacy-importer')
			);
			return $this->result(false, array($sent['error']), wc_get_order($order->get_id()), $nanos, null, false, true);
		}

		if (!$sent['ok']) {
			$this->orders->set_stage(
				$order,
				TPFWLI_Plugin::META_EMAIL_STAGE,
				'failed',
				$sent['error']
			);
			return $this->result(false, array($sent['error']), wc_get_order($order->get_id()), $nanos);
		}

		$this->orders->set_stage(
			$order,
			TPFWLI_Plugin::META_EMAIL_STAGE,
			'sent',
			sprintf(
				/* translators: %s: billing email */
				__('Legacy import customer email sent to %s.', 'tickets-passes-legacy-importer'),
				$order->get_billing_email()
			)
		);

		return $this->result(true, array(), wc_get_order($order->get_id()), $nanos);
	}

	private function mark_completed(WC_Order $order): void
	{
		if (!$order->get_date_paid('edit')) {
			$order->set_date_paid(time());
		}
		if ($order->get_status() !== 'completed') {
			$order->update_status('completed', __('Legacy import marked completed after verified ticket issue.', 'tickets-passes-legacy-importer'), true);
		} else {
			$order->save();
		}
	}

	/**
	 * @param string[]            $errors
	 * @param string[]            $nanos
	 * @return array<string,mixed>
	 */
	private function result(bool $ok, array $errors, $order, array $nanos = array(), $current_stock = null, bool $email_already = false, bool $email_unknown = false): array
	{
		$order = $order instanceof WC_Order ? wc_get_order($order->get_id()) : $order;
		$qty   = 0;
		$stock_label = '';
		if ($order instanceof WC_Order) {
			$qty = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY);
			if ($qty < 1) {
				foreach ($order->get_items('line_item') as $item) {
					$qty += (int) $item->get_quantity();
				}
			}
			$stock_label = $this->stock->accounted_label($order);
			if ($current_stock === null) {
				$items = array_values($order->get_items('line_item'));
				if (isset($items[0])) {
					$p = wc_get_product($items[0]->get_product_id());
					$current_stock = $p ? $p->get_stock_quantity() : null;
				}
			}
		}

		return array(
			'ok'             => $ok,
			'errors'         => $errors,
			'order'          => $order,
			'nanos'          => $nanos,
			'ticket_count'   => count($nanos),
			'quantity'       => $qty,
			'current_stock'  => $current_stock,
			'stock_qty'      => $order instanceof WC_Order ? $this->stock->reduced_qty($order) : 0,
			'stock_label'    => $stock_label,
			'email_already'  => $email_already,
			'email_unknown'  => $email_unknown,
			'import_id'      => $order instanceof WC_Order ? (string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID) : '',
		);
	}

	/**
	 * @param string[] $errors
	 */
	private function fail_result(array $errors, $order, string $import_id): array
	{
		$out = $this->result(false, $errors, $order);
		if ($out['import_id'] === '') {
			$out['import_id'] = $import_id;
		}
		return $out;
	}
}
