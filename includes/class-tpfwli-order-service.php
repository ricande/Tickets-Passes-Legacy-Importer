<?php
defined('ABSPATH') || exit;

/**
 * Creates or resumes a guest WooCommerce order for one legacy import ID.
 *
 * First save persists created_via (UUID in the order INSERT) plus immutable
 * snapshots. The Ticket line is added later by TPFWLI_Order_Shape.
 */
final class TPFWLI_Order_Service
{
	private TPFWLI_Import_Repository $repository;

	public function __construct(?TPFWLI_Import_Repository $repository = null)
	{
		$this->repository = $repository ?: new TPFWLI_Import_Repository();
	}

	/**
	 * @param array<string,mixed> $customer
	 * @param array<string,mixed> $product_meta
	 * @return array{ok:bool,order:?WC_Order,created:bool,error:string}
	 */
	public function create_or_resume(string $import_id, array $customer, WC_Product $product, int $quantity, array $product_meta): array
	{
		$existing = $this->repository->find_by_import_id($import_id);
		if (!$existing['ok']) {
			return array(
				'ok'      => false,
				'order'   => null,
				'created' => false,
				'error'   => $existing['error'],
			);
		}

		if ($existing['order'] instanceof WC_Order) {
			$order = $this->complete_identity_if_needed($existing['order'], $import_id, $customer, $product, $quantity, $product_meta);
			return array(
				'ok'      => true,
				'order'   => $order,
				'created' => false,
				'error'   => '',
			);
		}

		$order = new WC_Order();
		$order->set_status('pending');
		$order->set_customer_id(0);
		$order->set_created_via(TPFWLI_Plugin::created_via($import_id));
		$this->apply_billing($order, $customer);
		$this->apply_snapshots($order, $import_id, $product, $quantity, $product_meta);
		$order->save();

		$fresh = wc_get_order($order->get_id());
		if (!$fresh instanceof WC_Order) {
			return array(
				'ok'      => false,
				'order'   => null,
				'created' => false,
				'error'   => __('Could not create a WooCommerce order.', 'tickets-passes-legacy-importer'),
			);
		}

		return array(
			'ok'      => true,
			'order'   => $fresh,
			'created' => true,
			'error'   => '',
		);
	}

	public function set_stage(WC_Order $order, string $meta_key, string $value, string $note = ''): void
	{
		$order->update_meta_data($meta_key, $value);
		if ($note !== '') {
			$order->add_order_note($note);
		}
		$order->save();
	}

	/**
	 * @param array<string,mixed> $customer
	 * @param array<string,mixed> $product_meta
	 */
	private function complete_identity_if_needed(WC_Order $order, string $import_id, array $customer, WC_Product $product, int $quantity, array $product_meta): WC_Order
	{
		$dirty = false;

		if ((string) $order->get_created_via() === '') {
			$order->set_created_via(TPFWLI_Plugin::created_via($import_id));
			$dirty = true;
		}
		if ((string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID) === '') {
			$order->update_meta_data(TPFWLI_Plugin::META_IMPORT_ID, $import_id);
			$dirty = true;
		}
		if ((string) $order->get_meta(TPFWLI_Plugin::META_IMPORT) !== 'yes') {
			$order->update_meta_data(TPFWLI_Plugin::META_IMPORT, 'yes');
			$dirty = true;
		}

		$has_snapshot = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID) > 0
			&& (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY) > 0;
		$items = $order->get_items('line_item');
		$stock = new TPFWLI_Stock_Service();
		if (!$has_snapshot && $items === array() && !$stock->is_reduced($order)) {
			$this->apply_snapshots($order, $import_id, $product, $quantity, $product_meta);
			$dirty = true;
		}

		if ($order->get_billing_email() === '') {
			$this->apply_billing($order, $customer);
			$dirty = true;
		}

		if ((string) $order->get_meta(TPFWLI_Plugin::META_ORDER_STAGE) === '') {
			$order->update_meta_data(TPFWLI_Plugin::META_ORDER_STAGE, TPFWLI_Plugin::STAGE_BOOTSTRAPPING);
			$dirty = true;
		}
		if ((string) $order->get_meta(TPFWLI_Plugin::META_STOCK_STAGE) === '') {
			$order->update_meta_data(TPFWLI_Plugin::META_STOCK_STAGE, 'pending');
			$dirty = true;
		}
		if ((string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) === '') {
			$order->update_meta_data(TPFWLI_Plugin::META_ISSUE_STAGE, 'pending');
			$dirty = true;
		}
		if ((string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE) === '') {
			$order->update_meta_data(TPFWLI_Plugin::META_EMAIL_STAGE, 'not_sent');
			$dirty = true;
		}

		if ($dirty) {
			$order->save();
		}

		$fresh = wc_get_order($order->get_id());
		return $fresh instanceof WC_Order ? $fresh : $order;
	}

	/**
	 * @param array<string,mixed> $customer
	 */
	private function apply_billing(WC_Order $order, array $customer): void
	{
		$order->set_customer_id(0);
		$order->set_billing_first_name((string) ($customer['first_name'] ?? ''));
		$order->set_billing_last_name((string) ($customer['last_name'] ?? ''));
		$order->set_billing_email((string) ($customer['email'] ?? ''));
		$order->set_billing_phone((string) ($customer['phone'] ?? ''));
	}

	/**
	 * @param array<string,mixed> $product_meta
	 */
	private function apply_snapshots(WC_Order $order, string $import_id, WC_Product $product, int $quantity, array $product_meta): void
	{
		$order->update_meta_data(TPFWLI_Plugin::META_IMPORT, 'yes');
		$order->update_meta_data(TPFWLI_Plugin::META_IMPORT_ID, $import_id);
		$order->update_meta_data(TPFWLI_Plugin::META_ORDER_STAGE, TPFWLI_Plugin::STAGE_BOOTSTRAPPING);
		$order->update_meta_data(TPFWLI_Plugin::META_STOCK_STAGE, 'pending');
		$order->update_meta_data(TPFWLI_Plugin::META_ISSUE_STAGE, 'pending');
		$order->update_meta_data(TPFWLI_Plugin::META_EMAIL_STAGE, 'not_sent');
		$order->update_meta_data(TPFWLI_Plugin::META_IMPORTED_AT, gmdate('c'));
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID, (int) $product->get_id());
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_QUANTITY, $quantity);
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_MAX_USES, (int) ($product_meta['max_uses'] ?? 0));
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_VALID_FROM, (string) ($product_meta['valid_from'] ?? ''));
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_VALID_TO, (string) ($product_meta['valid_to'] ?? ''));
	}
}
