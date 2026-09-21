<?php
defined('ABSPATH') || exit;

/**
 * Per-import-ID MySQL named lock. Two parallel Confirm posts cannot both create an order.
 */
final class TPFWLI_Import_Lock
{
	private $wpdb;
	private string $name;
	private $raw = null;

	public function __construct($wpdb, string $import_id)
	{
		$this->wpdb = $wpdb;
		$this->name = 'tpfwli_' . md5($import_id);
	}

	public function acquire(int $timeout = 10): bool
	{
		$this->raw = $this->wpdb->get_var($this->wpdb->prepare('SELECT GET_LOCK(%s, %d)', $this->name, $timeout));
		return $this->raw === '1' || $this->raw === 1;
	}

	public function release(): void
	{
		if ($this->raw !== '1' && $this->raw !== 1) {
			return;
		}
		if (class_exists('TPFWLI_Database_Session') && TPFWLI_Database_Session::is_quarantined()) {
			$this->raw = '0';
			return;
		}
		$this->wpdb->get_var($this->wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->name));
		$this->raw = '0';
	}
}
