<?php
/**
 * Make sure PHPUnit and helper processes execute this checkout, not another copy
 * already active in WordPress. Already-defined classes are never reloaded.
 */

/**
 * Real path of tickets-passes-legacy-importer.php in this checkout.
 */
function tpfwli_test_checkout_plugin_file(): string
{
	$file = dirname(__DIR__, 2) . '/tickets-passes-legacy-importer.php';
	$real = realpath($file);
	if (!is_string($real) || $real === '') {
		fwrite(STDERR, "Could not resolve the importer checkout at {$file}\n");
		exit(1);
	}
	return $real;
}

function tpfwli_test_normalize_path(string $path): string
{
	$real = realpath($path);
	return is_string($real) && $real !== '' ? $real : $path;
}

/**
 * Filesystem path of an already-defined class. Does not autoload.
 */
function tpfwli_test_class_file(string $class): ?string
{
	if (!class_exists($class, false)) {
		return null;
	}
	$file = (new ReflectionClass($class))->getFileName();
	if (!is_string($file) || $file === '') {
		return null;
	}
	return tpfwli_test_normalize_path($file);
}

function tpfwli_test_checkout_mismatch_message(string $expected, ?string $actual): string
{
	return sprintf(
		"Legacy importer tests must load the current checkout.\nExpected: %s\nActual: %s\n",
		$expected,
		$actual !== null && $actual !== '' ? $actual : '(not loaded)'
	);
}

/**
 * Compare two filesystem paths after symlink resolution.
 */
function tpfwli_test_same_checkout_path(string $expected, string $actual): bool
{
	return tpfwli_test_normalize_path($expected) === tpfwli_test_normalize_path($actual);
}

function tpfwli_test_path_is_in_checkout(string $path): bool
{
	$expected = tpfwli_test_checkout_plugin_file();
	$actual   = tpfwli_test_normalize_path($path);
	if ($actual === $expected) {
		return true;
	}
	$root = dirname($expected) . DIRECTORY_SEPARATOR;
	return str_starts_with($actual, $root);
}

function tpfwli_test_abort_unless_checkout(?string $actual): void
{
	$expected = tpfwli_test_checkout_plugin_file();
	if ($actual !== null && tpfwli_test_path_is_in_checkout($actual)) {
		return;
	}
	fwrite(STDERR, tpfwli_test_checkout_mismatch_message($expected, $actual));
	exit(1);
}

/**
 * If an importer copy is already in memory, it must be this checkout.
 * Missing classes are allowed so deactivation checks can run.
 */
function tpfwli_test_reject_foreign_importer_if_loaded(): void
{
	$loaded = tpfwli_test_class_file('TPFWLI_Plugin');
	if ($loaded === null) {
		return;
	}
	tpfwli_test_abort_unless_checkout($loaded);
	if (defined('TPFWLI_PLUGIN_FILE')) {
		tpfwli_test_abort_unless_checkout((string) TPFWLI_PLUGIN_FILE);
	}
}

/**
 * Abort when a different importer copy is already in memory. Optionally load this checkout.
 *
 * @return string Real path of the loaded plugin file.
 */
function tpfwli_test_assert_checkout_code(bool $load_if_missing = true): string
{
	$expected = tpfwli_test_checkout_plugin_file();
	$loaded   = tpfwli_test_class_file('TPFWLI_Plugin');

	if ($loaded !== null) {
		tpfwli_test_abort_unless_checkout($loaded);
	}

	if (defined('TPFWLI_PLUGIN_FILE')) {
		tpfwli_test_abort_unless_checkout((string) TPFWLI_PLUGIN_FILE);
	}

	if ($loaded === null) {
		if (!$load_if_missing) {
			tpfwli_test_abort_unless_checkout(null);
		}
		require $expected;
		if (class_exists('TPFWLI_Plugin', false)) {
			TPFWLI_Plugin::instance()->boot();
		}
		$loaded = tpfwli_test_class_file('TPFWLI_Plugin');
	}

	tpfwli_test_abort_unless_checkout($loaded);

	if (defined('TPFWLI_PLUGIN_FILE')) {
		tpfwli_test_abort_unless_checkout((string) TPFWLI_PLUGIN_FILE);
	}

	$orchestrator = tpfwli_test_class_file('TPFWLI_Orchestrator');
	tpfwli_test_abort_unless_checkout($orchestrator);

	if (!class_exists('TPFWLI_Orchestrator', false)) {
		fwrite(STDERR, "Legacy importer classes failed to load.\n");
		exit(1);
	}

	return defined('TPFWLI_PLUGIN_FILE')
		? tpfwli_test_normalize_path((string) TPFWLI_PLUGIN_FILE)
		: $expected;
}

function tpfwli_test_writable_uploads_root(): string
{
	$root = dirname(__DIR__, 2) . '/tests/.uploads';
	if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
		fwrite(STDERR, "Could not create writable test uploads at {$root}\n");
		exit(1);
	}
	return $root;
}

/**
 * PHPUnit and workers run as the checkout owner, who cannot write the shop uploads.
 * Keep QR and other media in a disposable directory owned by this checkout.
 */
function tpfwli_test_redirect_uploads(): void
{
	static $done = false;
	if ($done) {
		return;
	}
	$done = true;
	$root = tpfwli_test_writable_uploads_root();
	add_filter('upload_dir', static function ($dirs) use ($root) {
		if (!is_array($dirs)) {
			return $dirs;
		}
		$subdir = (string) ($dirs['subdir'] ?? '');
		$dirs['basedir'] = $root;
		$dirs['baseurl'] = 'https://tpfwli.test/uploads';
		$dirs['path']    = $root . $subdir;
		$dirs['url']     = $dirs['baseurl'] . $subdir;
		$dirs['error']   = false;
		return $dirs;
	}, 0);
	if (function_exists('wp_upload_dir')) {
		wp_upload_dir(null, true, true);
	}
}

function tpfwli_test_load_wordpress(): void
{
	if (defined('ABSPATH')) {
		return;
	}

	$wp_root = getenv('TPFWLI_WP_PATH') ?: '/var/www/woocommerce';
	if (!is_readable($wp_root . '/wp-load.php')) {
		fwrite(STDERR, "WordPress not found at {$wp_root}. Set TPFWLI_WP_PATH.\n");
		exit(1);
	}

	if (!defined('WP_USE_THEMES')) {
		define('WP_USE_THEMES', false);
	}
	require $wp_root . '/wp-load.php';
}

/**
 * Boot WordPress, then this checkout. Never swaps the shop's active plugin.
 *
 * @return string Real path of the loaded importer plugin file.
 */
function tpfwli_test_load_wordpress_and_checkout(): string
{
	tpfwli_test_load_wordpress();

	if (!class_exists('WooCommerce') || !defined('TPFW_VERSION')) {
		fwrite(STDERR, "WooCommerce and Tickets & Passes for WooCommerce must be active.\n");
		exit(1);
	}

	tpfwli_test_redirect_uploads();
	return tpfwli_test_assert_checkout_code(true);
}
