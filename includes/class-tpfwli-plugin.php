<?php
defined('ABSPATH') || exit;

/**
 * Plugin bootstrap.
 */
final class TPFWLI_Plugin
{
	const META_IMPORT      = '_tpfwli_import';
	const META_IMPORT_ID   = '_tpfwli_import_id';
	const META_ORDER_STAGE = '_tpfwli_order_stage';
	const META_STOCK_STAGE = '_tpfwli_stock_stage';
	const META_ISSUE_STAGE = '_tpfwli_issue_stage';
	const META_EMAIL_STAGE = '_tpfwli_email_stage';
	const META_IMPORTED_AT = '_tpfwli_imported_at';
	const CREATED_VIA      = 'tpfwli_legacy_import';

	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void
	{
		if ($this->booted) {
			return;
		}
		$this->booted = true;

		foreach (array(
			'class-tpfwli-dependencies.php',
			'class-tpfwli-import-lock.php',
			'class-tpfwli-import-repository.php',
			'class-tpfwli-input-validator.php',
			'class-tpfwli-tpfw-adapter.php',
			'class-tpfwli-order-service.php',
			'class-tpfwli-stock-service.php',
			'class-tpfwli-email-service.php',
			'class-tpfwli-orchestrator.php',
			'class-tpfwli-admin-page.php',
		) as $file) {
			require_once TPFWLI_PLUGIN_DIR . 'includes/' . $file;
		}

		TPFWLI_Email_Service::instance()->register();

		if (is_admin()) {
			$admin = new TPFWLI_Admin_Page();
			$admin->register();
		}
	}
}
