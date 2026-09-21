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

		if (function_exists('get_bloginfo')) {
			$wp_version = (string) get_bloginfo('version');
			if ($wp_version !== '' && version_compare($wp_version, TPFWLI_MIN_WP, '<')) {
				$problems[] = sprintf(
					/* translators: 1: required WordPress version, 2: installed version */
					__('WordPress %1$s or newer is required (found %2$s).', 'tickets-passes-legacy-importer'),
					TPFWLI_MIN_WP,
					$wp_version
				);
			}
		}

		if (!class_exists('WooCommerce')) {
			$problems[] = __('WooCommerce is not active.', 'tickets-passes-legacy-importer');
		} else {
			$wc_version = defined('WC_VERSION') ? (string) WC_VERSION : '';
			if ($wc_version !== '' && version_compare($wc_version, TPFWLI_MIN_WC, '<')) {
				$problems[] = sprintf(
					/* translators: 1: required WooCommerce version, 2: installed version */
					__('WooCommerce %1$s or newer is required (found %2$s).', 'tickets-passes-legacy-importer'),
					TPFWLI_MIN_WC,
					$wc_version
				);
			}
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

		if (class_exists('WooCommerce') && !self::hpos_enabled()) {
			$problems[] = __('Tickets & Passes – Legacy Ticket Importer requires WooCommerce HPOS to guarantee crash-safe import idempotency.', 'tickets-passes-legacy-importer');
		}

		return $problems;
	}

	/**
	 * True when WooCommerce is actually reading/writing orders via HPOS tables.
	 */
	public static function hpos_enabled(): bool
	{
		if (!class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)) {
			return false;
		}
		if (!method_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class, 'custom_orders_table_usage_is_enabled')) {
			return false;
		}
		return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	public static function ready(bool $require_runtime_hooks = true): bool
	{
		return self::problems($require_runtime_hooks) === array();
	}

	/**
	 * Tables first-time order bootstrap writes inside wc_transaction_query().
	 *
	 * @return string[]
	 */
	public static function bootstrap_storage_tables(): array
	{
		global $wpdb;

		$tables = array();
		if (class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class)) {
			$named = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_all_table_names_with_id();
			if (is_array($named)) {
				foreach ($named as $table) {
					if (is_string($table) && $table !== '') {
						$tables[] = $table;
					}
				}
			}
		}

		$tables[] = $wpdb->prefix . 'woocommerce_order_items';
		$tables[] = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// wc_create_order -> persist_order_to_db -> maybe_create_backup_post -> wp_insert_post
		// even when HPOS data sync is off (placeholder shop_order_placehold).
		$tables[] = $wpdb->posts;
		$tables[] = $wpdb->postmeta;

		// Order_Shape repair adds an order note via WC_Order::add_order_note -> wp_insert_comment.
		$tables[] = $wpdb->comments;
		$tables[] = $wpdb->commentmeta;

		$tables = array_values(array_unique(array_filter($tables)));
		return apply_filters('tpfwli_bootstrap_storage_tables', $tables);
	}

	/**
	 * Tables WooCommerce writes during importer stock reduction:
	 * product _stock / lookup, line _reduced_stock, order_stock_reduced, notes.
	 *
	 * @return string[]
	 */
	public static function stock_storage_tables(): array
	{
		global $wpdb;

		$tables = array(
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
			$wpdb->term_relationships,
			$wpdb->term_taxonomy,
			$wpdb->prefix . 'wc_product_meta_lookup',
			$wpdb->prefix . 'woocommerce_order_items',
			$wpdb->prefix . 'woocommerce_order_itemmeta',
		);

		if (class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class)) {
			$named = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_all_table_names_with_id();
			if (is_array($named)) {
				foreach ($named as $table) {
					if (is_string($table) && $table !== '') {
						$tables[] = $table;
					}
				}
			}
		}

		$tables = array_values(array_unique(array_filter($tables)));
		return apply_filters('tpfwli_stock_storage_tables', $tables);
	}

	public static function engine_is_transactional(string $engine): bool
	{
		return strtoupper(trim($engine)) === 'INNODB';
	}

	/**
	 * HPOS being on does not prove every bootstrap table can roll back.
	 * Engines are never changed automatically.
	 *
	 * @return string[]
	 */
	public static function transactional_storage_problems(): array
	{
		return self::engine_problems_for_tables(
			self::bootstrap_storage_tables(),
			__('Order storage table %s is missing, so first-time import cannot run inside a database transaction.', 'tickets-passes-legacy-importer'),
			__('Order storage table %1$s uses %2$s, which cannot roll back a crashed first-time import. InnoDB is required. The table engine was not changed.', 'tickets-passes-legacy-importer'),
			'tpfwli_transactional_storage_problems'
		);
	}

	/**
	 * Engine check for tables written during stock reduction, including retries.
	 * Engines are never changed automatically.
	 *
	 * @return string[]
	 */
	public static function transactional_stock_storage_problems(): array
	{
		return self::engine_problems_for_tables(
			self::stock_storage_tables(),
			__('Stock storage table %s is missing, so stock reduction cannot run inside a database transaction.', 'tickets-passes-legacy-importer'),
			__('Stock storage table %1$s uses %2$s, which cannot roll back a crashed stock reduction. InnoDB is required. The table engine was not changed.', 'tickets-passes-legacy-importer'),
			'tpfwli_transactional_stock_storage_problems'
		);
	}

	/**
	 * @param string[] $tables
	 * @return string[]
	 */
	private static function engine_problems_for_tables(array $tables, string $missing, string $wrong_engine, string $filter): array
	{
		global $wpdb;

		$problems = array();
		if (!$wpdb instanceof wpdb) {
			$problems[] = __('Could not inspect order-table storage engines before import.', 'tickets-passes-legacy-importer');
			return apply_filters($filter, $problems);
		}

		foreach ($tables as $table) {
			$engine = $wpdb->get_var($wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			));
			if (!is_string($engine) || $engine === '') {
				$problems[] = sprintf($missing, $table);
				continue;
			}
			if (!self::engine_is_transactional($engine)) {
				$problems[] = sprintf($wrong_engine, $table, $engine);
			}
		}

		return apply_filters($filter, $problems);
	}
}
