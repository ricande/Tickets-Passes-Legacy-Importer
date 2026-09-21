<?php
/**
 * Connect to the isolated MySQL instance used for transaction-state tests.
 */
function tpfwli_isolated_mysql_wpdb(): ?wpdb
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
