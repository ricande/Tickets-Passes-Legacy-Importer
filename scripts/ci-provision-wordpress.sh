#!/usr/bin/env bash
# Provision a disposable WordPress + WooCommerce HPOS + pinned TPFW tree for CI.
# Required env: TPFWLI_WP_PATH, TPFWLI_DB_NAME, TPFWLI_DB_USER, TPFWLI_DB_PASSWORD, TPFWLI_DB_HOST
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=ci-pins.env
source "$ROOT/scripts/ci-pins.env"

WP_PATH="${TPFWLI_WP_PATH:?set TPFWLI_WP_PATH}"
DB_NAME="${TPFWLI_DB_NAME:?set TPFWLI_DB_NAME}"
DB_USER="${TPFWLI_DB_USER:?set TPFWLI_DB_USER}"
DB_PASSWORD="${TPFWLI_DB_PASSWORD:?set TPFWLI_DB_PASSWORD}"
DB_HOST="${TPFWLI_DB_HOST:?set TPFWLI_DB_HOST}"
WP="${WP:-wp}"

if ! command -v "$WP" >/dev/null 2>&1 && [[ -x /usr/local/bin/wp ]]; then
	WP=/usr/local/bin/wp
fi
if ! command -v "$WP" >/dev/null 2>&1; then
	echo "wp-cli is required" >&2
	exit 1
fi

mkdir -p "$WP_PATH"
if [[ ! -f "$WP_PATH/wp-load.php" ]]; then
	"$WP" core download --version="$WP_VERSION" --path="$WP_PATH" --force
fi

if [[ ! -f "$WP_PATH/wp-config.php" ]]; then
	"$WP" config create \
		--path="$WP_PATH" \
		--dbname="$DB_NAME" \
		--dbuser="$DB_USER" \
		--dbpass="$DB_PASSWORD" \
		--dbhost="$DB_HOST" \
		--skip-check \
		--force
fi

if ! "$WP" core is-installed --path="$WP_PATH"; then
	"$WP" core install \
		--path="$WP_PATH" \
		--url="${TPFWLI_WP_URL:-http://localhost}" \
		--title='TPFWLI CI' \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--skip-email
fi

INSTALLED_WP="$("$WP" core version --path="$WP_PATH")"
if [[ "$INSTALLED_WP" != "$WP_VERSION" && "$INSTALLED_WP" != "${WP_VERSION}.0" ]]; then
	echo "WordPress version mismatch: have ${INSTALLED_WP}, want ${WP_VERSION}" >&2
	exit 1
fi

if ! "$WP" plugin is-installed woocommerce --path="$WP_PATH"; then
	"$WP" plugin install woocommerce --version="$WC_VERSION" --path="$WP_PATH"
fi
"$WP" plugin activate woocommerce --path="$WP_PATH"

INSTALLED_WC="$("$WP" plugin get woocommerce --field=version --path="$WP_PATH")"
if [[ "$INSTALLED_WC" != "$WC_VERSION" ]]; then
	echo "WooCommerce version mismatch: have ${INSTALLED_WC}, want ${WC_VERSION}" >&2
	exit 1
fi

TPFW_DIR="$WP_PATH/wp-content/plugins/tickets-passes-for-woocommerce"
if [[ ! -d "$TPFW_DIR/.git" ]]; then
	rm -rf "$TPFW_DIR"
	git clone --filter=blob:none "$TPFW_REPO" "$TPFW_DIR"
fi
git -C "$TPFW_DIR" fetch --force origin "$TPFW_COMMIT"
git -C "$TPFW_DIR" checkout --detach --force "$TPFW_COMMIT"
TPFW_HEAD="$(git -C "$TPFW_DIR" rev-parse HEAD)"
if [[ "$TPFW_HEAD" != "$TPFW_COMMIT" ]]; then
	echo "TPFW commit mismatch: have ${TPFW_HEAD}, want ${TPFW_COMMIT}" >&2
	exit 1
fi
"$WP" plugin activate tickets-passes-for-woocommerce --path="$WP_PATH"

"$WP" option update woocommerce_coming_soon 'no' --path="$WP_PATH"
"$WP" option update woocommerce_manage_stock 'yes' --path="$WP_PATH"
"$WP" option update woocommerce_custom_orders_table_enabled 'yes' --path="$WP_PATH"
"$WP" option update woocommerce_feature_custom_order_tables_enabled 'yes' --path="$WP_PATH"
"$WP" option update woocommerce_custom_orders_table_data_sync_enabled 'no' --path="$WP_PATH"
if "$WP" wc hpos enable --path="$WP_PATH" >/dev/null 2>&1; then
	true
fi

"$WP" eval --path="$WP_PATH" '
$opts = get_option("tpfw_general_settings_options", array());
if (!is_array($opts)) { $opts = array(); }
$opts["bEnableTicketProduct"] = 1;
update_option("tpfw_general_settings_options", $opts);
echo "ticket_type=1\n";
'

IMPORTER_LINK="$WP_PATH/wp-content/plugins/tickets-passes-legacy-importer"
if [[ -e "$IMPORTER_LINK" || -L "$IMPORTER_LINK" ]]; then
	rm -rf "$IMPORTER_LINK"
fi
ln -s "$ROOT" "$IMPORTER_LINK"
"$WP" plugin activate tickets-passes-legacy-importer --path="$WP_PATH"

"$WP" eval --path="$WP_PATH" '
if (!class_exists("Automattic\\WooCommerce\\Utilities\\OrderUtil")
	|| !Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
	fwrite(STDERR, "HPOS is not enabled\n");
	exit(1);
}
if (!defined("TPFW_VERSION")) {
	fwrite(STDERR, "TPFW is not loaded\n");
	exit(1);
}
echo "hpos=on tpfw=" . TPFW_VERSION . "\n";
'

echo "provision_ok wp=${INSTALLED_WP} wc=${INSTALLED_WC} tpfw=${TPFW_HEAD}"
