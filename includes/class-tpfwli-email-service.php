<?php
defined('ABSPATH') || exit;

/**
 * Blocks automatic WooCommerce emails for legacy imports, then sends the
 * customer completed-order email after verified issue.
 *
 * Create/complete customer notifications stay blocked even after email_stage=sent,
 * so a queued status email cannot send in a later process. The explicit send
 * window is limited to one order and customer_completed_order.
 */
final class TPFWLI_Email_Service
{
	private static ?self $instance = null;

	private int $allow_order_id = 0;

	private string $allow_email_id = '';

	private bool $filters_hooked = false;

	public const COMPLETED_EMAIL_ID = 'customer_completed_order';

	/**
	 * Later shop events that may still email an imported order.
	 */
	private const LATER_EMAIL_IDS = array(
		'customer_refunded_order',
		'customer_cancelled_order',
		'customer_failed_order',
		'cancelled_order',
		'failed_order',
		'customer_note',
	);

	public static function instance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void
	{
		add_action('init', array($this, 'hook_email_enabled_filters'), 20);
		add_action('wp_loaded', array($this, 'hook_email_enabled_filters'), 20);
	}

	public function hook_email_enabled_filters(): void
	{
		if ($this->filters_hooked) {
			return;
		}
		if (!function_exists('WC') || !WC()->mailer()) {
			return;
		}
		foreach (WC()->mailer()->get_emails() as $email) {
			if (!is_object($email) || empty($email->id)) {
				continue;
			}
			add_filter('woocommerce_email_enabled_' . $email->id, array($this, 'filter_enabled'), 10, 3);
		}
		$this->filters_hooked = true;
	}

	/**
	 * @param bool          $enabled
	 * @param mixed         $object
	 * @param WC_Email|null $email
	 */
	public function filter_enabled($enabled, $object, $email = null): bool
	{
		if (!$object instanceof WC_Order) {
			return (bool) $enabled;
		}

		$identified_import = $this->is_importer_order($object);
		$order             = wc_get_order($object->get_id());
		if (!$order instanceof WC_Order) {
			return $identified_import ? false : (bool) $enabled;
		}
		if (!$this->is_importer_order($order)) {
			return $identified_import ? false : (bool) $enabled;
		}

		$email_id = (is_object($email) && isset($email->id)) ? (string) $email->id : '';
		$order_id = (int) $order->get_id();

		if (
			$this->allow_order_id > 0
			&& $order_id === $this->allow_order_id
			&& $this->allow_email_id !== ''
			&& $email_id === $this->allow_email_id
		) {
			return true;
		}

		if (
			$this->is_manual_order_details()
			&& current_user_can('manage_woocommerce')
			&& (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) === 'issued'
		) {
			return (bool) $enabled;
		}

		if ($email_id !== '' && in_array($email_id, self::LATER_EMAIL_IDS, true)) {
			return (bool) $enabled;
		}

		return false;
	}

	/**
	 * @return array{ok:bool,error:string,sent:bool,unknown:bool}
	 */
	public function send_completed_email(WC_Order $order): array
	{
		$order = wc_get_order($order->get_id());
		if (!$order instanceof WC_Order) {
			return array(
				'ok'      => false,
				'error'   => __('The importer order no longer exists.', 'tickets-passes-legacy-importer'),
				'sent'    => false,
				'unknown' => false,
			);
		}

		$recipient = $order->get_billing_email();
		if (!is_email($recipient)) {
			return array(
				'ok'      => false,
				'error'   => __('Billing email is missing; cannot send.', 'tickets-passes-legacy-importer'),
				'sent'    => false,
				'unknown' => false,
			);
		}

		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		if (empty($emails['WC_Email_Customer_Completed_Order']) || !is_object($emails['WC_Email_Customer_Completed_Order'])) {
			return array(
				'ok'      => false,
				'error'   => __('WooCommerce completed-order email class is not available.', 'tickets-passes-legacy-importer'),
				'sent'    => false,
				'unknown' => false,
			);
		}

		$expected_id = (int) $order->get_id();
		$sent        = null;
		$disabled    = false;
		$on_sent     = static function ($return, $id, $email_obj) use (&$sent, $expected_id) {
			if (!is_object($email_obj) || (string) ($email_obj->id ?? '') !== self::COMPLETED_EMAIL_ID) {
				return;
			}
			$obj = isset($email_obj->object) && $email_obj->object instanceof WC_Order
				? $email_obj->object
				: null;
			if (!$obj instanceof WC_Order || (int) $obj->get_id() !== $expected_id) {
				return;
			}
			$sent = (bool) $return;
		};
		$on_disabled = static function ($id) use (&$disabled) {
			if ($id === self::COMPLETED_EMAIL_ID) {
				$disabled = true;
			}
		};

		add_action('woocommerce_email_sent', $on_sent, 10, 3);
		add_action('woocommerce_email_disabled', $on_disabled, 10, 2);

		$this->allow_order_id  = $expected_id;
		$this->allow_email_id  = self::COMPLETED_EMAIL_ID;
		try {
			$emails['WC_Email_Customer_Completed_Order']->trigger($expected_id, $order);
		} finally {
			$this->allow_order_id = 0;
			$this->allow_email_id = '';
			remove_action('woocommerce_email_sent', $on_sent, 10);
			remove_action('woocommerce_email_disabled', $on_disabled, 10);
		}

		if ($sent === true) {
			return array('ok' => true, 'error' => '', 'sent' => true, 'unknown' => false);
		}
		if ($disabled && $sent === null) {
			return array(
				'ok'      => false,
				'error'   => __('The completed-order email is disabled in WooCommerce.', 'tickets-passes-legacy-importer'),
				'sent'    => false,
				'unknown' => false,
			);
		}
		if ($sent === false) {
			return array(
				'ok'      => false,
				'error'   => __('The mailer returned failure for the completed-order email.', 'tickets-passes-legacy-importer'),
				'sent'    => false,
				'unknown' => false,
			);
		}

		return array(
			'ok'      => false,
			'error'   => __('The mailer outcome could not be determined.', 'tickets-passes-legacy-importer'),
			'sent'    => false,
			'unknown' => true,
		);
	}

	private function is_importer_order(WC_Order $order): bool
	{
		if ((string) $order->get_meta(TPFWLI_Plugin::META_IMPORT) === 'yes') {
			return true;
		}
		if ((string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID) !== '') {
			return true;
		}
		$via = (string) $order->get_created_via();
		return str_starts_with($via, TPFWLI_Plugin::CREATED_VIA_PREFIX . ':');
	}

	private function is_manual_order_details(): bool
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check of WooCommerce admin resend action.
		return isset($_POST['wc_order_action'])
			&& sanitize_text_field(wp_unslash((string) $_POST['wc_order_action'])) === 'send_order_details';
	}
}
