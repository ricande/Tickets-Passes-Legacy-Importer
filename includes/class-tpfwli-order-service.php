<?php
defined('ABSPATH') || exit;

/**
 * Creates or resumes a guest WooCommerce order for one legacy import ID.
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
		if ($existing instanceof WC_Order) {
			return array(
				'ok'      => true,
				'order'   => $existing,
				'created' => false,
				'error'   => '',
			);
		}

		$order = wc_create_order(array(
			'status'      => 'pending',
			'customer_id' => 0,
			'created_via' => TPFWLI_Plugin::CREATED_VIA,
		));

		if (is_wp_error($order) || !$order instanceof WC_Order) {
			return array(
				'ok'      => false,
				'order'   => null,
				'created' => false,
				'error'   => __('Could not create a WooCommerce order.', 'tickets-passes-legacy-importer'),
			);
		}

		$order->set_customer_id(0);
		$order->set_billing_first_name($customer['first_name']);
		$order->set_billing_last_name($customer['last_name']);
		$order->set_billing_email($customer['email']);
		$order->set_billing_phone($customer['phone']);
		$order->set_created_via(TPFWLI_Plugin::CREATED_VIA);

		$order->update_meta_data(TPFWLI_Plugin::META_IMPORT, 'yes');
		$order->update_meta_data(TPFWLI_Plugin::META_IMPORT_ID, $import_id);
		$order->update_meta_data(TPFWLI_Plugin::META_ORDER_STAGE, 'ready');
		$order->update_meta_data(TPFWLI_Plugin::META_STOCK_STAGE, 'pending');
		$order->update_meta_data(TPFWLI_Plugin::META_ISSUE_STAGE, 'pending');
		$order->update_meta_data(TPFWLI_Plugin::META_EMAIL_STAGE, 'not_sent');
		$order->update_meta_data(TPFWLI_Plugin::META_IMPORTED_AT, gmdate('c'));
		$order->save();

		$item_id = $order->add_product($product, $quantity);
		if (!$item_id) {
			$order->add_order_note(__('Legacy import failed: could not add the Ticket product line.', 'tickets-passes-legacy-importer'));
			$order->save();
			return array(
				'ok'      => false,
				'order'   => $order,
				'created' => true,
				'error'   => __('Could not add the Ticket product to the order.', 'tickets-passes-legacy-importer'),
			);
		}

		$item = $order->get_item($item_id);
		if ($item) {
			$item->update_meta_data('tpfw_firstname', $customer['first_name']);
			$item->update_meta_data('tpfw_lastname', $customer['last_name']);
			if (!empty($product_meta['display_from'])) {
				$item->update_meta_data('tpfw_predefined_date', (string) $product_meta['display_from']);
			}
			if (!empty($product_meta['display_to'])) {
				$item->update_meta_data('tpfw_valid_to', (string) $product_meta['display_to']);
			}
			$item->save();
		}

		$order->calculate_totals();
		$order->add_order_note(__('Imported historical ticket sale by Tickets & Passes – Legacy Ticket Importer.', 'tickets-passes-legacy-importer'));
		$order->save();

		return array(
			'ok'      => true,
			'order'   => wc_get_order($order->get_id()),
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
}
