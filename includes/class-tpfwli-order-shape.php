<?php
defined('ABSPATH') || exit;

/**
 * Strict V1 importer order shape. Stock reduction is forbidden until this passes.
 *
 * The only automatic repair is a missing Ticket line on a still-bootstrapping
 * order that has not yet reduced stock.
 */
final class TPFWLI_Order_Shape
{
	private TPFWLI_Stock_Service $stock;

	public function __construct(?TPFWLI_Stock_Service $stock = null)
	{
		$this->stock = $stock ?: new TPFWLI_Stock_Service();
	}

	/**
	 * @return array{ok:bool,repaired:bool,error:string,order:?WC_Order}
	 */
	public function assert_or_repair(WC_Order $order, WC_Product $expected_product, int $expected_quantity): array
	{
		$order = wc_get_order($order->get_id());
		if (!$order instanceof WC_Order) {
			return $this->fail(__('The importer order no longer exists.', 'tickets-passes-legacy-importer'));
		}

		$expected_product_id = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID);
		$expected_qty_meta   = (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY);
		if ($expected_product_id < 1 || $expected_qty_meta < 1) {
			return $this->fail(
				__('This importer order is missing its locked product/quantity snapshot.', 'tickets-passes-legacy-importer'),
				$order
			);
		}
		if ($expected_product_id !== (int) $expected_product->get_id() || $expected_qty_meta !== $expected_quantity) {
			return $this->fail(
				__('Locked import snapshot does not match the product/quantity being processed.', 'tickets-passes-legacy-importer'),
				$order
			);
		}

		$items = array_values($order->get_items('line_item'));
		if ($items === array()) {
			return $this->repair_missing_line($order, $expected_product, $expected_quantity);
		}

		if (count($items) !== 1) {
			$note = __('Legacy import refused: order has extra or unexpected product lines. Stock, tickets and email were not changed.', 'tickets-passes-legacy-importer');
			$order->add_order_note($note);
			$order->save();
			return $this->fail($note, $order);
		}

		$item = $items[0];
		$line_product_id = (int) $item->get_product_id();
		$line_qty        = (int) $item->get_quantity();
		$line_product    = wc_get_product($line_product_id);

		if ($line_product_id !== $expected_product_id) {
			$note = __('Legacy import refused: order line product does not match the locked import product. Stock, tickets and email were not changed.', 'tickets-passes-legacy-importer');
			$order->add_order_note($note);
			$order->save();
			return $this->fail($note, $order);
		}
		if (!$line_product || $line_product->get_type() !== 'tpfw-ticket') {
			$note = __('Legacy import refused: order line is not a Tickets & Passes Ticket product. Stock, tickets and email were not changed.', 'tickets-passes-legacy-importer');
			$order->add_order_note($note);
			$order->save();
			return $this->fail($note, $order);
		}
		if ($line_qty !== $expected_quantity) {
			$note = __('Legacy import refused: order line quantity does not match the locked import quantity. Stock, tickets and email were not changed.', 'tickets-passes-legacy-importer');
			$order->add_order_note($note);
			$order->save();
			return $this->fail($note, $order);
		}

		if ((string) $order->get_meta(TPFWLI_Plugin::META_ORDER_STAGE) !== TPFWLI_Plugin::STAGE_READY) {
			$order->update_meta_data(TPFWLI_Plugin::META_ORDER_STAGE, TPFWLI_Plugin::STAGE_READY);
			$order->save();
		}

		return array(
			'ok'       => true,
			'repaired' => false,
			'error'    => '',
			'order'    => wc_get_order($order->get_id()),
		);
	}

	/**
	 * @return array{ok:bool,repaired:bool,error:string,order:?WC_Order}
	 */
	private function repair_missing_line(WC_Order $order, WC_Product $expected_product, int $expected_quantity): array
	{
		$stage = (string) $order->get_meta(TPFWLI_Plugin::META_ORDER_STAGE);
		if ($this->stock->is_reduced($order)) {
			$note = __('Legacy import refused: order has no Ticket line but WooCommerce already marked stock as reduced. Stock, tickets and email were not changed.', 'tickets-passes-legacy-importer');
			$order->add_order_note($note);
			$order->save();
			return $this->fail($note, $order);
		}
		if ($stage !== '' && $stage !== TPFWLI_Plugin::STAGE_BOOTSTRAPPING) {
			$note = __('Legacy import refused: order is not in bootstrap state and has no Ticket line. Stock, tickets and email were not changed.', 'tickets-passes-legacy-importer');
			$order->add_order_note($note);
			$order->save();
			return $this->fail($note, $order);
		}

		$item_id = $order->add_product($expected_product, $expected_quantity);
		if (!$item_id) {
			$note = __('Legacy import failed: could not add the Ticket product line.', 'tickets-passes-legacy-importer');
			$order->add_order_note($note);
			$order->save();
			return $this->fail($note, $order);
		}

		$item = $order->get_item($item_id);
		if ($item) {
			$item->update_meta_data('tpfw_firstname', $order->get_billing_first_name());
			$item->update_meta_data('tpfw_lastname', $order->get_billing_last_name());
			$from = (string) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_VALID_FROM);
			$to   = (string) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_VALID_TO);
			if ($from !== '') {
				$item->update_meta_data('tpfw_predefined_date', substr($from, 0, 10));
			}
			if ($to !== '') {
				$item->update_meta_data('tpfw_valid_to', substr($to, 0, 10));
			}
			$item->save();
		}

		$order->calculate_totals();
		$order->update_meta_data(TPFWLI_Plugin::META_ORDER_STAGE, TPFWLI_Plugin::STAGE_READY);
		$order->add_order_note(__('Imported historical ticket sale by Tickets & Passes – Legacy Ticket Importer.', 'tickets-passes-legacy-importer'));
		$order->save();

		return array(
			'ok'       => true,
			'repaired' => true,
			'error'    => '',
			'order'    => wc_get_order($order->get_id()),
		);
	}

	/**
	 * @return array{ok:bool,repaired:bool,error:string,order:?WC_Order}
	 */
	private function fail(string $error, $order = null): array
	{
		return array(
			'ok'       => false,
			'repaired' => false,
			'error'    => $error,
			'order'    => $order instanceof WC_Order ? $order : null,
		);
	}
}
