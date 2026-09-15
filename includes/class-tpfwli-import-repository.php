<?php
defined('ABSPATH') || exit;

/**
 * HPOS-safe lookup of importer orders via wc_get_orders().
 *
 * Identity is the import UUID. Meta `_tpfwli_import_id` is canonical once present.
 * `created_via` is the first-INSERT fallback so a crash after the order row exists
 * but before meta is written cannot produce an unidentifiable orphan.
 */
final class TPFWLI_Import_Repository
{
	private string $last_error = '';

	public function last_error(): string
	{
		return $this->last_error;
	}

	/**
	 * @return array{ok:bool,order:?WC_Order,error:string}
	 */
	public function find_by_import_id(string $import_id): array
	{
		$this->last_error = '';
		$import_id = trim($import_id);
		if ($import_id === '') {
			return array('ok' => true, 'order' => null, 'error' => '');
		}

		$found = array();
		foreach ($this->query_by_meta($import_id) as $order) {
			$found[(int) $order->get_id()] = $order;
		}
		foreach ($this->query_by_created_via($import_id) as $order) {
			$found[(int) $order->get_id()] = $order;
		}

		if (count($found) > 1) {
			$ids = implode(', ', array_map(static function ($id) {
				return '#' . $id;
			}, array_keys($found)));
			$this->last_error = sprintf(
				/* translators: %s: comma-separated order IDs */
				__('Import ID matched more than one WooCommerce order (%s). Refusing to continue.', 'tickets-passes-legacy-importer'),
				$ids
			);
			return array('ok' => false, 'order' => null, 'error' => $this->last_error);
		}

		if ($found === array()) {
			return array('ok' => true, 'order' => null, 'error' => '');
		}

		return array('ok' => true, 'order' => array_values($found)[0], 'error' => '');
	}

	/**
	 * @return WC_Order[]
	 */
	public function list_imports(int $page = 1, int $per_page = 20): array
	{
		$page     = max(1, $page);
		$per_page = max(1, min(100, $per_page));

		$orders = wc_get_orders(array(
			'limit'      => $per_page,
			'page'       => $page,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => 'any',
			'meta_key'   => TPFWLI_Plugin::META_IMPORT,
			'meta_value' => 'yes',
			'return'     => 'objects',
		));

		return is_array($orders) ? $orders : array();
	}

	/**
	 * @return WC_Order[]
	 */
	private function query_by_meta(string $import_id): array
	{
		$orders = wc_get_orders(array(
			'limit'      => 2,
			'status'     => 'any',
			'meta_key'   => TPFWLI_Plugin::META_IMPORT_ID,
			'meta_value' => $import_id,
			'return'     => 'objects',
		));
		return $this->as_orders($orders);
	}

	/**
	 * @return WC_Order[]
	 */
	private function query_by_created_via(string $import_id): array
	{
		$orders = wc_get_orders(array(
			'limit'       => 2,
			'status'      => 'any',
			'created_via' => TPFWLI_Plugin::created_via($import_id),
			'return'      => 'objects',
		));
		return $this->as_orders($orders);
	}

	/**
	 * @param mixed $orders
	 * @return WC_Order[]
	 */
	private function as_orders($orders): array
	{
		$out = array();
		if (!is_array($orders)) {
			return $out;
		}
		foreach ($orders as $order) {
			if ($order instanceof WC_Order) {
				$out[] = $order;
			}
		}
		return $out;
	}
}
