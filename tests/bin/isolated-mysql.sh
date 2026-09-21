#!/usr/bin/env bash
# Isolated MySQL 8 for transaction-state tests. Does not touch the host MariaDB.
set -euo pipefail
NAME="${TPFWLI_MYSQL_CONTAINER:-tpfwli-mysql-txn}"
PORT="${TPFWLI_MYSQL_PORT:-3307}"
PASS="${TPFWLI_MYSQL_PASSWORD:-tpfwli}"
DB="${TPFWLI_MYSQL_DATABASE:-tpfwli_txn}"
IMAGE="${TPFWLI_MYSQL_IMAGE:-mysql:8.0}"

cmd="${1:-status}"

start() {
	if docker ps -a --format '{{.Names}}' | grep -qx "$NAME"; then
		docker start "$NAME" >/dev/null
	else
		docker run -d --name "$NAME" \
			-e MYSQL_ROOT_PASSWORD="$PASS" \
			-e MYSQL_DATABASE="$DB" \
			-p "127.0.0.1:${PORT}:3306" \
			"$IMAGE" \
			--performance-schema=ON \
			--performance-schema-instrument=transaction=ON \
			--performance-schema-consumer-events-transactions-current=ON \
			--performance-schema-consumer-events-transactions-history=ON >/dev/null
	fi
	for _ in $(seq 1 40); do
		if docker exec "$NAME" mysqladmin ping -uroot -p"$PASS" --silent >/dev/null 2>&1 \
			&& docker exec "$NAME" mysql -uroot -p"$PASS" -e "SELECT 1" >/dev/null 2>&1; then
			docker exec "$NAME" mysql -uroot -p"$PASS" -e "
				UPDATE performance_schema.setup_instruments SET ENABLED='YES', TIMED='YES' WHERE NAME='transaction';
				UPDATE performance_schema.setup_consumers SET ENABLED='YES' WHERE NAME LIKE '%transactions%';
			" >/dev/null
			echo "mysql://${PORT} ready"
			return 0
		fi
		sleep 1
	done
	echo "isolated MySQL did not become ready" >&2
	return 1
}

case "$cmd" in
	start) start ;;
	stop) docker rm -f "$NAME" >/dev/null 2>&1 || true ;;
	status)
		if docker exec "$NAME" mysql -uroot -p"$PASS" -N -e "SELECT VERSION()" 2>/dev/null; then
			exit 0
		fi
		exit 1
		;;
	*)
		echo "usage: $0 start|stop|status" >&2
		exit 2
		;;
esac
