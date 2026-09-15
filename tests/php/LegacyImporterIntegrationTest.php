<?php
use PHPUnit\Framework\TestCase;

/**
 * Automated checks against the local WordPress / WooCommerce / TPFW 1.3.0 stack.
 *
 * @group live
 */
final class LegacyImporterIntegrationTest extends TestCase
{
	private static int $ticket_id = 0;
	private static int $relative_id = 0;
	private static int $simple_id = 0;
	private static int $admin_id = 0;
	private static int $subscriber_id = 0;
	private static array $mail = array();
	private static bool $mail_fail = false;

	public static function setUpBeforeClass(): void
	{
		self::$admin_id = self::ensure_user('tpfwli_admin', 'administrator');
		self::$subscriber_id = self::ensure_user('tpfwli_subscriber', 'subscriber');
		wp_set_current_user(self::$admin_id);

		self::$ticket_id = self::create_ticket_product(array(
			'name'       => 'TPFWLI Festival Ticket 2026',
			'stock'      => 10,
			'duration'   => 172800,
			'start'      => '2026-10-10',
			'fixed'      => true,
			'user_start' => false,
		));
		self::$relative_id = self::create_ticket_product(array(
			'name'       => 'TPFWLI Relative Ticket',
			'stock'      => 10,
			'duration'   => 172800,
			'start'      => '',
			'fixed'      => false,
			'user_start' => false,
		));
		$simple = new WC_Product_Simple();
		$simple->set_name('TPFWLI Plain Product');
		$simple->set_regular_price('10');
		$simple->set_manage_stock(true);
		$simple->set_stock_quantity(10);
		$simple->set_status('publish');
		self::$simple_id = $simple->save();

		add_filter('pre_wp_mail', array(self::class, 'intercept_mail'), 10, 2);
	}

	public static function intercept_mail($short, $atts)
	{
		self::$mail[] = $atts;
		if (self::$mail_fail) {
			return false;
		}
		return true;
	}

	protected function setUp(): void
	{
		self::$mail = array();
		self::$mail_fail = false;
		wp_set_current_user(self::$admin_id);
	}

	public function test_preview_does_not_mutate_order_stock_tickets_or_mail(): void
	{
		$before_orders = $this->count_import_orders();
		$before_stock  = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$before_tickets = $this->count_tickets();
		$before_mail = count(self::$mail);

		$input = $this->valid_input();
		$result = (new TPFWLI_Orchestrator())->preview($input);

		$this->assertTrue($result['ok'], implode('; ', $result['errors']));
		$this->assertSame($before_orders, $this->count_import_orders());
		$this->assertSame($before_stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($before_tickets, $this->count_tickets());
		$this->assertCount($before_mail, self::$mail);
	}

	public function test_missing_nonce_is_refused(): void
	{
		$_POST = $this->valid_input();
		$_POST['action'] = 'tpfwli_confirm';
		unset($_POST['_wpnonce'], $_REQUEST['_wpnonce']);
		$this->expectWpDie();
		(new TPFWLI_Admin_Page())->handle_confirm();
	}

	public function test_user_without_manage_woocommerce_is_refused(): void
	{
		wp_set_current_user(self::$subscriber_id);
		$_POST = $this->valid_input();
		$_POST['action'] = 'tpfwli_confirm';
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce('tpfwli_confirm');
		$this->expectWpDie();
		(new TPFWLI_Admin_Page())->handle_confirm();
	}

	public function test_invalid_email_is_refused(): void
	{
		$input = $this->valid_input();
		$input['email'] = 'not-an-email';
		$result = (new TPFWLI_Orchestrator())->preview($input);
		$this->assertFalse($result['ok']);
		$this->assertNotEmpty($result['errors']);
	}

	public function test_zero_and_negative_quantity_are_refused(): void
	{
		foreach (array('0', '-2') as $qty) {
			$input = $this->valid_input();
			$input['quantity'] = $qty;
			$result = (new TPFWLI_Orchestrator())->preview($input);
			$this->assertFalse($result['ok'], 'quantity ' . $qty . ' should fail');
		}
	}

	public function test_non_tpfw_product_is_refused(): void
	{
		$input = $this->valid_input();
		$input['product_id'] = self::$simple_id;
		$result = (new TPFWLI_Orchestrator())->preview($input);
		$this->assertFalse($result['ok']);
	}

	public function test_relative_validity_product_is_refused(): void
	{
		$input = $this->valid_input();
		$input['product_id'] = self::$relative_id;
		$result = (new TPFWLI_Orchestrator())->preview($input);
		$this->assertFalse($result['ok']);
	}

	public function test_insufficient_stock_is_refused(): void
	{
		$input = $this->valid_input();
		$input['quantity'] = '999';
		$result = (new TPFWLI_Orchestrator())->preview($input);
		$this->assertFalse($result['ok']);
	}

	public function test_guest_order_billing_line_import_id_and_idempotency(): void
	{
		$product = wc_get_product(self::$ticket_id);
		$start_stock = (int) $product->get_stock_quantity();
		$input = $this->valid_input(array('quantity' => '2'));

		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame(0, (int) $order->get_customer_id());
		$this->assertSame('Anna', $order->get_billing_first_name());
		$this->assertSame('Andersson', $order->get_billing_last_name());
		$this->assertSame('anna@example.com', $order->get_billing_email());
		$this->assertNotSame('', $order->get_billing_phone());
		$items = array_values($order->get_items('line_item'));
		$this->assertCount(1, $items);
		$this->assertSame(self::$ticket_id, (int) $items[0]->get_product_id());
		$this->assertSame(2, (int) $items[0]->get_quantity());
		$this->assertSame($input['import_id'], (string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID));
		$this->assertSame('yes', (string) $order->get_meta(TPFWLI_Plugin::META_IMPORT));
		$this->assertSame('reduced', (string) $order->get_meta(TPFWLI_Plugin::META_STOCK_STAGE));
		$this->assertSame('issued', (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$this->assertSame('sent', (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertCount(2, $first['nanos']);
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertNotEmpty(self::$mail);
		$body = (string) (self::$mail[0]['message'] ?? '');
		$this->assertStringContainsString($first['nanos'][0], $body);
		$this->assertStringContainsString($first['nanos'][1], $body);

		$order_count_before = $this->count_import_orders();
		$mail_before = count(self::$mail);
		$second = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($second['ok'] || $second['email_already'], implode('; ', $second['errors']));
		$this->assertSame((int) $order->get_id(), (int) $second['order']->get_id());
		$this->assertSame($order_count_before, $this->count_import_orders());
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($first['nanos'], $second['nanos']);
		$this->assertSame($mail_before, count(self::$mail), 'already-sent import must not send a second normal email');

		$issue_again = (new TPFWLI_Orchestrator())->run($input, 'retry_issue');
		$this->assertSame($first['nanos'], $issue_again['nanos']);
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
	}

	public function test_issue_failure_does_not_send_email_and_email_failure_keeps_tickets(): void
	{
		$input = $this->valid_input(array(
			'email'    => 'bengt@example.com',
			'quantity' => '1',
		));
		self::$mail_fail = true;
		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($result['ok']);
		$order = $result['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame('issued', (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$this->assertSame('failed', (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertCount(1, $result['nanos']);
		$stock_after = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();

		self::$mail_fail = false;
		self::$mail = array();
		$retry = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertSame('sent', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertSame($result['nanos'], $retry['nanos']);
		$this->assertSame($stock_after, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertCount(1, self::$mail);

		self::$mail = array();
		$again = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
		$this->assertTrue($again['email_already'] || $again['ok']);
		$this->assertCount(0, self::$mail);
	}

	public function test_unknown_email_outcome_does_not_autoresend(): void
	{
		$input = $this->valid_input(array(
			'email' => 'cecilia@example.com',
			'first_name' => 'Cecilia',
		));
		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($result['ok'], implode('; ', $result['errors']));
		$order = $result['order'];
		$order->update_meta_data(TPFWLI_Plugin::META_EMAIL_STAGE, 'sending');
		$order->save();

		self::$mail = array();
		$retry = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
		$this->assertTrue($retry['email_unknown']);
		$this->assertCount(0, self::$mail);
	}

	public function test_plugin_deactivation_does_not_touch_orders_or_tickets(): void
	{
		$input = $this->valid_input(array(
			'email' => 'keep@example.com',
			'first_name' => 'Keep',
		));
		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($result['ok'], implode('; ', $result['errors']));
		$order_id = (int) $result['order']->get_id();
		$nanos = $result['nanos'];
		$stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();

		$plugin = plugin_basename(TPFWLI_PLUGIN_FILE);
		if (function_exists('deactivate_plugins')) {
			deactivate_plugins($plugin, true);
		}
		$still = wc_get_order($order_id);
		$this->assertInstanceOf(WC_Order::class, $still);
		$this->assertSame('yes', $still->get_meta(TPFWLI_Plugin::META_IMPORT));
		$this->assertSame($stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame(count($nanos), $this->count_tickets_for_order($order_id));
		if (function_exists('activate_plugin')) {
			activate_plugin($plugin);
		}
	}

	/**
	 * @param array<string,mixed> $override
	 * @return array<string,mixed>
	 */
	private function valid_input(array $override = array()): array
	{
		return array_merge(array(
			'product_id' => self::$ticket_id,
			'first_name' => 'Anna',
			'last_name'  => 'Andersson',
			'email'      => 'anna@example.com',
			'phone'      => '0701234567',
			'quantity'   => '2',
			'import_id'  => wp_generate_uuid4(),
		), $override);
	}

	private function count_import_orders(): int
	{
		$orders = wc_get_orders(array(
			'limit'      => -1,
			'status'     => 'any',
			'meta_key'   => TPFWLI_Plugin::META_IMPORT,
			'meta_value' => 'yes',
			'return'     => 'ids',
		));
		return is_array($orders) ? count($orders) : 0;
	}

	private function count_tickets(): int
	{
		global $wpdb;
		return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'tpfw_tickets WHERE deleted IS NULL');
	}

	private function count_tickets_for_order(int $order_id): int
	{
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE order_id = %d AND deleted IS NULL',
			$wpdb->prefix . 'tpfw_tickets',
			$order_id
		));
	}

	private function expectWpDie(): void
	{
		add_filter('wp_die_handler', static function () {
			return static function ($message) {
				throw new RuntimeException('wp_die:' . wp_strip_all_tags((string) $message));
			};
		});
		$this->expectException(RuntimeException::class);
	}

	private static function ensure_user(string $login, string $role): int
	{
		$user = get_user_by('login', $login);
		if ($user) {
			$user->set_role($role);
			return (int) $user->ID;
		}
		$id = wp_create_user($login, wp_generate_password(20), $login . '@woocommerce.local');
		if (is_wp_error($id)) {
			throw new RuntimeException($id->get_error_message());
		}
		$user = get_user_by('id', $id);
		$user->set_role($role);
		return (int) $id;
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private static function create_ticket_product(array $args): int
	{
		$product = new TPFW_Product_Ticket();
		$product->set_name($args['name']);
		$product->set_status('publish');
		$product->set_catalog_visibility('hidden');
		$product->set_regular_price('50');
		$product->set_price('50');
		$product->set_virtual(true);
		$product->set_manage_stock(true);
		$product->set_stock_quantity((int) $args['stock']);
		$product->set_stock_status('instock');
		$product->update_meta_data('_tpfw_ticket_max_uses', 1);
		$product->update_meta_data('_tpfw_ticket_valid_duration', (int) $args['duration']);
		$product->update_meta_data('_tpfw_ticket_predefined_start_date_enable', !empty($args['fixed']) ? 'yes' : 'no');
		if (!empty($args['start'])) {
			$product->update_meta_data('_tpfw_ticket_predefined_start_date', $args['start']);
		}
		$product->update_meta_data('_tpfw_ticket_user_start_date_enable', !empty($args['user_start']) ? 'yes' : 'no');
		return $product->save();
	}
}
