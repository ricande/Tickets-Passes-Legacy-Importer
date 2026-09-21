<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce stock is the source of truth. Never writes _stock or _order_stock_reduced.
 *
 * Product stock, the line's _reduced_stock, and the order stock-reduced flag are
 * committed together after a verified START TRANSACTION. Ticket issue and
 * customer email stay outside this transaction and require a verified COMMIT.
 *
 * Rollback is confirmed from the closed transaction plus this import's own
 * flag and _reduced_stock. A later sale that changes the product balance after
 * the row lock is released is not treated as a failed restore.
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

		$txn_state = $this->inspect_sql_transaction();
		if (!$txn_state['ok']) {
			return $fail(
				$txn_state['error'] !== ''
					? $txn_state['error']
					: __('Could not determine whether a database transaction is already open. Stock was not changed.', 'tickets-passes-legacy-importer')
			);
		}
		if ($txn_state['open']) {
			return $fail(__('Stock reduction refused because another database transaction is already open.', 'tickets-passes-legacy-importer'));
		}

		$fresh = wc_get_product($product_id);
		if (!$fresh || !$fresh->managing_stock()) {
			return $fail(__('Stock is no longer managed on this product.', 'tickets-passes-legacy-importer'), null);
		}

		$txn_open     = false;
		$locked_stock = null;
		$saw_product  = false;
		$on_product   = null;
		$on_line      = null;
		try {
			$begin = $this->begin_sql_transaction();
			if (!$begin['ok']) {
				return $fail($begin['error']);
			}
			$txn_open = true;

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

			$commit = $this->commit_sql_transaction();
			if (!$commit['ok']) {
				$txn_open = !empty($commit['still_open']);
				$this->forget_stock_runtime($order_id, $product_id);
				if (!$txn_open && !$this->import_reduction_cleared($order_id, $product_id, $quantity)) {
					return $fail(
						__('Stock reduction commit failed and the importer order still looks reduced. Tickets and email were not sent.', 'tickets-passes-legacy-importer')
					);
				}
				return $fail($commit['error']);
			}
			$txn_open = false;
			$this->forget_stock_runtime($order_id, $product_id);

			$order = wc_get_order($order_id);
			if (!$order instanceof WC_Order || !$this->is_accounted($order, $product, $quantity)) {
				return $fail(__('Stock reduction did not persist after a verified commit.', 'tickets-passes-legacy-importer'));
			}

			$fresh = wc_get_product($product_id);
			return array(
				'ok'            => true,
				'error'         => '',
				'current_stock' => $fresh ? $fresh->get_stock_quantity() : $after_stock,
				'reduced_qty'   => $quantity,
			);
		} catch (Throwable $e) {
			$message = $e->getMessage() !== ''
				? $e->getMessage()
				: __('Stock reduction failed.', 'tickets-passes-legacy-importer');
			if ($txn_open) {
				$rolled = $this->rollback_sql_transaction();
				$txn_open = !empty($rolled['still_open']);
				$this->forget_stock_runtime($order_id, $product_id);
				if (!$rolled['ok']) {
					return $fail($rolled['error']);
				}
				if (!$this->import_reduction_cleared($order_id, $product_id, $quantity)) {
					return $fail(
						__('Stock reduction was rolled back but the importer order still looks reduced. Tickets and email were not sent.', 'tickets-passes-legacy-importer')
					);
				}
			}
			return $fail($message);
		} finally {
			if ($txn_open) {
				$this->rollback_sql_transaction();
				$this->forget_stock_runtime($order_id, $product_id);
			}
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

	/**
	 * @return array{ok:bool,open:bool,error:string}
	 */
	private function inspect_sql_transaction(): array
	{
		global $wpdb;
		if (!$wpdb instanceof wpdb) {
			return array(
				'ok'    => false,
				'open'  => false,
				'error' => __('Could not inspect the database transaction state.', 'tickets-passes-legacy-importer'),
			);
		}

		$unknown = __('Could not determine whether a database transaction is already open. Stock was not changed.', 'tickets-passes-legacy-importer');

		$session = $this->sql_scalar('SELECT @@SESSION.in_transaction');
		if ($session['ok'] && $this->is_bool_flag($session['value'])) {
			return array('ok' => true, 'open' => $this->flag_is_true($session['value']), 'error' => '');
		}

		$trx = $this->sql_scalar('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()');
		if ($trx['ok'] && is_numeric($trx['value'])) {
			return array('ok' => true, 'open' => (int) $trx['value'] > 0, 'error' => '');
		}

		$detail = $session['error'] !== '' ? $session['error'] : $trx['error'];
		return array(
			'ok'    => false,
			'open'  => false,
			'error' => $detail !== '' ? $unknown . ' ' . $detail : $unknown,
		);
	}

	/**
	 * @return array{ok:bool,value:mixed,error:string}
	 */
	private function sql_scalar(string $sql): array
	{
		global $wpdb;
		$previous_suppress = $wpdb->suppress_errors(true);
		$previous_show     = $wpdb->show_errors(false);
		try {
			$value = $wpdb->get_var($sql);
			$error = (string) $wpdb->last_error;
			if ($error !== '') {
				return array('ok' => false, 'value' => null, 'error' => $error);
			}
			if ($value === null) {
				return array('ok' => false, 'value' => null, 'error' => '');
			}
			return array('ok' => true, 'value' => $value, 'error' => '');
		} finally {
			$wpdb->suppress_errors((bool) $previous_suppress);
			$wpdb->show_errors((bool) $previous_show);
		}
	}

	private function is_bool_flag(mixed $value): bool
	{
		return in_array((string) $value, array('0', '1'), true);
	}

	private function flag_is_true(mixed $value): bool
	{
		return (string) $value === '1';
	}

	/**
	 * @return array{ok:bool,error:string}
	 */
	private function begin_sql_transaction(): array
	{
		$run = $this->run_sql_transaction_command('START TRANSACTION');
		if (!$run['ok']) {
			return array(
				'ok'    => false,
				'error' => $run['error'] !== ''
					? $run['error']
					: __('Could not start a database transaction for stock reduction.', 'tickets-passes-legacy-importer'),
			);
		}
		$state = $this->inspect_sql_transaction();
		if (!$state['ok']) {
			$this->rollback_sql_transaction();
			return array(
				'ok'    => false,
				'error' => __('Started stock reduction but could not verify that a database transaction is open.', 'tickets-passes-legacy-importer'),
			);
		}
		if (!$state['open']) {
			return array(
				'ok'    => false,
				'error' => __('START TRANSACTION did not open a database transaction. Stock was not changed.', 'tickets-passes-legacy-importer'),
			);
		}
		return array('ok' => true, 'error' => '');
	}

	/**
	 * @return array{ok:bool,error:string,still_open:bool}
	 */
	private function commit_sql_transaction(): array
	{
		$run   = $this->run_sql_transaction_command('COMMIT');
		$state = $this->inspect_sql_transaction();
		if ($run['ok'] && $state['ok'] && !$state['open']) {
			return array('ok' => true, 'error' => '', 'still_open' => false);
		}

		$rolled = $this->rollback_sql_transaction();
		if (!$run['ok']) {
			return array(
				'ok'         => false,
				'still_open' => $rolled['still_open'],
				'error'      => $run['error'] !== ''
					? $run['error']
					: __('COMMIT failed. Stock reduction was not accepted.', 'tickets-passes-legacy-importer'),
			);
		}
		if (!$state['ok']) {
			return array(
				'ok'         => false,
				'still_open' => $rolled['still_open'],
				'error'      => __('Stock reduction commit outcome is uncertain. Tickets and email were not sent.', 'tickets-passes-legacy-importer'),
			);
		}
		return array(
			'ok'         => false,
			'still_open' => $rolled['still_open'],
			'error'      => __('COMMIT did not close the database transaction. Stock reduction was not accepted.', 'tickets-passes-legacy-importer'),
		);
	}

	/**
	 * @return array{ok:bool,error:string,still_open:bool}
	 */
	private function rollback_sql_transaction(): array
	{
		$last_error = '';
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$run       = $this->run_sql_transaction_command('ROLLBACK');
			$last_error = $run['error'];
			$state     = $this->inspect_sql_transaction();
			if ($run['ok'] && $state['ok'] && !$state['open']) {
				return array('ok' => true, 'error' => '', 'still_open' => false);
			}
			if ($state['ok'] && !$state['open']) {
				return array(
					'ok'         => true,
					'error'      => '',
					'still_open' => false,
				);
			}
			if (!$state['ok']) {
				return array(
					'ok'         => false,
					'still_open' => true,
					'error'      => __('Stock reduction was interrupted and the rollback outcome is uncertain. Tickets and email were not sent.', 'tickets-passes-legacy-importer'),
				);
			}
		}
		return array(
			'ok'         => false,
			'still_open' => true,
			'error'      => $last_error !== ''
				? $last_error
				: __('ROLLBACK failed and a database transaction may still be open. Tickets and email were not sent.', 'tickets-passes-legacy-importer'),
		);
	}

	/**
	 * @return array{ok:bool,error:string}
	 */
	private function run_sql_transaction_command(string $sql): array
	{
		global $wpdb;
		if (!$wpdb instanceof wpdb) {
			return array('ok' => false, 'error' => __('Database connection is not available.', 'tickets-passes-legacy-importer'));
		}

		$previous_suppress = $wpdb->suppress_errors(true);
		$previous_show     = $wpdb->show_errors(false);
		try {
			$result = $wpdb->query($sql);
			$error  = (string) $wpdb->last_error;
			if ($result === false || $error !== '') {
				return array(
					'ok'    => false,
					'error' => $error !== '' ? $error : sprintf(
						/* translators: %s: SQL command */
						__('Database command %s failed.', 'tickets-passes-legacy-importer'),
						$sql
					),
				);
			}
			return array('ok' => true, 'error' => '');
		} finally {
			$wpdb->suppress_errors((bool) $previous_suppress);
			$wpdb->show_errors((bool) $previous_show);
		}
	}

	private function import_reduction_cleared(int $order_id, int $product_id, int $quantity): bool
	{
		$this->forget_stock_runtime($order_id, $product_id);
		if ($this->db_import_still_reduced($order_id)) {
			return false;
		}
		$this->forget_stock_runtime($order_id, $product_id);
		return true;
	}

	private function db_import_still_reduced(int $order_id): bool
	{
		global $wpdb;
		if (!$wpdb instanceof wpdb || $order_id < 1) {
			return true;
		}
		$previous_suppress = $wpdb->suppress_errors(true);
		$previous_show     = $wpdb->show_errors(false);
		try {
			$flag = $wpdb->get_var($wpdb->prepare(
				"SELECT order_stock_reduced FROM {$wpdb->prefix}wc_order_operational_data WHERE order_id = %d",
				$order_id
			));
			if ((string) $wpdb->last_error !== '') {
				return true;
			}
			if ($flag === '1' || $flag === 1 || $flag === true) {
				return true;
			}
			$meta = $wpdb->get_var($wpdb->prepare(
				"SELECT im.meta_value
				FROM {$wpdb->prefix}woocommerce_order_itemmeta im
				INNER JOIN {$wpdb->prefix}woocommerce_order_items i ON i.order_item_id = im.order_item_id
				WHERE i.order_id = %d AND im.meta_key = %s
				LIMIT 1",
				$order_id,
				'_reduced_stock'
			));
			if ((string) $wpdb->last_error !== '') {
				return true;
			}
			return $meta !== null && $meta !== '';
		} finally {
			$wpdb->suppress_errors((bool) $previous_suppress);
			$wpdb->show_errors((bool) $previous_show);
		}
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

		$item_ids = $this->order_item_ids($order_id);
		$item_keys = array();
		foreach ($item_ids as $item_id) {
			$item_keys[] = 'item-' . $item_id;
			$item_keys[] = (string) $item_id;
			$item_keys[] = 'order-item-meta-' . $item_id;
			wp_cache_delete('item-' . $item_id, 'order-items');
			wp_cache_delete($item_id, 'order-items');
			wp_cache_delete($item_id, 'order_item_meta');
			wp_cache_delete($item_id, 'item_meta');
			wp_cache_delete('order-item-meta-' . $item_id, 'order-items');
		}
		wp_cache_delete('order-items-' . $order_id, 'orders');
		wp_cache_delete($order_id, 'orders');
		wp_cache_delete('order-' . $order_id, 'orders');
		wp_cache_delete($order_id, 'posts');
		wp_cache_delete($order_id, 'post_meta');
		if (class_exists('WC_Cache_Helper') && method_exists('WC_Cache_Helper', 'get_cache_prefix')) {
			$order_prefix = (string) WC_Cache_Helper::get_cache_prefix('orders');
			wp_cache_delete($order_prefix . $order_id, 'orders');
			wp_cache_delete($order_prefix . 'order_' . $order_id, 'orders');
			wp_cache_delete($order_prefix . 'order-items-' . $order_id, 'orders');
			$item_prefix = (string) WC_Cache_Helper::get_cache_prefix('order-items');
			foreach ($item_keys as $item_key) {
				wp_cache_delete($item_prefix . $item_key, 'order-items');
				wp_cache_delete($item_prefix . $item_key, 'order_item_meta');
			}
		}
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
		try {
			$controller = wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCacheController::class);
			if (is_object($controller) && method_exists($controller, 'remove')) {
				$controller->remove($order_id);
			}
		} catch (Throwable $ignored) {
			unset($ignored);
		}
	}

	/**
	 * @return int[]
	 */
	private function order_item_ids(int $order_id): array
	{
		global $wpdb;
		if (!$wpdb instanceof wpdb || $order_id < 1) {
			return array();
		}
		$ids = $wpdb->get_col($wpdb->prepare(
			"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d",
			$order_id
		));
		if (!is_array($ids)) {
			return array();
		}
		return array_map('intval', $ids);
	}
}
