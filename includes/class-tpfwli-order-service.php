<?php
defined('ABSPATH') || exit;

/**
 * Creates or resumes a guest WooCommerce order for one legacy import ID.
 *
 * First-time bootstrap runs inside wc_transaction_query() so a crash cannot
 * leave a wc_orders row without import identity or Ticket line. Resume of an
 * already-committed order is unchanged and is not wrapped in that transaction.
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
		$problems = TPFWLI_Dependencies::problems(true);
		if ($problems !== array()) {
			return array(
				'ok'      => false,
				'order'   => null,
				'created' => false,
				'error'   => implode(' ', $problems),
			);
		}

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

		$storage = TPFWLI_Dependencies::transactional_storage_problems();
		if ($storage !== array()) {
			return array(
				'ok'      => false,
				'order'   => null,
				'created' => false,
				'error'   => implode(' ', $storage),
			);
		}

		return $this->bootstrap_new($import_id, $customer, $product, $quantity, $product_meta);
	}

	/**
	 * First-time order + identity + Ticket line. Rolled back as one unit on failure.
	 *
	 * @param array<string,mixed> $customer
	 * @param array<string,mixed> $product_meta
	 * @return array{ok:bool,order:?WC_Order,created:bool,error:string}
	 */
	private function bootstrap_new(string $import_id, array $customer, WC_Product $product, int $quantity, array $product_meta): array
	{
		$storage = TPFWLI_Dependencies::transactional_storage_problems();
		if ($storage !== array()) {
			return array(
				'ok'      => false,
				'order'   => null,
				'created' => false,
				'error'   => implode(' ', $storage),
			);
		}

		$order_id = 0;
		wc_transaction_query('start');
		try {
			$order = wc_create_order(array(
				'status'      => 'pending',
				'customer_id' => 0,
				'created_via' => TPFWLI_Plugin::created_via($import_id),
			));
			if (is_wp_error($order) || !$order instanceof WC_Order) {
				$message = is_wp_error($order) ? $order->get_error_message() : '';
				throw new RuntimeException(
					$message !== '' ? $message : __('Could not create a WooCommerce order.', 'tickets-passes-legacy-importer')
				);
			}
			$order_id = (int) $order->get_id();
			if ($order_id < 1) {
				throw new RuntimeException(__('Could not create a WooCommerce order.', 'tickets-passes-legacy-importer'));
			}
			$this->crash_checkpoint('after_order_id', $order);

			$this->apply_billing($order, $customer);
			$this->apply_snapshots($order, $import_id, $product, $quantity, $product_meta);
			$order->save();
			$this->crash_checkpoint('after_meta', $order);

			$shaped = (new TPFWLI_Order_Shape())->assert_or_repair($order, $product, $quantity);
			if (!$shaped['ok'] || !$shaped['order'] instanceof WC_Order) {
				throw new RuntimeException(
					$shaped['error'] !== '' ? $shaped['error'] : __('Could not add the Ticket product line.', 'tickets-passes-legacy-importer')
				);
			}
			$order = $shaped['order'];
			$this->crash_checkpoint('after_line', $order);

			wc_transaction_query('commit');
		} catch (Throwable $e) {
			wc_transaction_query('rollback');
			$this->forget_rolled_back_order($order_id);
			return array(
				'ok'      => false,
				'order'   => null,
				'created' => false,
				'error'   => $e->getMessage() !== ''
					? $e->getMessage()
					: __('Could not create a WooCommerce order.', 'tickets-passes-legacy-importer'),
			);
		}

		$fresh = wc_get_order($order_id);
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

	private function crash_checkpoint(string $point, WC_Order $order): void
	{
		do_action('tpfwli_bootstrap_checkpoint', $point, $order);
	}

	private function forget_rolled_back_order(int $order_id): void
	{
		if ($order_id < 1) {
			return;
		}
		wp_cache_delete($order_id, 'orders');
		wp_cache_delete('order-' . $order_id, 'orders');
		clean_post_cache($order_id);
		if (function_exists('wc_delete_shop_order_transients')) {
			wc_delete_shop_order_transients($order_id);
		}
		if (!function_exists('wc_get_container')) {
			return;
		}
		try {
			$store = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class);
			if (is_object($store) && method_exists($store, 'clear_cached_data')) {
				$store->clear_cached_data(array($order_id));
			}
		} catch (Throwable $ignored) {
			unset($ignored);
		}
		try {
			$cache = wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class);
			if (is_object($cache) && method_exists($cache, 'remove')) {
				$cache->remove($order_id);
			}
		} catch (Throwable $ignored) {
			unset($ignored);
		}
	}

	public function set_stage(WC_Order $order, string $meta_key, string $value, string $note = ''): void
	{
		TPFWLI_Database_Session::assert_writable();
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
