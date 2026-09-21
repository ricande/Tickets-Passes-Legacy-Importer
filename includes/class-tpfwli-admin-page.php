<?php
defined('ABSPATH') || exit;

/**
 * Admin UI: form, preview, confirm, retries, overview.
 */
final class TPFWLI_Admin_Page
{
	const SLUG = 'tpfwli-legacy-importer';

	public const RUN_QUERY = 'tpfwli_run';

	public const PAGE_QUERY = 'tpfwli_page';

	public const PAGE_SIZE = 50;

	private TPFWLI_Input_Validator $validator;
	private TPFWLI_Orchestrator $orchestrator;
	private TPFWLI_Import_Repository $repository;

	public function __construct()
	{
		$this->validator    = new TPFWLI_Input_Validator();
		$this->orchestrator = new TPFWLI_Orchestrator();
		$this->repository   = new TPFWLI_Import_Repository();
	}

	public function register(): void
	{
		add_action('admin_menu', array($this, 'menu'));
		add_action('admin_post_tpfwli_preview', array($this, 'handle_preview'));
		add_action('admin_post_tpfwli_confirm', array($this, 'handle_confirm'));
		add_action('admin_post_tpfwli_retry_issue', array($this, 'handle_retry_issue'));
		add_action('admin_post_tpfwli_retry_email', array($this, 'handle_retry_email'));
		add_action('admin_post_tpfwli_resume', array($this, 'handle_resume'));
		add_action('admin_notices', array($this, 'dependency_notice'));
	}

	public function menu(): void
	{
		add_submenu_page(
			'woocommerce',
			__('Legacy Ticket Importer', 'tickets-passes-legacy-importer'),
			__('Legacy Ticket Importer', 'tickets-passes-legacy-importer'),
			'manage_woocommerce',
			self::SLUG,
			array($this, 'render')
		);
	}

	public function dependency_notice(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			return;
		}
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->id !== 'woocommerce_page_' . self::SLUG) {
			return;
		}
		foreach (array_merge(TPFWLI_Dependencies::problems(), TPFWLI_Dependencies::transactional_storage_problems()) as $problem) {
			echo '<div class="notice notice-error"><p>' . esc_html($problem) . '</p></div>';
		}
	}

	public function render(): void
	{
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to import legacy tickets.', 'tickets-passes-legacy-importer'));
		}

		$view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : 'form';
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Tickets & Passes – Legacy Ticket Importer', 'tickets-passes-legacy-importer') . '</h1>';
		echo '<p><a href="' . esc_url($this->url(array('view' => 'form'))) . '">' . esc_html__('New import', 'tickets-passes-legacy-importer') . '</a> · ';
		echo '<a href="' . esc_url($this->url(array('view' => 'overview'))) . '">' . esc_html__('Import overview', 'tickets-passes-legacy-importer') . '</a></p>';

		if (!TPFWLI_Dependencies::ready()) {
			echo '</div>';
			return;
		}

		if ($view === 'preview') {
			$this->render_preview();
		} elseif ($view === 'result') {
			$this->render_result();
		} elseif ($view === 'overview') {
			$this->render_overview();
		} else {
			$this->render_form();
		}
		echo '</div>';
	}

	public function handle_preview(): void
	{
		$this->require_post('tpfwli_preview');
		$input = $this->posted_input();
		if (empty($input['import_id'])) {
			$input['import_id'] = $this->validator->new_import_id();
		}
		$result = $this->orchestrator->preview($input);
		set_transient($this->flash_key('preview'), array(
			'result' => $result,
			'input'  => $input,
		), 10 * MINUTE_IN_SECONDS);
		wp_safe_redirect($this->url(array('view' => 'preview')));
		exit;
	}

	public function handle_confirm(): void
	{
		$this->require_post('tpfwli_confirm');
		$this->require_mutation_ready();
		$this->store_run($this->orchestrator->run($this->posted_input(), 'confirm'));
	}

	public function handle_retry_issue(): void
	{
		$this->require_post('tpfwli_retry_issue');
		$this->require_mutation_ready();
		$this->store_run($this->orchestrator->run($this->posted_input(), 'retry_issue'));
	}

	public function handle_retry_email(): void
	{
		$this->require_post('tpfwli_retry_email');
		$this->require_mutation_ready();
		$this->store_run($this->orchestrator->run($this->posted_input(), 'retry_email'));
	}

	public function handle_resume(): void
	{
		$this->require_post('tpfwli_resume');
		$this->require_mutation_ready();
		$this->store_run($this->orchestrator->run($this->posted_input(), 'resume'));
	}

	private function store_run(array $result): void
	{
		$saved = $this->persist_run($result, $this->posted_input());
		wp_safe_redirect($this->url($saved['args']));
		exit;
	}

	/**
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $input
	 * @return array{token:string,args:array<string,string>}
	 */
	private function persist_run(array $result, array $input = array()): array
	{
		$order_id  = ($result['order'] instanceof WC_Order) ? (int) $result['order']->get_id() : 0;
		$import_id = '';
		if ($result['order'] instanceof WC_Order) {
			$import_id = (string) $result['order']->get_meta(TPFWLI_Plugin::META_IMPORT_ID);
		}
		if ($import_id === '' && !empty($input['import_id'])) {
			$import_id = sanitize_text_field((string) $input['import_id']);
		}

		$token = $this->new_run_token();
		set_transient(
			$this->run_key($token),
			array(
				'order_id'      => $order_id,
				'import_id'     => $import_id,
				'ok'            => !empty($result['ok']),
				'errors'        => array_values(array_filter(array_map('strval', $result['errors'] ?? array()))),
				'email_already' => !empty($result['email_already']),
				'email_unknown' => !empty($result['email_unknown']),
			),
			10 * MINUTE_IN_SECONDS
		);

		$args = array(
			'view'         => 'result',
			self::RUN_QUERY => $token,
		);
		if ($order_id > 0) {
			$args['order_id'] = (string) $order_id;
		}
		return array('token' => $token, 'args' => $args);
	}

	private function require_post(string $action): void
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			wp_die(
				esc_html__('This importer action must be submitted with POST.', 'tickets-passes-legacy-importer'),
				esc_html__('Tickets & Passes – Legacy Ticket Importer', 'tickets-passes-legacy-importer'),
				array('response' => 405)
			);
		}
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('You do not have permission to import legacy tickets.', 'tickets-passes-legacy-importer'), 403);
		}
		check_admin_referer($action);
	}

	private function require_mutation_ready(): void
	{
		$problems = TPFWLI_Dependencies::problems(true);
		if ($problems === array()) {
			return;
		}
		wp_die(
			esc_html(implode(' ', $problems)),
			esc_html__('Tickets & Passes – Legacy Ticket Importer', 'tickets-passes-legacy-importer'),
			array('response' => 409)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function posted_input(): array
	{
		return array(
			'product_id' => isset($_POST['product_id']) ? absint($_POST['product_id']) : 0,
			'first_name' => isset($_POST['first_name']) ? wp_unslash((string) $_POST['first_name']) : '',
			'last_name'  => isset($_POST['last_name']) ? wp_unslash((string) $_POST['last_name']) : '',
			'email'      => isset($_POST['email']) ? wp_unslash((string) $_POST['email']) : '',
			'phone'      => isset($_POST['phone']) ? wp_unslash((string) $_POST['phone']) : '',
			'quantity'   => isset($_POST['quantity']) ? wp_unslash((string) $_POST['quantity']) : '',
			'import_id'  => isset($_POST['import_id']) ? wp_unslash((string) $_POST['import_id']) : '',
		);
	}

	private function render_form(array $input = array(), array $errors = array()): void
	{
		$import_id = !empty($input['import_id']) ? (string) $input['import_id'] : $this->validator->new_import_id();
		$products  = wc_get_products(array(
			'type'   => 'tpfw-ticket',
			'limit'  => 200,
			'status' => 'publish',
			'orderby'=> 'title',
			'order'  => 'ASC',
		));

		foreach ($errors as $error) {
			echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="tpfwli_preview" />';
		echo '<input type="hidden" name="import_id" value="' . esc_attr($import_id) . '" />';
		wp_nonce_field('tpfwli_preview');
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="tpfwli_product">' . esc_html__('Product', 'tickets-passes-legacy-importer') . '</label></th><td>';
		echo '<select name="product_id" id="tpfwli_product" required>';
		echo '<option value="">' . esc_html__('Select a Ticket product…', 'tickets-passes-legacy-importer') . '</option>';
		foreach ($products as $product) {
			if (!$product instanceof WC_Product) {
				continue;
			}
			echo '<option value="' . esc_attr((string) $product->get_id()) . '"' . selected((int) ($input['product_id'] ?? 0), $product->get_id(), false) . '>';
			echo esc_html($product->get_name() . ' (#' . $product->get_id() . ')');
			echo '</option>';
		}
		echo '</select></td></tr>';
		$this->text_row('first_name', __('First name', 'tickets-passes-legacy-importer'), $input['first_name'] ?? '');
		$this->text_row('last_name', __('Last name', 'tickets-passes-legacy-importer'), $input['last_name'] ?? '');
		$this->text_row('email', __('Email', 'tickets-passes-legacy-importer'), $input['email'] ?? '', 'email');
		$this->text_row('phone', __('Phone', 'tickets-passes-legacy-importer'), $input['phone'] ?? '', 'tel');
		$this->text_row('quantity', __('Quantity', 'tickets-passes-legacy-importer'), $input['quantity'] ?? '1', 'number');
		echo '</tbody></table>';
		submit_button(__('Preview legacy purchase', 'tickets-passes-legacy-importer'));
		echo '</form>';
	}

	private function render_preview(): void
	{
		$flash = get_transient($this->flash_key('preview'));
		if (!is_array($flash) || empty($flash['result'])) {
			echo '<div class="notice notice-warning"><p>' . esc_html__('Preview expired. Enter the purchase again.', 'tickets-passes-legacy-importer') . '</p></div>';
			$this->render_form();
			return;
		}
		$result = $flash['result'];
		$input  = $flash['input'];
		if (empty($result['ok'])) {
			$this->render_form($input, $result['errors'] ?? array());
			return;
		}

		$c = $result['customer'];
		$p = $result['product'];
		echo '<div class="notice notice-info"><p><strong>' . esc_html__('No order has been created yet.', 'tickets-passes-legacy-importer') . '</strong></p></div>';
		echo '<table class="widefat striped"><tbody>';
		$this->kv(__('Product', 'tickets-passes-legacy-importer'), $p['name'] . ' (#' . $p['id'] . ')');
		$this->kv(__('Product type', 'tickets-passes-legacy-importer'), $p['type']);
		$this->kv(__('Customer', 'tickets-passes-legacy-importer'), trim($c['first_name'] . ' ' . $c['last_name']));
		$this->kv(__('Billing email', 'tickets-passes-legacy-importer'), $c['email']);
		$this->kv(__('Phone', 'tickets-passes-legacy-importer'), $c['phone']);
		$this->kv(__('Quantity', 'tickets-passes-legacy-importer'), (string) $c['quantity']);
		$this->kv(__('Current stock', 'tickets-passes-legacy-importer'), (string) $p['stock']);
		$this->kv(__('Expected stock after import', 'tickets-passes-legacy-importer'), (string) $p['expected_stock']);
		$this->kv(__('Backorder policy', 'tickets-passes-legacy-importer'), !empty($p['backorders']) ? __('Allowed', 'tickets-passes-legacy-importer') : __('Not allowed', 'tickets-passes-legacy-importer'));
		$this->kv(__('Max uses', 'tickets-passes-legacy-importer'), (string) $p['max_uses']);
		$this->kv(__('Fixed valid from', 'tickets-passes-legacy-importer'), (string) $p['valid_from']);
		$this->kv(__('Calculated valid until', 'tickets-passes-legacy-importer'), (string) $p['valid_to']);
		$this->kv(__('Product price', 'tickets-passes-legacy-importer'), (string) $p['price']);
		$this->kv(__('Approx. order total', 'tickets-passes-legacy-importer'), (string) $p['order_total']);
		$this->kv(__('Guest order', 'tickets-passes-legacy-importer'), __('Yes (customer_id = 0, no WordPress user)', 'tickets-passes-legacy-importer'));
		$this->kv(__('Mail recipient', 'tickets-passes-legacy-importer'), $c['email']);
		$this->kv(__('Immutable import ID', 'tickets-passes-legacy-importer'), $c['import_id']);
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:1em">';
		echo '<input type="hidden" name="action" value="tpfwli_confirm" />';
		wp_nonce_field('tpfwli_confirm');
		foreach (array('product_id', 'first_name', 'last_name', 'email', 'phone', 'quantity', 'import_id') as $field) {
			echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr((string) ($c[$field] ?? '')) . '" />';
		}
		submit_button(__('Confirm import', 'tickets-passes-legacy-importer'));
		echo '</form>';
	}

	private function render_result(): void
	{
		$requested    = $this->requested_order();
		$token        = $this->requested_run_token();
		$run          = $this->load_run($token);

		if ($requested['present'] && !$requested['ok']) {
			echo '<div class="notice notice-error"><p>' . esc_html__('The requested order ID is not valid.', 'tickets-passes-legacy-importer') . '</p></div>';
			return;
		}

		$requested_id = $requested['id'];
		if ($requested_id > 0) {
			$order = wc_get_order($requested_id);
			if (!$order instanceof WC_Order) {
				echo '<div class="notice notice-error"><p>' . esc_html__('This import order could not be found.', 'tickets-passes-legacy-importer') . '</p></div>';
				return;
			}
			if (!$this->is_importer_order($order)) {
				echo '<div class="notice notice-error"><p>' . esc_html__('This order is not a legacy import.', 'tickets-passes-legacy-importer') . '</p></div>';
				return;
			}
			$notices = (is_array($run) && (int) ($run['order_id'] ?? 0) === $requested_id) ? $run : null;
			$this->render_run_notices($notices);
			$this->render_order_result($order);
			return;
		}

		if ($token !== '' && $run === null) {
			echo '<div class="notice notice-warning"><p>' . esc_html__('This import result has expired.', 'tickets-passes-legacy-importer') . '</p></div>';
			return;
		}

		if (is_array($run) && (int) ($run['order_id'] ?? 0) === 0) {
			$this->render_run_notices($run);
			if (!empty($run['import_id'])) {
				echo '<table class="widefat striped"><tbody>';
				$this->kv(__('Legacy import ID', 'tickets-passes-legacy-importer'), (string) $run['import_id']);
				echo '</tbody></table>';
			}
			return;
		}

		if (is_array($run) && (int) ($run['order_id'] ?? 0) > 0) {
			echo '<div class="notice notice-error"><p>' . esc_html__('This result link is missing its order.', 'tickets-passes-legacy-importer') . '</p></div>';
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__('No import result to show.', 'tickets-passes-legacy-importer') . '</p></div>';
	}

	private function render_order_result(WC_Order $order): void
	{
		$state = $this->orchestrator->inspect($order);
		$nanos = $state['nanos'];
		$stock_stage  = $state['stock_stage'];
		$issue_stage  = $state['issue_stage'];
		$email_stage  = $state['email_stage'];
		$qty          = (int) $state['quantity'];
		$product_name = (string) $state['product_name'];

		echo '<table class="widefat striped"><tbody>';
		$this->kv(__('Order created', 'tickets-passes-legacy-importer'), '#' . $order->get_id());
		$this->kv(__('Legacy import ID', 'tickets-passes-legacy-importer'), $state['import_id']);
		$this->kv(__('Product', 'tickets-passes-legacy-importer'), $product_name);
		$this->kv(__('Stock accounted for this order', 'tickets-passes-legacy-importer'), $state['stock_label']);
		$this->kv(__('Current stock', 'tickets-passes-legacy-importer'), $state['current_stock'] !== null ? (string) $state['current_stock'] : '—');
		$this->kv(__('Tickets issued', 'tickets-passes-legacy-importer'), count($nanos) . ' / ' . $qty . ' (' . $issue_stage . ')');
		if ($nanos) {
			$this->kv(__('Nano IDs', 'tickets-passes-legacy-importer'), implode(', ', $nanos));
		}
		$this->kv(__('Email', 'tickets-passes-legacy-importer'), $email_stage);
		$this->kv(__('Recipient', 'tickets-passes-legacy-importer'), $order->get_billing_email());
		$this->kv(__('Stock stage', 'tickets-passes-legacy-importer'), $stock_stage);
		echo '</tbody></table>';

		$input = array(
			'product_id' => (int) $order->get_meta(TPFWLI_Plugin::META_EXPECTED_PRODUCT_ID),
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'phone'      => $order->get_billing_phone(),
			'quantity'   => $qty > 0 ? $qty : 1,
			'import_id'  => $state['import_id'],
		);

		$life = TPFWLI_Order_Lifecycle::assess($order);
		if (!$life['ok']) {
			echo '<div class="notice notice-error"><p>' . esc_html($life['error']) . '</p></div>';
			return;
		}

		if ($issue_stage !== 'issued') {
			$this->retry_form('tpfwli_retry_issue', __('Retry ticket issue', 'tickets-passes-legacy-importer'), $input);
		} elseif ($email_stage === 'not_sent') {
			$this->retry_form('tpfwli_resume', __('Finish issued import', 'tickets-passes-legacy-importer'), $input);
		} elseif ($email_stage === 'failed') {
			$this->retry_form('tpfwli_retry_email', __('Retry email', 'tickets-passes-legacy-importer'), $input);
		} elseif ($email_stage === 'sending' || $email_stage === 'unknown') {
			echo '<div class="notice notice-warning"><p>' . esc_html__('Previous email attempt has an unknown outcome. Verify before forcing a resend.', 'tickets-passes-legacy-importer') . '</p></div>';
		}
	}

	/**
	 * @param array<string,mixed>|null $run
	 */
	private function render_run_notices(?array $run): void
	{
		if (!is_array($run)) {
			return;
		}
		if (!empty($run['email_already'])) {
			echo '<div class="notice notice-info"><p>' . esc_html__('Email was already sent for this import. Normal retry will not send again.', 'tickets-passes-legacy-importer') . '</p></div>';
		}
		if (!empty($run['email_unknown'])) {
			echo '<div class="notice notice-warning"><p>' . esc_html__('Previous email attempt has an unknown outcome. Verify before forcing a resend.', 'tickets-passes-legacy-importer') . '</p></div>';
		}
		foreach ((array) ($run['errors'] ?? array()) as $error) {
			$error = (string) $error;
			if ($error === '' || $this->is_already_sent_message($error)) {
				continue;
			}
			echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
		}
	}

	private function render_overview(): void
	{
		$parsed = $this->requested_list_page();
		if (!$parsed['ok']) {
			echo '<div class="notice notice-error"><p>' . esc_html__('The requested page number is not valid.', 'tickets-passes-legacy-importer') . '</p></div>';
		}
		$page = $parsed['page'];
		$list = $this->repository->list_imports($page, self::PAGE_SIZE);
		if (empty($list['ok'])) {
			echo '<div class="notice notice-error"><p>' . esc_html($list['error'] !== '' ? $list['error'] : __('Could not read the legacy import list.', 'tickets-passes-legacy-importer')) . '</p></div>';
			return;
		}

		$total = (int) $list['total'];
		$pages = (int) $list['pages'];
		$orders = $list['orders'];

		if ($total === 0 && $page === 1) {
			echo '<p>' . esc_html__('No legacy imports yet.', 'tickets-passes-legacy-importer') . '</p>';
			return;
		}
		if ($total === 0 || $page > max(1, $pages)) {
			$last  = max(1, $pages);
			$label = $last === 1
				? __('Go to the first page', 'tickets-passes-legacy-importer')
				: __('Go to the last page', 'tickets-passes-legacy-importer');
			echo '<div class="notice notice-warning"><p>' . esc_html__('There are no imports on this page.', 'tickets-passes-legacy-importer') . '</p></div>';
			echo '<p><a href="' . esc_url($this->url(array('view' => 'overview', self::PAGE_QUERY => (string) $last))) . '">' . esc_html($label) . '</a></p>';
			return;
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages, 3: total imports */
				__('Page %1$d of %2$d (%3$d imports)', 'tickets-passes-legacy-importer'),
				$page,
				max(1, $pages),
				$total
			)
		) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach (array('Import date', 'Name', 'Email', 'Product', 'Quantity', 'Order ID', 'Stock', 'Issue', 'Email', 'Actions') as $col) {
			echo '<th>' . esc_html__($col, 'tickets-passes-legacy-importer') . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ($orders as $order) {
			if (!$order instanceof WC_Order) {
				continue;
			}
			$qty = 0;
			$name = '';
			foreach ($order->get_items('line_item') as $item) {
				$qty += (int) $item->get_quantity();
				$name = $item->get_name();
			}
			$issue = (string) $order->get_meta(TPFWLI_Plugin::META_ISSUE_STAGE);
			$email = (string) $order->get_meta(TPFWLI_Plugin::META_EMAIL_STAGE);
			echo '<tr>';
			echo '<td>' . esc_html($order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '') . '</td>';
			echo '<td>' . esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) . '</td>';
			echo '<td>' . esc_html($order->get_billing_email()) . '</td>';
			echo '<td>' . esc_html($name) . '</td>';
			echo '<td>' . esc_html((string) $qty) . '</td>';
			echo '<td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html((string) $order->get_id()) . '</a></td>';
			echo '<td>' . esc_html((string) $order->get_meta(TPFWLI_Plugin::META_STOCK_STAGE)) . '</td>';
			echo '<td>' . esc_html($issue) . '</td>';
			echo '<td>' . esc_html($email) . '</td>';
			echo '<td><a href="' . esc_url($this->url(array('view' => 'result', 'order_id' => (string) $order->get_id()))) . '">' . esc_html__('Open', 'tickets-passes-legacy-importer') . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		$this->render_pagination($page, $pages);
	}

	private function render_pagination(int $page, int $pages): void
	{
		if ($pages < 2) {
			return;
		}
		echo '<p class="tpfwli-pagination">';
		if ($page > 1) {
			echo '<a href="' . esc_url($this->url(array('view' => 'overview', self::PAGE_QUERY => (string) ($page - 1)))) . '">' . esc_html__('Previous', 'tickets-passes-legacy-importer') . '</a> ';
		}
		echo '<span>' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: total pages */
				__('Page %1$d of %2$d', 'tickets-passes-legacy-importer'),
				$page,
				$pages
			)
		) . '</span>';
		if ($page < $pages) {
			echo ' <a href="' . esc_url($this->url(array('view' => 'overview', self::PAGE_QUERY => (string) ($page + 1)))) . '">' . esc_html__('Next', 'tickets-passes-legacy-importer') . '</a>';
		}
		echo '</p>';
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function retry_form(string $action, string $label, array $input): void
	{
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:1em">';
		echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
		wp_nonce_field($action);
		foreach (array('product_id', 'first_name', 'last_name', 'email', 'phone', 'quantity', 'import_id') as $field) {
			echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . esc_attr((string) ($input[$field] ?? '')) . '" />';
		}
		submit_button($label);
		echo '</form>';
	}

	private function is_importer_order(WC_Order $order): bool
	{
		if ($order->get_meta(TPFWLI_Plugin::META_IMPORT) === 'yes') {
			return true;
		}
		if ((string) $order->get_meta(TPFWLI_Plugin::META_IMPORT_ID) !== '') {
			return true;
		}
		$via = (string) $order->get_created_via();
		return str_starts_with($via, TPFWLI_Plugin::CREATED_VIA_PREFIX . ':');
	}

	private function text_row(string $name, string $label, string $value, string $type = 'text'): void
	{
		echo '<tr><th><label for="tpfwli_' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
		echo '<input class="regular-text" type="' . esc_attr($type) . '" id="tpfwli_' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" required />';
		echo '</td></tr>';
	}

	private function kv(string $label, string $value): void
	{
		echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
	}

	/**
	 * @param array<string,string> $args
	 */
	private function url(array $args = array()): string
	{
		$args['page'] = self::SLUG;
		return add_query_arg($args, admin_url('admin.php'));
	}

	private function flash_key(string $kind): string
	{
		return 'tpfwli_' . $kind . '_' . get_current_user_id();
	}

	private function run_key(string $token): string
	{
		return 'tpfwli_run_' . get_current_user_id() . '_' . $token;
	}

	private function new_run_token(): string
	{
		return bin2hex(random_bytes(16));
	}

	/**
	 * @return array{present:bool,ok:bool,id:int}
	 */
	private function requested_order(): array
	{
		if (!isset($_GET['order_id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array('present' => false, 'ok' => true, 'id' => 0);
		}
		$raw = wp_unslash((string) $_GET['order_id']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ($raw === '' || !ctype_digit($raw) || (int) $raw < 1) {
			return array('present' => true, 'ok' => false, 'id' => 0);
		}
		return array('present' => true, 'ok' => true, 'id' => (int) $raw);
	}

	private function requested_run_token(): string
	{
		if (!isset($_GET[self::RUN_QUERY])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		$token = sanitize_key((string) wp_unslash($_GET[self::RUN_QUERY])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return preg_match('/^[a-f0-9]{16,64}$/', $token) ? $token : '';
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function load_run(string $token): ?array
	{
		if ($token === '') {
			return null;
		}
		$data = get_transient($this->run_key($token));
		return is_array($data) ? $data : null;
	}

	/**
	 * @return array{ok:bool,page:int}
	 */
	private function requested_list_page(): array
	{
		if (!isset($_GET[self::PAGE_QUERY])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array('ok' => true, 'page' => 1);
		}
		$raw = wp_unslash((string) $_GET[self::PAGE_QUERY]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ($raw === '' || !ctype_digit($raw) || (int) $raw < 1) {
			return array('ok' => false, 'page' => 1);
		}
		return array('ok' => true, 'page' => (int) $raw);
	}

	private function is_already_sent_message(string $error): bool
	{
		return str_contains(strtolower($error), 'already sent');
	}
}
