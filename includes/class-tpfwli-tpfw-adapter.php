<?php
defined('ABSPATH') || exit;

/**
 * Talks to the already-registered TPFW ticket issuer. Never constructs a second
 * TPFW_Ticket_WC_Product (that would duplicate WooCommerce hooks).
 */
final class TPFWLI_Tpfw_Adapter
{
	private string $last_error = '';

	/** @var TPFW_Ticket_WC_Product|null */
	private $runtime = null;

	public function last_error(): string
	{
		return $this->last_error;
	}

	/**
	 * Locate the live TPFW_Ticket_WC_Product instance from the hook registry.
	 *
	 * @return TPFW_Ticket_WC_Product|null
	 */
	public function find_runtime()
	{
		if ($this->runtime instanceof TPFW_Ticket_WC_Product) {
			return $this->runtime;
		}

		if (!class_exists('TPFW_Ticket_WC_Product')) {
			$this->last_error = __('TPFW_Ticket_WC_Product is not loaded.', 'tickets-passes-legacy-importer');
			return null;
		}

		$from_completed = $this->instance_from_hook('woocommerce_order_status_completed', 'order_status_completed');
		$from_payment   = $this->instance_from_hook('woocommerce_payment_complete', 'order_payment_complete');

		if (!$from_completed instanceof TPFW_Ticket_WC_Product) {
			return null;
		}
		if (!$from_payment instanceof TPFW_Ticket_WC_Product) {
			$this->last_error = __('Could not find the TPFW ticket issuer on woocommerce_payment_complete.', 'tickets-passes-legacy-importer');
			return null;
		}
		if (spl_object_id($from_completed) !== spl_object_id($from_payment)) {
			$this->last_error = __('TPFW ticket issuer callbacks on payment_complete and order_status_completed are not the same instance.', 'tickets-passes-legacy-importer');
			return null;
		}

		$this->runtime = $from_completed;
		$this->last_error = '';
		return $this->runtime;
	}

	/**
	 * Invoke TPFW's public force-issue path. Idempotent per order line.
	 */
	public function force_issue(int $order_id): bool
	{
		$runtime = $this->find_runtime();
		if (!$runtime) {
			return false;
		}
		$runtime->order_force_issue($order_id);
		return true;
	}

	/**
	 * @return array{ok:bool,errors:string[],product:?WC_Product,meta:array<string,mixed>}
	 */
	public function validate_ticket_product(int $product_id, int $quantity, bool $require_available_stock = true): array
	{
		$errors = array();
		$product = wc_get_product($product_id);

		if (!$product) {
			return array(
				'ok'      => false,
				'errors'  => array(__('The selected product no longer exists.', 'tickets-passes-legacy-importer')),
				'product' => null,
				'meta'    => array(),
			);
		}

		if ($product->get_type() !== 'tpfw-ticket' || !($product instanceof TPFW_Product_Ticket)) {
			$errors[] = __('Only a Tickets & Passes Ticket product (tpfw-ticket) can be imported.', 'tickets-passes-legacy-importer');
		}

		$settings = get_option('tpfw_general_settings_options', array());
		if (empty($settings['bEnableTicketProduct']) || (int) $settings['bEnableTicketProduct'] !== 1) {
			$errors[] = __('The TPFW Ticket product type is disabled.', 'tickets-passes-legacy-importer');
		}

		$start_enable = (string) $product->get_meta('_tpfw_ticket_predefined_start_date_enable', true);
		$start_date   = (string) $product->get_meta('_tpfw_ticket_predefined_start_date', true);
		$user_start   = (string) $product->get_meta('_tpfw_ticket_user_start_date_enable', true);
		$duration     = (int) $product->get_meta('_tpfw_ticket_valid_duration', true);
		$max_uses     = (int) $product->get_meta('_tpfw_ticket_max_uses', true);

		if ($user_start === 'yes') {
			$errors[] = __('This importer V1 does not support customer-selected start dates. Use a Ticket product with a fixed event start date.', 'tickets-passes-legacy-importer');
		}
		if ($start_enable !== 'yes') {
			$errors[] = __('This importer V1 requires a fixed event window. Enable the predefined start date on the Ticket product.', 'tickets-passes-legacy-importer');
		}
		if ($start_enable === 'yes' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
			$errors[] = __('The Ticket product predefined start date must be a valid Y-m-d date.', 'tickets-passes-legacy-importer');
		}
		if ($duration <= 0) {
			$errors[] = __('The Ticket product is missing a valid duration (seconds).', 'tickets-passes-legacy-importer');
		}
		if ($max_uses <= 0) {
			$errors[] = __('The Ticket product is missing max uses.', 'tickets-passes-legacy-importer');
		}

		$valid_from = '';
		$valid_to   = '';
		if ($start_enable === 'yes' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && $duration > 0) {
			$valid_from = gmdate('Y-m-d H:i:s', strtotime($start_date));
			$valid_to   = gmdate('Y-m-d H:i:s', strtotime($start_date) + $duration);
		}

		$date_format = $this->tpfw_date_format();
		$display_from = ($start_date !== '') ? gmdate($date_format, strtotime($start_date)) : '';
		$display_to   = ($valid_to !== '') ? gmdate($date_format, strtotime($valid_to)) : '';

		$stock = null;
		$managing = false;
		$backorders = false;
		$enough = false;
		if ($product instanceof WC_Product) {
			$managing   = (bool) $product->managing_stock();
			$stock      = $product->get_stock_quantity();
			$backorders = (bool) $product->backorders_allowed();
			$enough     = $quantity > 0 ? (bool) $product->has_enough_stock($quantity) : false;
		}

		if ('yes' !== get_option('woocommerce_manage_stock')) {
			$errors[] = __('WooCommerce stock management is disabled for the store.', 'tickets-passes-legacy-importer');
		}
		if (!$managing) {
			$errors[] = __('This Ticket product does not manage stock. Enable stock management so legacy imports and online sales share one capacity.', 'tickets-passes-legacy-importer');
		}
		if ($require_available_stock && $managing && !$backorders && $quantity > 0 && !$enough) {
			$errors[] = sprintf(
				/* translators: 1: requested qty, 2: current stock */
				__('Not enough stock: requested %1$d, available %2$s. No order, tickets or email will be created.', 'tickets-passes-legacy-importer'),
				$quantity,
				(string) $stock
			);
		}

		$price = (float) $product->get_price();

		$meta = array(
			'name'            => $product->get_name(),
			'id'              => $product->get_id(),
			'type'            => $product->get_type(),
			'price'           => $price,
			'stock'           => $stock,
			'managing_stock'  => $managing,
			'backorders'      => $backorders,
			'max_uses'        => $max_uses,
			'duration'        => $duration,
			'valid_from'      => $valid_from,
			'valid_to'        => $valid_to,
			'display_from'    => $display_from,
			'display_to'      => $display_to,
			'start_date_raw'  => $start_date,
			'expected_stock'  => ($stock === null) ? null : ((int) $stock - $quantity),
			'order_total'     => $price * max(0, $quantity),
		);

		return array(
			'ok'      => $errors === array(),
			'errors'  => $errors,
			'product' => $product,
			'meta'    => $meta,
		);
	}

	/**
	 * Read-only verification that TPFW issued exactly $quantity live tickets.
	 *
	 * @return array{ok:bool,errors:string[],nanos:string[],count:int}
	 */
	public function verify_issue(WC_Order $order, int $quantity): array
	{
		$errors = array();
		$items  = array_values($order->get_items('line_item'));
		if (count($items) !== 1) {
			return array(
				'ok'     => false,
				'errors' => array(__('Expected exactly one order line.', 'tickets-passes-legacy-importer')),
				'nanos'  => array(),
				'count'  => 0,
			);
		}

		$item       = $items[0];
		$product_id = (int) $item->get_product_id();
		$line_id    = (int) $item->get_id();
		$order_id   = (int) $order->get_id();

		$product_check = $this->validate_ticket_product($product_id, $quantity, false);
		if (!$product_check['ok']) {
			return array(
				'ok'     => false,
				'errors' => $product_check['errors'],
				'nanos'  => array(),
				'count'  => 0,
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'tpfw_tickets';
		$rows  = $wpdb->get_results($wpdb->prepare(
			'SELECT nano_id, product_id, order_id, order_line_id, max_uses, valid_from, valid_to
			 FROM %i
			 WHERE product_id = %d AND order_id = %d AND order_line_id = %d AND deleted IS NULL
			 ORDER BY id ASC',
			$table,
			$product_id,
			$order_id,
			$line_id
		));

		$count = is_array($rows) ? count($rows) : 0;
		$nanos = array();
		foreach ((array) $rows as $row) {
			$nano = (string) $row->nano_id;
			if ($nano === '') {
				$errors[] = __('A ticket row has an empty nano ID.', 'tickets-passes-legacy-importer');
				continue;
			}
			$nanos[] = $nano;
			if ((int) $row->product_id !== $product_id || (int) $row->order_id !== $order_id || (int) $row->order_line_id !== $line_id) {
				$errors[] = __('A ticket row does not match the order line.', 'tickets-passes-legacy-importer');
			}
			if ((int) $row->max_uses !== (int) $product_check['meta']['max_uses']) {
				$errors[] = __('A ticket row max_uses does not match the product.', 'tickets-passes-legacy-importer');
			}
			if ((string) $row->valid_from !== (string) $product_check['meta']['valid_from']
				|| (string) $row->valid_to !== (string) $product_check['meta']['valid_to']) {
				$errors[] = __('A ticket validity window does not match the product fixed event dates.', 'tickets-passes-legacy-importer');
			}
		}

		if ($count !== $quantity) {
			$errors[] = sprintf(
				/* translators: 1: found, 2: expected */
				__('Issued ticket count is %1$d, expected %2$d.', 'tickets-passes-legacy-importer'),
				$count,
				$quantity
			);
		}

		if (count($nanos) !== count(array_unique($nanos))) {
			$errors[] = __('Issued nano IDs are not unique.', 'tickets-passes-legacy-importer');
		}

		for ($i = 1; $i <= $quantity; $i++) {
			$meta_nano = (string) $item->get_meta('tpfw_ticket_id_' . $i, true);
			if ($meta_nano === '') {
				$errors[] = sprintf(
					/* translators: %d: 1-based ticket index */
					__('Order line is missing tpfw_ticket_id_%d.', 'tickets-passes-legacy-importer'),
					$i
				);
			} elseif (!in_array($meta_nano, $nanos, true)) {
				$errors[] = sprintf(
					/* translators: %s: nano id */
					__('Order line nano ID %s does not match a live ticket row.', 'tickets-passes-legacy-importer'),
					$meta_nano
				);
			}
		}

		$slug = get_option('tpfw_upload_slug');
		if (!is_string($slug) || !preg_match('/^[a-f0-9]{10}$/', $slug)) {
			$errors[] = __('TPFW upload slug is missing; QR files cannot be verified.', 'tickets-passes-legacy-importer');
		} else {
			$uploads = wp_upload_dir();
			$qr_dir  = trailingslashit($uploads['basedir']) . 'tpfw-' . $slug . '/qr-codes/';
			foreach ($nanos as $nano) {
				$path = $qr_dir . $nano . '.webp';
				if (!is_readable($path)) {
					$errors[] = sprintf(
						/* translators: %s: nano id */
						__('QR file for ticket %s is missing.', 'tickets-passes-legacy-importer'),
						$nano
					);
				}
			}
		}

		return array(
			'ok'     => $errors === array(),
			'errors' => $errors,
			'nanos'  => $nanos,
			'count'  => $count,
		);
	}

	private function instance_from_hook(string $hook, string $method)
	{
		global $wp_filter;
		if (!isset($wp_filter[$hook])) {
			$this->last_error = sprintf(
				/* translators: %s: hook name */
				__('WordPress hook %s is not registered.', 'tickets-passes-legacy-importer'),
				$hook
			);
			return null;
		}

		$found = array();
		$callbacks = $wp_filter[$hook]->callbacks ?? array();
		foreach ($callbacks as $priority_group) {
			foreach ($priority_group as $callback) {
				$fn = $callback['function'] ?? null;
				if (!is_array($fn) || !is_object($fn[0]) || !isset($fn[1])) {
					continue;
				}
				if ($fn[1] !== $method) {
					continue;
				}
				if ($fn[0] instanceof TPFW_Ticket_WC_Product) {
					$found[spl_object_id($fn[0])] = $fn[0];
				}
			}
		}

		if (count($found) !== 1) {
			$this->last_error = sprintf(
				/* translators: 1: hook, 2: method, 3: count */
				__('Expected exactly one TPFW_Ticket_WC_Product::%2$s on %1$s (found %3$d).', 'tickets-passes-legacy-importer'),
				$hook,
				$method,
				count($found)
			);
			return null;
		}

		return array_values($found)[0];
	}

	private function tpfw_date_format(): string
	{
		$settings = get_option('tpfw_general_settings_options', array());
		$format   = 'Y-m-d';
		if (!empty($settings['sDateTimeformat']) && is_string($settings['sDateTimeformat'])) {
			$parts  = explode(' ', $settings['sDateTimeformat'], 2);
			$format = $parts[0] !== '' ? $parts[0] : 'Y-m-d';
		}
		return $format;
	}
}
