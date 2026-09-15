<?php
defined('ABSPATH') || exit;

/**
 * WordPress-compatible dependency checks. Never fatals on a missing host plugin.
 */
final class TPFWLI_Dependencies
{
	/**
	 * @return string[] Human-readable problems. Empty means ready.
	 */
	public static function problems(bool $require_runtime_hooks = true): array
	{
		$problems = array();

		if (!class_exists('WooCommerce')) {
			$problems[] = __('WooCommerce is not active.', 'tickets-passes-legacy-importer');
		}

		if (!defined('TPFW_VERSION') || !class_exists('TPFW_Ticket_WC_Product') || !class_exists('TPFW_Product_Ticket')) {
			$problems[] = __('Tickets & Passes for WooCommerce is not active.', 'tickets-passes-legacy-importer');
		} elseif (version_compare((string) TPFW_VERSION, TPFWLI_MIN_TPFW, '<')) {
			$problems[] = sprintf(
				/* translators: 1: required TPFW version, 2: installed version */
				__('Tickets & Passes for WooCommerce %1$s or newer is required (found %2$s).', 'tickets-passes-legacy-importer'),
				TPFWLI_MIN_TPFW,
				TPFW_VERSION
			);
		} else {
			$settings = get_option('tpfw_general_settings_options', array());
			if (empty($settings['bEnableTicketProduct']) || (int) $settings['bEnableTicketProduct'] !== 1) {
				$problems[] = __('The TPFW Ticket product type is disabled. Enable it under Tickets & Passes settings.', 'tickets-passes-legacy-importer');
			}
		}

		if ($require_runtime_hooks && class_exists('WooCommerce') && class_exists('TPFW_Ticket_WC_Product')) {
			$adapter = new TPFWLI_Tpfw_Adapter();
			if (!$adapter->find_runtime()) {
				$problems[] = $adapter->last_error() !== ''
					? $adapter->last_error()
					: __('The TPFW ticket issuer is not registered on WooCommerce order hooks.', 'tickets-passes-legacy-importer');
			}
		}

		return $problems;
	}

	public static function ready(bool $require_runtime_hooks = true): bool
	{
		return self::problems($require_runtime_hooks) === array();
	}
}
