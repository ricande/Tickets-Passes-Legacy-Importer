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
		if (class_exists('TPFWLI_Database_Session')) {
			TPFWLI_Database_Session::reset_for_tests();
		}
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

	public function test_stock_crash_after_product_change_rolls_back_and_retry_reduces_once(): void
	{
		$this->assertStockCrashThenRetry('after_product_stock');
	}

	public function test_stock_crash_after_line_meta_rolls_back_and_retry_reduces_once(): void
	{
		$this->assertStockCrashThenRetry('after_line_meta');
	}

	public function test_stock_crash_after_commit_before_stage_mark_retries_without_second_reduction(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-after-commit@example.com',
			'first_name' => 'StockAfterCommit',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$hit = false;
		$crash = static function (string $point) use (&$hit): void {
			if ($point !== 'after_commit') {
				return;
			}
			$hit = true;
			throw new RuntimeException('stock-checkpoint:after_commit');
		};
		add_action('tpfwli_stock_checkpoint', $crash, 10, 1);
		try {
			(new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->fail('after_commit checkpoint should abort before stage mark');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('stock-checkpoint:after_commit', $e->getMessage());
		} finally {
			remove_action('tpfwli_stock_checkpoint', $crash, 10);
		}
		$this->assertTrue($hit);
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$order = $found['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		wp_cache_flush();
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertTrue($this->db_order_stock_flag((int) $order->get_id()));
		$this->assertNotSame('reduced', (string) $order->get_meta(TPFWLI_Plugin::META_STOCK_STAGE));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));

		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		wp_cache_flush();
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock((int) $retry['order']->get_id()));
		$this->assertSame('reduced', (string) $retry['order']->get_meta(TPFWLI_Plugin::META_STOCK_STAGE));
		$this->assertCount(2, $retry['nanos']);
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));
	}

	public function test_stock_process_abort_after_product_change_rolls_back(): void
	{
		$this->assertStockProcessAbortThenRetry('after_product_stock');
	}

	public function test_denied_stock_reduction_filter_does_not_accept_the_order_flag(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-denied@example.com',
			'first_name' => 'StockDenied',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$deny = function ($allowed, $order) use ($input) {
			if ($order instanceof WC_Order && (string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID) === $input['import_id']) {
				return false;
			}
			return $allowed;
		};
		add_filter('woocommerce_can_reduce_order_stock', $deny, 10, 2);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_filter('woocommerce_can_reduce_order_stock', $deny, 10);
		}
		$this->assertFalse($result['ok']);
		$order = $result['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		wp_cache_flush();
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $order->get_id()));
		$this->assertFalse((new TPFWLI_Stock_Service())->is_accounted(wc_get_order($order->get_id()), wc_get_product(self::$ticket_id), 2));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_filter_changing_reduction_quantity_is_not_accepted(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-qty-filter@example.com',
			'first_name' => 'StockQtyFilter',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$filter = static function ($qty) {
			return 1;
		};
		add_filter('woocommerce_order_item_quantity', $filter, 10, 1);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_filter('woocommerce_order_item_quantity', $filter, 10);
		}
		$this->assertFalse($result['ok']);
		$order = $result['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		wp_cache_flush();
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $order->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_sql_error_during_stock_update_rolls_back_without_tickets(): void
	{
		$this->assertStockSqlFailureStopsImport('stock');
	}

	public function test_sql_error_during_reduced_stock_meta_rolls_back_without_tickets(): void
	{
		$this->assertStockSqlFailureStopsImport('itemmeta');
	}

	public function test_sql_error_during_stock_flag_rolls_back_without_tickets(): void
	{
		$this->assertStockSqlFailureStopsImport('flag');
	}

	public function test_existing_flag_without_reduced_stock_stops_before_issue(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-flag-only@example.com',
			'first_name' => 'StockFlagOnly',
			'quantity'   => '2',
		));
		$order = $this->bootstrap_identity($input);
		$shaped = (new TPFWLI_Order_Shape())->assert_or_repair($order, wc_get_product(self::$ticket_id), 2);
		$this->assertTrue($shaped['ok'], $shaped['error']);
		$order = $shaped['order'];
		$order->get_data_store()->set_stock_reduced($order->get_id(), true);
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		wp_cache_flush();
		$this->assertTrue((new TPFWLI_Stock_Service())->is_reduced(wc_get_order($order->get_id())));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));

		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($result['ok']);
		wp_cache_flush();
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_already_reduced_order_retries_without_further_stock_draw(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-already@example.com',
			'first_name' => 'StockAlready',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		wp_cache_flush();
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$product = wc_get_product(self::$ticket_id);
		$product->set_stock_quantity($start_stock - 5);
		$product->save();
		wp_cache_flush();

		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'] || $retry['email_already'], implode('; ', $retry['errors']));
		wp_cache_flush();
		$this->assertSame($start_stock - 5, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock((int) $first['order']->get_id()));
		$this->assertSame($first['nanos'], $retry['nanos']);
	}

	public function test_insufficient_stock_at_confirm_does_not_issue_tickets(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-empty@example.com',
			'first_name' => 'StockEmpty',
			'quantity'   => '2',
		));
		$product = wc_get_product(self::$ticket_id);
		$product->set_stock_quantity(1);
		$product->save();
		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($result['ok']);
		wp_cache_flush();
		$this->assertSame(1, $this->db_product_stock(self::$ticket_id));
		if ($result['order'] instanceof WC_Order) {
			$this->assertSame(0, $this->count_tickets_for_order((int) $result['order']->get_id()));
		}
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_other_order_stock_reduction_is_not_overwritten(): void
	{
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$other = wc_create_order(array('status' => 'pending', 'customer_id' => 0));
		$this->assertInstanceOf(WC_Order::class, $other);
		$other->add_product(wc_get_product(self::$ticket_id), 1);
		$other->save();
		wc_maybe_reduce_stock_levels($other->get_id());
		wp_cache_flush();
		$this->assertSame($start_stock - 1, $this->db_product_stock(self::$ticket_id));

		$input = $this->valid_input(array(
			'email'      => 'stock-other-order@example.com',
			'first_name' => 'StockOther',
			'quantity'   => '2',
		));
		$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($result['ok'], implode('; ', $result['errors']));
		wp_cache_flush();
		$this->assertSame($start_stock - 3, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(1, $this->db_line_reduced_stock((int) $other->get_id()));
		$this->assertSame(2, $this->db_line_reduced_stock((int) $result['order']->get_id()));
	}

	public function test_concurrent_imports_with_different_ids_do_not_oversell(): void
	{
		if (!function_exists('proc_open')) {
			$this->markTestSkipped('proc_open is not available');
		}
		$worker = dirname(__DIR__) . '/bin/confirm-worker.php';
		if (!is_readable($worker)) {
			$this->markTestSkipped('confirm worker is missing');
		}
		$product = wc_get_product(self::$ticket_id);
		$product->set_stock_quantity(3);
		$product->save();
		$input_a = $this->valid_input(array(
			'email'      => 'stock-race-a@example.com',
			'first_name' => 'StockRaceA',
			'quantity'   => '2',
		));
		$input_b = $this->valid_input(array(
			'email'      => 'stock-race-b@example.com',
			'first_name' => 'StockRaceB',
			'quantity'   => '2',
		));
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
		fwrite($pipes1[0], wp_json_encode($input_a));
		fwrite($pipes2[0], wp_json_encode($input_b));
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
		wp_cache_flush();
		$stock = $this->db_product_stock(self::$ticket_id);
		$ok_count = (!empty($a['ok']) ? 1 : 0) + (!empty($b['ok']) ? 1 : 0);
		$this->assertSame(1, $ok_count, wp_json_encode(array($a, $b, $stock)));
		$this->assertSame(1, $stock);
		$winner = !empty($a['ok']) ? $a : $b;
		$loser  = !empty($a['ok']) ? $b : $a;
		$this->assertSame(2, $this->count_tickets_for_order((int) $winner['order_id']));
		if ((int) $loser['order_id'] > 0) {
			$this->assertSame(0, $this->count_tickets_for_order((int) $loser['order_id']));
		}
	}

	public function test_stock_tables_include_product_lookup_and_notes(): void
	{
		global $wpdb;
		$tables = TPFWLI_Dependencies::stock_storage_tables();
		$this->assertContains($wpdb->postmeta, $tables);
		$this->assertContains($wpdb->prefix . 'wc_product_meta_lookup', $tables);
		$this->assertContains($wpdb->prefix . 'woocommerce_order_itemmeta', $tables);
		$this->assertContains($wpdb->comments, $tables);
		$this->assertContains($wpdb->commentmeta, $tables);
		$this->assertSame(array(), TPFWLI_Dependencies::transactional_stock_storage_problems());
	}

	public function test_non_transactional_stock_engine_stops_reduction_on_existing_order(): void
	{
		global $wpdb;
		$input = $this->valid_input(array(
			'email'      => 'stock-engine@example.com',
			'first_name' => 'StockEngine',
			'quantity'   => '2',
		));
		$order = $this->bootstrap_identity($input);
		$shaped = (new TPFWLI_Order_Shape())->assert_or_repair($order, wc_get_product(self::$ticket_id), 2);
		$this->assertTrue($shaped['ok'], $shaped['error']);
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$probe = $this->createNonTransactionalProbeTable();
		$filter = static function (array $tables) use ($probe) {
			$tables[] = $probe;
			return $tables;
		};
		add_filter('tpfwli_stock_storage_tables', $filter);
		try {
			$problems = TPFWLI_Dependencies::transactional_stock_storage_problems();
			$this->assertNotSame(array(), $problems);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			wp_cache_flush();
			$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
			$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
			$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		} finally {
			remove_filter('tpfwli_stock_storage_tables', $filter);
			$wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $probe) . '`');
		}
	}

	public function test_failed_start_transaction_does_not_change_stock(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'txn-start@example.com',
			'first_name' => 'TxnStart',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$filter = $this->failSqlCommands(array('START TRANSACTION'));
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_filter('query', $filter, 999);
		}
		$this->assertFalse($result['ok']);
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $order->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertSame('0', $this->sessionInTransaction());
	}

	public function test_failed_commit_is_not_accepted_from_same_connection_reads(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'txn-commit@example.com',
			'first_name' => 'TxnCommit',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$filter = $this->failSqlCommands(array('COMMIT'));
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_filter('query', $filter, 999);
		}
		$this->assertFalse($result['ok']);
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $order->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertSame('0', $this->sessionInTransaction());
		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock((int) $order->get_id()));
	}

	public function test_failed_rollback_does_not_leave_a_transaction_open(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'txn-rollback@example.com',
			'first_name' => 'TxnRollback',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$filter = $this->failSqlCommands(array('ROLLBACK'), 1);
		$crash = static function (string $point): void {
			if ($point === 'after_product_stock') {
				throw new RuntimeException('stock-checkpoint:after_product_stock');
			}
		};
		add_action('tpfwli_stock_checkpoint', $crash, 10, 1);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_action('tpfwli_stock_checkpoint', $crash, 10);
			remove_filter('query', $filter, 999);
		}
		$this->assertFalse($result['ok']);
		$this->assertSame('0', $this->sessionInTransaction());
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_start_then_inspect_closed_is_rolled_back(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'txn-start-closed@example.com',
			'first_name' => 'TxnStartClosed',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$filter = $this->forceTxnInspectAfterFirst('closed');
		$GLOBALS['wpdb']->suppress_errors(true);
		try {
			$result = (new TPFWLI_Stock_Service())->reduce_if_needed($order, wc_get_product(self::$ticket_id), 2);
		} finally {
			remove_filter('query', $filter, 999);
			$GLOBALS['wpdb']->suppress_errors(false);
		}
		$this->assertFalse($result['ok']);
		$this->assertSame('0', $this->sessionInTransaction());
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $order->get_id()));
	}

	public function test_start_then_inspect_unknown_is_rolled_back(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'txn-start-unknown@example.com',
			'first_name' => 'TxnStartUnknown',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$filter = $this->forceTxnInspectAfterFirst('unknown');
		$GLOBALS['wpdb']->suppress_errors(true);
		try {
			$result = (new TPFWLI_Stock_Service())->reduce_if_needed($order, wc_get_product(self::$ticket_id), 2);
		} finally {
			remove_filter('query', $filter, 999);
			$GLOBALS['wpdb']->suppress_errors(false);
		}
		$this->assertFalse($result['ok']);
		$this->assertSame('0', $this->sessionInTransaction());
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
	}

	public function test_unconfirmed_rollback_does_not_write_follow_up_state(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'txn-fatal-rollback@example.com',
			'first_name' => 'TxnFatalRb',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$order_id = (int) $order->get_id();
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$other = $this->secondDb();
		$notes_before = $this->count_order_notes_other($other, $order_id);
		$writes = array();
		$after_crash = false;
		$filter = $this->failSqlCommands(array('ROLLBACK'));
		$spy = static function ($sql) use (&$writes, &$after_crash) {
			if ($after_crash && is_string($sql) && preg_match('/^\s*(INSERT|UPDATE|REPLACE|DELETE)/i', $sql)) {
				$writes[] = $sql;
			}
			return $sql;
		};
		$crash = static function (string $point) use (&$after_crash): void {
			if ($point === 'after_product_stock') {
				$after_crash = true;
				throw new RuntimeException('stock-checkpoint:after_product_stock');
			}
		};
		add_filter('query', $spy, 1000);
		add_action('tpfwli_stock_checkpoint', $crash, 10, 1);
		$GLOBALS['wpdb']->suppress_errors(true);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_action('tpfwli_stock_checkpoint', $crash, 10);
			remove_filter('query', $spy, 1000);
			remove_filter('query', $filter, 999);
			$GLOBALS['wpdb']->suppress_errors(false);
		}
		$this->assertFalse($result['ok']);
		$this->assertTrue(!empty($result['errors']));
		$this->assertTrue(
			(bool) preg_match('/stock-checkpoint:after_product_stock|ROLLBACK|uncertain|isolated/i', implode(' ', $result['errors']))
		);
		$this->assertTrue(TPFWLI_Database_Session::is_quarantined());
		$follow_up = array_values(array_filter($writes, static function ($sql) {
			return str_contains($sql, '_tpfwli_stock_stage')
				|| str_contains($sql, '_tpfwli_issue_stage')
				|| str_contains($sql, '_tpfwli_email_stage')
				|| str_contains($sql, 'comment_content')
				|| str_contains($sql, 'wp_comments');
		}));
		$this->assertSame(array(), $follow_up, implode("\n", $follow_up));
		$this->assertSame($start_stock, (int) $other->get_var($other->prepare(
			"SELECT meta_value FROM {$other->postmeta} WHERE post_id = %d AND meta_key = %s",
			self::$ticket_id,
			'_stock'
		)));
		$this->assertNotSame('failed', $this->db_stock_stage_other($other, $order_id));
		$this->assertSame($notes_before, $this->count_order_notes_other($other, $order_id));
		$this->assertSame(0, (int) $other->get_var($other->prepare(
			'SELECT COUNT(*) FROM `' . $other->prefix . 'tpfw_tickets` WHERE order_id = %d AND deleted IS NULL',
			$order_id
		)));
		TPFWLI_Database_Session::reset_for_tests();
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_unconfirmed_rollback_unknown_inspect_does_not_write_follow_up_state(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'txn-fatal-unknown@example.com',
			'first_name' => 'TxnFatalUnk',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$order_id = (int) $order->get_id();
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$other = $this->secondDb();
		$notes_before = $this->count_order_notes_other($other, $order_id);
		$writes = array();
		$after_crash = false;
		$fail_rollback = $this->failSqlCommands(array('ROLLBACK'));
		$fail_inspect = $this->failTxnInspectWhen(static function () use (&$after_crash): bool {
			return $after_crash;
		});
		$spy = static function ($sql) use (&$writes, &$after_crash) {
			if ($after_crash && is_string($sql) && preg_match('/^\s*(INSERT|UPDATE|REPLACE|DELETE)/i', $sql)) {
				$writes[] = $sql;
			}
			return $sql;
		};
		$crash = static function (string $point) use (&$after_crash): void {
			if ($point === 'after_product_stock') {
				$after_crash = true;
				throw new RuntimeException('stock-checkpoint:after_product_stock');
			}
		};
		add_filter('query', $spy, 1000);
		add_action('tpfwli_stock_checkpoint', $crash, 10, 1);
		$GLOBALS['wpdb']->suppress_errors(true);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_action('tpfwli_stock_checkpoint', $crash, 10);
			remove_filter('query', $spy, 1000);
			remove_filter('query', $fail_inspect, 998);
			remove_filter('query', $fail_rollback, 999);
			$GLOBALS['wpdb']->suppress_errors(false);
		}
		$joined = implode(' ', $result['errors'] ?? array());
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('stock-checkpoint:after_product_stock', $joined);
		$this->assertTrue((bool) preg_match('/ROLLBACK|uncertain|isolated/i', $joined), $joined);
		$this->assertTrue(TPFWLI_Database_Session::is_quarantined());
		$this->assertTrue(empty($GLOBALS['wpdb']->dbh) || !$GLOBALS['wpdb']->ready);
		$follow_up = array_values(array_filter($writes, static function ($sql) {
			return str_contains($sql, '_tpfwli_stock_stage')
				|| str_contains($sql, '_tpfwli_issue_stage')
				|| str_contains($sql, '_tpfwli_email_stage')
				|| str_contains($sql, 'comment_content')
				|| str_contains($sql, 'wp_comments');
		}));
		$this->assertSame(array(), $follow_up, implode("\n", $follow_up));
		$this->assertSame($start_stock, (int) $other->get_var($other->prepare(
			"SELECT meta_value FROM {$other->postmeta} WHERE post_id = %d AND meta_key = %s",
			self::$ticket_id,
			'_stock'
		)));
		$this->assertNotSame('failed', $this->db_stock_stage_other($other, $order_id));
		$this->assertSame($notes_before, $this->count_order_notes_other($other, $order_id));
		$this->assertSame(0, (int) $other->get_var($other->prepare(
			'SELECT COUNT(*) FROM `' . $other->prefix . 'tpfw_tickets` WHERE order_id = %d AND deleted IS NULL',
			$order_id
		)));
		TPFWLI_Database_Session::reset_for_tests();
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_unknown_after_commit_inspect_is_not_verified(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'txn-commit-unknown@example.com',
			'first_name' => 'TxnCommitUnk',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$order_id = (int) $order->get_id();
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$other = $this->secondDb();
		$seen_commit = false;
		$filter = static function ($sql) use (&$seen_commit) {
			if (!is_string($sql)) {
				return $sql;
			}
			if (strtoupper(trim($sql)) === 'COMMIT') {
				$seen_commit = true;
				return $sql;
			}
			if (
				$seen_commit
				&& (
					str_contains($sql, 'in_transaction')
					|| str_contains($sql, 'INNODB_TRX')
					|| str_contains($sql, 'events_transactions_current')
				)
			) {
				return 'SELECT tpfwli_missing_txn_state FROM dual';
			}
			return $sql;
		};
		add_filter('query', $filter, 999);
		$GLOBALS['wpdb']->suppress_errors(true);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_filter('query', $filter, 999);
			$GLOBALS['wpdb']->suppress_errors(false);
		}
		$joined = implode(' ', $result['errors'] ?? array());
		$this->assertFalse($result['ok']);
		$this->assertTrue($seen_commit);
		$this->assertTrue((bool) preg_match('/uncertain|isolated|COMMIT/i', $joined), $joined);
		$this->assertTrue(TPFWLI_Database_Session::is_quarantined());
		$this->assertSame(0, (int) $other->get_var($other->prepare(
			'SELECT COUNT(*) FROM `' . $other->prefix . 'tpfw_tickets` WHERE order_id = %d AND deleted IS NULL',
			$order_id
		)));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertNotSame('reduced', $this->db_stock_stage_other($other, $order_id));
		$this->assertSame($start_stock - 2, (int) $other->get_var($other->prepare(
			"SELECT meta_value FROM {$other->postmeta} WHERE post_id = %d AND meta_key = %s",
			self::$ticket_id,
			'_stock'
		)));
		TPFWLI_Database_Session::reset_for_tests();
		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock($order_id));
		$this->assertSame(2, $this->count_tickets_for_order($order_id));
	}

	public function test_wc_order_item_raw_meta_cache_is_cleared_after_rollback(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-wc-item-cache@example.com',
			'first_name' => 'StockWcItemCache',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$order_id = (int) $order->get_id();
		$item_id = (int) array_values($order->get_items('line_item'))[0]->get_id();
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$this->assertTrue(method_exists('WC_Data', 'generate_meta_cache_key'));
		$crash = static function (string $point) use ($item_id): void {
			if ($point !== 'after_line_meta') {
				return;
			}
			$warm = new WC_Order_Item_Product($item_id);
			$warm->get_meta('_reduced_stock', true);
			throw new RuntimeException('stock-checkpoint:after_line_meta');
		};
		add_action('tpfwli_stock_checkpoint', $crash, 10, 1);
		try {
			$result = (new TPFWLI_Stock_Service())->reduce_if_needed($order, wc_get_product(self::$ticket_id), 2);
		} finally {
			remove_action('tpfwli_stock_checkpoint', $crash, 10);
		}
		$this->assertFalse($result['ok']);
		$this->assertNull($this->db_line_reduced_stock($order_id));
		$probe = new WC_Order_Item_Product($item_id);
		$this->assertFalse($probe->meta_exists('_reduced_stock'));
		$this->assertSame('', (string) $probe->get_meta('_reduced_stock', true));
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock($order_id));
		if (wp_using_ext_object_cache()) {
			$key = WC_Data::generate_meta_cache_key($item_id, 'order-items');
			$cached = wp_cache_get($key, 'order-items');
			$this->assertTrue($cached === false || $cached === null || empty($cached['_reduced_stock']));
		}
	}

	public function test_mysql_transaction_inspect_is_safe_immediately_after_start(): void
	{
		$mysql = $this->isolatedMysql();
		if (!$mysql instanceof wpdb) {
			$this->markTestSkipped('Isolated MySQL is not available on 127.0.0.1:3307');
		}
		$previous = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $mysql;
		$mysql->suppress_errors(true);
		try {
			$service = new TPFWLI_Stock_Service();
			$mysql->query('START TRANSACTION');
			$mysql->query('SELECT 1');
			$mysql->query('COMMIT');
			$inspect = $this->callStockPrivate($service, 'inspect_sql_transaction');
			$this->assertSame('unknown', $inspect['status'], wp_json_encode($inspect));
			$idle_gate = $this->callStockPrivate($service, 'begin_stock_transaction_if_idle');
			$this->assertFalse($idle_gate['ok'], wp_json_encode($idle_gate));
			$this->assertFalse($idle_gate['accepted']);
			// MySQL idle cannot be proven; stock START is refused. Helpers below
			// only show that ACTIVE is still visible and that unknown stays unknown.

			$started = $this->callStockPrivate($service, 'begin_sql_transaction');
			$this->assertTrue($started['ok'], $started['error'] ?? '');
			$after_start = $this->callStockPrivate($service, 'inspect_sql_transaction');
			$this->assertSame('open', $after_start['status'], wp_json_encode($after_start));

			$trx = $mysql->get_var('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()');
			$this->assertTrue(is_numeric($trx));

			$committed = $this->callStockPrivate($service, 'commit_sql_transaction');
			$this->assertFalse($committed['ok'], wp_json_encode($committed));
			$this->assertSame('unknown', $committed['status'], wp_json_encode($committed));
			$after_commit = $this->callStockPrivate($service, 'inspect_sql_transaction');
			$this->assertSame('unknown', $after_commit['status'], wp_json_encode($after_commit));

			$started = $this->callStockPrivate($service, 'begin_sql_transaction');
			$this->assertTrue($started['ok'], $started['error'] ?? '');
			$rolled = $this->callStockPrivate($service, 'rollback_sql_transaction');
			$this->assertFalse($rolled['ok'], wp_json_encode($rolled));
			$this->assertSame('unknown', $rolled['status'], wp_json_encode($rolled));
			$after_rollback = $this->callStockPrivate($service, 'inspect_sql_transaction');
			$this->assertSame('unknown', $after_rollback['status'], wp_json_encode($after_rollback));
		} finally {
			$mysql->query('ROLLBACK');
			$mysql->suppress_errors(false);
			$GLOBALS['wpdb'] = $previous;
			TPFWLI_Database_Session::reset_for_tests();
		}
	}

	public function test_mysql_empty_innodb_trx_is_not_treated_as_closed(): void
	{
		$mysql = $this->isolatedMysql();
		if (!$mysql instanceof wpdb) {
			$this->markTestSkipped('Isolated MySQL is not available on 127.0.0.1:3307');
		}
		$previous = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $mysql;
		$filter = static function ($sql) {
			if (!is_string($sql)) {
				return $sql;
			}
			if (str_contains($sql, 'in_transaction') || str_contains($sql, 'events_transactions_current')) {
				return 'SELECT tpfwli_missing_txn_state FROM dual';
			}
			return $sql;
		};
		add_filter('query', $filter, 999);
		$mysql->suppress_errors(true);
		try {
			$mysql->query('START TRANSACTION');
			$inspect = $this->callStockPrivate(new TPFWLI_Stock_Service(), 'inspect_sql_transaction');
			$trx = $mysql->get_var('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()');
			$this->assertSame('0', (string) $trx);
			$this->assertSame('unknown', $inspect['status'], wp_json_encode($inspect));
			$this->assertFalse($inspect['ok']);
		} finally {
			remove_filter('query', $filter, 999);
			$mysql->query('ROLLBACK');
			$mysql->suppress_errors(false);
			$GLOBALS['wpdb'] = $previous;
			TPFWLI_Database_Session::reset_for_tests();
		}
	}

	public function test_mysql_incomplete_ps_does_not_close_or_commit_an_outer_transaction(): void
	{
		$admin = $this->isolatedMysql();
		if (!$admin instanceof wpdb) {
			$this->markTestSkipped('Isolated MySQL is not available on 127.0.0.1:3307');
		}
		$saved = $this->mysqlPsSnapshot($admin);
		$admin->query('CREATE TABLE IF NOT EXISTS tpfwli_ps_probe (id INT PRIMARY KEY, note VARCHAR(64)) ENGINE=InnoDB');
		$modes = array(
			'instrument_off',
			'thread_not_instrumented',
			'thread_instrumentation_off',
		);
		$id = 1;
		try {
			foreach ($modes as $mode) {
				foreach (array('no_event', 'stale_then_new') as $scenario) {
					$this->assertMysqlIncompletePsLeavesOuterTransaction($admin, $mode, $scenario, $id);
					$id++;
				}
			}
		} finally {
			$this->mysqlPsRestore($admin, $saved);
		}
	}

	public function test_mysql_reenabled_ps_stale_committed_is_not_closed(): void
	{
		$admin = $this->isolatedMysql();
		if (!$admin instanceof wpdb) {
			$this->markTestSkipped('Isolated MySQL is not available on 127.0.0.1:3307');
		}
		$saved = $this->mysqlPsSnapshot($admin);
		$admin->query('CREATE TABLE IF NOT EXISTS tpfwli_ps_probe (id INT PRIMARY KEY, note VARCHAR(64)) ENGINE=InnoDB');
		$modes = array(
			'instrument_off',
			'thread_not_instrumented',
			'thread_instrumentation_off',
		);
		$id = 200;
		try {
			foreach ($modes as $mode) {
				$this->assertMysqlReenabledPsDoesNotTreatStaleCommittedAsClosed($admin, $mode, $id);
				$id++;
			}
		} finally {
			$this->mysqlPsRestore($admin, $saved);
		}
	}

	public function test_wc_use_transactions_false_still_rolls_back_in_a_separate_process(): void
	{
		if (!function_exists('proc_open')) {
			$this->markTestSkipped('proc_open is not available');
		}
		$input = $this->valid_input(array(
			'email'      => 'txn-wc-off@example.com',
			'first_name' => 'TxnWcOff',
			'quantity'   => '2',
		));
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$out = $this->runStockCrashWorker($input, 'after_product_stock', array(
			'abort' => 'throw',
			'env'   => array('TPFWLI_WC_USE_TRANSACTIONS' => '0'),
		));
		$this->assertFalse(!empty($out['ok']));
		$this->assertFalse($out['wc_use_transactions'] ?? true);
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$this->assertInstanceOf(WC_Order::class, $found['order']);
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $found['order']->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $found['order']->get_id()));
		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
	}

	public function test_open_outer_transaction_is_left_untouched(): void
	{
		global $wpdb;
		$input = $this->valid_input(array(
			'email'      => 'txn-outer@example.com',
			'first_name' => 'TxnOuter',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$probe = $wpdb->prefix . 'tpfwli_outer_' . wp_generate_password(8, false, false);
		$wpdb->query("CREATE TABLE `{$probe}` (`id` INT PRIMARY KEY) ENGINE=InnoDB");
		$wpdb->query('START TRANSACTION');
		$wpdb->query("INSERT INTO `{$probe}` (`id`) VALUES (1)");
		try {
			$this->assertSame('1', $this->sessionInTransaction());
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertSame('1', $this->sessionInTransaction());
			$this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$probe}`"));
			$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
			$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		} finally {
			$wpdb->query('ROLLBACK');
			$wpdb->query("DROP TABLE IF EXISTS `{$probe}`");
		}
		$this->assertSame('0', $this->sessionInTransaction());
	}

	public function test_failed_transaction_state_check_does_not_commit_an_outer_transaction(): void
	{
		global $wpdb;
		$input = $this->valid_input(array(
			'email'      => 'txn-state-err@example.com',
			'first_name' => 'TxnStateErr',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$probe = $wpdb->prefix . 'tpfwli_state_' . wp_generate_password(8, false, false);
		$wpdb->query("CREATE TABLE `{$probe}` (`id` INT PRIMARY KEY) ENGINE=InnoDB");
		$wpdb->query('START TRANSACTION');
		$wpdb->query("INSERT INTO `{$probe}` (`id`) VALUES (1)");
		$filter = static function ($sql) {
			if (!is_string($sql)) {
				return $sql;
			}
			if (str_contains($sql, 'in_transaction') || str_contains($sql, 'INNODB_TRX') || str_contains($sql, 'events_transactions_current')) {
				return 'SELECT tpfwli_missing_txn_state FROM dual';
			}
			return $sql;
		};
		add_filter('query', $filter, 999);
		$wpdb->suppress_errors(true);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_filter('query', $filter, 999);
			$wpdb->suppress_errors(false);
		}
		$this->assertFalse($result['ok']);
		$this->assertSame('1', $this->sessionInTransaction());
		$this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$probe}`"));
		$wpdb->query('ROLLBACK');
		$this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$probe}`"));
		$wpdb->query("DROP TABLE IF EXISTS `{$probe}`");
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
	}

	public function test_stale_order_item_cache_after_rollback_does_not_block_retry(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-item-cache@example.com',
			'first_name' => 'StockItemCache',
			'quantity'   => '2',
		));
		$order = $this->readyOrderForStock($input);
		$order_id = (int) $order->get_id();
		$item_id = (int) array_values($order->get_items('line_item'))[0]->get_id();
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$crash = static function (string $point) use ($item_id): void {
			if ($point !== 'after_line_meta') {
				return;
			}
			get_metadata('order_item', $item_id, '_reduced_stock', true);
			throw new RuntimeException('stock-checkpoint:after_line_meta');
		};
		add_action('tpfwli_stock_checkpoint', $crash, 10, 1);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_action('tpfwli_stock_checkpoint', $crash, 10);
		}
		$this->assertFalse($result['ok']);
		$this->assertNull($this->db_line_reduced_stock($order_id));
		$this->assertSame('', (string) get_metadata('order_item', $item_id, '_reduced_stock', true));
		$fresh = wc_get_order($order_id);
		$item = array_values($fresh->get_items('line_item'))[0];
		$this->assertFalse($item->meta_exists('_reduced_stock'));
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(0, $this->count_tickets_for_order($order_id));
		if (wp_using_ext_object_cache()) {
			$cached = wp_cache_get($item_id, 'order_item_meta');
			$this->assertTrue($cached === false || empty($cached['_reduced_stock']));
		}
		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock($order_id));
	}

	public function test_concurrent_sale_after_rollback_is_not_treated_as_rollback_failure(): void
	{
		if (!function_exists('proc_open')) {
			$this->markTestSkipped('proc_open is not available');
		}
		$input = $this->valid_input(array(
			'email'      => 'stock-race-rollback@example.com',
			'first_name' => 'StockRaceRb',
			'quantity'   => '2',
		));
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$dir = sys_get_temp_dir() . '/tpfwli-rb-' . wp_generate_uuid4();
		$this->assertTrue(mkdir($dir, 0700, true));
		$ready = $dir . '/ready';
		$go    = $dir . '/go';
		try {
			$holder = $this->startJsonWorker(
				dirname(__DIR__) . '/bin/stock-crash-worker.php',
				array(
					'input'      => $input,
					'checkpoint' => 'after_product_stock',
					'abort'      => 'throw',
					'ready_file' => $ready,
					'wait_file'  => $go,
				)
			);
			$deadline = microtime(true) + 10;
			while (!is_file($ready) && microtime(true) < $deadline) {
				usleep(20000);
			}
			$this->assertFileExists($ready);
			$contender = $this->startJsonWorker(
				dirname(__DIR__) . '/bin/stock-contender-worker.php',
				array(
					'product_id' => self::$ticket_id,
					'quantity'   => 1,
					'ready_file' => $ready,
				)
			);
			usleep(300000);
			file_put_contents($go, '1');
			$holder_out = $this->finishJsonWorker($holder);
			$sale_out   = $this->finishJsonWorker($contender);
		} finally {
			@unlink($ready);
			@unlink($go);
			@rmdir($dir);
		}
		$this->assertIsArray($holder_out);
		$this->assertFalse(!empty($holder_out['ok']));
		$this->assertStringNotContainsString('did not restore product stock', implode(' ', $holder_out['errors'] ?? array()));
		$this->assertIsArray($sale_out);
		$this->assertSame($start_stock - 1, $this->db_product_stock(self::$ticket_id));
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$this->assertInstanceOf(WC_Order::class, $found['order']);
		$this->assertNull($this->db_line_reduced_stock((int) $found['order']->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $found['order']->get_id()));
		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		$this->assertSame($start_stock - 3, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock((int) $retry['order']->get_id()));
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

	public function test_cancelled_order_after_stock_is_not_resumed_by_any_mode(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-cancel@example.com',
			'first_name' => 'LifeCancel',
			'quantity'   => '2',
		));
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$blocker = static function () {
			return false;
		};
		add_filter('tpfwli_allow_force_issue', $blocker);
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		remove_filter('tpfwli_allow_force_issue', $blocker);
		$this->assertFalse($first['ok']);
		$order = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$order->update_status('cancelled', 'test cancel after stock', true);
		$this->assertSame('cancelled', wc_get_order($order->get_id())->get_status());
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertLifecycleBlocked($input, $order, $start_stock, $this->count_import_orders(), 'cancelled');
	}

	public function test_failed_refunded_and_trash_orders_are_not_resumed(): void
	{
		foreach (array('failed' => 'failed', 'refunded' => 'refund', 'trash' => 'trash') as $status => $needle) {
			$input = $this->valid_input(array(
				'email'      => 'life-' . $status . '@example.com',
				'first_name' => 'Life' . ucfirst($status),
				'quantity'   => '1',
			));
			$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertTrue($first['ok'], $status . ' setup: ' . implode('; ', $first['errors']));
			$order = $first['order'];
			$this->assertInstanceOf(WC_Order::class, $order);
			$order_id = (int) $order->get_id();
			if ($status === 'trash') {
				$order->delete(false);
			} else {
				$order->update_status($status, 'test ' . $status, true);
			}
			$fresh = wc_get_order($order_id);
			if (!$fresh instanceof WC_Order && $status === 'trash') {
				$fresh = $order;
			}
			$this->assertInstanceOf(WC_Order::class, $fresh);
			$this->assertSame($status, $fresh->get_status(), $status);
			self::$mail = array();
			$this->assertLifecycleBlocked($input, $fresh, $this->db_product_stock(self::$ticket_id), $this->count_import_orders(), $needle);
		}
	}

	public function test_partial_refund_blocks_all_resume_modes(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-partial-refund@example.com',
			'first_name' => 'LifePartial',
			'quantity'   => '2',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$refund = wc_create_refund(array(
			'order_id'       => $order->get_id(),
			'amount'         => '1',
			'reason'         => 'test partial refund',
			'refund_payment' => false,
			'restock_items'  => false,
		));
		$this->assertInstanceOf(WC_Order_Refund::class, $refund);
		self::$mail = array();
		$this->assertLifecycleBlocked($input, wc_get_order($order->get_id()), $start_stock, $this->count_import_orders(), 'refund');
	}

	public function test_custom_order_status_is_not_automatically_resumable(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-custom@example.com',
			'first_name' => 'LifeCustom',
			'quantity'   => '1',
		));
		$register = static function (array $statuses): array {
			$statuses['wc-tpfwli-custom'] = 'TPFWLI custom';
			return $statuses;
		};
		add_filter('wc_order_statuses', $register);
		try {
			$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertTrue($first['ok'], implode('; ', $first['errors']));
			$order = $first['order'];
			$this->assertInstanceOf(WC_Order::class, $order);
			$order->update_status('tpfwli-custom', 'test custom status', true);
			wp_cache_flush();
			$fresh = wc_get_order($order->get_id());
			$this->assertSame('tpfwli-custom', $fresh->get_status());
			global $wpdb;
			$stored = (string) $wpdb->get_var($wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d",
				$order->get_id()
			));
			$this->assertTrue(
				in_array($stored, array('tpfwli-custom', 'wc-tpfwli-custom'), true),
				'stored status ' . $stored
			);
			$life = TPFWLI_Order_Lifecycle::assess($fresh);
			$this->assertFalse($life['ok'], wp_json_encode($life));
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertInstanceOf(WC_Order::class, $found['order']);
			$this->assertSame((int) $order->get_id(), (int) $found['order']->get_id());
			$this->assertSame('tpfwli-custom', $found['order']->get_status());
			self::$mail = array();
			$this->assertLifecycleBlocked($input, $fresh, $this->db_product_stock(self::$ticket_id), $this->count_import_orders(), 'tpfwli-custom');
		} finally {
			remove_filter('wc_order_statuses', $register);
		}
	}

	public function test_hook_cancel_between_stock_and_issue_stops_without_tickets(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-hook-stock@example.com',
			'first_name' => 'LifeHookStock',
			'quantity'   => '2',
		));
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$cancel = static function (string $point, $order) {
			if ($point !== 'after_commit' || !$order instanceof WC_Order) {
				return;
			}
			$fresh = wc_get_order($order->get_id());
			if ($fresh instanceof WC_Order) {
				$fresh->update_status('cancelled', 'hook cancel after stock', true);
			}
		};
		add_action('tpfwli_stock_checkpoint', $cancel, 10, 2);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_action('tpfwli_stock_checkpoint', $cancel, 10);
		}
		$this->assertFalse($result['ok']);
		$order = $result['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame('cancelled', wc_get_order($order->get_id())->get_status());
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
	}

	public function test_hook_cancel_after_issued_stops_before_completed_and_email(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-hook-issued@example.com',
			'first_name' => 'LifeHookIssued',
			'quantity'   => '2',
		));
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$cancel = static function (string $point, $order) {
			if ($point !== 'after_issued' || !$order instanceof WC_Order) {
				return;
			}
			$fresh = wc_get_order($order->get_id());
			if ($fresh instanceof WC_Order) {
				$fresh->update_status('cancelled', 'hook cancel after issued', true);
			}
		};
		add_action('tpfwli_lifecycle_checkpoint', $cancel, 10, 2);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_action('tpfwli_lifecycle_checkpoint', $cancel, 10);
		}
		$this->assertFalse($result['ok']);
		$order = $result['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$fresh = wc_get_order($order->get_id());
		$this->assertSame('cancelled', $fresh->get_status());
		$this->assertSame('issued', (string) $fresh->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$this->assertSame('not_sent', (string) $fresh->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
	}

	public function test_hook_cancel_after_completed_stops_before_email(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-hook-completed@example.com',
			'first_name' => 'LifeHookCompleted',
			'quantity'   => '1',
		));
		$cancel = static function (string $point, $order) {
			if ($point !== 'after_completed' || !$order instanceof WC_Order) {
				return;
			}
			$fresh = wc_get_order($order->get_id());
			if ($fresh instanceof WC_Order) {
				$fresh->update_status('cancelled', 'hook cancel after completed', true);
			}
		};
		add_action('tpfwli_lifecycle_checkpoint', $cancel, 10, 2);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_action('tpfwli_lifecycle_checkpoint', $cancel, 10);
		}
		$this->assertFalse($result['ok']);
		$order = $result['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame('cancelled', wc_get_order($order->get_id())->get_status());
		$this->assertSame('issued', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$this->assertSame('not_sent', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_abort_after_issued_can_be_finished_without_new_stock_or_tickets(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-after-issued@example.com',
			'first_name' => 'LifeAfterIssued',
			'quantity'   => '2',
		));
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$abort = static function (string $point) {
			if ($point === 'after_issued') {
				throw new RuntimeException('lifecycle-checkpoint:after_issued');
			}
		};
		add_action('tpfwli_lifecycle_checkpoint', $abort, 10, 1);
		try {
			(new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->fail('confirm should abort after issued');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('after_issued', $e->getMessage());
		} finally {
			remove_action('tpfwli_lifecycle_checkpoint', $abort, 10);
		}
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$order = $found['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame('pending', $order->get_status());
		$this->assertSame('issued', (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$this->assertSame('not_sent', (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$nanos = (new TPFWLI_Orchestrator())->inspect($order)['nanos'];
		$this->assertCount(2, $nanos);
		$html = $this->adminResultHtml($order);
		$this->assertStringContainsString('tpfwli_resume', $html);
		$this->assertStringContainsString('Finish issued import', $html);
		$this->assertStringNotContainsString('tpfwli_retry_issue', $html);
		(new TPFWLI_Admin_Page())->register();
		$this->assertTrue((bool) has_action('admin_post_tpfwli_resume'));

		self::$mail = array();
		$resume = $this->postAdminResume($input);
		$this->assertTrue(!empty($resume['ok']), implode('; ', $resume['errors'] ?? array()));
		$fresh = wc_get_order($order->get_id());
		$this->assertSame('completed', $fresh->get_status());
		$this->assertSame($nanos, $resume['nanos']);
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));

		self::$mail = array();
		$again = (new TPFWLI_Orchestrator())->run($input, 'resume');
		$this->assertTrue(!empty($again['ok']) || !empty($again['email_already']));
		$this->assertSame($nanos, $again['nanos']);
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
	}

	public function test_abort_after_completed_before_email_is_resumable(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-after-completed@example.com',
			'first_name' => 'LifeAfterCompleted',
			'quantity'   => '1',
		));
		$start_stock = $this->db_product_stock(self::$ticket_id);
		$abort = static function (string $point) {
			if ($point === 'after_completed') {
				throw new RuntimeException('lifecycle-checkpoint:after_completed');
			}
		};
		add_action('tpfwli_lifecycle_checkpoint', $abort, 10, 1);
		try {
			(new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->fail('confirm should abort after completed');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('after_completed', $e->getMessage());
		} finally {
			remove_action('tpfwli_lifecycle_checkpoint', $abort, 10);
		}
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$order = $found['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame('completed', $order->get_status());
		$this->assertSame('issued', (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE));
		$this->assertSame('not_sent', (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$html = $this->adminResultHtml($order);
		$this->assertStringContainsString('tpfwli_resume', $html);
		self::$mail = array();
		$resume = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
		$this->assertTrue($resume['ok'], implode('; ', $resume['errors']));
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));
		$this->assertSame($start_stock - 1, $this->db_product_stock(self::$ticket_id));
		$this->assertSame('sent', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
	}

	public function test_cancelled_result_page_explains_block_and_resume_post_is_refused(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-admin-block@example.com',
			'first_name' => 'LifeAdminBlock',
			'quantity'   => '1',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		$order->update_status('cancelled', 'test admin block', true);
		$html = $this->adminResultHtml(wc_get_order($order->get_id()));
		$this->assertStringContainsString('cannot be resumed', $html);
		$this->assertStringNotContainsString('name="action" value="tpfwli_resume"', $html);
		$this->assertStringNotContainsString('name="action" value="tpfwli_retry_issue"', $html);
		$this->assertStringNotContainsString('name="action" value="tpfwli_retry_email"', $html);
		self::$mail = array();
		$posted = $this->postAdminResume($input);
		$this->assertFalse(!empty($posted['ok']));
		$this->assertStringContainsString('cancelled', implode(' ', $posted['errors'] ?? array()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));

		wp_set_current_user(self::$subscriber_id);
		$_POST = $input;
		$_POST['action'] = 'tpfwli_resume';
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce('tpfwli_resume');
		$this->installWpDieThrower();
		try {
			(new TPFWLI_Admin_Page())->handle_resume();
			$this->fail('subscriber resume POST should be refused');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('wp_die:', $e->getMessage());
		} finally {
			wp_set_current_user(self::$admin_id);
			unset($_POST, $_REQUEST['_wpnonce']);
		}
	}

	public function test_deferred_queue_after_sent_does_not_duplicate_customer_email(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'queue-after-sent@example.com',
			'first_name' => 'QueueAfterSent',
		));
		$import = $this->runDeferredImportWorker($input, array('capture_mail' => true));
		$this->assertTrue(!empty($import['ok']), implode('; ', $import['errors'] ?? array()));
		$this->assertSame('sent', $import['email_stage']);
		$this->assertSame(1, (int) $import['mail_for_recipient']);
		$this->assertNotEmpty($import['queued']);
		$stock = $this->db_product_stock(self::$ticket_id);
		$tickets = $this->count_tickets_for_order((int) $import['order_id']);
		$queue = $this->runQueuedEmailWorker((int) $import['order_id'], true);
		$this->assertSame($import['pid'] === $queue['pid'] ? 0 : $queue['pid'], (int) $queue['pid']);
		$this->assertNotSame((int) $import['pid'], (int) $queue['pid']);
		$this->assertSame('sent', $queue['email_stage']);
		$this->assertQueuedJobsRan($import['queued'], $queue);
		$this->assertSame(0, (int) $queue['mail_for_recipient'], implode(' | ', (array) ($queue['recipient_subjects'] ?? array())));
		$this->assertSame($stock, $this->db_product_stock(self::$ticket_id));
		$this->assertSame($tickets, $this->count_tickets_for_order((int) $import['order_id']));

		self::$mail = array();
		$retry = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
		$this->assertTrue(!empty($retry['ok']) || !empty($retry['email_already']));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));

		$redeliver_id = tpfwli_test_enqueue_completed_notification((int) $import['order_id']);
		$this->assertGreaterThan(0, $redeliver_id);
		$again = $this->runQueuedEmailWorker((int) $import['order_id'], true);
		$this->assertContains($redeliver_id, array_map('intval', (array) ($again['ran'] ?? array())));
		$this->assertSame(0, (int) $again['mail_for_recipient']);
		$this->assertSame('sent', $again['email_stage']);
	}

	public function test_queued_complete_before_importer_send_is_suppressed(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'queue-before-send@example.com',
			'first_name' => 'QueueBeforeSend',
		));
		$import = $this->runDeferredImportWorker($input, array(
			'capture_mail' => true,
			'abort'        => 'after_completed',
		));
		$this->assertSame('not_sent', $import['email_stage']);
		$this->assertSame('issued', $import['issue_stage']);
		$this->assertSame(0, (int) $import['mail_count']);
		$queue = $this->runQueuedEmailWorker((int) $import['order_id'], true);
		$this->assertSame('not_sent', $queue['email_stage']);
		$this->assertQueuedJobsRan($import['queued'], $queue);
		$this->assertSame(0, (int) $queue['mail_for_recipient']);
		$finish = $this->runDeferredImportWorker($input, array(
			'mode'         => 'resume',
			'capture_mail' => true,
		));
		$this->assertTrue(!empty($finish['ok']), implode('; ', $finish['errors'] ?? array()));
		$this->assertSame('sent', $finish['email_stage']);
		$this->assertSame(1, (int) $finish['mail_for_recipient']);
	}

	public function test_abort_after_mailer_accepted_does_not_autoresend(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'abort-after-accepted@example.com',
			'first_name' => 'AbortAccepted',
		));
		$import = $this->runDeferredImportWorker($input, array(
			'capture_mail' => true,
			'abort'        => 'after_accepted_before_sent',
		));
		$this->assertSame('sending', $import['email_stage']);
		$this->assertSame(1, (int) $import['mail_for_recipient']);
		$queue = $this->runQueuedEmailWorker((int) $import['order_id'], true);
		$this->assertQueuedJobsRan($import['queued'], $queue);
		$this->assertSame(0, (int) $queue['mail_for_recipient']);
		$this->assertSame('sending', (string) wc_get_order((int) $import['order_id'])->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		self::$mail = array();
		$retry = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
		$this->assertTrue(!empty($retry['email_unknown']));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_allow_window_is_limited_to_completed_email_on_that_order(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'allow-window@example.com',
			'first_name' => 'AllowWindow',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		$other_input = $this->valid_input(array(
			'email'      => 'allow-window-other@example.com',
			'first_name' => 'AllowOther',
		));
		$other = (new TPFWLI_Orchestrator())->run($other_input, 'confirm');
		$this->assertTrue($other['ok'], implode('; ', $other['errors']));
		$svc = TPFWLI_Email_Service::instance();
		$mailer = WC()->mailer();
		$completed = $mailer->emails['WC_Email_Customer_Completed_Order'];
		$processing = $mailer->emails['WC_Email_Customer_Processing_Order'];
		$ref = new ReflectionClass($svc);
		$allow_id = $ref->getProperty('allow_order_id');
		$allow_email = $ref->getProperty('allow_email_id');
		$allow_id->setValue($svc, (int) $order->get_id());
		$allow_email->setValue($svc, TPFWLI_Email_Service::COMPLETED_EMAIL_ID);
		try {
			$this->assertTrue($svc->filter_enabled(false, $order, $completed));
			$this->assertFalse($svc->filter_enabled(true, $order, $processing));
			$this->assertFalse($svc->filter_enabled(true, $other['order'], $completed));
		} finally {
			$allow_id->setValue($svc, 0);
			$allow_email->setValue($svc, '');
		}
	}

	public function test_failed_order_reread_does_not_reopen_import_email(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'reread-fail@example.com',
			'first_name' => 'RereadFail',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame('sent', (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		$this->assertSame(1, $this->customerMailCount((string) $input['email']));

		$completed = WC()->mailer()->emails['WC_Email_Customer_Completed_Order'];
		$svc = TPFWLI_Email_Service::instance();
		$ref = new ReflectionClass($svc);
		$allow_id = $ref->getProperty('allow_order_id');
		$allow_email = $ref->getProperty('allow_email_id');
		$allow_id->setValue($svc, (int) $order->get_id());
		$allow_email->setValue($svc, TPFWLI_Email_Service::COMPLETED_EMAIL_ID);
		$this->poisonOrderCache((int) $order->get_id());
		try {
			$this->assertFalse($svc->filter_enabled(true, $order, $completed));
			self::$mail = array();
			$completed->trigger((int) $order->get_id(), $order);
			$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		} finally {
			$allow_id->setValue($svc, 0);
			$allow_email->setValue($svc, '');
			$this->forgetOrderCache((int) $order->get_id());
		}
		$this->assertSame('sent', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));

		$blank = new WC_Order();
		$blank->set_id((int) $order->get_id());
		$blank->set_created_via('');
		$blank->update_meta_data(TPFWLI_Plugin::META_IMPORT, '');
		$blank->update_meta_data(TPFWLI_Plugin::META_IMPORT_ID, '');
		$cache = wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class);
		$cache->remove((int) $order->get_id());
		$cache->set($blank, (int) $order->get_id());
		try {
			$this->assertFalse($svc->filter_enabled(true, $order, $completed));
			self::$mail = array();
			$completed->trigger((int) $order->get_id(), $order);
			$this->assertSame(0, $this->customerMailCount((string) $input['email']));
		} finally {
			$this->forgetOrderCache((int) $order->get_id());
		}
		$this->assertSame('sent', (string) wc_get_order($order->get_id())->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));

		self::$mail = array();
		$web = wc_create_order();
		$web->set_billing_email('web-reread-control@example.com');
		$web->set_billing_first_name('WebReread');
		$web->add_product(wc_get_product(self::$simple_id), 1);
		$web->calculate_totals();
		$web->save();
		$this->poisonOrderCache((int) $web->get_id());
		try {
			$completed->trigger((int) $web->get_id(), $web);
			$this->assertSame(1, $this->customerMailCount('web-reread-control@example.com'));
		} finally {
			$this->forgetOrderCache((int) $web->get_id());
		}
	}

	public function test_queued_email_selection_is_exact_and_paged(): void
	{
		$created = array();
		try {
			for ($i = 0; $i < 105; $i++) {
				$created[] = tpfwli_test_enqueue_completed_notification(900000 + $i);
			}
			$job_85 = (int) WC()->queue()->add(
				'woocommerce_send_queued_transactional_email',
				array(
					'woocommerce_order_status_completed',
					array(
						85,
						array(
							'__woocommerce_deferred_email_object' => array(
								'type' => 'order',
								'id'   => 85,
							),
						),
					),
				),
				'woocommerce-emails'
			);
			$job_185 = (int) WC()->queue()->add(
				'woocommerce_send_queued_transactional_email',
				array(
					'woocommerce_order_status_completed',
					array(
						185,
						array(
							'__woocommerce_deferred_email_object' => array(
								'type' => 'order',
								'id'   => 185,
							),
						),
					),
				),
				'woocommerce-emails'
			);
			$job_product = (int) WC()->queue()->add(
				'woocommerce_send_queued_transactional_email',
				array(
					'woocommerce_low_stock',
					array(
						array(
							'__woocommerce_deferred_email_object' => array(
								'type' => 'product',
								'id'   => 85,
							),
						),
					),
				),
				'woocommerce-emails'
			);
			$job_text = (int) WC()->queue()->add(
				'woocommerce_send_queued_transactional_email',
				array(
					'woocommerce_new_customer_note',
					array(
						array(
							'order_id'      => 999001,
							'customer_note' => 'mentions 85 and 185 in free text',
						),
					),
				),
				'woocommerce-emails'
			);
			$created = array_merge($created, array($job_85, $job_185, $job_product, $job_text));
			$matched_85 = tpfwli_test_pending_queued_emails_for_order(85);
			$matched_185 = tpfwli_test_pending_queued_emails_for_order(185);
			$ids_85 = array_column($matched_85, 'id');
			$ids_185 = array_column($matched_185, 'id');
			$this->assertContains($job_85, $ids_85);
			$this->assertContains($job_185, $ids_185);
			$this->assertNotContains($job_185, $ids_85);
			$this->assertNotContains($job_85, $ids_185);
			$this->assertNotContains($job_product, $ids_85);
			$this->assertNotContains($job_text, $ids_85);
			$this->assertNotContains($created[85], $ids_85);
			$this->assertSame(array(), tpfwli_test_run_queued_email_jobs($matched_185, 85));
			$store = ActionScheduler::store();
			$this->assertSame(ActionScheduler_Store::STATUS_PENDING, $store->get_status($job_185));
		} finally {
			tpfwli_test_cancel_queued_email_jobs($created);
		}
	}

	public function test_queued_jobs_for_another_order_are_not_run(): void
	{
		$first = (new TPFWLI_Orchestrator())->run($this->valid_input(array(
			'email'      => 'queue-owner@example.com',
			'first_name' => 'QueueOwner',
		)), 'confirm');
		$other = (new TPFWLI_Orchestrator())->run($this->valid_input(array(
			'email'      => 'queue-other@example.com',
			'first_name' => 'QueueOther',
		)), 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$this->assertTrue($other['ok'], implode('; ', $other['errors']));
		$owner_id = (int) $first['order']->get_id();
		$other_id = (int) $other['order']->get_id();
		$owner_job = tpfwli_test_enqueue_completed_notification($owner_id);
		$other_job = tpfwli_test_enqueue_completed_notification($other_id);
		$this->assertGreaterThan(0, $owner_job);
		$this->assertGreaterThan(0, $other_job);
		$other_stage = (string) $other['order']->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE);
		$queue = $this->runQueuedEmailWorker($owner_id, true);
		$this->assertContains($owner_job, array_map('intval', (array) ($queue['ran'] ?? array())));
		$this->assertNotContains($other_job, array_map('intval', (array) ($queue['ran'] ?? array())));
		$this->assertSame(0, (int) $queue['mail_for_recipient']);
		$store = ActionScheduler::store();
		$this->assertSame(ActionScheduler_Store::STATUS_PENDING, $store->get_status($other_job));
		$this->assertSame($other_stage, (string) wc_get_order($other_id)->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE));
		tpfwli_test_cancel_queued_email_jobs(array($other_job));
	}

	public function test_regular_web_order_and_later_refund_cancel_still_email(): void
	{
		self::$mail = array();
		$web = wc_create_order();
		$web->set_billing_email('web-order-step4@example.com');
		$web->set_billing_first_name('Web');
		$web->add_product(wc_get_product(self::$simple_id), 1);
		$web->calculate_totals();
		$web->save();
		$web->update_status('completed', 'web order complete', true);
		$this->assertSame(1, $this->customerMailCount('web-order-step4@example.com'));

		$input = $this->valid_input(array(
			'email'      => 'later-refund-cancel@example.com',
			'first_name' => 'LaterRefund',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		self::$mail = array();
		$refund = wc_create_refund(array(
			'order_id'       => $order->get_id(),
			'amount'         => (string) $order->get_total(),
			'reason'         => 'step4 later refund',
			'refund_payment' => false,
			'restock_items'  => false,
		));
		$this->assertInstanceOf(WC_Order_Refund::class, $refund);
		$this->assertGreaterThanOrEqual(1, $this->customerMailCount((string) $input['email']));

		$input2 = $this->valid_input(array(
			'email'      => 'later-cancel@example.com',
			'first_name' => 'LaterCancel',
		));
		$second = (new TPFWLI_Orchestrator())->run($input2, 'confirm');
		$this->assertTrue($second['ok'], implode('; ', $second['errors']));
		$cancel_mail = WC()->mailer()->emails['WC_Email_Customer_Cancelled_Order'] ?? null;
		if (is_object($cancel_mail) && method_exists($cancel_mail, 'enable')) {
			$cancel_mail->enable();
		}
		$svc = TPFWLI_Email_Service::instance();
		if (is_object($cancel_mail)) {
			$this->assertTrue($svc->filter_enabled(true, $second['order'], $cancel_mail));
		}
		self::$mail = array();
		$second['order']->update_status('cancelled', 'step4 later cancel', true);
		if (is_object($cancel_mail) && !empty($cancel_mail->is_enabled())) {
			$this->assertGreaterThanOrEqual(1, $this->customerMailCount((string) $input2['email']));
		}
	}

	public function test_manual_send_order_details_still_works(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'manual-details@example.com',
			'first_name' => 'ManualDetails',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = wc_get_order($first['order']->get_id());
		self::$mail = array();
		$_POST['wc_order_action'] = 'send_order_details';
		try {
			$invoice = WC()->mailer()->emails['WC_Email_Customer_Invoice'] ?? null;
			$this->assertIsObject($invoice);
			$invoice->trigger($order->get_id(), $order);
			$this->assertSame(1, $this->customerMailCount((string) $input['email']));
		} finally {
			unset($_POST['wc_order_action']);
		}
	}

	public function test_host_mailpit_receives_one_completed_import_email(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'mailpit-' . wp_generate_uuid4() . '@example.com',
			'first_name' => 'MailpitReal',
		));
		$before = tpfwli_test_mailpit_messages_for_recipient('http://127.0.0.1:8025', (string) $input['email']);
		$import = $this->runDeferredImportWorker($input, array('capture_mail' => false));
		$this->assertTrue(!empty($import['ok']), implode('; ', $import['errors'] ?? array()));
		$queue = $this->runQueuedEmailWorker((int) $import['order_id'], false);
		$this->assertQueuedJobsRan($import['queued'], $queue);
		$after = tpfwli_test_mailpit_messages_for_recipient('http://127.0.0.1:8025', (string) $input['email']);
		$this->assertSame($before['count'] + 1, $after['count'], wp_json_encode($after['subjects']));
		$found = false;
		foreach ((array) ($after['messages'] ?? array()) as $msg) {
			$id = (string) ($msg['ID'] ?? '');
			if ($id === '') {
				continue;
			}
			$raw = @file_get_contents('http://127.0.0.1:8025/api/v1/message/' . rawurlencode($id));
			$data = is_string($raw) ? json_decode($raw, true) : null;
			$body = is_array($data) ? strtolower((string) ($data['Text'] ?? '') . (string) ($data['HTML'] ?? '')) : '';
			if (
				str_contains($body, strtolower((string) $input['email']))
				&& (str_contains($body, (string) $import['order_id']) || str_contains(strtolower((string) ($msg['Subject'] ?? '')), (string) $import['order_id']))
				&& (str_contains($body, strtolower((string) ($import['nanos'][0] ?? 'nope'))) || str_contains($body, 'qr') || str_contains($body, 'biljett') || str_contains($body, 'ticket'))
			) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Mailpit message missing recipient, order or ticket content');
	}

	public function test_identity_lookup_refuses_live_and_trash_duplicates(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-trash-conflict@example.com',
			'first_name' => 'LifeTrashConflict',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$alive = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $alive);
		$twin = new WC_Order();
		$twin->set_created_via(TPFWLI_Plugin::created_via($input['import_id']));
		$twin->set_status('pending');
		$twin->set_billing_email((string) $input['email']);
		$twin_id = (int) $twin->save();
		$this->assertGreaterThan(0, $twin_id);
		$twin->update_meta_data(TPFWLI_Plugin::META_IMPORT_ID, $input['import_id']);
		$twin->update_meta_data(TPFWLI_Plugin::META_IMPORT, 'yes');
		$twin->save();
		$twin->delete(false);
		$this->assertSame('trash', wc_get_order($twin_id)->get_status());
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$this->assertFalse($found['ok']);
		$this->assertNull($found['order']);
		$this->assertStringContainsString('more than one', $found['error']);
		$before = $this->count_import_orders();
		self::$mail = array();
		$again = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($again['ok']);
		$this->assertSame($before, $this->count_import_orders());
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_unregistered_created_via_only_order_is_not_replaced(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-ghost-via@example.com',
			'first_name' => 'LifeGhostVia',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$order_id = (int) $order->get_id();
		$register = static function (array $statuses): array {
			$statuses['wc-tpfwli-ghost'] = 'TPFWLI ghost';
			return $statuses;
		};
		add_filter('wc_order_statuses', $register);
		try {
			$order->update_status('tpfwli-ghost', 'test ghost via', true);
		} finally {
			remove_filter('wc_order_statuses', $register);
		}
		$order->delete_meta_data(TPFWLI_Plugin::META_IMPORT_ID);
		$order->save();
		wp_cache_flush();
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$this->assertTrue($found['ok'], (string) $found['error']);
		$this->assertInstanceOf(WC_Order::class, $found['order']);
		$this->assertSame($order_id, (int) $found['order']->get_id());
		$ids_before = $this->db_import_order_ids($input['import_id']);
		self::$mail = array();
		$again = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertFalse($again['ok']);
		$this->assertSame($order_id, $again['order'] instanceof WC_Order ? (int) $again['order']->get_id() : $order_id);
		$this->assertSame($ids_before, $this->db_import_order_ids($input['import_id']));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	public function test_identity_id_that_cannot_be_loaded_is_a_read_error(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-unloadable@example.com',
			'first_name' => 'LifeUnloadable',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order_id = (int) $first['order']->get_id();
		$this->forgetOrderCache($order_id);
		$blocker = static function ($class, $type, $id) use ($order_id) {
			if ((int) $id === $order_id) {
				return 'TPFWLI_Missing_Order_Class';
			}
			return $class;
		};
		add_filter('woocommerce_order_class', $blocker, 10, 3);
		try {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$this->assertNull($found['order']);
			$this->assertStringContainsString('import ID', $found['error']);
			$ids_before = $this->db_import_order_ids($input['import_id']);
			$again = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($again['ok']);
			$this->assertNull($again['order']);
			$this->assertSame($ids_before, $this->db_import_order_ids($input['import_id']));
		} finally {
			remove_filter('woocommerce_order_class', $blocker, 10);
		}
	}

	public function test_zero_amount_refund_blocks_all_resume_modes(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-zero-refund@example.com',
			'first_name' => 'LifeZeroRefund',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		$refund = wc_create_refund(array(
			'order_id'       => $order->get_id(),
			'amount'         => '0',
			'reason'         => 'test zero refund',
			'refund_payment' => false,
			'restock_items'  => false,
		));
		$this->assertInstanceOf(WC_Order_Refund::class, $refund);
		self::$mail = array();
		$this->assertLifecycleBlocked($input, wc_get_order($order->get_id()), $this->db_product_stock(self::$ticket_id), $this->count_import_orders(), 'refund');
	}

	public function test_refund_read_error_blocks_all_resume_modes_after_followup_sql(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'life-refund-read@example.com',
			'first_name' => 'LifeRefundRead',
		));
		$first = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($first['ok'], implode('; ', $first['errors']));
		$order = $first['order'];
		$refund = wc_create_refund(array(
			'order_id'       => $order->get_id(),
			'amount'         => '1',
			'reason'         => 'test refund read error',
			'refund_payment' => false,
			'restock_items'  => false,
		));
		$this->assertInstanceOf(WC_Order_Refund::class, $refund);
		$order_id = (int) $order->get_id();
		$fail = $this->failRefundQueries($order_id);
		$mask = static function ($results) {
			global $wpdb;
			$wpdb->get_var('SELECT 1');
			return $results;
		};
		add_filter('woocommerce_order_query', $mask, 10, 2);
		try {
			$fresh = wc_get_order($order_id);
			$this->assertInstanceOf(WC_Order::class, $fresh);
			$state = TPFWLI_Order_Lifecycle::refund_state($fresh);
			$this->assertFalse($state['ok']);
			$this->assertStringContainsString('refund status', $state['error']);
			self::$mail = array();
			$this->assertLifecycleBlocked($input, $fresh, $this->db_product_stock(self::$ticket_id), $this->count_import_orders(), 'refund status');
		} finally {
			remove_filter('query', $fail, 999);
			remove_filter('woocommerce_order_query', $mask, 10);
		}
	}

	public function test_after_completed_pending_or_processing_does_not_send_email(): void
	{
		foreach (array('pending', 'processing') as $status) {
			$input = $this->valid_input(array(
				'email'      => 'life-demote-' . $status . '@example.com',
				'first_name' => 'LifeDemote' . ucfirst($status),
				'quantity'   => '1',
			));
			$hook = static function (string $point, $order) use ($status) {
				if ($point !== 'after_completed' || !$order instanceof WC_Order) {
					return;
				}
				$fresh = wc_get_order($order->get_id());
				if ($fresh instanceof WC_Order) {
					$fresh->update_status($status, 'test demote ' . $status, true);
				}
			};
			add_action('tpfwli_lifecycle_checkpoint', $hook, 10, 2);
			try {
				$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			} finally {
				remove_action('tpfwli_lifecycle_checkpoint', $hook, 10);
			}
			$this->assertFalse($result['ok'], $status);
			$order = $result['order'];
			$this->assertInstanceOf(WC_Order::class, $order, $status);
			$fresh = wc_get_order($order->get_id());
			$this->assertSame($status, $fresh->get_status(), $status);
			$this->assertSame('issued', (string) $fresh->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE), $status);
			$this->assertSame('not_sent', (string) $fresh->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE), $status);
			$this->assertSame(0, $this->customerMailCount((string) $input['email']), $status);
			$stock_after = $this->db_product_stock(self::$ticket_id);
			$this->assertSame(1, $this->count_tickets_for_order((int) $order->get_id()), $status);

			self::$mail = array();
			$resume_hook = static function (string $point, $order) use ($status) {
				if ($point !== 'after_completed' || !$order instanceof WC_Order) {
					return;
				}
				$fresh = wc_get_order($order->get_id());
				if ($fresh instanceof WC_Order) {
					$fresh->update_status($status, 'test demote resume ' . $status, true);
				}
			};
			add_action('tpfwli_lifecycle_checkpoint', $resume_hook, 10, 2);
			try {
				$resume = (new TPFWLI_Orchestrator())->run($input, 'resume');
			} finally {
				remove_action('tpfwli_lifecycle_checkpoint', $resume_hook, 10);
			}
			$this->assertFalse($resume['ok'], $status . ' resume');
			$after = wc_get_order($order->get_id());
			$this->assertSame($status, $after->get_status(), $status . ' resume');
			$this->assertSame('not_sent', (string) $after->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE), $status . ' resume');
			$this->assertSame($result['nanos'], $resume['nanos'], $status . ' resume');
			$this->assertSame(0, $this->customerMailCount((string) $input['email']), $status . ' resume');
			$this->assertSame($stock_after, $this->db_product_stock(self::$ticket_id), $status . ' resume');

			self::$mail = array();
			add_action('tpfwli_lifecycle_checkpoint', $resume_hook, 10, 2);
			try {
				$email = (new TPFWLI_Orchestrator())->run($input, 'retry_email');
			} finally {
				remove_action('tpfwli_lifecycle_checkpoint', $resume_hook, 10);
			}
			$this->assertFalse($email['ok'], $status . ' retry_email');
			$after_email = wc_get_order($order->get_id());
			$this->assertSame($status, $after_email->get_status(), $status . ' retry_email keeps hook status');
			$this->assertSame('not_sent', (string) $after_email->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE), $status . ' retry_email');
			$this->assertSame($result['nanos'], $email['nanos'], $status . ' retry_email');
			$this->assertSame(0, $this->customerMailCount((string) $input['email']), $status . ' retry_email');
		}
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

	public function test_meta_lookup_sql_error_still_stops_after_matching_followup_select(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-meta-count@example.com',
			'first_name' => 'LookupMetaCount',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$this->withIdentityLookupFailureThenMatchingSuccessfulSelect($input['import_id'], 'meta', 1, function () use ($input, $before): void {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$this->assertNull($found['order']);
			$this->assertStringContainsString('import ID', $found['error']);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertSame(array(), $this->db_import_order_ids($input['import_id']));
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		});
	}

	public function test_created_via_lookup_sql_error_still_stops_after_matching_followup_select(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-via-count@example.com',
			'first_name' => 'LookupViaCount',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$this->withIdentityLookupFailureThenMatchingSuccessfulSelect($input['import_id'], 'created_via', 1, function () use ($input, $before): void {
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertFalse($found['ok']);
			$this->assertStringContainsString('created_via', $found['error']);
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
			$this->assertFalse($result['ok']);
			$this->assertNull($result['order']);
			$this->assertSame(array(), $this->db_import_order_ids($input['import_id']));
			$this->assertBusinessUnchanged($before, (string) $input['email']);
		});
	}

	public function test_later_create_or_resume_lookup_sql_error_still_stops_after_matching_followup_select(): void
	{
		$input = $this->valid_input(array(
			'email'      => 'lookup-later-count@example.com',
			'first_name' => 'LookupLaterCount',
		));
		$before = $this->captureBusinessState((string) $input['email']);
		$this->withIdentityLookupFailureThenMatchingSuccessfulSelect($input['import_id'], 'any', 3, function () use ($input, $before): void {
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

	/**
	 * @param array<int,array<string,mixed>> $queued
	 * @param array<string,mixed>            $queue
	 */
	private function assertQueuedJobsRan(array $queued, array $queue): void
	{
		$queued_ids = array();
		foreach ($queued as $job) {
			$id = (int) ($job['id'] ?? 0);
			if ($id > 0) {
				$queued_ids[] = $id;
			}
		}
		$this->assertNotEmpty($queued_ids, 'import must leave deferred jobs for the test order');
		$ran = array_map('intval', (array) ($queue['ran'] ?? array()));
		$this->assertNotEmpty($ran, 'zero mail is not a pass unless the expected jobs ran');
		$this->assertEqualsCanonicalizing($queued_ids, $ran);
	}

	private function poisonOrderCache(int $order_id): void
	{
		$this->forgetOrderCache($order_id);
		$cache = wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class);
		$cache->set(new WC_Order(), $order_id);
		$this->assertNotInstanceOf(WC_Order::class, wc_get_order($order_id));
	}

	private function forgetOrderCache(int $order_id): void
	{
		if ($order_id < 1 || !function_exists('wc_get_container')) {
			return;
		}
		try {
			$store = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class);
			if (is_object($store) && method_exists($store, 'clear_cached_data')) {
				$store->clear_cached_data(array($order_id));
			}
		} catch (Throwable $ignored) {
			unset($ignored);
		}
		try {
			$cache = wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class);
			if (is_object($cache) && method_exists($cache, 'remove')) {
				$cache->remove($order_id);
			}
		} catch (Throwable $ignored) {
			unset($ignored);
		}
		wp_cache_delete($order_id, 'orders');
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function runDeferredImportWorker(array $input, array $extra = array()): array
	{
		$payload = array_merge(array(
			'input'        => $input,
			'mode'         => 'confirm',
			'capture_mail' => true,
		), $extra);
		return $this->runJsonFileWorker(dirname(__DIR__) . '/bin/deferred-import-worker.php', $payload);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function runQueuedEmailWorker(int $order_id, bool $capture_mail): array
	{
		return $this->runJsonFileWorker(dirname(__DIR__) . '/bin/run-queued-email-worker.php', array(
			'order_id'     => $order_id,
			'capture_mail' => $capture_mail,
		));
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function runJsonFileWorker(string $bin, array $payload): array
	{
		$file = tempnam(sys_get_temp_dir(), 'tpfwli-json-');
		$this->assertNotFalse($file);
		file_put_contents($file, wp_json_encode($payload));
		$spec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		$proc = proc_open(array(PHP_BINARY, $bin, $file), $spec, $pipes);
		$this->assertIsResource($proc);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);
		@unlink($file);
		$decoded = json_decode((string) $out, true);
		$this->assertIsArray($decoded, (string) $out . "\n" . (string) $err);
		return $decoded;
	}

	private function failRefundQueries(int $order_id): callable
	{
		$filter = static function ($sql) use ($order_id) {
			if (!is_string($sql) || !str_contains($sql, (string) $order_id)) {
				return $sql;
			}
			if (!str_contains($sql, 'shop_order_refund') && !str_contains($sql, 'parent_order_id')) {
				return $sql;
			}
			return 'SELECT id FROM tpfwli_missing_refund_lookup WHERE id = 1';
		};
		add_filter('query', $filter, 999);
		return $filter;
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
	 * Identity SQL failure, then a successful COUNT that still matches the identity.
	 *
	 * @param callable():void $callback
	 */
	private function withIdentityLookupFailureThenMatchingSuccessfulSelect(string $import_id, string $which, int $from_call, callable $callback): void
	{
		$calls = 0;
		$fail  = static function ($sql) use ($import_id, $which, $from_call, &$calls) {
			if (!is_string($sql) || !str_contains($sql, $import_id)) {
				return $sql;
			}
			if (str_contains($sql, 'SELECT DISTINCT o.id')) {
				return $sql;
			}
			if (preg_match('/SELECT\s+COUNT\s*\(/i', $sql)) {
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
		$mask = static function ($results) use ($import_id, $which) {
			global $wpdb;
			if ($which === 'created_via') {
				$table = $wpdb->prefix . 'wc_order_operational_data';
				$sql   = $wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE created_via = %s",
					TPFWLI_Plugin::created_via($import_id)
				);
			} else {
				$table = $wpdb->prefix . 'wc_orders_meta';
				$sql   = $wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE meta_key = %s AND meta_value = %s",
					TPFWLI_Plugin::META_IMPORT_ID,
					$import_id
				);
			}
			$count = $wpdb->get_var($sql);
			if ($count === null || $wpdb->last_error !== '') {
				throw new RuntimeException('Follow-up identity SELECT COUNT(*) failed: ' . (string) $wpdb->last_error);
			}
			return $results;
		};
		add_filter('query', $fail, 999);
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

	private function readyOrderForStock(array $input): WC_Order
	{
		$order = $this->bootstrap_identity($input);
		$shaped = (new TPFWLI_Order_Shape())->assert_or_repair(
			$order,
			wc_get_product(self::$ticket_id),
			(int) $input['quantity']
		);
		$this->assertTrue($shaped['ok'], $shaped['error']);
		$this->assertInstanceOf(WC_Order::class, $shaped['order']);
		return $shaped['order'];
	}

	private function sessionInTransaction(): string
	{
		global $wpdb;
		return (string) $wpdb->get_var('SELECT @@SESSION.in_transaction');
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function assertLifecycleBlocked(array $input, WC_Order $order, int $stock, int $orders_before, string $needle): void
	{
		$order_id = (int) $order->get_id();
		$tickets = $this->count_tickets_for_order($order_id);
		$notes = (string) $order->get_status();
		foreach (array('confirm', 'retry_issue', 'retry_email', 'resume') as $mode) {
			self::$mail = array();
			$result = (new TPFWLI_Orchestrator())->run($input, $mode);
			$this->assertFalse($result['ok'], $needle . ' ' . $mode . ' ' . implode('; ', $result['errors'] ?? array()));
			$this->assertStringContainsString($needle, implode(' ', $result['errors'] ?? array()), $mode);
			$this->assertSame($order_id, $result['order'] instanceof WC_Order ? (int) $result['order']->get_id() : $order_id, $mode);
			$this->assertSame($orders_before, $this->count_import_orders(), $mode);
			$this->assertSame($stock, $this->db_product_stock(self::$ticket_id), $mode);
			$this->assertSame($tickets, $this->count_tickets_for_order($order_id), $mode);
			$this->assertSame(0, $this->customerMailCount((string) $input['email']), $mode);
			$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
			$this->assertInstanceOf(WC_Order::class, $found['order'], $mode);
			$this->assertSame($order_id, (int) $found['order']->get_id(), $mode);
			$this->assertSame($notes, $found['order']->get_status(), $mode);
		}
	}

	private function adminResultHtml(WC_Order $order): string
	{
		if (!function_exists('submit_button')) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		delete_transient('tpfwli_result_' . get_current_user_id());
		$_GET['view'] = 'result';
		$_GET['order_id'] = (string) $order->get_id();
		$page = new TPFWLI_Admin_Page();
		ob_start();
		(new ReflectionMethod($page, 'render_result'))->invoke($page);
		$html = (string) ob_get_clean();
		unset($_GET['view'], $_GET['order_id']);
		return $html;
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	private function postAdminResume(array $input): array
	{
		$_POST = $input;
		$_POST['action'] = 'tpfwli_resume';
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce('tpfwli_resume');
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$redirect = static function ($location) {
			throw new RuntimeException('redirect:' . $location);
		};
		add_filter('wp_redirect', $redirect, 999);
		try {
			(new TPFWLI_Admin_Page())->handle_resume();
			$this->fail('resume POST should redirect');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('redirect:', $e->getMessage());
		} finally {
			remove_filter('wp_redirect', $redirect, 999);
			unset($_POST, $_REQUEST['_wpnonce']);
		}
		$flash = get_transient('tpfwli_result_' . get_current_user_id());
		$this->assertIsArray($flash);
		return $flash;
	}

	private function failTxnInspectWhen(callable $active): callable
	{
		$filter = static function ($sql) use ($active) {
			if (!is_string($sql) || !$active()) {
				return $sql;
			}
			if (
				!str_contains($sql, 'in_transaction')
				&& !str_contains($sql, 'INNODB_TRX')
				&& !str_contains($sql, 'events_transactions_current')
			) {
				return $sql;
			}
			return 'SELECT tpfwli_missing_txn_state FROM dual';
		};
		add_filter('query', $filter, 998);
		return $filter;
	}

	private function forceTxnInspectAfterFirst(string $mode): callable
	{
		$seen = 0;
		$filter = static function ($sql) use ($mode, &$seen) {
			if (!is_string($sql)) {
				return $sql;
			}
			if (
				!str_contains($sql, 'in_transaction')
				&& !str_contains($sql, 'INNODB_TRX')
				&& !str_contains($sql, 'events_transactions_current')
			) {
				return $sql;
			}
			$seen++;
			if ($seen <= 1) {
				return $sql;
			}
			return $mode === 'closed'
				? 'SELECT 0'
				: 'SELECT tpfwli_missing_txn_state FROM dual';
		};
		add_filter('query', $filter, 999);
		return $filter;
	}

	private function secondDb(): wpdb
	{
		global $wpdb;
		$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
		$this->assertInstanceOf(wpdb::class, $other);
		$other->set_prefix($wpdb->prefix);
		return $other;
	}

	private function count_order_notes(int $order_id): int
	{
		return count(wc_get_order_notes(array('order_id' => $order_id)));
	}

	private function count_order_notes_other(wpdb $db, int $order_id): int
	{
		return (int) $db->get_var($db->prepare(
			"SELECT COUNT(*) FROM {$db->comments} WHERE comment_post_ID = %d AND comment_type = %s",
			$order_id,
			'order_note'
		));
	}

	private function db_stock_stage_other(wpdb $db, int $order_id): string
	{
		$table = $db->prefix . 'wc_orders_meta';
		$value = $db->get_var($db->prepare(
			"SELECT meta_value FROM {$table} WHERE order_id = %d AND meta_key = %s",
			$order_id,
			TPFWLI_Plugin::META_STOCK_STAGE
		));
		return (string) $value;
	}

	/**
	 * @return mixed
	 */
	private function callStockPrivate(TPFWLI_Stock_Service $service, string $method, array $args = array())
	{
		return (new ReflectionMethod($service, $method))->invokeArgs($service, $args);
	}

	private function isolatedMysql(): ?wpdb
	{
		$host = getenv('TPFWLI_MYSQL_HOST') ?: '127.0.0.1';
		$port = getenv('TPFWLI_MYSQL_PORT') ?: '3307';
		$user = getenv('TPFWLI_MYSQL_USER') ?: 'root';
		$pass = getenv('TPFWLI_MYSQL_PASSWORD') ?: 'tpfwli';
		$name = getenv('TPFWLI_MYSQL_DATABASE') ?: 'tpfwli_txn';
		$db   = @new wpdb($user, $pass, $name, $host . ':' . $port);
		if (!empty($db->error) || !$db->ready) {
			return null;
		}
		return $db;
	}

	/**
	 * @return array<string,string>
	 */
	private function mysqlPsSnapshot(wpdb $admin): array
	{
		return array(
			'instrument' => (string) $admin->get_var("SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME = 'transaction'"),
			'global'     => (string) $admin->get_var("SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME = 'global_instrumentation'"),
			'thread'     => (string) $admin->get_var("SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME = 'thread_instrumentation'"),
			'current'    => (string) $admin->get_var("SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME = 'events_transactions_current'"),
		);
	}

	/**
	 * @param array<string,string> $saved
	 */
	private function mysqlPsRestore(wpdb $admin, array $saved): void
	{
		$admin->query($admin->prepare("UPDATE performance_schema.setup_instruments SET ENABLED = %s WHERE NAME = 'transaction'", $saved['instrument'] !== '' ? $saved['instrument'] : 'YES'));
		$admin->query($admin->prepare("UPDATE performance_schema.setup_consumers SET ENABLED = %s WHERE NAME = 'global_instrumentation'", $saved['global'] !== '' ? $saved['global'] : 'YES'));
		$admin->query($admin->prepare("UPDATE performance_schema.setup_consumers SET ENABLED = %s WHERE NAME = 'thread_instrumentation'", $saved['thread'] !== '' ? $saved['thread'] : 'YES'));
		$admin->query($admin->prepare("UPDATE performance_schema.setup_consumers SET ENABLED = %s WHERE NAME = 'events_transactions_current'", $saved['current'] !== '' ? $saved['current'] : 'YES'));
	}

	private function mysqlPsEnableFull(wpdb $admin): void
	{
		$admin->query("UPDATE performance_schema.setup_instruments SET ENABLED = 'YES' WHERE NAME = 'transaction'");
		$admin->query("UPDATE performance_schema.setup_consumers SET ENABLED = 'YES' WHERE NAME IN ('global_instrumentation', 'thread_instrumentation', 'events_transactions_current')");
	}

	private function mysqlPsDisable(string $mode, wpdb $admin, wpdb $conn): void
	{
		if ($mode === 'instrument_off') {
			$admin->query("UPDATE performance_schema.setup_instruments SET ENABLED = 'NO' WHERE NAME = 'transaction'");
			return;
		}
		if ($mode === 'thread_not_instrumented') {
			$conn->query("UPDATE performance_schema.threads SET INSTRUMENTED = 'NO' WHERE PROCESSLIST_ID = CONNECTION_ID()");
			return;
		}
		$admin->query("UPDATE performance_schema.setup_consumers SET ENABLED = 'NO' WHERE NAME = 'thread_instrumentation'");
	}

	private function mysqlPsReenableViaAdmin(wpdb $admin, int $connection_id): void
	{
		$this->mysqlPsEnableFull($admin);
		$admin->query($admin->prepare(
			"UPDATE performance_schema.threads SET INSTRUMENTED = 'YES' WHERE PROCESSLIST_ID = %d",
			$connection_id
		));
	}

	/**
	 * @return array{instrument:string,global:string,thread:string,current:string,thread_inst:string,state:string}
	 */
	private function mysqlPsLiveFlags(wpdb $admin, int $connection_id): array
	{
		return array(
			'instrument'  => (string) $admin->get_var("SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME = 'transaction'"),
			'global'      => (string) $admin->get_var("SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME = 'global_instrumentation'"),
			'thread'      => (string) $admin->get_var("SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME = 'thread_instrumentation'"),
			'current'     => (string) $admin->get_var("SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME = 'events_transactions_current'"),
			'thread_inst' => (string) $admin->get_var($admin->prepare(
				'SELECT INSTRUMENTED FROM performance_schema.threads WHERE PROCESSLIST_ID = %d',
				$connection_id
			)),
			'state'       => strtoupper((string) $admin->get_var($admin->prepare(
				'SELECT e.STATE
				FROM performance_schema.threads t
				LEFT JOIN performance_schema.events_transactions_current e ON e.THREAD_ID = t.THREAD_ID
				WHERE t.PROCESSLIST_ID = %d',
				$connection_id
			))),
		);
	}

	/**
	 * @return array{0:callable,1:object}
	 */
	private function spySqlTxnCommands(): array
	{
		$seen = (object) array(
			'START TRANSACTION' => 0,
			'COMMIT'            => 0,
			'ROLLBACK'          => 0,
		);
		$filter = static function ($sql) use ($seen) {
			if (!is_string($sql)) {
				return $sql;
			}
			$normalized = strtoupper(trim($sql));
			if (property_exists($seen, $normalized)) {
				$seen->{$normalized}++;
			}
			return $sql;
		};
		add_filter('query', $filter, 999);
		return array($filter, $seen);
	}

	private function assertMysqlStartGateLeavesOuterTransaction(string $label, wpdb $conn, wpdb $other, int $probe_id): void
	{
		[$filter, $seen] = $this->spySqlTxnCommands();
		try {
			$gate = $this->callStockPrivate(new TPFWLI_Stock_Service(), 'begin_stock_transaction_if_idle');
		} finally {
			remove_filter('query', $filter, 999);
		}
		$this->assertFalse($gate['ok'], $label . ' ' . wp_json_encode($gate));
		$this->assertFalse($gate['accepted'], $label);
		$this->assertSame(0, $seen->{'START TRANSACTION'}, $label);
		$this->assertSame(0, $seen->COMMIT, $label);
		$this->assertSame(0, $seen->ROLLBACK, $label);
		$this->assertTrue($conn->ready, $label);
		$this->assertNotEmpty($conn->dbh, $label);
		$this->assertSame(1, (int) $conn->get_var($conn->prepare('SELECT COUNT(*) FROM tpfwli_ps_probe WHERE id = %d', $probe_id)), $label);
		$this->assertSame(0, (int) $other->get_var($other->prepare('SELECT COUNT(*) FROM tpfwli_ps_probe WHERE id = %d', $probe_id)), $label);
	}

	private function assertMysqlIncompletePsLeavesOuterTransaction(wpdb $admin, string $mode, string $scenario, int $probe_id): void
	{
		$this->mysqlPsEnableFull($admin);
		$conn = $this->isolatedMysql();
		$other = $this->isolatedMysql();
		$this->assertInstanceOf(wpdb::class, $conn);
		$this->assertInstanceOf(wpdb::class, $other);
		$admin->query($admin->prepare('DELETE FROM tpfwli_ps_probe WHERE id = %d', $probe_id));
		$conn->suppress_errors(true);
		$previous = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $conn;
		try {
			if ($scenario === 'stale_then_new') {
				$conn->query('START TRANSACTION');
				$conn->query('SELECT 1');
				$conn->query('COMMIT');
				$stale = $conn->get_var(
					"SELECT STATE FROM performance_schema.events_transactions_current
					WHERE THREAD_ID = (SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID())"
				);
				$this->assertSame('COMMITTED', strtoupper((string) $stale), $mode . ' ' . $scenario);
			}
			$this->mysqlPsDisable($mode, $admin, $conn);
			$conn->query('START TRANSACTION');
			$conn->query($conn->prepare(
				'INSERT INTO tpfwli_ps_probe (id, note) VALUES (%d, %s)',
				$probe_id,
				$mode . ':' . $scenario
			));
			$this->assertSame(1, (int) $conn->get_var($conn->prepare('SELECT COUNT(*) FROM tpfwli_ps_probe WHERE id = %d', $probe_id)));

			$inspect = $this->callStockPrivate(new TPFWLI_Stock_Service(), 'inspect_sql_transaction');
			$this->assertNotSame('closed', $inspect['status'], $mode . ' ' . $scenario . ' ' . wp_json_encode($inspect));
			$this->assertContains($inspect['status'], array('unknown', 'open'), $mode . ' ' . $scenario . ' ' . wp_json_encode($inspect));
			if ($inspect['status'] === 'unknown') {
				$this->assertFalse($inspect['ok']);
				$this->assertFalse($inspect['open']);
			} else {
				$this->assertTrue($inspect['ok']);
				$this->assertTrue($inspect['open']);
			}

			$this->assertMysqlStartGateLeavesOuterTransaction($mode . ' ' . $scenario, $conn, $other, $probe_id);
		} finally {
			$conn->query('ROLLBACK');
			$conn->query("UPDATE performance_schema.threads SET INSTRUMENTED = 'YES' WHERE PROCESSLIST_ID = CONNECTION_ID()");
			$GLOBALS['wpdb'] = $previous;
			$this->mysqlPsEnableFull($admin);
			TPFWLI_Database_Session::reset_for_tests();
		}
		$this->assertSame(0, (int) $other->get_var($other->prepare('SELECT COUNT(*) FROM tpfwli_ps_probe WHERE id = %d', $probe_id)));
		$this->assertSame(0, (int) $conn->get_var($conn->prepare('SELECT COUNT(*) FROM tpfwli_ps_probe WHERE id = %d', $probe_id)));
	}

	private function assertMysqlReenabledPsDoesNotTreatStaleCommittedAsClosed(wpdb $admin, string $mode, int $probe_id): void
	{
		$this->mysqlPsEnableFull($admin);
		$conn = $this->isolatedMysql();
		$other = $this->isolatedMysql();
		$this->assertInstanceOf(wpdb::class, $conn);
		$this->assertInstanceOf(wpdb::class, $other);
		$connection_id = (int) $conn->get_var('SELECT CONNECTION_ID()');
		$this->assertGreaterThan(0, $connection_id, $mode);
		$admin->query($admin->prepare('DELETE FROM tpfwli_ps_probe WHERE id = %d', $probe_id));
		$conn->suppress_errors(true);
		$previous = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = $conn;
		try {
			$conn->query('START TRANSACTION');
			$conn->query('SELECT 1');
			$conn->query('COMMIT');
			$this->mysqlPsDisable($mode, $admin, $conn);
			$conn->query('START TRANSACTION');
			$conn->query($conn->prepare(
				'INSERT INTO tpfwli_ps_probe (id, note) VALUES (%d, %s)',
				$probe_id,
				'reenable:' . $mode
			));
			$this->mysqlPsReenableViaAdmin($admin, $connection_id);
			$flags = $this->mysqlPsLiveFlags($admin, $connection_id);
			$this->assertSame('YES', $flags['instrument'], $mode . ' ' . wp_json_encode($flags));
			$this->assertSame('YES', $flags['global'], $mode . ' ' . wp_json_encode($flags));
			$this->assertSame('YES', $flags['thread'], $mode . ' ' . wp_json_encode($flags));
			$this->assertSame('YES', $flags['current'], $mode . ' ' . wp_json_encode($flags));
			$this->assertSame('YES', $flags['thread_inst'], $mode . ' ' . wp_json_encode($flags));
			$this->assertSame('COMMITTED', $flags['state'], $mode . ' ' . wp_json_encode($flags));

			$inspect = $this->callStockPrivate(new TPFWLI_Stock_Service(), 'inspect_sql_transaction');
			$this->assertSame('unknown', $inspect['status'], $mode . ' ' . wp_json_encode(array('flags' => $flags, 'inspect' => $inspect)));
			$this->assertFalse($inspect['ok']);
			$this->assertFalse($inspect['open']);

			$this->assertMysqlStartGateLeavesOuterTransaction('reenable ' . $mode, $conn, $other, $probe_id);
		} finally {
			$conn->query('ROLLBACK');
			$conn->query("UPDATE performance_schema.threads SET INSTRUMENTED = 'YES' WHERE PROCESSLIST_ID = CONNECTION_ID()");
			$GLOBALS['wpdb'] = $previous;
			$this->mysqlPsEnableFull($admin);
			TPFWLI_Database_Session::reset_for_tests();
		}
		$this->assertSame(0, (int) $other->get_var($other->prepare('SELECT COUNT(*) FROM tpfwli_ps_probe WHERE id = %d', $probe_id)), $mode);
		$this->assertSame(0, (int) $conn->get_var($conn->prepare('SELECT COUNT(*) FROM tpfwli_ps_probe WHERE id = %d', $probe_id)), $mode);
	}

	/**
	 * @param string[] $commands
	 */
	private function failSqlCommands(array $commands, int $times = 0): callable
	{
		$seen = array();
		$filter = static function ($sql) use ($commands, $times, &$seen) {
			if (!is_string($sql)) {
				return $sql;
			}
			$normalized = strtoupper(trim($sql));
			foreach ($commands as $command) {
				if ($normalized !== strtoupper($command)) {
					continue;
				}
				$seen[$command] = ($seen[$command] ?? 0) + 1;
				if ($times > 0 && $seen[$command] > $times) {
					return $sql;
				}
				return $command . ' TO tpfwli_missing_txn';
			}
			return $sql;
		};
		add_filter('query', $filter, 999);
		return $filter;
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function runStockCrashWorker(array $input, string $checkpoint, array $extra = array()): array
	{
		$payload = array_merge(array(
			'input'      => $input,
			'checkpoint' => $checkpoint,
		), $extra);
		unset($payload['env']);
		$worker = $this->startJsonWorker(
			dirname(__DIR__) . '/bin/stock-crash-worker.php',
			$payload,
			is_array($extra['env'] ?? null) ? $extra['env'] : array()
		);
		$out = $this->finishJsonWorker($worker);
		$this->assertIsArray($out, wp_json_encode($worker['err'] ?? ''));
		return $out;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,string> $env
	 * @return array{proc:resource,pipes:array,err:string}
	 */
	private function startJsonWorker(string $script, array $payload, array $env = array()): array
	{
		$cmd = array(PHP_BINARY, $script);
		$spec = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		if ($env !== array()) {
			$prefixed = array('env');
			foreach ($env as $name => $value) {
				$prefixed[] = $name . '=' . $value;
			}
			$cmd = array_merge($prefixed, $cmd);
		}
		$proc = proc_open($cmd, $spec, $pipes);
		$this->assertIsResource($proc);
		fwrite($pipes[0], wp_json_encode($payload));
		fclose($pipes[0]);
		return array('proc' => $proc, 'pipes' => $pipes, 'err' => '');
	}

	/**
	 * @param array{proc:resource,pipes:array,err:string} $worker
	 * @return array<string,mixed>|null
	 */
	private function finishJsonWorker(array $worker): ?array
	{
		$out = stream_get_contents($worker['pipes'][1]);
		$err = stream_get_contents($worker['pipes'][2]);
		fclose($worker['pipes'][1]);
		fclose($worker['pipes'][2]);
		proc_close($worker['proc']);
		$decoded = json_decode((string) $out, true);
		if (!is_array($decoded)) {
			fwrite(STDERR, (string) $out . "\n" . (string) $err . "\n");
			return null;
		}
		return $decoded;
	}

	private function db_product_stock(int $product_id): int
	{
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$product_id,
			'_stock'
		));
	}

	private function db_line_reduced_stock(int $order_id): ?int
	{
		global $wpdb;
		$item_id = $wpdb->get_var($wpdb->prepare(
			"SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = 'line_item' LIMIT 1",
			$order_id
		));
		if (!$item_id) {
			return null;
		}
		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d AND meta_key = %s",
			(int) $item_id,
			'_reduced_stock'
		));
		return $value === null ? null : (int) $value;
	}

	private function db_order_stock_flag(int $order_id): bool
	{
		global $wpdb;
		$value = $wpdb->get_var($wpdb->prepare(
			"SELECT order_stock_reduced FROM {$wpdb->prefix}wc_order_operational_data WHERE order_id = %d",
			$order_id
		));
		return $value === '1' || $value === 1 || $value === true;
	}

	private function assertStockCrashThenRetry(string $checkpoint): void
	{
		$input = $this->valid_input(array(
			'email'      => 'stock-crash-' . $checkpoint . '@example.com',
			'first_name' => 'StockCrash',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$hit = false;
		$crash = static function (string $point) use ($checkpoint, &$hit): void {
			if ($point !== $checkpoint) {
				return;
			}
			$hit = true;
			throw new RuntimeException('stock-checkpoint:' . $checkpoint);
		};
		add_action('tpfwli_stock_checkpoint', $crash, 10, 1);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_action('tpfwli_stock_checkpoint', $crash, 10);
		}
		$this->assertTrue($hit, 'checkpoint ' . $checkpoint . ' was not reached');
		$this->assertFalse($result['ok']);
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$order = $found['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		wp_cache_flush();
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $order->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));

		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		wp_cache_flush();
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock((int) $retry['order']->get_id()));
		$this->assertTrue($this->db_order_stock_flag((int) $retry['order']->get_id()));
		$this->assertCount(2, $retry['nanos']);
		$again = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		wp_cache_flush();
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame($retry['nanos'], $again['nanos']);
	}

	private function assertStockProcessAbortThenRetry(string $checkpoint): void
	{
		if (!function_exists('proc_open')) {
			$this->markTestSkipped('proc_open is not available');
		}
		$worker = dirname(__DIR__) . '/bin/stock-crash-worker.php';
		if (!is_readable($worker)) {
			$this->markTestSkipped('stock crash worker is missing');
		}
		$input = $this->valid_input(array(
			'email'      => 'stock-kill-' . $checkpoint . '@example.com',
			'first_name' => 'StockKill',
			'quantity'   => '2',
		));
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$payload = wp_json_encode(array(
			'input'      => $input,
			'checkpoint' => $checkpoint,
		));
		$spec = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$proc = proc_open(array(PHP_BINARY, $worker), $spec, $pipes);
		$this->assertIsResource($proc);
		fwrite($pipes[0], $payload);
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		$decoded = json_decode((string) $out, true);
		$this->assertTrue(!is_array($decoded) || empty($decoded['survived']), 'worker survived: ' . $out . ' ' . $err . ' code=' . $code);
		wp_cache_flush();
		$found = (new TPFWLI_Import_Repository())->find_by_import_id($input['import_id']);
		$order = $found['order'];
		$this->assertInstanceOf(WC_Order::class, $order);
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $order->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));

		$retry = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		$this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
		wp_cache_flush();
		$this->assertSame($start_stock - 2, $this->db_product_stock(self::$ticket_id));
		$this->assertSame(2, $this->db_line_reduced_stock((int) $retry['order']->get_id()));
		$this->assertCount(2, $retry['nanos']);
	}

	private function assertStockSqlFailureStopsImport(string $which): void
	{
		global $wpdb;
		$input = $this->valid_input(array(
			'email'      => 'stock-sql-' . $which . '@example.com',
			'first_name' => 'StockSql',
			'quantity'   => '2',
		));
		$order = $this->bootstrap_identity($input);
		$shaped = (new TPFWLI_Order_Shape())->assert_or_repair($order, wc_get_product(self::$ticket_id), 2);
		$this->assertTrue($shaped['ok'], $shaped['error']);
		$start_stock = (int) wc_get_product(self::$ticket_id)->get_stock_quantity();
		$previous_suppress = $wpdb->suppress_errors(true);
		$filter = $this->failStockQueries(self::$ticket_id, $which);
		try {
			$result = (new TPFWLI_Orchestrator())->run($input, 'confirm');
		} finally {
			remove_filter('query', $filter, 999);
			$wpdb->suppress_errors((bool) $previous_suppress);
		}
		$this->assertFalse($result['ok']);
		wp_cache_flush();
		$this->assertSame($start_stock, $this->db_product_stock(self::$ticket_id));
		$this->assertNull($this->db_line_reduced_stock((int) $order->get_id()));
		$this->assertFalse($this->db_order_stock_flag((int) $order->get_id()));
		$this->assertSame(0, $this->count_tickets_for_order((int) $order->get_id()));
		$this->assertSame(0, $this->customerMailCount((string) $input['email']));
	}

	private function failStockQueries(int $product_id, string $which): callable
	{
		$filter = static function ($sql) use ($product_id, $which) {
			if (!is_string($sql)) {
				return $sql;
			}
			if ($which === 'stock' && preg_match('/UPDATE\s+.*postmeta/i', $sql) && str_contains($sql, '_stock') && str_contains($sql, (string) $product_id)) {
				return 'UPDATE tpfwli_missing_stock_table SET meta_value = 0 WHERE 1=0';
			}
			if ($which === 'itemmeta' && str_contains($sql, '_reduced_stock') && (stripos($sql, 'INSERT') !== false || stripos($sql, 'UPDATE') !== false || stripos($sql, 'REPLACE') !== false)) {
				return 'INSERT INTO tpfwli_missing_itemmeta_table (meta_key) VALUES (1)';
			}
			if ($which === 'flag' && str_contains($sql, 'order_stock_reduced') && str_contains($sql, 'wc_order_operational_data') && preg_match('/VALUES\s*\(\s*\d+\s*,\s*1\s*\)/i', $sql)) {
				return 'UPDATE tpfwli_missing_flag_table SET order_stock_reduced = 1 WHERE 1=0';
			}
			return $sql;
		};
		add_filter('query', $filter, 999);
		return $filter;
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
