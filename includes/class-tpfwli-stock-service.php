<?php
defined('ABSPATH') || exit;

/**
 * WooCommerce stock is the source of truth. Never writes _stock or _order_stock_reduced.
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

	/**
	 * @return array{ok:bool,error:string,current_stock:?int,reduced_qty:int}
	 */
	public function reduce_if_needed(WC_Order $order, WC_Product $product, int $quantity): array
	{
		if ($this->is_reduced($order)) {
			$fresh = wc_get_product($product->get_id());
			return array(
				'ok'            => true,
				'error'         => '',
				'current_stock' => $fresh ? $fresh->get_stock_quantity() : null,
				'reduced_qty'   => $this->reduced_qty($order),
			);
		}

		$fresh = wc_get_product($product->get_id());
		if (!$fresh || !$fresh->managing_stock()) {
			return array(
				'ok'            => false,
				'error'         => __('Stock is no longer managed on this product.', 'tickets-passes-legacy-importer'),
				'current_stock' => null,
				'reduced_qty'   => 0,
			);
		}
		if (!$fresh->backorders_allowed() && !$fresh->has_enough_stock($quantity)) {
			return array(
				'ok'            => false,
				'error'         => __('Insufficient stock at confirm time. Nothing was reduced.', 'tickets-passes-legacy-importer'),
				'current_stock' => $fresh->get_stock_quantity(),
				'reduced_qty'   => 0,
			);
		}

		wc_maybe_reduce_stock_levels($order->get_id());

		$order = wc_get_order($order->get_id());
		if (!$order || !$this->is_reduced($order)) {
			return array(
				'ok'            => false,
				'error'         => __('WooCommerce did not mark this order as stock-reduced.', 'tickets-passes-legacy-importer'),
				'current_stock' => wc_get_product($product->get_id())?->get_stock_quantity(),
				'reduced_qty'   => 0,
			);
		}

		$after = wc_get_product($product->get_id());
		$stock = $after ? $after->get_stock_quantity() : null;
		if ($stock !== null && (int) $stock < 0 && !$after->backorders_allowed()) {
			wc_maybe_increase_stock_levels($order->get_id());
			return array(
				'ok'            => false,
				'error'         => __('Stock would have gone negative (likely a concurrent online sale). This order’s reduction was restored via WooCommerce. No tickets or email.', 'tickets-passes-legacy-importer'),
				'current_stock' => wc_get_product($product->get_id())?->get_stock_quantity(),
				'reduced_qty'   => 0,
			);
		}

		return array(
			'ok'            => true,
			'error'         => '',
			'current_stock' => $stock,
			'reduced_qty'   => $this->reduced_qty($order),
		);
	}

	public function reduced_qty(WC_Order $order): int
	{
		$qty = 0;
		foreach ($order->get_items('line_item') as $item) {
			$qty += (int) $item->get_meta('_reduced_stock', true);
		}
		return $qty;
	}
}
