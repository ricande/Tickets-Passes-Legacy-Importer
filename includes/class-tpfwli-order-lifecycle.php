<?php
defined('ABSPATH') || exit;

/**
 * Shared resume policy for Confirm, Retry ticket issue, Retry email, and Finish issued import.
 *
 * Terminal WooCommerce statuses and any refund are refused. Only an explicit
 * allow-list may continue. Unknown or custom statuses are not treated as resumable.
 * A refund read error is not treated as “no refund”.
 */
final class TPFWLI_Order_Lifecycle
{
	public const ALLOWED_STATUSES = array('pending', 'on-hold', 'processing', 'completed');
	public const TERMINAL_STATUSES = array('cancelled', 'failed', 'refunded', 'trash');

	/**
	 * @return array{ok:bool,allowed:bool,error:string,status:string,has_refund:bool,refund_read_ok:bool}
	 */
	public static function assess(WC_Order $order): array
	{
		$status = self::status($order);
		$refund = self::refund_state($order);

		if (!$refund['ok']) {
			return self::denied(
				$status,
				false,
				$refund['error'],
				false
			);
		}

		if ($refund['has_refund']) {
			return self::denied(
				$status,
				true,
				__('This import has a refund and was stopped for manual handling. The importer will not change the order, stock, tickets or customer email.', 'tickets-passes-legacy-importer')
			);
		}

		if (in_array($status, self::TERMINAL_STATUSES, true)) {
			return self::denied(
				$status,
				false,
				sprintf(
					/* translators: %s: WooCommerce order status */
					__('This import cannot be resumed because the WooCommerce order is %s. The importer will not change the order, stock, tickets or customer email.', 'tickets-passes-legacy-importer'),
					$status
				)
			);
		}

		if (!in_array($status, self::ALLOWED_STATUSES, true)) {
			return self::denied(
				$status,
				false,
				sprintf(
					/* translators: %s: WooCommerce order status */
					__('This import cannot be resumed because the order status “%s” is not allowed. The importer will not change the order, stock, tickets or customer email.', 'tickets-passes-legacy-importer'),
					$status
				)
			);
		}

		return array(
			'ok'             => true,
			'allowed'        => true,
			'error'          => '',
			'status'         => $status,
			'has_refund'     => false,
			'refund_read_ok' => true,
		);
	}

	public static function status(WC_Order $order): string
	{
		return $order->get_status();
	}

	/**
	 * Verified refund presence. A read error is not “no refund”.
	 *
	 * @return array{ok:bool,has_refund:bool,error:string}
	 */
	public static function refund_state(WC_Order $order): array
	{
		$order_id = (int) $order->get_id();
		self::forget_refund_cache($order_id);

		$ids = self::query_refund_ids($order_id);
		if (!$ids['ok']) {
			return $ids;
		}
		if ($ids['has_refund']) {
			return array('ok' => true, 'has_refund' => true, 'error' => '');
		}

		$total = self::query_refund_total($order_id);
		if (!$total['ok']) {
			return $total;
		}
		return array(
			'ok'         => true,
			'has_refund' => $total['has_refund'],
			'error'      => '',
		);
	}

	/**
	 * @return array{ok:bool,allowed:bool,error:string,status:string,has_refund:bool,refund_read_ok:bool}
	 */
	private static function denied(string $status, bool $has_refund, string $error, bool $refund_read_ok = true): array
	{
		return array(
			'ok'             => false,
			'allowed'        => false,
			'error'          => $error,
			'status'         => $status,
			'has_refund'     => $has_refund,
			'refund_read_ok' => $refund_read_ok,
		);
	}

	private static function forget_refund_cache(int $order_id): void
	{
		if (!class_exists('WC_Cache_Helper') || $order_id < 1) {
			return;
		}
		$prefix = WC_Cache_Helper::get_cache_prefix('orders');
		wp_cache_delete($prefix . 'refund_ids' . $order_id, 'orders');
		wp_cache_delete($prefix . 'total_refunded' . $order_id, 'orders');
	}

	/**
	 * @return array{ok:bool,has_refund:bool,error:string}
	 */
	private static function query_refund_ids(int $order_id): array
	{
		$read_error = __('The importer could not read the refund status of this order. The importer will not change the order, stock, tickets or customer email.', 'tickets-passes-legacy-importer');
		$captured = self::capture_refund_sql(
			static function () use ($order_id) {
				return wc_get_orders(array(
					'type'   => 'shop_order_refund',
					'parent' => $order_id,
					'status' => 'all',
					'return' => 'ids',
					'limit'  => -1,
				));
			},
			$order_id,
			$read_error
		);
		if (!$captured['ok']) {
			return array('ok' => false, 'has_refund' => false, 'error' => $captured['error']);
		}
		$ids = $captured['value'];
		if ($ids instanceof WP_Error || !is_array($ids)) {
			return array('ok' => false, 'has_refund' => false, 'error' => $read_error);
		}
		foreach ($ids as $id) {
			if ((int) $id < 1) {
				return array('ok' => false, 'has_refund' => false, 'error' => $read_error);
			}
		}
		return array(
			'ok'         => true,
			'has_refund' => $ids !== array(),
			'error'      => '',
		);
	}

	/**
	 * @return array{ok:bool,has_refund:bool,error:string}
	 */
	private static function query_refund_total(int $order_id): array
	{
		$read_error = __('The importer could not read the refund status of this order. The importer will not change the order, stock, tickets or customer email.', 'tickets-passes-legacy-importer');
		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return array('ok' => false, 'has_refund' => false, 'error' => $read_error);
		}
		$store = $order->get_data_store();
		if (!$store || !is_callable(array($store, 'get_total_refunded'))) {
			return array('ok' => false, 'has_refund' => false, 'error' => $read_error);
		}
		$captured = self::capture_refund_sql(
			static function () use ($store, $order) {
				return $store->get_total_refunded($order);
			},
			$order_id,
			$read_error
		);
		if (!$captured['ok']) {
			return array('ok' => false, 'has_refund' => false, 'error' => $captured['error']);
		}
		if (!is_numeric($captured['value'])) {
			return array('ok' => false, 'has_refund' => false, 'error' => $read_error);
		}
		return array(
			'ok'         => true,
			'has_refund' => (float) $captured['value'] != 0.0,
			'error'      => '',
		);
	}

	/**
	 * @param callable():mixed $run
	 * @return array{ok:bool,error:string,value:mixed}
	 */
	private static function capture_refund_sql(callable $run, int $order_id, string $read_error): array
	{
		global $wpdb;

		$previous_suppress = null;
		$previous_show     = null;
		$awaiting          = false;
		$seen              = false;
		$sql_error         = '';
		$watcher           = null;
		$value             = null;

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
			$watcher = static function ($sql) use ($order_id, &$awaiting, &$seen, &$sql_error, $capture) {
				$capture();
				if ($sql_error !== '' || $seen) {
					return $sql;
				}
				if (is_string($sql) && self::sql_looks_like_refund_query($sql, $order_id)) {
					$awaiting = true;
				}
				return $sql;
			};
			add_filter('query', $watcher, 1);
		}

		try {
			$value = $run();
			$capture();
		} catch (Throwable $e) {
			$capture();
			if ($sql_error === '') {
				$sql_error = $e->getMessage();
			}
			$value = null;
		} finally {
			if (is_callable($watcher)) {
				remove_filter('query', $watcher, 1);
			}
			if ($wpdb instanceof wpdb) {
				$wpdb->suppress_errors((bool) $previous_suppress);
				$wpdb->show_errors((bool) $previous_show);
			}
		}

		if ($sql_error !== '') {
			return array('ok' => false, 'error' => $read_error, 'value' => null);
		}
		return array('ok' => true, 'error' => '', 'value' => $value);
	}

	private static function sql_looks_like_refund_query(string $sql, int $order_id): bool
	{
		if ($order_id < 1 || !str_contains($sql, (string) $order_id)) {
			return false;
		}
		return str_contains($sql, 'shop_order_refund')
			|| str_contains($sql, 'total_refunded')
			|| str_contains($sql, 'parent_order_id');
	}
}
