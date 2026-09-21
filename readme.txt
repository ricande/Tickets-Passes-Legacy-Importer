=== Tickets & Passes – Legacy Ticket Importer ===
Contributors: ricande
Tags: woocommerce, tickets, import
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Temporary admin tool that turns a historical ticket sale into a WooCommerce guest order so Tickets & Passes for WooCommerce can issue QR tickets.

== Description ==

This is a temporary **admin-only** plugin. It is **not** part of Tickets & Passes for WooCommerce.

Use it on a disposable copy of the shop, not on production, until you have verified the import.

WooCommerce **HPOS must be on**. Tickets & Passes for WooCommerce 1.3.0 or newer must be active, with the Ticket product type enabled.

== Installation ==

1. Install the WordPress plugin zip from the GitHub Release — not GitHub’s Source code archive.
2. Activate the plugin.
3. Open WooCommerce → Legacy Ticket Importer.

== Changelog ==

= 1.0.2 =
* Fail closed when import identity, refund, list COUNT or later reread cannot be trusted.
* Keep resume and email retry from issuing extra tickets, reducing stock again or mailing an unverified mapping.
* Require a unique one-to-one ticket-code bijection, compared exactly, before an import is treated as issued.
* Bind admin results to the requested import and recover an empty high overview page to the real last page.
* Add reproducible GitHub Actions that provision pinned WordPress, WooCommerce HPOS and Tickets & Passes revisions.

= 1.0.1 =
* Qualified implementation release for legacy ticket migration: atomic HPOS order bootstrap, crash-safe retry, strict order shape before stock, product-config snapshot protection, email-retry correctness and POST-only mutations.

= 1.0.0 =
* Initial release.
