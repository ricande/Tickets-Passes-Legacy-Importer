# Tests and production ZIP

## Source / review — run tests

From a Git checkout of this plugin directory:

```bash
bash tests/run.sh
```

PHPUnit lives in the Git repository, not in the WordPress plugin zip. `tests/run.sh` downloads PHPUnit 11.5.42 into `tests/phpunit.phar` when it is missing. The phar is gitignored.

Requires the local WordPress path (default `/var/www/woocommerce`) with WooCommerce, HPOS and Tickets & Passes for WooCommerce active. Set `TPFWLI_WP_PATH` if needed.

GitHub Actions (`.github/workflows/ci.yml`) provisions a clean WordPress 7.1.1 + WooCommerce 11.1.0 + HPOS + pinned TPFW commit from `scripts/ci-pins.env`, then runs `php -l`, the full PHPUnit suite and the ZIP package contract. That workflow is the CI proof; a green local suite is not.

## Production ZIP

```bash
bash scripts/build-plugin-zip.sh
```

Writes `dist/tickets-passes-legacy-importer-<version>.zip` (version from the plugin header). Optional: `TPFWLI_ZIP_OUT=/tmp/plugin.zip`.

Top folder inside the zip: `tickets-passes-legacy-importer/`.

**Included:** plugin PHP, `readme.txt`, `LICENSE`, `uninstall.php`.

**Excluded:** `.git/`, `tests/`, `scripts/`, PHPUnit, `README.md` (GitHub developer index), `phpunit.xml`, `docs/`, `dist/`.
