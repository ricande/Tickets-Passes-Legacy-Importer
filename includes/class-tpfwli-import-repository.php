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
 * a SQL failure as an empty ID list. wc_get_orders then runs woocommerce_order_query
 * (and order hydration) before returning, which can overwrite $wpdb->last_error.
 * Identity errors are therefore captured at the identity SQL itself, before any
 * follow-up query runs.
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
	 * @return array{ok:bool,orders:WC_Order[],total:int,pages:int,page:int,error:string}
	 */
	public function list_imports(int $page = 1, int $per_page = 50): array
	{
		$this->last_error = '';
		$page             = max(1, $page);
		$per_page         = max(1, min(50, $per_page));
		$empty            = array(
			'ok'     => true,
			'orders' => array(),
			'total'  => 0,
			'pages'  => 0,
			'page'   => $page,
			'error'  => '',
		);

		$queried = $this->query_import_list_page($page, $per_page);
		if (!$queried['ok']) {
			$this->last_error = $queried['error'];
			return array(
				'ok'     => false,
				'orders' => array(),
				'total'  => 0,
				'pages'  => 0,
				'page'   => $page,
				'error'  => $this->last_error,
			);
		}

		$out = array();
		foreach ($queried['ids'] as $id) {
			$order = wc_get_order((int) $id);
			if (!$order instanceof WC_Order) {
				$this->last_error = __('Could not read the legacy import list.', 'tickets-passes-legacy-importer');
				return array(
					'ok'     => false,
					'orders' => array(),
					'total'  => 0,
					'pages'  => 0,
					'page'   => $page,
					'error'  => $this->last_error,
				);
			}
			$out[] = $order;
		}

		$empty['ok']     = true;
		$empty['orders'] = $out;
		$empty['total']  = $queried['total'];
		$empty['pages']  = $queried['pages'];
		return $empty;
	}

	/**
	 * @return array{ok:bool,ids:int[],total:int,pages:int,error:string}
	 */
	private function query_import_list_page(int $page, int $per_page): array
	{
		$read_error = __('Could not read the legacy import list.', 'tickets-passes-legacy-importer');
		$args       = array(
			'limit'      => $per_page,
			'page'       => $page,
			'paginate'   => true,
			'orderby'    => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'status'     => 'any',
			'meta_key'   => TPFWLI_Plugin::META_IMPORT,
			'meta_value' => 'yes',
			'return'     => 'ids',
		);

		global $wpdb;
		$previous_suppress = null;
		$previous_show     = null;
		$awaiting          = false;
		$seen              = false;
		$sql_error         = '';
		$exception_error   = '';
		$watcher           = null;
		$result            = null;

		$capture = static function () use (&$awaiting, &$seen, &$sql_error): void {
			global $wpdb;
			if (!$awaiting) {
				return;
			}
			$awaiting = false;
			$seen     = true;
			if ($sql_error !== '') {
				return;
			}
			if ($wpdb instanceof wpdb) {
				$sql_error = (string) $wpdb->last_error;
			}
		};

		if ($wpdb instanceof wpdb) {
			$previous_suppress = $wpdb->suppress_errors(true);
			$previous_show     = $wpdb->show_errors(false);
			$watcher = static function ($sql) use (&$awaiting, &$seen, &$sql_error, $capture) {
				$capture();
				if ($sql_error !== '' || $seen) {
					return $sql;
				}
				if (is_string($sql) && self::sql_looks_like_import_list_query($sql)) {
					$awaiting = true;
				}
				return $sql;
			};
			add_filter('query', $watcher, 1);
		}

		try {
			$result = wc_get_orders($args);
			$capture();
		} catch (Throwable $e) {
			$capture();
			if ($sql_error === '') {
				$exception_error = $e->getMessage();
			}
			$result = null;
		} finally {
			if (is_callable($watcher)) {
				remove_filter('query', $watcher, 1);
			}
			if ($wpdb instanceof wpdb) {
				$wpdb->suppress_errors((bool) $previous_suppress);
				$wpdb->show_errors((bool) $previous_show);
			}
		}

		if ($sql_error !== '' || $exception_error !== '') {
			return array('ok' => false, 'ids' => array(), 'total' => 0, 'pages' => 0, 'error' => $read_error);
		}
		if ($result instanceof WP_Error || !is_object($result)) {
			return array('ok' => false, 'ids' => array(), 'total' => 0, 'pages' => 0, 'error' => $read_error);
		}

		$ids = array();
		foreach ((array) ($result->orders ?? array()) as $id) {
			if (!is_numeric($id) || (int) $id < 1) {
				return array('ok' => false, 'ids' => array(), 'total' => 0, 'pages' => 0, 'error' => $read_error);
			}
			$ids[] = (int) $id;
		}

		$total = isset($result->total) ? (int) $result->total : 0;
		$pages = isset($result->max_num_pages) ? (int) $result->max_num_pages : 0;
		if ($total < 0 || $pages < 0) {
			return array('ok' => false, 'ids' => array(), 'total' => 0, 'pages' => 0, 'error' => $read_error);
		}

		return array(
			'ok'    => true,
			'ids'   => $ids,
			'total' => $total,
			'pages' => $pages,
			'error' => '',
		);
	}

	private static function sql_looks_like_import_list_query(string $sql): bool
	{
		if (str_contains($sql, TPFWLI_Plugin::META_IMPORT_ID)) {
			return false;
		}
		return str_contains($sql, TPFWLI_Plugin::META_IMPORT);
	}

	/**
	 * @return array{ok:bool,orders:WC_Order[],error:string}
	 */
	private function query_by_meta(string $import_id): array
	{
		return $this->query_identity_orders_including_hidden(
			array(
				'limit'      => 2,
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
		return $this->query_identity_orders_including_hidden(
			array(
				'limit'       => 2,
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
	private function query_identity_orders_including_hidden(array $args, string $read_error): array
	{
		$args['status'] = 'all';
		$args['return'] = 'ids';
		$args['limit']  = max(2, (int) ($args['limit'] ?? 2));
		return $this->query_identity_orders($args, $read_error);
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
		$awaiting          = false;
		$identity_seen     = false;
		$identity_error    = '';
		$exception_error   = '';
		$watcher           = null;
		$orders            = null;

		$capture_identity_outcome = static function () use (&$awaiting, &$identity_seen, &$identity_error): void {
			global $wpdb;
			if (!$awaiting) {
				return;
			}
			$awaiting      = false;
			$identity_seen = true;
			if ($identity_error !== '') {
				return;
			}
			if ($wpdb instanceof wpdb) {
				$identity_error = (string) $wpdb->last_error;
			}
		};

		if ($wpdb instanceof wpdb) {
			$previous_suppress = $wpdb->suppress_errors(true);
			$previous_show     = $wpdb->show_errors(false);
			$watcher = static function ($sql) use ($args, &$awaiting, &$identity_seen, &$identity_error, $capture_identity_outcome) {
				$capture_identity_outcome();
				if ($identity_error !== '' || $identity_seen) {
					return $sql;
				}
				if (is_string($sql) && self::sql_looks_like_identity_query($sql, $args)) {
					$awaiting = true;
				}
				return $sql;
			};
			add_filter('query', $watcher, 1);
		}

		try {
			$orders = wc_get_orders($args);
			$capture_identity_outcome();
		} catch (Throwable $e) {
			$capture_identity_outcome();
			if ($identity_error === '') {
				$exception_error = $e->getMessage();
			}
			$orders = null;
		} finally {
			if (is_callable($watcher)) {
				remove_filter('query', $watcher, 1);
			}
			if ($wpdb instanceof wpdb) {
				$wpdb->suppress_errors((bool) $previous_suppress);
				$wpdb->show_errors((bool) $previous_show);
			}
		}

		if ($identity_error !== '' || $exception_error !== '') {
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
			if (is_numeric($order)) {
				$loaded = wc_get_order((int) $order);
				if (!$loaded instanceof WC_Order) {
					return array('ok' => false, 'orders' => array(), 'error' => $read_error);
				}
				$out[] = $loaded;
				continue;
			}
			if (!$order instanceof WC_Order) {
				return array('ok' => false, 'orders' => array(), 'error' => $read_error);
			}
			$out[] = $order;
		}

		return array('ok' => true, 'orders' => $out, 'error' => '');
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private static function sql_looks_like_identity_query(string $sql, array $args): bool
	{
		if (isset($args['meta_key'], $args['meta_value'])) {
			$key   = (string) $args['meta_key'];
			$value = (string) $args['meta_value'];
			return $key !== '' && $value !== '' && str_contains($sql, $key) && str_contains($sql, $value);
		}
		if (isset($args['created_via'])) {
			$via = (string) $args['created_via'];
			return $via !== '' && str_contains($sql, $via);
		}
		return false;
	}
}
