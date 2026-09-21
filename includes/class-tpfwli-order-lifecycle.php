<?php
defined('ABSPATH') || exit;

/**
 * Shared resume policy for Confirm, Retry ticket issue, Retry email, and Finish issued import.
 *
 * Terminal WooCommerce statuses and any refund are refused. Only an explicit
 * allow-list may continue. Unknown or custom statuses are not treated as resumable.
 */
final class TPFWLI_Order_Lifecycle
{
	public const ALLOWED_STATUSES = array('pending', 'on-hold', 'processing', 'completed');
	public const TERMINAL_STATUSES = array('cancelled', 'failed', 'refunded', 'trash');

	/**
	 * @return array{ok:bool,allowed:bool,error:string,status:string,has_refund:bool}
	 */
	public static function assess(WC_Order $order): array
	{
		$status     = self::status($order);
		$has_refund = self::has_refund($order);

		if ($has_refund) {
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
			'ok'         => true,
			'allowed'    => true,
			'error'      => '',
			'status'     => $status,
			'has_refund' => false,
		);
	}

	public static function status(WC_Order $order): string
	{
		return $order->get_status();
	}

	public static function has_refund(WC_Order $order): bool
	{
		$refunds = $order->get_refunds();
		if (is_array($refunds) && $refunds !== array()) {
			return true;
		}
		return (float) $order->get_total_refunded() > 0;
	}

	/**
	 * @return array{ok:bool,allowed:bool,error:string,status:string,has_refund:bool}
	 */
	private static function denied(string $status, bool $has_refund, string $error): array
	{
		return array(
			'ok'         => false,
			'allowed'    => false,
			'error'      => $error,
			'status'     => $status,
			'has_refund' => $has_refund,
		);
	}
}
