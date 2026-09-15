<?php
defined('ABSPATH') || exit;

/**
 * Blocks automatic WooCommerce emails for unfinished legacy imports, then sends
 * the customer completed-order email (billing address) after verified issue.
 */
final class TPFWLI_Email_Service
{
	private static ?self $instance = null;

	private int $allow_order_id = 0;

	public static function instance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void
	{
		add_action('wp_loaded', array($this, 'hook_email_enabled_filters'), 20);
	}

	public function hook_email_enabled_filters(): void
	{
		if (!function_exists('WC') || !WC()->mailer()) {
			return;
		}
		foreach (WC()->mailer()->get_emails() as $email) {
			if (!is_object($email) || empty($email->id)) {
				continue;
			}
			add_filter('woocommerce_email_enabled_' . $email->id, array($this, 'filter_enabled'), 10, 3);
		}
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
		if ($object->get_meta(TPFWLI_Plugin::META_IMPORT) !== 'yes') {
			return (bool) $enabled;
		}

		$order_id = (int) $object->get_id();
		if ($this->allow_order_id > 0 && $order_id === $this->allow_order_id) {
			return true;
		}

		$stage = (string) $object->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE);
		if ($stage === 'sent') {
			return (bool) $enabled;
		}

		if ($this->is_manual_order_details() && (string) $object->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE) === 'issued') {
			return (bool) $enabled;
		}

		return false;
	}

	/**
	 * @return array{ok:bool,error:string,sent:bool,unknown:bool}
	 */
	public function send_completed_email(WC_Order $order): array
	{
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

		$sent     = null;
		$disabled = false;
		$on_sent  = static function ($return, $id, $email_obj) use (&$sent) {
			if (is_object($email_obj) && isset($email_obj->id) && $email_obj->id === 'customer_completed_order') {
				$sent = (bool) $return;
			}
		};
		$on_disabled = static function ($id) use (&$disabled) {
			if ($id === 'customer_completed_order') {
				$disabled = true;
			}
		};

		add_action('woocommerce_email_sent', $on_sent, 10, 3);
		add_action('woocommerce_email_disabled', $on_disabled, 10, 2);

		$this->allow_order_id = (int) $order->get_id();
		try {
			$emails['WC_Email_Customer_Completed_Order']->trigger($order->get_id(), $order);
		} finally {
			$this->allow_order_id = 0;
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

	private function is_manual_order_details(): bool
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only check of WooCommerce admin resend action.
		return isset($_POST['wc_order_action'])
			&& sanitize_text_field(wp_unslash((string) $_POST['wc_order_action'])) === 'send_order_details';
	}
}
