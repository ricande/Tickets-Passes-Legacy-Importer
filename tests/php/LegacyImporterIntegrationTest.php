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
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$ticket = wc_get_product(self::$ticket_id);
		if ($ticket) {
			$ticket->set_stock_quantity(50);
			$ticket->set_stock_status('instock');
			$ticket->update_meta_data('_tpfw_ticket_max_uses', 1);
			$ticket->save();
		}
		$simple = wc_get_product(self::$simple_id);
		if ($simple) {
			$simple->set_stock_quantity(10);
			$simple->set_stock_status('instock');
			$simple->save();
		}
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

	public function test_get_with_valid_nonce_does_not_mutate(): void
	{
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST = $this->valid_input();
		$_POST['action'] = 'tpfwli_confirm';
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce('tpfwli_confirm');
		$before_orders = $this->count_import_orders();
		$before_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$this->installWpDieThrower();
		try {
			(new TPFWLI_Admin_Page())->handle_confirm();
			$this->fail('GET confirm should have been refused');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('wp_die:', $e->getMessage());
		}
		$this->assertSame($before_orders, $this->count_import_orders());
		$this->assertSame($before_stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
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
		$this->assertSame(TPFWLI_Plugin::created_via($input['import_id']), $order->get_created_via());
		$this->assertSame($input['import_id'], (string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID));
		$this->assertSame(self::$ticket_id, (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID));
		$this->assertSame(2, (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_QUANTITY));
		$this->assertSame(1, (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_MAX_USES));
		$this->assertNotSame('', (string) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_VALID_FROM));
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
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));
		$this->assertSame(0, $this->adminMailCount((string) $input['email']));

		$order_count_before = $this->count_import_orders();
		$second = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($second['ok'] || $second['email_already'], implode('; ', $second['errors']));
		$this->assertSame((int) $order->get_id(), (int) $second['order']->get_id());
		$this->assertSame($order_count_before, $this->count_import_orders());
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($first['nanos'], $second['nanos']);
		$this->assertSame(1, $this->customerMailCount((string) $input['email']), 'already-sent import must not send a second customer email');

		$issue_again = (new TPFWLI_Orchestrator())->run($input, 'retry_issue');
		$this->assertSame($first['nanos'], $issue_again['nanos']);
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
	}

	public function test_actual_issue_failure_does_not_send_email_and_retry_does_not_reduce_stock_again(): void
	{
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$input = $this->valid_input(array(
			'email'      => 'issue-fail@example.com',
			'first_name' => 'IssueFail',
			'quantity'   => '2',
		));
		$blocker = static function () {
			return false;
		};
		add_filter('tpfwli_allow_force_issue', $blocker);
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		remove_filter('tpfwli_allow_force_issue', $blocker);

		$this->assertFalse($first['ok']);
		$order = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame((int) $order->get_id(), (int) (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id'])['order']->get_id());
		$this->assertTrue((new TPFWLI_Stock_Service())->is_reduced($order));
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame('failed', (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$this->assertSame('not_sent', (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertCount(0, self::$mail);
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));

		$retry = (new TPFWLI_Orchestrator())->run($input, 'retry_issue');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertSame((int) $order->get_id(), (int) $retry['order']->get_id());
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertCount(2, $retry['nanos']);
		$this->assertSame(2, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame('sent', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));
		$this->assertSame(0, $this->adminMailCount((string) $input['email']));
	}

	public function test_bootstrap_crash_after_order_id_rolls_back_and_retry_creates_exactly_one_order(): void
	{
		$this->assertBootstrapCrashThenRetry('after_order_id');
	}

	public function test_bootstrap_crash_after_meta_rolls_back_and_retry_creates_exactly_one_order(): void
	{
		$this->assertBootstrapCrashThenRetry('after_meta');
	}

	public function test_bootstrap_crash_after_line_rolls_back_and_retry_creates_exactly_one_order(): void
	{
		$this->assertBootstrapCrashThenRetry('after_line');
	}

	public function test_incomplete_bootstrap_created_via_is_resumed_as_one_order(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'bootstrap@example.com',
			'first_name' => 'Bootstrap',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$orphan = new WC_Order();
		$orphan->set_status('pending');
		$orphan->set_customer_id(0);
		$orphan->set_created_via(TPFWLI_Plugin::created_via($input['import_id']));
		$orphan->save();
		$orphan_id = (int) $orphan->get_id();
		$this->assertSame('', (string) $orphan->get_meta(TPFWLI_Plugin::META_IMPORT_ID));

		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($result['ok'], implode('; ', $result['errors']));
		$this->assertSame($orphan_id, (int) $result['order']->get_id());
		$this->assertSame($input['import_id'], (string) $result['order']->get_meta(TPFWLI_Plugin::META_IMPORT_ID));
		$this->assertCount(1, $result['order']->get_items('line_item'));
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertCount(2, $result['nanos']);
	}

	public function test_missing_line_is_repaired_then_stock_reduced_once(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'missing-line@example.com',
			'first_name' => 'MissingLine',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$order = $this->bootstrap_identity($input);
		$this->assertCount(0, $order->get_items('line_item'));
		$this->assertFalse((new TPFWLI_Stock_Service())->is_reduced($order));

		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($result['ok'], implode('; ', $result['errors']));
		$this->assertSame((int) $order->get_id(), (int) $result['order']->get_id());
		$items = array_values($result['order']->get_items('line_item'));
		$this->assertCount(1, $items);
		$this->assertSame(self::$ticket_id, (int) $items[0]->get_product_id());
		$this->assertSame(2, (int) $items[0]->get_quantity());
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertCount(2, $result['nanos']);
	}

	public function test_missing_line_does_not_mark_stock_reduced_when_reduction_is_refused(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'no-false-stock@example.com',
			'first_name' => 'NoFalseStock',
		));
		$order = $this->bootstrap_identity($input);
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$stock = new TPFWLI_Stock_Service();
		$out = $stock->reduce_if_needed($order, wc_get_product(self::$ticket_id), 2);
		$this->assertFalse($out['ok']);
		$this->assertFalse($stock->is_reduced(wc_get_order($order->get_id())));
		$this->assertSame($start_stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame('not reduced', $stock->accounted_label(wc_get_order($order->get_id())));
	}

	public function test_wrong_quantity_fails_closed_before_stock(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'wrong-qty@example.com',
			'first_name' => 'WrongQty',
			'quantity'   => '2',
		));
		$order = $this->bootstrap_identity($input);
		$order->add_product(wc_get_product(self::$ticket_id), 3);
		$order->save();
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$mail_before = count(self::$mail);

		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($result['ok']);
		$this->assertSame($start_stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertCount($mail_before, self::$mail);
		$this->assertNotSame('reduced', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_STOCK_STAGE));
	}

	public function test_wrong_product_fails_closed_before_stock(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'wrong-product@example.com',
			'first_name' => 'WrongProduct',
			'quantity'   => '2',
		));
		$order = $this->bootstrap_identity($input);
		$order->add_product(wc_get_product(self::$simple_id), 2);
		$order->save();
		$ticket_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$simple_stock = (int) wc_get_product(self::$simple_id)->get_stock_quantity();

		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($result['ok']);
		$this->assertSame($ticket_stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($simple_stock, (int) wc_get_product(self::$simple_id)->get_stock_quantity());
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertCount(0, self::$mail);
	}

	public function test_extra_line_fails_closed_before_stock(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'extra-line@example.com',
			'first_name' => 'ExtraLine',
			'quantity'   => '2',
		));
		$order = $this->bootstrap_identity($input);
		$shape = (new TPFWLI_Order_Shape())->assert_or_repair($order, wc_get_product(self::$ticket_id), 2);
		$this->assertTrue($shape['ok'], $shape['error']);
		$order = $shape['order'];
		$order->add_product(wc_get_product(self::$simple_id), 1);
		$order->save();
		$ticket_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$simple_stock = (int) wc_get_product(self::$simple_id)->get_stock_quantity();
		$mail_before = count(self::$mail);

		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($result['ok']);
		$this->assertSame($ticket_stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($simple_stock, (int) wc_get_product(self::$simple_id)->get_stock_quantity());
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertCount($mail_before, self::$mail);
	}

	public function test_resume_ignores_tampered_product_quantity_and_billing(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'locked@example.com',
			'first_name' => 'Locked',
			'quantity'   => '2',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$tampered = $input;
		$tampered['quantity'] = '9';
		$tampered['product_id'] = self::$simple_id;
		$tampered['email'] = 'attacker@example.com';
		$second = (new TPFWLI_Orchestrator())->run($tampered, 'confirm');
		$order = wc_get_order($first['order']->get_id());
		$this->assertSame((int) $first['order']->get_id(), (int) $second['order']->get_id());
		$items = array_values($order->get_items('line_item'));
		$this->assertCount(1, $items);
		$this->assertSame(2, (int) $items[0]->get_quantity());
		$this->assertSame(self::$ticket_id, (int) $items[0]->get_product_id());
		$this->assertSame('locked@example.com', $order->get_billing_email());
		$this->assertSame($first['nanos'], $second['nanos']);
	}

	public function test_persistent_result_reconstructs_nanos_and_real_stock_without_transient(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'persistent@example.com',
			'first_name' => 'Persistent',
			'quantity'   => '2',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$inspect = (new TPFWLI_Orchestrator())->inspect($first['order']);
		$this->assertSame($first['nanos'], $inspect['nanos']);
		$this->assertSame('2', $inspect['stock_label']);
		$this->assertTrue($inspect['stock_reduced']);
		$this->assertSame(2, $inspect['stock_qty']);
		$this->assertSame('sent', $inspect['email_stage']);
		$this->assertTrue($inspect['verify_ok']);
	}

	public function test_product_config_drift_before_issue_fails_closed(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'drift-before@example.com',
			'first_name' => 'DriftBefore',
			'quantity'   => '2',
		));
		$order = $this->bootstrap_identity($input);
		$product = wc_get_product(self::$ticket_id);
		$product->update_meta_data('_tpfw_ticket_max_uses', 9);
		$product->save();
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();

		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertSame($start_stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
			$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
			$this->assertCount(0, self::$mail);
		} finally {
			$product = wc_get_product(self::$ticket_id);
			$product->update_meta_data('_tpfw_ticket_max_uses', 1);
			$product->save();
		}
	}

	public function test_product_config_drift_after_issue_does_not_block_email_retry(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'drift-after@example.com',
			'first_name' => 'DriftAfter',
			'quantity'   => '1',
		));
		self::$mail_fail = true;
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		self::$mail_fail = false;
		$this->assertFalse($first['ok']);
		$this->assertSame('issued', (string) $first['order']->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$stock_after_issue = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$product = wc_get_product(self::$ticket_id);
		$product->update_meta_data('_tpfw_ticket_max_uses', 9);
		$product->update_meta_data('_tpfw_ticket_predefined_start_date_enable', 'no');
		$product->save();
		self::$mail = array();
		try {
			$retry = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
			$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
			$this->assertSame($first['nanos'], $retry['nanos']);
			$this->assertSame(1, $this->customerMailCount((string) $input['email']));
			$this->assertSame(0, $this->adminMailCount((string) $input['email']));
			$this->assertSame($stock_after_issue, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		} finally {
			$product = wc_get_product(self::$ticket_id);
			$product->update_meta_data('_tpfw_ticket_max_uses', 1);
			$product->update_meta_data('_tpfw_ticket_predefined_start_date_enable', 'yes');
			$product->save();
		}
	}

	public function test_product_config_drift_after_stock_blocks_issue_retry(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'drift-retry-issue@example.com',
			'first_name' => 'DriftRetryIssue',
			'quantity'   => '2',
		));
		$blocker = static function () {
			return false;
		};
		add_filter('tpfwli_allow_force_issue', $blocker);
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		remove_filter('tpfwli_allow_force_issue', $blocker);
		$this->assertFalse($first['ok']);
		$order = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertTrue((new TPFWLI_Stock_Service())->is_reduced($order));
		$stock_after = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame('not_sent', (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));

		$product = wc_get_product(self::$ticket_id);
		$product->update_meta_data('_tpfw_ticket_max_uses', 9);
		$product->save();
		self::$mail = array();
		try {
			$retry = (new TPFWLI_Orchestrator())->run($input, 'retry_issue');
			$this->assertFalse($retry['ok']);
			$this->assertSame($stock_after, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
			$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
			$this->assertCount(0, self::$mail);
			$this->assertNotSame('issued', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		} finally {
			$product = wc_get_product(self::$ticket_id);
			$product->update_meta_data('_tpfw_ticket_max_uses', 1);
			$product->save();
		}
	}

	public function test_invalid_predefined_date_is_rejected(): void
	{
		$bad_id = self::create_ticket_product(array(
			'name'       => 'TPFWLI Invalid Date Ticket',
			'stock'      => 5,
			'duration'   => 86400,
			'start'      => '2026-02-31',
			'fixed'      => true,
			'user_start' => false,
		));
		$input = $this->valid_input(array(
			'product_id' => $bad_id,
			'email'      => 'bad-date@example.com',
		));
		$result = (new TPFWLI_Orchestrator())->preview($input);
		$this->assertFalse($result['ok']);
		$this->assertTrue((new TPFWLI_Tpfw_Adapter())->is_strict_ymd('2026-10-10'));
		$this->assertFalse((new TPFWLI_Tpfw_Adapter())->is_strict_ymd('2026-02-31'));
	}

	public function test_activation_hook_and_runtime_dependency_checks_exist(): void
	{
		$this->assertTrue(function_exists('tpfwli_activate'));
		$this->assertTrue(TPFWLI_Dependencies::hpos_enabled());
		$this->assertSame(array(), TPFWLI_Dependencies::problems(false));
		$this->assertSame(array(), TPFWLI_Dependencies::problems(true));
		$this->assertSame(array(), TPFWLI_Dependencies::transactional_storage_problems());
		$this->assertNotFalse(has_action('activate_' . plugin_basename(TPFWLI_PLUGIN_FILE)));
		$this->assertSame(tpfwli_test_checkout_plugin_file(), tpfwli_test_normalize_path(TPFWLI_PLUGIN_FILE));
		$this->assertTrue(tpfwli_test_path_is_in_checkout((string) tpfwli_test_class_file('TPFWLI_Plugin')));
	}

	public function test_concurrent_confirm_same_uuid_creates_one_order(): void
	{
		if (!function_exists('proc_open')) {
			$this->markTestSkipped('proc_open is not available');
		}
		$worker = dirname(__DIR__) . '/bin/confirm-worker.php';
		if (!is_readable($worker)) {
			$this->markTestSkipped('confirm worker is missing');
		}

		$input = $this->valid_input(array(
			'email'      => 'concurrent@example.com',
			'first_name' => 'Concurrent',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$payload = wp_json_encode($input);
		$cmd = array(PHP_BINARY, $worker);
		$spec = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);

		$p1 = proc_open($cmd, $spec, $pipes1);
		$p2 = proc_open($cmd, $spec, $pipes2);
		if (!is_resource($p1) || !is_resource($p2)) {
			$this->markTestSkipped('Could not start concurrent PHP workers');
		}

		fwrite($pipes1[0], $payload);
		fwrite($pipes2[0], $payload);
		fclose($pipes1[0]);
		fclose($pipes2[0]);
		$out1 = stream_get_contents($pipes1[1]);
		$out2 = stream_get_contents($pipes2[1]);
		$err1 = stream_get_contents($pipes1[2]);
		$err2 = stream_get_contents($pipes2[2]);
		fclose($pipes1[1]);
		fclose($pipes1[2]);
		fclose($pipes2[1]);
		fclose($pipes2[2]);
		proc_close($p1);
		proc_close($p2);

		$a = json_decode((string) $out1, true);
		$b = json_decode((string) $out2, true);
		$this->assertIsArray($a, 'worker1: ' . $out1 . ' ' . $err1);
		$this->assertIsArray($b, 'worker2: ' . $out2 . ' ' . $err2);
		$expected_plugin = tpfwli_test_checkout_plugin_file();
		$this->assertSame($expected_plugin, $a['loaded_plugin_file'] ?? '');
		$this->assertSame($expected_plugin, $b['loaded_plugin_file'] ?? '');
		$this->assertGreaterThan(0, (int) $a['order_id']);
		$this->assertSame((int) $a['order_id'], (int) $b['order_id']);
		$order = wc_get_order((int) $a['order_id']);
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertCount(1, $order->get_items('line_item'));
		wp_cache_flush();
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame(2, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame('sent', (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
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
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));
		$this->assertSame(0, $this->adminMailCount((string) $input['email']));

		self::$mail = array();
		$again = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
		$this->assertTrue($again['email_already'] || $again['ok']);
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
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

	public function test_checkout_symlink_and_real_path_are_the_same_code(): void
	{
		$expected = tpfwli_test_checkout_plugin_file();
		$dir      = sys_get_temp_dir() . '/tpfwli-symlink-' . wp_generate_uuid4();
		$this->assertTrue(mkdir($dir, 0700, true));
		$link = $dir . '/tickets-passes-legacy-importer.php';
		$this->assertTrue(symlink($expected, $link));
		try {
			$this->assertTrue(tpfwli_test_same_checkout_path($expected, $link));
			$this->assertSame($expected, tpfwli_test_normalize_path($link));
			$this->assertTrue(tpfwli_test_path_is_in_checkout((string) tpfwli_test_class_file('TPFWLI_Plugin')));
		} finally {
			unlink($link);
			rmdir($dir);
		}
	}

	public function test_foreign_importer_already_loaded_aborts_with_expected_and_actual_paths(): void
	{
		$fake   = sys_get_temp_dir() . '/tpfwli-foreign-plugin-' . wp_generate_uuid4() . '.php';
		$script = sys_get_temp_dir() . '/tpfwli-foreign-assert-' . wp_generate_uuid4() . '.php';
		$helper = dirname(__DIR__) . '/lib/checkout-code.php';
		file_put_contents($fake, "<?php\nclass TPFWLI_Plugin {}\n");
		file_put_contents(
			$script,
			'<?php require ' . var_export($fake, true) . '; require ' . var_export($helper, true) . '; tpfwli_test_assert_checkout_code(true); fwrite(STDOUT, "LOADED\n");'
		);

		$spec  = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		$pipes = array();
		$proc  = proc_open(array(PHP_BINARY, $script), $spec, $pipes);
		$this->assertIsResource($proc);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		unlink($fake);
		unlink($script);

		$this->assertNotSame(0, $code);
		$this->assertStringNotContainsString('LOADED', (string) $stdout);
		$this->assertStringContainsString('Expected:', (string) $stderr);
		$this->assertStringContainsString('Actual:', (string) $stderr);
		$this->assertStringContainsString(tpfwli_test_checkout_plugin_file(), (string) $stderr);
		$this->assertStringContainsString($fake, (string) $stderr);
	}

	public function test_confirm_post_stops_when_hpos_disabled_after_preview(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'hpos-post@example.com',
			'first_name' => 'HposPost',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$_POST  = $input;
		$_POST['action'] = 'tpfwli_confirm';
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce('tpfwli_confirm');
		$this->installWpDieThrower();
		try {
			$this->withHposDisabled(function () use ($input): void {
				try {
					(new TPFWLI_Admin_Page())->handle_confirm();
					$this->fail('Confirm POST should have been refused after HPOS was turned off');
				} catch (RuntimeException $e) {
					$this->assertStringContainsString('wp_die:', $e->getMessage());
					$this->assertStringContainsString('HPOS', $e->getMessage());
				}
			});
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		} finally {
			unset($_POST, $_REQUEST['_wpnonce']);
		}
	}

	public function test_direct_confirm_stops_without_mutations_when_hpos_is_off(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'hpos-run@example.com',
			'first_name' => 'HposRun',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$this->withHposDisabled(function () use ($input): void {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNotEmpty($result['errors']);
			$this->assertStringContainsString('HPOS', implode(' ', $result['errors']));
			$this->assertNull($result['order']);
		});
		$this->assertBusinessUnchanged($before, (string) $input['email']);
	}

	public function test_retry_issue_and_email_stop_when_ticket_product_type_is_disabled(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'dep-retry@example.com',
			'first_name' => 'DepRetry',
			'quantity'   => '1',
		));
		self::$mail_fail = true;
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		self::$mail_fail = false;
		$this->assertFalse($first['ok']);
		$order = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame('issued', (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$tickets = $this->count_tickets_for_order((int) $order->get_id());
		$orders = $this->count_import_orders();
		self::$mail = array();

		$settings = get_option('tpfw_general_settings_options', array());
		$saved    = $settings;
		$settings['bEnableTicketProduct'] = 0;
		update_option('tpfw_general_settings_options', $settings);
		try {
			foreach (array('retry_issue', 'retry_email') as $mode) {
				$result = (new TPFWLI_Orchestrator())->run($input, $mode);
				$this->assertFalse($result['ok'], $mode . ' should fail when TPFW ticket type is disabled');
				$this->assertNotEmpty($result['errors']);
			}

			$_POST = $input;
			$_POST['action'] = 'tpfwli_retry_email';
			$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce('tpfwli_retry_email');
			$this->installWpDieThrower();
			try {
				(new TPFWLI_Admin_Page())->handle_retry_email();
				$this->fail('Retry email POST should have been refused');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString('wp_die:', $e->getMessage());
			}
		} finally {
			update_option('tpfw_general_settings_options', $saved);
			unset($_POST, $_REQUEST['_wpnonce']);
		}

		$fresh = wc_get_order($order->get_id());
		$this->assertSame($stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($tickets, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame($orders, $this->count_import_orders());
		$this->assertSame('issued', (string) $fresh->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$this->assertSame('failed', (string) $fresh->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_missing_issue_hooks_stop_confirm_without_mutations(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'no-hooks@example.com',
			'first_name' => 'NoHooks',
		));
		$before  = $this->captureBusinessState((string) $input['email']);
		$adapter = new TPFWLI_Tpfw_Adapter();
		$runtime = $adapter->find_runtime();
		$this->assertInstanceOf(TPFW_Ticket_WC_Product::class, $runtime);
		$completed_prio = has_action('woocommerce_order_status_completed', array($runtime, 'order_status_completed'));
		$payment_prio   = has_action('woocommerce_payment_complete', array($runtime, 'order_payment_complete'));
		$this->assertNotFalse($completed_prio);
		$this->assertNotFalse($payment_prio);
		remove_action('woocommerce_order_status_completed', array($runtime, 'order_status_completed'), (int) $completed_prio);
		remove_action('woocommerce_payment_complete', array($runtime, 'order_payment_complete'), (int) $payment_prio);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNotEmpty($result['errors']);
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		} finally {
			add_action('woocommerce_order_status_completed', array($runtime, 'order_status_completed'), (int) $completed_prio);
			add_action('woocommerce_payment_complete', array($runtime, 'order_payment_complete'), (int) $payment_prio);
		}
	}

	public function test_non_transactional_storage_stops_bootstrap_without_mutations(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'myisam@example.com',
			'first_name' => 'Myisam',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$filter = static function () {
			return array('Order storage table wp_wc_orders uses MyISAM, which cannot roll back a crashed first-time import. InnoDB is required. The table engine was not changed.');
		};
		add_filter('tpfwli_transactional_storage_problems', $filter);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertStringContainsString('MyISAM', implode(' ', $result['errors']));
			$this->assertBusinessUnchanged($before, (string) $input['email']);
			$direct = (new TPFWLI_Order_Service())->create_or_resume(
				$input['import_id'],
				$input,
				wc_get_product(self::$ticket_id),
				(int) $input['quantity'],
				array()
			);
			$this->assertFalse($direct['ok']);
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		} finally {
			remove_filter('tpfwli_transactional_storage_problems', $filter);
		}
	}

	public function test_meta_lookup_sql_error_stops_import_and_does_not_create_an_order(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-meta@example.com',
			'first_name' => 'LookupMeta',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$filter = $this->failIdentityQueries($input['import_id'], 'meta', 1);
		try {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$this->assertNull($found['order']);
			$this->assertStringContainsString('import ID', $found['error']);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		} finally {
			remove_filter('query', $filter, 999);
		}
	}

	public function test_created_via_lookup_sql_error_stops_import_and_does_not_create_an_order(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-via@example.com',
			'first_name' => 'LookupVia',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$filter = $this->failIdentityQueries($input['import_id'], 'created_via', 1);
		try {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$this->assertStringContainsString('created_via', $found['error']);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		} finally {
			remove_filter('query', $filter, 999);
		}
	}

	public function test_later_create_or_resume_lookup_sql_error_stops_before_insert(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-later@example.com',
			'first_name' => 'LookupLater',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$filter = $this->failIdentityQueries($input['import_id'], 'any', 3);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertSame(array(), $this->db_import_order_ids($input['import_id']));
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		} finally {
			remove_filter('query', $filter, 999);
		}
	}

	public function test_successful_created_via_hit_does_not_mask_a_meta_read_error(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-mask@example.com',
			'first_name' => 'LookupMask',
		));
		$orphan = new WC_Order();
		$orphan->set_status('pending');
		$orphan->set_customer_id(0);
		$orphan->set_created_via(TPFWLI_Plugin::created_via($input['import_id']));
		$orphan->save();
		$orphan_id = (int) $orphan->get_id();
		$this->assertSame('', (string) $orphan->get_meta(TPFWLI_Plugin::META_IMPORT_ID));
		$before = $this->captureBusinessState((string) $input['email']);
		$filter = $this->failIdentityQueries($input['import_id'], 'meta', 1);
		try {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$this->assertNull($found['order']);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$fresh = wc_get_order($orphan_id);
			$this->assertInstanceOf(WC_Order::class, $fresh);
			$this->assertSame('', (string) $fresh->get_meta(TPFWLI_Plugin::META_IMPORT_ID));
			$this->assertSame($before['stock'], (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
			$this->assertSame($before['tickets'], $this->count_tickets());
			$this->assertSame(0, $this->customerMailCount((string) $input['email']));
			$this->assertSame(array($orphan_id), $this->db_import_order_ids($input['import_id']));
		} finally {
			remove_filter('query', $filter, 999);
		}
	}

	public function test_meta_lookup_sql_error_still_stops_after_woocommerce_order_query_select(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-meta-mask@example.com',
			'first_name' => 'LookupMetaMask',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$this->withIdentityLookupFailureThenSuccessfulSelect($input['import_id'], 'meta', 1, function () use ($input, $before): void {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$this->assertNull($found['order']);
			$this->assertStringContainsString('import ID', $found['error']);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		});
	}

	public function test_created_via_lookup_sql_error_still_stops_after_woocommerce_order_query_select(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-via-mask@example.com',
			'first_name' => 'LookupViaMask',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$this->withIdentityLookupFailureThenSuccessfulSelect($input['import_id'], 'created_via', 1, function () use ($input, $before): void {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$this->assertStringContainsString('created_via', $found['error']);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		});
	}

	public function test_later_create_or_resume_lookup_sql_error_still_stops_after_order_query_select(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-later-mask@example.com',
			'first_name' => 'LookupLaterMask',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$this->withIdentityLookupFailureThenSuccessfulSelect($input['import_id'], 'any', 3, function () use ($input, $before): void {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertSame(array(), $this->db_import_order_ids($input['import_id']));
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		});
	}

	public function test_stale_wpdb_last_error_does_not_fail_a_successful_miss(): void
	{
		global $wpdb;
		$previous = (string) $wpdb->last_error;
		$wpdb->last_error = 'unrelated leftover from another query';
		try {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id(wp_generate_uuid4());
			$this->assertTrue($found['ok']);
			$this->assertNull($found['order']);
			$this->assertSame('', $found['error']);
		} finally {
			$wpdb->last_error = $previous;
		}
	}

	public function test_bootstrap_tables_include_posts_and_notes_when_hpos_sync_is_off(): void
	{
		global $wpdb;
		$this->withHposSyncOption('no', function () use ($wpdb): void {
			$tables = TPFWLI_Dependencies::bootstrap_storage_tables();
			$this->assertContains($wpdb->posts, $tables);
			$this->assertContains($wpdb->postmeta, $tables);
			$this->assertContains($wpdb->comments, $tables);
			$this->assertContains($wpdb->commentmeta, $tables);
		});
	}

	public function test_bootstrap_tables_include_posts_and_notes_when_hpos_sync_is_on(): void
	{
		global $wpdb;
		$this->withHposSyncOption('yes', function () use ($wpdb): void {
			$tables = TPFWLI_Dependencies::bootstrap_storage_tables();
			$this->assertContains($wpdb->posts, $tables);
			$this->assertContains($wpdb->postmeta, $tables);
			$this->assertContains($wpdb->comments, $tables);
			$this->assertContains($wpdb->commentmeta, $tables);
		});
	}

	public function test_observed_non_transactional_engine_stops_bootstrap_without_mutations(): void
	{
		global $wpdb;
		$input = $this->valid_input(array(
			'email'      => 'engine-probe@example.com',
			'first_name' => 'EngineProbe',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$probe  = $this->createNonTransactionalProbeTable();
		$filter = static function (array $tables) use ($probe) {
			$tables[] = $probe;
			return $tables;
		};
		add_filter('tpfwli_bootstrap_storage_tables', $filter);
		try {
			$problems = TPFWLI_Dependencies::transactional_storage_problems();
			$this->assertNotSame(array(), $problems);
			$this->assertTrue((bool) preg_grep('/' . preg_quote($probe, '/') . '/', $problems));
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertBusinessUnchanged($before, (string) $input['email']);
			$this->assertSame(array(), $this->db_import_order_ids($input['import_id']));
		} finally {
			remove_filter('tpfwli_bootstrap_storage_tables', $filter);
			$wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $probe) . '`');
		}
	}

	public function test_two_matching_orders_still_stop_import(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'dup-id@example.com',
			'first_name' => 'DupId',
			'quantity'   => '1',
		));
		$first  = $this->bootstrap_identity($input);
		$second = $this->bootstrap_identity($input);
		$this->assertNotSame((int) $first->get_id(), (int) $second->get_id());
		$before = $this->captureBusinessState((string) $input['email']);
		$found  = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$this->assertFalse($found['ok']);
		$this->assertStringContainsString('more than one', $found['error']);
		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($result['ok']);
		$this->assertSame($before['stock'], (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame(0, $this->count_tickets_for_order((int) $first->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $second->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertCount(2, $this->db_import_order_ids($input['import_id']));
	}

	public function test_successful_lookup_without_hit_allows_normal_import_and_same_id_resumes(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-ok@example.com',
			'first_name' => 'LookupOk',
			'quantity'   => '2',
		));
		$missing = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$this->assertTrue($missing['ok']);
		$this->assertNull($missing['order']);
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order_id = (int) $first['order']->get_id();
		$hit = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$this->assertTrue($hit['ok']);
		$this->assertInstanceOf(WC_Order::class, $hit['order']);
		$this->assertSame($order_id, (int) $hit['order']->get_id());
		$second = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertSame($order_id, (int) $second['order']->get_id());
		$this->assertSame(array($order_id), $this->db_import_order_ids($input['import_id']));
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($first['nanos'], $second['nanos']);
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));
	}

	public function test_unexpected_wc_get_orders_return_is_a_read_error_not_a_miss(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-false@example.com',
			'first_name' => 'LookupFalse',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$filter = static function ($results, $args) {
			if (($args['meta_key'] ?? '') === TPFWLI_Plugin::META_IMPORT_ID) {
				return false;
			}
			return $results;
		};
		add_filter('woocommerce_order_query', $filter, 10, 2);
		try {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		} finally {
			remove_filter('woocommerce_order_query', $filter, 10);
		}
	}

	/**
	 * @return array{orders:int,stock:int,tickets:int,mail:int}
	 */
	private function captureBusinessState(string $email): array
	{
		unset($email);
		return array(
			'orders'  => $this->count_import_orders(),
			'stock'   => (int) wc_get_product(self::$ticket_id)->get_stock_quantity(),
			'tickets' => $this->count_tickets(),
			'mail'    => count(self::$mail),
		);
	}

	/**
	 * @param array{orders:int,stock:int,tickets:int,mail:int} $before
	 */
	private function assertBusinessUnchanged(array $before, string $email): void
	{
		$this->assertSame($before['orders'], $this->count_import_orders());
		$this->assertSame($before['stock'], (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($before['tickets'], $this->count_tickets());
		$this->assertSame($before['mail'], count(self::$mail));
		$this->assertSame(0, $this->customerMailCount($email));
	}

	/**
	 * @param callable():void $callback
	 */
	private function withHposDisabled(callable $callback): void
	{
		$filter = static function () {
			return 'no';
		};
		add_filter('pre_option_woocommerce_custom_orders_table_enabled', $filter);
		try {
			$callback();
		} finally {
			remove_filter('pre_option_woocommerce_custom_orders_table_enabled', $filter);
		}
	}

	private function failIdentityQueries(string $import_id, string $which, int $from_call): callable
	{
		$calls  = 0;
		$filter = static function ($sql) use ($import_id, $which, $from_call, &$calls) {
			if (!is_string($sql) || !str_contains($sql, $import_id)) {
				return $sql;
			}
			if (str_contains($sql, 'SELECT DISTINCT o.id')) {
				return $sql;
			}
			$is_meta = str_contains($sql, TPFWLI_Plugin::META_IMPORT_ID);
			$is_via  = str_contains($sql, TPFWLI_Plugin::CREATED_VIA_PREFIX);
			if ($which === 'meta' && !$is_meta) {
				return $sql;
			}
			if ($which === 'created_via' && !$is_via) {
				return $sql;
			}
			$calls++;
			if ($calls < $from_call) {
				return $sql;
			}
			return 'SELECT id FROM tpfwli_missing_identity_lookup_table WHERE id = 1';
		};
		add_filter('query', $filter, 999);
		return $filter;
	}

	/**
	 * @param callable():void $callback
	 */
	private function withIdentityLookupFailureThenSuccessfulSelect(string $import_id, string $which, int $from_call, callable $callback): void
	{
		$fail = $this->failIdentityQueries($import_id, $which, $from_call);
		$mask = static function ($results) {
			global $wpdb;
			$wpdb->get_var('SELECT 1');
			return $results;
		};
		add_filter('woocommerce_order_query', $mask, 10, 2);
		try {
			$callback();
		} finally {
			remove_filter('query', $fail, 999);
			remove_filter('woocommerce_order_query', $mask, 10);
		}
	}

	/**
	 * @param callable(string[]):void $callback
	 */
	private function withHposSyncOption(string $value, callable $callback): void
	{
		$filter = static function () use ($value) {
			return $value;
		};
		add_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled', $filter);
		try {
			$callback();
		} finally {
			remove_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled', $filter);
		}
	}

	/**
	 * @return string Disposable table name.
	 */
	private function createNonTransactionalProbeTable(): string
	{
		global $wpdb;
		$name = $wpdb->prefix . 'tpfwli_probe_' . substr(md5(uniqid((string) mt_rand(), true)), 0, 12);
		$quoted = '`' . str_replace('`', '', $name) . '`';
		$created = $wpdb->query("CREATE TABLE {$quoted} (`id` INT UNSIGNED NOT NULL) ENGINE=MyISAM");
		if ($created === false) {
			$created = $wpdb->query("CREATE TABLE {$quoted} (`id` INT UNSIGNED NOT NULL) ENGINE=MEMORY");
		}
		$this->assertNotFalse($created, 'Could not create a disposable non-transactional probe table');
		$engine = (string) $wpdb->get_var($wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$name
		));
		$this->assertNotSame('', $engine);
		$this->assertFalse(TPFWLI_Dependencies::engine_is_transactional($engine), $name . ' engine ' . $engine);
		return $name;
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

	/**
	 * @param array<string,mixed> $input
	 */
	private function bootstrap_identity(array $input): WC_Order
	{
		$adapter = new TPFWLI_Tpfw_Adapter();
		$check = $adapter->validate_ticket_product((int) $input['product_id'], (int) $input['quantity'], false);
		$this->assertTrue($check['ok'], implode('; ', $check['errors']));
		$order = new WC_Order();
		$order->set_status('pending');
		$order->set_customer_id(0);
		$order->set_created_via(TPFWLI_Plugin::created_via($input['import_id']));
		$order->set_billing_first_name((string) $input['first_name']);
		$order->set_billing_last_name((string) ($input['last_name'] ?? 'Andersson'));
		$order->set_billing_email((string) $input['email']);
		$order->set_billing_phone((string) ($input['phone'] ?? '0701234567'));
		$order->update_meta_data(TPFWLI_Plugin::META_IMPORT, 'yes');
		$order->update_meta_data(TPFWLI_Plugin::META_IMPORT_ID, $input['import_id']);
		$order->update_meta_data(TPFWLI_Plugin::META_ORDER_STAGE, TPFWLI_Plugin::STAGE_BOOTSTRAPPING);
		$order->update_meta_data(TPFWLI_Plugin::META_STOCK_STAGE, 'pending');
		$order->update_meta_data(TPFWLI_Plugin::META_ISSUE_STAGE, 'pending');
		$order->update_meta_data(TPFWLI_Plugin::META_EMAIL_STAGE, 'not_sent');
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID, (int) $input['product_id']);
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_QUANTITY, (int) $input['quantity']);
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_MAX_USES, (int) ($check['meta']['max_uses'] ?? 1));
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_VALID_FROM, (string) ($check['meta']['valid_from'] ?? ''));
		$order->update_meta_data(TPFWLI_Plugin::META_EXPECTED_VALID_TO, (string) ($check['meta']['valid_to'] ?? ''));
		$order->save();
		$fresh = wc_get_order($order->get_id());
		$this->assertInstanceOf(WC_Order::class, $fresh);
		return $fresh;
	}

	private function assertBootstrapCrashThenRetry(string $checkpoint): void
	{
		$this->assertHposTablesAreInnoDb();
		$input = $this->valid_input(array(
			'email'      => 'crash-' . $checkpoint . '@example.com',
			'first_name' => 'Crash',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$this->assertSame(array(), $this->db_import_order_ids($input['import_id']));

		$seen_id = 0;
		$hit     = false;
		$crash   = function (string $point, $order) use ($checkpoint, &$seen_id, &$hit): void {
			if (!$order instanceof WC_Order || $point !== $checkpoint) {
				return;
			}
			$seen_id = (int) $order->get_id();
			$hit     = true;
			throw new RuntimeException('injected:' . $checkpoint);
		};
		add_action('tpfwli_bootstrap_checkpoint', $crash, 10, 2);
		$failed = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		remove_action('tpfwli_bootstrap_checkpoint', $crash, 10);

		$this->assertTrue($hit, 'checkpoint ' . $checkpoint . ' was not reached');
		$this->assertGreaterThan(0, $seen_id);
		$this->assertFalse($failed['ok']);
		$this->assertNull($failed['order']);
		$this->assertSame(array(), $this->db_import_order_ids($input['import_id']));
		$this->assertSame(0, $this->db_table_count($this->hpos_orders_table(), 'id', $seen_id));
		$this->assertSame(0, $this->db_table_count($this->hpos_operational_table(), 'order_id', $seen_id));
		$this->assertSame(0, $this->db_table_count($this->hpos_meta_table(), 'order_id', $seen_id));
		$this->assertSame(0, $this->db_table_count($this->hpos_address_table(), 'order_id', $seen_id));
		$this->assertSame(0, $this->db_table_count($this->order_items_table(), 'order_id', $seen_id));
		global $wpdb;
		$this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d", $seen_id)));
		$this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $seen_id)));
		$this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $seen_id)));
		$this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_post_ID = %d",
			$seen_id
		)));
		$this->assertSame($start_stock, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertSame(0, $this->count_tickets_for_order($seen_id));

		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertInstanceOf(WC_Order::class, $retry['order']);
		$final_id = (int) $retry['order']->get_id();
		$this->assertNotSame($seen_id, $final_id);
		$this->assertSame(array($final_id), $this->db_import_order_ids($input['import_id']));
		$this->assertCount(1, $retry['order']->get_items('line_item'));
		$this->assertSame(0, (int) $retry['order']->get_customer_id());
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertCount(2, $retry['nanos']);
		$this->assertSame(2, $this->count_tickets_for_order($final_id));
		$this->assertSame('sent', (string) $retry['order']->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));
		$this->assertSame(0, $this->adminMailCount((string) $input['email']));

		$again = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertSame($final_id, (int) $again['order']->get_id());
		$this->assertSame(array($final_id), $this->db_import_order_ids($input['import_id']));
		$this->assertSame($start_stock - 2, (int) wc_get_product(self::$ticket_id)->get_stock_quantity());
		$this->assertSame($retry['nanos'], $again['nanos']);
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));
	}

	private function assertHposTablesAreInnoDb(): void
	{
		global $wpdb;
		$tables = TPFWLI_Dependencies::bootstrap_storage_tables();
		$this->assertContains($wpdb->posts, $tables);
		$this->assertContains($wpdb->postmeta, $tables);
		$this->assertContains($wpdb->comments, $tables);
		$this->assertContains($wpdb->commentmeta, $tables);
		$this->assertSame(array(), TPFWLI_Dependencies::transactional_storage_problems());
	}

	/**
	 * @return int[]
	 */
	private function db_import_order_ids(string $import_id): array
	{
		global $wpdb;
		$via = TPFWLI_Plugin::created_via($import_id);
		$ids = $wpdb->get_col($wpdb->prepare(
			'SELECT DISTINCT o.id FROM ' . $this->hpos_orders_table() . ' o
			LEFT JOIN ' . $this->hpos_operational_table() . ' od ON od.order_id = o.id
			LEFT JOIN ' . $this->hpos_meta_table() . ' m ON m.order_id = o.id AND m.meta_key = %s
			WHERE od.created_via = %s OR m.meta_value = %s
			ORDER BY o.id ASC',
			TPFWLI_Plugin::META_IMPORT_ID,
			$via,
			$import_id
		));
		if (!is_array($ids)) {
			return array();
		}
		return array_map('intval', $ids);
	}

	private function db_table_count(string $table, string $column, int $order_id): int
	{
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '` WHERE `' . str_replace('`', '', $column) . '` = %d';
		return (int) $wpdb->get_var($wpdb->prepare($sql, $order_id));
	}

	private function hpos_orders_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'wc_orders';
	}

	private function hpos_operational_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'wc_order_operational_data';
	}

	private function hpos_address_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'wc_order_addresses';
	}

	private function hpos_meta_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'wc_orders_meta';
	}

	private function order_items_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'woocommerce_order_items';
	}

	/**
	 * wp_mail calls whose To-list includes the import billing address.
	 */
	private function customerMailCount(string $billing): int
	{
		$billing = strtolower($billing);
		$count = 0;
		foreach (self::$mail as $atts) {
			if ($this->mailToContains($atts, $billing)) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Admin/internal wp_mail that did not also target the billing address.
	 */
	private function adminMailCount(string $billing): int
	{
		$billing = strtolower($billing);
		$admin = strtolower((string) get_option('admin_email'));
		if ($admin === '' || $admin === $billing) {
			return 0;
		}
		$count = 0;
		foreach (self::$mail as $atts) {
			if ($this->mailToContains($atts, $admin) && !$this->mailToContains($atts, $billing)) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * @param array<string,mixed> $atts
	 */
	private function mailToContains(array $atts, string $needle): bool
	{
		$to = $atts['to'] ?? '';
		if (is_array($to)) {
			$to = implode(',', $to);
		}
		$tos = array_map('trim', explode(',', strtolower((string) $to)));
		return in_array($needle, $tos, true);
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
		$this->installWpDieThrower();
		$this->expectException(RuntimeException::class);
	}

	private function installWpDieThrower(): void
	{
		add_filter('wp_die_handler', static function () {
			return static function ($message) {
				throw new RuntimeException('wp_die:' . wp_strip_all_tags((string) $message));
			};
		});
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
