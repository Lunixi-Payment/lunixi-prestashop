---
title: PrestaShop
description: Native Lunixi payment module for PrestaShop 8/9 — merchant ZIP, encrypted credentials, hosted or embedded form, verified webhooks and durable capture/refund operations.
---


# PrestaShop

`lunixipay` connects PrestaShop's native checkout/order lifecycle to Lunixi. Merchants choose the embedded or hosted Lunixi form; enabled methods, installments, eligible alternative methods, insurance and multi-card availability remain managed in Lunixi. The module never collects PAN/CVV.

The standalone merchant ZIP bundles a namespace-isolated [PHP SDK](/sdks/php). Shared Commerce code owns signing, integer money, durable attempts, webhook authentication, split-parent evidence and safe retries. Merchants do not need Composer.

## Requirements and installation

- PrestaShop **8.1+ / 9.x**, on a PHP version supported by the platform; **64-bit PHP 8.1+** is required by the module.
- Sodium, cURL, JSON, PDO MySQL; all shop tables must use **InnoDB**.
- HTTPS storefront/webhook, registered Lunixi merchant/key and webhook secret.
- A separate, backed-up 32-byte server encryption key outside the database/public web root.

Source: [Lunixi-Payment/lunixi-prestashop](https://github.com/Lunixi-Payment/lunixi-prestashop). Version 0.1.2 is a source/module release; this does not imply PrestaShop Addons approval. Native acceptance ran on PrestaShop 9.2.0 / PHP 8.3.6 / MariaDB 11 with synthetic gateway transport. The same 53-assertion native suite also passed on official 8.2.4 in an isolated local PHP 8.3.6/MariaDB fixture. Both official-image native CI jobs remain independent release gates.

Generate the server key locally:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Store it as `LUNIXI_ENCRYPTION_KEY` in the PHP worker environment through your secret manager. Do not derive it from a database/cookie/private/webhook key. Retain it across restarts and upgrades; loss prevents decryption of historical credentials/tokens.

Upload **`lunixipay-0.1.2.zip`** through **Module Manager → Upload a module**, then install/configure **Lunixi Payments**. The ZIP root is `lunixipay/`. A GitHub source archive is not the built merchant ZIP.

## Merchant configuration

Select one shop in multistore mode, then save:

| Setting | Meaning |
| --- | --- |
| Merchant ID | Merchant identity used to bind settlement evidence |
| Key ID / private Ed25519 key | Registered server-side signing credentials |
| Webhook secret | Secret matching the merchant webhook connection |
| Checkout mode | Hosted `pay.lunixi.com` form or embedded Lunixi form |
| Authorize first, capture later | Keep authorizations on hold until capture |

Secret fields are never populated back into the form; leaving a field blank preserves its saved secret. Credentials are authenticated-encrypted using the independent server key. Saving requires native employee module-edit permission and admin CSRF verification.

Register the **exact webhook URL shown in configuration**, including `shop_id`, in the Lunixi dashboard. Subscribe to the documented merchant payment events. Set currency/country/carrier/customer-group availability through PrestaShop's native payment preferences.

The approved gateway origin is fixed to `https://api-gateway.lunixi.com`; arbitrary browser/admin URLs are not accepted. The merchant key determines TEST/LIVE access.

## Checkout and order states

PrestaShop shows one **Pay securely with Lunixi** option. Native authenticated customer/guest session, order ownership and shop identity are checked before exposing a browser token. Initial checkout also requires a native customer CSRF token.

The adapter creates an unpaid native order, serializes conversion of the cart, reads its DECIMAL total as a string and persists a payment reservation before gateway I/O. A timeout preserves the exact attempt/key. Repeating checkout reuses that operation; it never silently creates another charge.

- Hosted mode redirects only to `https://pay.lunixi.com/payment/<token>`.
- Embedded mode loads the fixed official `https://cdn.lunixi.com/lunixi-checkout.min.js` through PrestaShop's asset API and mounts browser-safe JSON. Gateway-supplied HTML is not rendered.
- Authorization and partial capture stay on hold. Full authenticated settlement is required for a paid transition.

The merchant's form determines method availability; no duplicate method toggles are added to PrestaShop. Permit the official form's required origins in your storefront CSP.

> 
Version 0.1.2 supports one shipping package per cart. Multishipping/multi-package carts are rejected before a financial API call. Reconcile an existing attempt before changing totals, currency, merchant or checkout settings; an expired/missing token does not authorize a fresh charge.


Native computing-precision totals use PrestaShop’s exact decimal rounding before minor-unit conversion; currency precision must match the gateway ISO exponent. Half-odd rounding with nonzero sub-minor precision requires manual reconciliation because the native decimal library lacks that mode. Credential rotations retain encrypted history and two previous webhook secrets; a shop with financial history cannot be rebound to another merchant ID.

## Verified webhooks and reconciliation

The SDK verifies bounded raw-body v2 signature, timestamp, signed identity and replay/collision rules, correlates the persisted intent and authenticates a payment GET. Browser-return parameters cannot mark an order paid.

The SDK ledger reuses **PrestaShop's exact primary PDO connection**. Event receipt and native order/payment/history/invoice/stock changes commit in one InnoDB transaction. Failure rolls both back; replay cannot create another payment or invoice. Unknown, partial or inconsistent settlement does not trigger fulfillment.

Split success requires the correct parent, balanced aggregate totals, complete capture and no compensation/reconciliation condition. A single captured leg is insufficient. A buyer installment fee requires an authenticated echo of the persisted order fingerprint. The native basket amount is recorded once; gross charged/refunded balances remain separately visible in the ledger/admin panel.

Native status/download emails are placed in an encrypted durable outbox during that transaction. Schedule maintenance every few minutes:

Run cron and maintenance under the **same OS account as the web PHP worker**; use the account configured by your deployment. Preserve the same `LUNIXI_ENCRYPTION_KEY` and required PHP extensions. Root-created cache files can prevent the web worker from writing; repair ownership for the correct account instead of using `chmod 777`.

```bash
php /path/to/shop/modules/lunixipay/tools/cron.php /path/to/shop
```

The command reconciles attempts through authenticated GETs and drains notifications. Monitor deferred notices/nonzero exits. Email delivery is at least once after commit; a worker failure may duplicate a notification, never a payment transition. Third-party hooks must also use transactional storage and defer external effects.

## Capture, refund and void

The native order's Lunixi panel provides **Reconcile**, **Capture**, **Refund** and **Void authorization**. Actions require POST, admin CSRF, employee order/module edit permissions and shop association.

Enter an exact decimal amount in the order currency. The module persists a native operation ID before the gateway request. The same ID cannot be rebound to a different action/amount. Unresolved operations remain visible with their original immutable parameters; new financial IDs are blocked until authenticated settlement confirms the original. An HTTP 2xx mutation response is not confirmation. Retry uncertainty with the same ID and amount, or reconcile first.

> 
Gateway refunds move funds only. Create native product/tax credit notes and stock returns separately under your accounting policy, without repeating the gateway refund. The module does not invent item/VAT allocation for a monetary refund. Split refunds may need reconciliation when a method is nonrefundable.


## Uninstall and retention

Uninstall disables the module and removes its admin tab while retaining attempts, receipt history, operations, encrypted credentials, outbox and native financial states. Reinstall preserves the identifiers. Retain encryption keys/merchant access through your reconciliation and refund retention period. Data deletion is a separate reviewed retention action.

## Source build and validation

```bash
composer install --working-dir=tools --no-interaction
php tools/build.php
php tools/check-artifact.php
```

Default builds resolve public `lunixi/php-sdk:0.29.3`. Before that registry version is published, provide an explicit reviewed SDK checkout with `php tools/build.php /absolute/path/to/lunixi-php`. The build input path is never exported. PHP-Scoper isolates the SDK under `Lunixi\PrestaShop\Vendor\Lunixi\Sdk`; the ZIP has no dependency on sibling sources, unscoped aliases or the site's Composer loader.

Validation includes PHPCS, PHPStan level 8 against an installed native platform, Composer audit, artifact/secret-boundary checks and real native models/database tests with synthetic gateway transport. Tests exercise a real database-trigger rollback, stable retry keys, encrypted storage, ownership/CSRF gates, verified webhook/GET, replay, capture/partial capture/void/refund and uninstall retention. A separate fresh-process test checks reinstall.

A registered TEST-merchant checkout/webhook/refund run and storefront/theme acceptance are required before live use. See the repository [README](https://github.com/Lunixi-Payment/lunixi-prestashop) and [security policy](https://github.com/Lunixi-Payment/lunixi-prestashop/blob/main/SECURITY.md).


The informational return accepts only the order locator from the URL query, discards at most 1 MiB of POST body without interpreting any payment fields, then returns an empty 303 to the native shop return URL. The POST does not load an order or call the gateway; unknown order IDs receive the same redirect. The resumed GET retains native customer/guest authentication and order/shop ownership before authenticated reconciliation. Checkout CSRF remains required. Apply matching request-body limits at the reverse proxy as PHP may buffer input before controller execution.


Release 0.1.2 verification: **53 native assertions** on PrestaShop 9.2.0 / PHP 8.3.6 / MariaDB 11, **35 real HTTP assertions**, fresh-request reinstall, PHPStan level 8, PHPCS, Composer audits and standalone artifact/secret checks. Fresh activation was repeated after uninstall without manually clearing platform caches. HTTP tests use a genuine native login by a different synthetic customer and prove order ownership still returns 403; all gateway financial responses are synthetic.


Turkish catalog verification initializes the actual native `tr-TR` locale loader, as a Turkish request does, and asserts both Shop and Admin dictionaries. It does not ask an already-English BO translator to discover an uninitialized locale after installation. The 0.1.2 correction changes test/release metadata only; financial behavior is preserved. `VERSION` is the merchant ZIP version source even when a source publisher strips Composer's optional version field.
