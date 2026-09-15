# Tickets & Passes – Legacy Ticket Importer

Temporary **admin-only** WordPress plugin. It turns one already-paid historical ticket sale into a real WooCommerce guest order, lets **Tickets & Passes for WooCommerce** issue the QR tickets on its normal path, then emails the customer.

It is **not** part of Tickets & Passes for WooCommerce. Do not install it on production until you have run it against a disposable copy of the shop.

[![Release](https://img.shields.io/github/v/release/ricande/Tickets-Passes-Legacy-Importer?label=release)](https://github.com/ricande/Tickets-Passes-Legacy-Importer/releases/tag/1.0.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-HPOS-7f54b3)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)](https://www.php.net)
[![License](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)](LICENSE)

[**Download 1.0.0**](https://github.com/ricande/Tickets-Passes-Legacy-Importer/releases/download/1.0.0/tickets-passes-legacy-importer-1.0.0.zip)
· [Report an issue](https://github.com/ricande/Tickets-Passes-Legacy-Importer/issues/new)

Shop-owner install notes also live in [`readme.txt`](readme.txt). How tests vs the WordPress zip are split: [`docs/release.md`](docs/release.md).

## What it is for

A shop that already sold entrance tickets before TPFW 1.3.0 was installed. For each old purchase you have at least:

- First name, last name, email, phone, quantity

V1 is a single-row admin form. There is no CSV import, no public REST, no nopriv AJAX, and no WordPress user creation.

## Dependencies

- WordPress ≥ 6.5 (developed against 7.1)
- WooCommerce ≥ 8 (developed against **11.1.0**)
- **WooCommerce HPOS must be on.** V1 will not run against legacy CPT order storage. First-time order bootstrap runs inside a MySQL transaction (`wc_transaction_query`) covering the HPOS order tables, import snapshots and the Ticket line.
- Tickets & Passes for WooCommerce **≥ 1.3.0**, with the Ticket product type enabled

## Ticket product for this importer

Create a real `tpfw-ticket` product that future online sales will use as well. For V1 it must have:

- WooCommerce **stock management** on (store-wide and on the product)
- **Max uses** &gt; 0
- **Valid duration** in seconds &gt; 0
- **Predefined start date enabled**, with a `Y-m-d` date

Example: start `2026-10-10`, duration `172800` (two days). Imported tickets and new web orders then share the same event window.

Customer-selected start dates and “valid for N seconds from purchase/import time” are **refused**. This importer will not silently turn an old sale into “48 hours from import”.

## Preview / Confirm

1. WooCommerce → **Legacy Ticket Importer**
2. Fill the form → **Preview legacy purchase**
3. Preview re-reads the live product. It must say **No order has been created yet.**
4. **Confirm import** creates/resumes the guest order, reduces WooCommerce stock, asks TPFW to issue, verifies the tickets, then sends one completed-order email to the **billing** address.

Hidden fields are not trusted. Confirm always re-fetches product, price, stock and validity.

## Retries

The immutable **import ID** is the idempotency key (MySQL named lock for concurrent requests, plus a MySQL transaction around first-time order bootstrap). A crash during bootstrap rolls the order back; Confirm with the same ID then creates exactly one order. `created_via` remains an extra lookup/audit field on committed orders. A missing Ticket line on a still-bootstrapping committed order is repaired. Extra, wrong, or quantity-drifted lines fail closed before stock.

Result/overview reads WooCommerce + TPFW state, not the short-lived flash transient.

| Failed step | Button | Will not do |
|---|---|---|
| Ticket issue | Retry ticket issue | Create a second order or reduce stock again |
| Email | Retry email | Issue tickets or reduce stock again |
| Email already sent | *(none)* | A normal retry will not send again |
| Email stage `sending` / `unknown` | *(blocked)* | Automatic resend. Check Mailpit/SMTP first. |

TPFW’s own issue path is idempotent: retry keeps the same nano IDs.

## Stock is source of truth

There is no separate ticket-capacity counter. If the product has stock 500 and you import 2+1+4, stock becomes 493. Later web orders use the same number. The plugin never writes `_stock` or `_order_stock_reduced`.

## Guest orders

`customer_id = 0`. No WordPress account is created. TPFW ticket rows therefore have `user_id = 0`.

TPFW’s Ticket Dashboard **Resend** looks up `get_user_by('ID', user_id)` and **cannot** resend these. Use WooCommerce’s order screen **Resend order emails / Send order details** instead (it uses billing email and TPFW still injects QR codes on `woocommerce_email_order_details`).

## After you delete this plugin

Imported orders, stock movements, TPFW tickets, QR files, order notes and `_tpfwli_*` audit meta **stay**. Scanner, refunds and cancel/revoke keep working. Uninstall on purpose does not delete any of that.

## Accounting / analytics

Each import is a real WooCommerce order at the **current product price**, dated at **import time**. WooCommerce analytics will treat it as a sale then. V1 has no historical purchase date and no custom price. That is intentional — not a hidden “fix”.

## Verify before removing the plugin

- Stock moved by the imported quantity
- One order per import ID
- `customer_id = 0` and correct billing fields
- N live `tpfw_tickets` rows and `tpfw_ticket_id_1..N` on the line
- QR files present; validity matches the product event window
- Customer mail went to billing email and listed every QR
- Confirm the same import ID again: no extra order, stock, tickets or mail
- Deactivate this plugin: order and tickets remain; scanner still accepts them

## Development / source checkout

PHPUnit lives in the Git repository, not in the WordPress plugin zip. See [`docs/release.md`](docs/release.md).

```bash
bash tests/run.sh
```

Requires the local WordPress path (default `/var/www/woocommerce`) with WooCommerce, HPOS and TPFW active. Set `TPFWLI_WP_PATH` if needed.

WordPress plugin zip:

```bash
bash scripts/build-plugin-zip.sh
```

“Email sent” in the UI means the WooCommerce mailer returned success. It does not prove the message reached the inbox.

## License

GPLv2 or later. See [`LICENSE`](LICENSE).

## Download

Use the WordPress plugin zip from the GitHub Release — not GitHub’s automatically generated **Source code** archive.

**Tickets & Passes – Legacy Ticket Importer 1.0.0**

| | |
|---|---|
| Version | 1.0.0 |
| File | `tickets-passes-legacy-importer-1.0.0.zip` |
| SHA-256 | `7676d290276ac9fa0f0f732b639daf1b928790ee2d6de68b8a04be4a414fbc66` |

[⬇ Download the WordPress plugin](https://github.com/ricande/Tickets-Passes-Legacy-Importer/releases/download/1.0.0/tickets-passes-legacy-importer-1.0.0.zip)

> Do not use GitHub's automatically generated **Source code** archives as the WordPress plugin package.
