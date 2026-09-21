<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce stock is the source of truth. Never writes _stock or _order_stock_reduced.
 *
 * Product stock, the line's _reduced_stock, and the order stock-reduced flag are
 * committed together. Ticket issue and customer email stay outside this transaction.
 */
final class TPFWLI_Stock_Service
{
	public function is_reduced(WC_Order $order): bool
	{
		$order = wc_get_order($order->get_id());
		if (!$order) {
			return false;
		}
		$store = $order->get_data_store();
		// WC_Data_Store proxies get_stock_reduced() via __call; method_exists() is false.
		if ($store && is_callable(array($store, 'get_stock_reduced'))) {
			return (bool) $store->get_stock_reduced($order->get_id());
		}
		$meta = $order->get_meta('_order_stock_reduced');
		return $meta === true || $meta === 'yes' || $meta === 1 || $meta === '1';
	}

	public function is_accounted(WC_Order $order, WC_Product $product, int $quantity): bool
	{
		return $this->accounted_state($order, $product, $quantity) === 'complete';
	}

	/**
	 * @return array{ok:bool,error:string,current_stock:?int,reduced_qty:int}
	 */
	public function reduce_if_needed(WC_Order $order, WC_Product $product, int $quantity): array
	{
		$order_id   = (int) $order->get_id();
		$product_id = (int) $product->get_id();
		$fail       = function (string $error, ?int $stock = null, int $reduced = 0) use ($product_id): array {
			$fresh = wc_get_product($product_id);
			return array(
				'ok'            => false,
				'error'         => $error,
				'current_stock' => $stock !== null ? $stock : ($fresh ? $fresh->get_stock_quantity() : null),
				'reduced_qty'   => $reduced,
			);
		};

		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return $fail(__('The importer order no longer exists.', 'tickets-passes-legacy-importer'));
		}

		$items = array_values($order->get_items('line_item'));
		if (count($items) !== 1) {
			return $fail(__('Order shape is not ready for stock reduction.', 'tickets-passes-legacy-importer'));
		}
		$item = $items[0];
		if ((int) $item->get_product_id() !== $product_id || (int) $item->get_quantity() !== $quantity) {
			return $fail(__('Order line does not match the locked product and quantity; stock was not reduced.', 'tickets-passes-legacy-importer'));
		}

		$state = $this->accounted_state($order, $product, $quantity);
		if ($state === 'complete') {
			$fresh = wc_get_product($product_id);
			return array(
				'ok'            => true,
				'error'         => '',
				'current_stock' => $fresh ? $fresh->get_stock_quantity() : null,
				'reduced_qty'   => $quantity,
			);
		}
		if ($state === 'conflict') {
			return $fail(
				__('Stock state is inconsistent (order flag and line _reduced_stock do not both match the locked quantity). Stock, tickets and email were not changed.', 'tickets-passes-legacy-importer'),
				null,
				$this->reduced_qty($order)
			);
		}

		$storage = TPFWLI_Dependencies::transactional_stock_storage_problems();
		if ($storage !== array()) {
			return $fail(implode(' ', $storage));
		}

		if ($this->in_sql_transaction()) {
			return $fail(__('Stock reduction refused because another database transaction is already open.', 'tickets-passes-legacy-importer'));
		}

		$fresh = wc_get_product($product_id);
		if (!$fresh || !$fresh->managing_stock()) {
			return $fail(__('Stock is no longer managed on this product.', 'tickets-passes-legacy-importer'), null);
		}

		$started      = false;
		$locked_stock = null;
		$saw_product  = false;
		$on_product   = null;
		$on_line      = null;
		try {
			wc_transaction_query('start');
			$started = true;

			$locked_stock = $this->lock_product_stock($product_id);
			if ($locked_stock === null) {
				throw new RuntimeException(__('Could not lock product stock for reduction.', 'tickets-passes-legacy-importer'));
			}
			if (!$fresh->backorders_allowed() && $locked_stock < $quantity) {
				throw new RuntimeException(__('Insufficient stock at confirm time. Nothing was reduced.', 'tickets-passes-legacy-importer'));
			}

			$on_product = function ($changed) use ($order, $product_id, &$saw_product): void {
				if (!$changed instanceof WC_Product || (int) $changed->get_id() !== $product_id) {
					return;
				}
				$saw_product = true;
				do_action('tpfwli_stock_checkpoint', 'after_product_stock', $order, $changed);
			};
			$on_line = function ($changed_order) use ($order): void {
				if (!$changed_order instanceof WC_Order || (int) $changed_order->get_id() !== (int) $order->get_id()) {
					return;
				}
				do_action('tpfwli_stock_checkpoint', 'after_line_meta', $changed_order, null);
			};
			add_action('woocommerce_product_set_stock', $on_product, 1);
			add_action('woocommerce_reduce_order_stock', $on_line, 1);

			wc_maybe_reduce_stock_levels($order_id);

			$this->forget_stock_runtime($order_id, $product_id);
			$order = wc_get_order($order_id);
			if (!$order instanceof WC_Order) {
				throw new RuntimeException(__('The importer order no longer exists.', 'tickets-passes-legacy-importer'));
			}

			if (!$saw_product) {
				throw new RuntimeException(__('WooCommerce did not change product stock for this import. The reduction was not accepted.', 'tickets-passes-legacy-importer'));
			}
			if (!$this->is_accounted($order, $product, $quantity)) {
				throw new RuntimeException(__('WooCommerce stock reduction did not record the locked quantity on the order line and stock-reduced flag.', 'tickets-passes-legacy-importer'));
			}

			$after_stock = $this->read_product_stock($product_id);
			if ($after_stock === null) {
				throw new RuntimeException(__('Could not read product stock after reduction.', 'tickets-passes-legacy-importer'));
			}
			if ($after_stock !== ($locked_stock - $quantity)) {
				throw new RuntimeException(__('Product stock was not decreased by the locked quantity. The reduction was not accepted.', 'tickets-passes-legacy-importer'));
			}
			if (!$fresh->backorders_allowed() && $after_stock < 0) {
				throw new RuntimeException(__('Stock would have gone negative (likely a concurrent online sale). This order’s reduction was rolled back. No tickets or email.', 'tickets-passes-legacy-importer'));
			}

			wc_transaction_query('commit');
			$started = false;
			$this->forget_stock_runtime($order_id, $product_id);

			$order = wc_get_order($order_id);
			if (!$order instanceof WC_Order || !$this->is_accounted($order, $product, $quantity)) {
				return $fail(__('Stock reduction did not persist after commit.', 'tickets-passes-legacy-importer'));
			}

			$fresh = wc_get_product($product_id);
			return array(
				'ok'            => true,
				'error'         => '',
				'current_stock' => $fresh ? $fresh->get_stock_quantity() : $after_stock,
				'reduced_qty'   => $quantity,
			);
		} catch (Throwable $e) {
			if ($started) {
				wc_transaction_query('rollback');
				$this->forget_stock_runtime($order_id, $product_id);
				if ($locked_stock !== null) {
					$restored = $this->read_product_stock($product_id);
					if ($restored === null || (int) $restored !== (int) $locked_stock) {
						return $fail(
							__('Stock reduction was interrupted and the database rollback did not restore product stock. Tickets and email were not sent.', 'tickets-passes-legacy-importer'),
							$restored !== null ? (int) $restored : null
						);
					}
				}
			}
			$message = $e->getMessage() !== ''
				? $e->getMessage()
				: __('Stock reduction failed.', 'tickets-passes-legacy-importer');
			return $fail($message);
		} finally {
			if (is_callable($on_product)) {
				remove_action('woocommerce_product_set_stock', $on_product, 1);
			}
			if (is_callable($on_line)) {
				remove_action('woocommerce_reduce_order_stock', $on_line, 1);
			}
		}
	}

	public function reduced_qty(WC_Order $order): int
	{
		$qty = 0;
		foreach ($order->get_items('line_item') as $item) {
			$qty += (int) $item->get_meta('_reduced_stock', true);
		}
		return $qty;
	}

	public function accounted_label(WC_Order $order): string
	{
		if (!$this->is_reduced($order)) {
			return __('not reduced', 'tickets-passes-legacy-importer');
		}
		$expected = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY);
		$actual   = $this->reduced_qty($order);
		if ($expected > 0 && $actual === $expected) {
			return (string) $actual;
		}
		return __('Stock state inconsistent', 'tickets-passes-legacy-importer');
	}

	/**
	 * @return 'complete'|'conflict'|'none'
	 */
	private function accounted_state(WC_Order $order, WC_Product $product, int $quantity): string
	{
		$order = wc_get_order($order->get_id()) ?: $order;
		$items = array_values($order->get_items('line_item'));
		$flag  = $this->is_reduced($order);
		$meta  = null;
		$line_ok = false;
		if (count($items) === 1) {
			$item = $items[0];
			$line_ok = (int) $item->get_product_id() === (int) $product->get_id()
				&& (int) $item->get_quantity() === $quantity;
			if ($item->meta_exists('_reduced_stock')) {
				$meta = (int) $item->get_meta('_reduced_stock', true);
			}
		}

		if ($flag && $line_ok && $meta === $quantity) {
			return 'complete';
		}
		if ($flag || $meta !== null) {
			return 'conflict';
		}
		return 'none';
	}

	private function lock_product_stock(int $product_id): ?int
	{
		global $wpdb;
		if (!$wpdb instanceof wpdb) {
			return null;
		}
		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s FOR UPDATE",
			$product_id,
			'_stock'
		));
		if ($value === null || $wpdb->last_error !== '') {
			return null;
		}
		return (int) $value;
	}

	private function read_product_stock(int $product_id): ?int
	{
		global $wpdb;
		if (!$wpdb instanceof wpdb) {
			return null;
		}
		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$product_id,
			'_stock'
		));
		if ($value === null) {
			return null;
		}
		return (int) $value;
	}

	private function in_sql_transaction(): bool
	{
		global $wpdb;
		if (!$wpdb instanceof wpdb) {
			return false;
		}
		$flag = $wpdb->get_var('SELECT @@SESSION.in_transaction');
		return (string) $flag === '1';
	}

	private function forget_stock_runtime(int $order_id, int $product_id): void
	{
		if ($product_id > 0) {
			wp_cache_delete($product_id, 'posts');
			wp_cache_delete($product_id, 'post_meta');
			wp_cache_delete($product_id, 'products');
			wp_cache_delete('product-' . $product_id, 'products');
			clean_post_cache($product_id);
			if (function_exists('wc_delete_product_transients')) {
				wc_delete_product_transients($product_id);
			}
		}
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
}
