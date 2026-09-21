<?php
/**
 * Exercise TPFWLI_Stock_Service transaction inspect against isolated MySQL.
 *
 * Expects TPFWLI_MYSQL_* or the defaults used by tests/bin/isolated-mysql.sh.
 */
require dirname(__DIR__) . '/lib/checkout-code.php';
$loaded = tpfwli_test_load_wordpress_and_checkout();

$host = getenv('TPFWLI_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('TPFWLI_MYSQL_PORT') ?: '3307';
$user = getenv('TPFWLI_MYSQL_USER') ?: 'root';
$pass = getenv('TPFWLI_MYSQL_PASSWORD') ?: 'tpfwli';
$name = getenv('TPFWLI_MYSQL_DATABASE') ?: 'tpfwli_txn';

$mysql = new wpdb($user, $pass, $name, $host . ':' . $port);
if (!empty($mysql->error) || !$mysql->ready) {
	fwrite(STDERR, "could not connect to isolated MySQL {$host}:{$port}\n");
	exit(2);
}

$previous = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = $mysql;
$mysql->suppress_errors(true);

$mysql->query('CREATE TABLE IF NOT EXISTS tpfwli_probe (id INT PRIMARY KEY) ENGINE=InnoDB');

$service = new TPFWLI_Stock_Service();
$call = static function (string $method) use ($service) {
	return (new ReflectionMethod($service, $method))->invoke($service);
};

$out = array(
	'loaded'  => $loaded,
	'version' => $mysql->get_var('SELECT VERSION()'),
	'engine'  => 'mysql',
);

try {
	$mysql->query('START TRANSACTION');
	$mysql->query('SELECT 1');
	$mysql->query('COMMIT');
	$out['before'] = $call('inspect_sql_transaction');
	$mysql->query('START TRANSACTION');
	$out['after_start_before_innodb'] = array(
		'inspect'    => $call('inspect_sql_transaction'),
		'innodb_trx' => $mysql->get_var('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()'),
	);
	$mysql->query('INSERT INTO tpfwli_probe (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id');
	$out['after_innodb_write'] = array(
		'inspect'    => $call('inspect_sql_transaction'),
		'innodb_trx' => $mysql->get_var('SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = CONNECTION_ID()'),
	);
	$mysql->query('COMMIT');
	$out['after_commit'] = $call('inspect_sql_transaction');
	$mysql->query('START TRANSACTION');
	$mysql->query('INSERT INTO tpfwli_probe (id) VALUES (2) ON DUPLICATE KEY UPDATE id = id');
	$mysql->query('ROLLBACK');
	$out['after_rollback'] = $call('inspect_sql_transaction');
} finally {
	$mysql->query('ROLLBACK');
	$mysql->suppress_errors(false);
	$GLOBALS['wpdb'] = $previous;
}

echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
$after_start = $out['after_start_before_innodb']['inspect']['status'] ?? '';
if ($after_start !== 'open') {
	fwrite(STDERR, "expected open immediately after START, got {$after_start}\n");
	exit(1);
}
if (($out['after_commit']['status'] ?? '') !== 'closed' || ($out['after_rollback']['status'] ?? '') !== 'closed') {
	fwrite(STDERR, "expected closed after COMMIT and ROLLBACK\n");
	exit(1);
}
