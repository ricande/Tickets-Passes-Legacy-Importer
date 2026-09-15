<?php
defined('ABSPATH') || exit;

/**
 * HPOS-safe lookup of importer orders via wc_get_orders().
 */
final class TPFWLI_Import_Repository
{
	/**
	 * @return WC_Order|null
	 */
	public function find_by_import_id(string $import_id)
	{
		$import_id = trim($import_id);
		if ($import_id === '') {
			return null;
		}

		$orders = wc_get_orders(array(
			'limit'      => 1,
			'status'     => 'any',
			'meta_key'   => TPFWLI_Plugin::META_IMPORT_ID,
			'meta_value' => $import_id,
			'return'     => 'objects',
		));

		if (!empty($orders) && $orders[0] instanceof WC_Order) {
			return $orders[0];
		}

		return null;
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
}
