<?php
defined('ABSPATH') || exit;

/**
 * HPOS-safe lookup of importer orders via wc_get_orders().
 *
 * Identity is the import UUID. Meta `_tpfwli_import_id` is canonical once present.
 * `created_via` is an extra lookup/audit key for committed orders. Crash safety
 * for first-time create is the MySQL transaction in TPFWLI_Order_Service, not
 * the assumption that created_via lands in the first HPOS INSERT.
 *
 * WooCommerce 11.1 OrdersTableQuery::run_query() uses $wpdb->get_col() and treats
 * a SQL failure as an empty ID list. This repository therefore captures the
 * identity query's own error before any follow-up lookup can overwrite it.
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

		$meta = $this->query_by_meta($import_id);
		if (!$meta['ok']) {
			$this->last_error = $meta['error'];
			return array('ok' => false, 'order' => null, 'error' => $this->last_error);
		}

		$via = $this->query_by_created_via($import_id);
		if (!$via['ok']) {
			$this->last_error = $via['error'];
			return array('ok' => false, 'order' => null, 'error' => $this->last_error);
		}

		$found = array();
		foreach ($meta['orders'] as $order) {
			$found[(int) $order->get_id()] = $order;
		}
		foreach ($via['orders'] as $order) {
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
	 * @return array{ok:bool,orders:WC_Order[],error:string}
	 */
	private function query_by_meta(string $import_id): array
	{
		return $this->query_identity_orders(
			array(
				'limit'      => 2,
				'status'     => 'any',
				'meta_key'   => TPFWLI_Plugin::META_IMPORT_ID,
				'meta_value' => $import_id,
				'return'     => 'objects',
			),
			__('Could not read existing importer orders by import ID. Import was stopped before any changes.', 'tickets-passes-legacy-importer')
		);
	}

	/**
	 * @return array{ok:bool,orders:WC_Order[],error:string}
	 */
	private function query_by_created_via(string $import_id): array
	{
		return $this->query_identity_orders(
			array(
				'limit'       => 2,
				'status'      => 'any',
				'created_via' => TPFWLI_Plugin::created_via($import_id),
				'return'      => 'objects',
			),
			__('Could not read existing importer orders by created_via. Import was stopped before any changes.', 'tickets-passes-legacy-importer')
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array{ok:bool,orders:WC_Order[],error:string}
	 */
	private function query_identity_orders(array $args, string $read_error): array
	{
		global $wpdb;

		$previous_suppress = null;
		$previous_show     = null;
		if ($wpdb instanceof wpdb) {
			$previous_suppress = $wpdb->suppress_errors(true);
			$previous_show     = $wpdb->show_errors(false);
		}

		$sql_error = '';
		$orders    = null;
		try {
			$orders = wc_get_orders($args);
			if ($wpdb instanceof wpdb) {
				$sql_error = (string) $wpdb->last_error;
			}
		} catch (Throwable $e) {
			$sql_error = $e->getMessage();
			$orders    = null;
		} finally {
			if ($wpdb instanceof wpdb) {
				$wpdb->suppress_errors((bool) $previous_suppress);
				$wpdb->show_errors((bool) $previous_show);
			}
		}

		if ($sql_error !== '') {
			return array('ok' => false, 'orders' => array(), 'error' => $read_error);
		}

		if ($orders instanceof WP_Error) {
			return array('ok' => false, 'orders' => array(), 'error' => $read_error);
		}

		if (!is_array($orders)) {
			return array('ok' => false, 'orders' => array(), 'error' => $read_error);
		}

		$out = array();
		foreach ($orders as $order) {
			if (!$order instanceof WC_Order) {
				return array('ok' => false, 'orders' => array(), 'error' => $read_error);
			}
			$out[] = $order;
		}

		return array('ok' => true, 'orders' => $out, 'error' => '');
	}
}
