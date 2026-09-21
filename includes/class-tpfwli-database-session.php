<?php
defined('ABSPATH') || exit;

/**
 * Isolates the WordPress database connection after an unconfirmed rollback.
 *
 * Closing the connection aborts any leftover SQL transaction. Later business
 * writes must not run, including after an implicit reconnect that would drop
 * the import named lock.
 */
final class TPFWLI_Database_Session
{
	private static bool $quarantined = false;
	private static string $reason = '';

	public static function is_quarantined(): bool
	{
		return self::$quarantined;
	}

	public static function reason(): string
	{
		return self::$reason;
	}

	public static function quarantine(string $reason = ''): void
	{
		self::$quarantined = true;
		self::$reason      = $reason;
		global $wpdb;
		if ($wpdb instanceof wpdb && method_exists($wpdb, 'close')) {
			$wpdb->close();
		}
	}

	public static function assert_writable(): void
	{
		if (!self::$quarantined) {
			return;
		}
		throw new RuntimeException(
			self::$reason !== ''
				? self::$reason
				: __('Database session is isolated after an unconfirmed stock rollback.', 'tickets-passes-legacy-importer')
		);
	}

	public static function reset_for_tests(): void
	{
		self::$quarantined = false;
		self::$reason      = '';
		global $wpdb;
		if ($wpdb instanceof wpdb && empty($wpdb->dbh) && method_exists($wpdb, 'db_connect')) {
			$wpdb->db_connect();
		}
	}
}
