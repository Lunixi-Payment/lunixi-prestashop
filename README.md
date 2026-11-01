<p align="center"><img src=".github/assets/lunixi-logo.svg" width="300" alt="Lunixi"></p>

> Version 1.0.2 uses all money to canonical minor-unit strings. Read [the migration guide](MIGRATION-1.0.md).

# Lunixi for PrestaShop

Download the published 1.0.2 merchant ZIP from [GitHub Release v1.0.2](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/tag/v1.0.2) and verify its matching `SHA256SUMS`. This package requires PHP SDK 1.0.1. Follow the [money-string migration](MIGRATION-1.0.md); 0.x ZIPs use the previous numeric-money contract.

[English](README.md) · [Türkçe](README.tr.md) · [Documentation](https://docs.lunixi.com/sdks/prestashop)

Native payment module for **PrestaShop 8.1+ / 9.x**, version **1.0.2**. The existing Lunixi form opens on the merchant website by default; optional hosted checkout uses the validated backend `paymentPageUrl`. Payment methods, installments, eligible alternative methods, insurance and multi-card availability remain managed in the Lunixi dashboard/form. The module never collects PAN/CVV.

Payment signing, encrypted checkout storage, settlement evidence, split-parent checks, fee correlation, idempotency and webhook replay rules use the shared **Lunixi PHP SDK Commerce kernel**. The release ZIP ships an isolated SDK namespace and standalone autoloader; merchants do **not** need Composer.

The current module bundles PHP SDK 1.0.1. Historical 0.1.5 ZIP and acceptance evidence is retained below.

## Requirements

- PrestaShop 8.1+ or 9.x on a PHP version supported by that platform; this module requires **64-bit PHP 8.1+**.
- `ext-sodium`, `ext-curl`, `ext-json`, PDO MySQL, and **InnoDB for all shop tables**.
- HTTPS storefront and webhook URL; outbound access to the fixed approved gateway `https://api-gateway.lunixi.com`.
- Registered Lunixi merchant/key, its Ed25519 private key and webhook secret.
- A separate **32-byte server encryption key**, backed up outside the database/public web root.

Historical 0.1.5 native acceptance used fresh **PrestaShop 8.2.4 / PHP 8.1.34** and **9.2.0 / PHP 8.5.12** shops with **MariaDB 11.8.6/InnoDB**: each passed **70 native assertions, 35 actual HTTPS assertions and fresh-process reinstall**, plus customer embedded/hosted and signed settlement scenarios. Gateway transport was a local TLS stand-in; no bank/card/3DS or full browser/CDN acceptance is claimed. Historical 0.1.3/0.1.4 results remain separately labeled below; publication does not imply Addons approval.

## Install the merchant ZIP

1. Back up the shop database and server secrets.
2. Configure `LUNIXI_ENCRYPTION_KEY` as **base64 of 32 random bytes** in the PHP worker environment. Generate it on the server:

   ```bash
   php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
   ```

   Store the result in your secret manager. Do not derive it from the webhook secret, private key, database password or cookie key. Keep it stable across upgrades/restarts. Retain old keys during a planned credential/token migration; losing this key makes encrypted historical credentials/tokens unreadable.
3. Upload the verified `lunixipay-1.0.2.zip` using **Module Manager → Upload a module**, then install/configure **Lunixi Payments**. The ZIP contains a top-level `lunixipay/` directory. A source ZIP from GitHub is not the merchant installation ZIP.
4. Select one shop when configuring a multistore installation. Save **Merchant ID**, **Key ID**, **Private Ed25519 key**, **Webhook secret**, **Checkout mode** and optional **Authorize first, capture later**. Once payments exist, a shop cannot be rebound to another merchant ID. Rotated credentials remain encrypted in history; current and two previous webhook secrets are accepted during rotation. Secret fields remain blank when editing; blank preserves the saved secret. Only an employee with module-edit permission and a valid admin CSRF token can save.
5. Register the **exact webhook URL displayed in the configuration panel**, including its `shop_id`, in Lunixi. Subscribe to the documented merchant payment events. Configure payment currency/country/carrier/group availability through PrestaShop's native payment preferences.

The API origin is fixed in code; there is no browser/admin-supplied arbitrary gateway URL. The key determines TEST/LIVE access. Credentials are authenticated-encrypted in native configuration with `SecretBox`; no merchant secret is sent to the browser.

## Customer checkout

PrestaShop displays one **Pay securely with Lunixi** option. Native authenticated customer/guest sessions, order ownership and the shop identity are checked before showing a token. Initial checkout requires a native customer CSRF token. The merchant's Lunixi form decides which payment methods appear; the module does not duplicate per-method switches.

The module creates a native **Awaiting Lunixi payment** order, serializes cart conversion and persists a payment attempt before network I/O. It reads the native order DECIMAL amount as text and converts it with shared integer money helpers. A network timeout leaves the attempt and key intact. Repeating checkout reuses the original key/token; it never silently creates another charge.

- **Hosted (optional):** redirects only to the validated `paymentPageUrl` returned by the backend. Multi-card and alternative methods are available in either form mode when enabled for the merchant.
- **Embedded (default):** uses PrestaShop's asset API to load only `https://cdn.lunixi.com/lunixi-checkout.min.js`, renders `#lunixi-checkout-form` and mounts the SDK using browser-safe JSON. Gateway HTML is never printed. Allow the official form's required origins in your storefront CSP.
- **Preauthorization:** authorized and partially captured payments remain on hold. Full authenticated settlement is required for a paid transition.

This version accepts **one shipping package per cart**. A multi-package/multishipping cart is rejected before starting a financial request. Finish or reconcile the existing attempt before changing its amount, currency, merchant or checkout settings. A missing/expired token does not authorize a fresh charge. A native pending order can remain after an unsuccessful initialization; reconcile it rather than creating replacement orders/keys blindly.

Native computing-precision totals are rounded with PrestaShop’s exact decimal implementation before integer conversion. Currency precision must match the gateway ISO exponent. Half-odd rounding with nonzero sub-minor precision is rejected for manual reconciliation; the native decimal library does not implement that mode.

## Webhook and reconciliation

The module reads bounded raw bytes once. The SDK validates v2 HMAC signatures, freshness, signed event identity and replay/collision protection, locates the persisted intent and authenticates a payment GET. A browser return or submitted `status=paid` cannot mark an order paid.

The ledger uses **the same PDO connection** as PrestaShop orders, order history, payment and invoice/stock writes. An event claim and the native business changes commit together; any native failure rolls both back. Duplicate delivery cannot produce another payment/invoice. Unknown, inconsistent and partially captured states never trigger fulfillment.

The shared kernel validates split **parent** identity, aggregate balance, complete capture and compensation/reconciliation status before success. A captured leg alone is insufficient. A buyer-selected installment fee is accepted only with the persisted order fingerprint echoed by authenticated evidence. The module records the native **basket amount once**; gross charged/refunded balances stay separately in the ledger/admin panel. It does not add a fee to the basket twice.

A durable encrypted notification outbox prevents native status/download emails from being sent before payment commit. Run the CLI maintenance command every few minutes:

Run cron and maintenance under the **same OS account as the web PHP worker**; use the account configured by your deployment. Preserve the same `LUNIXI_ENCRYPTION_KEY` and required PHP extensions. Root-created cache files can prevent the web worker from writing; repair ownership for the correct account instead of using `chmod 777`.

```bash
php /path/to/shop/modules/lunixipay/tools/cron.php /path/to/shop
```

It authenticates pending/historical reconciliation reads and drains queued notifications. Use a scheduler and monitor nonzero exits/deferred notices. Email delivery is at least once after commit; a worker crash after sending but before acknowledging may duplicate an email, never a payment transition. Third-party order hooks must use transactional storage and defer external side effects as well.

## Capture, refund and void

The native order view includes a Lunixi panel for **Reconcile**, **Capture**, **Refund** and **Void authorization**. Financial actions require employee order-edit permission, shop association, module controller permission, POST and admin CSRF validation.

Enter an exact decimal amount in the order currency. The native operation ID is persisted before the SDK request and cannot be reused with different parameters. An unresolved operation remains visible with its original ID/action/amount; new financial IDs are blocked until authenticated settlement confirms the original. A 2xx mutation response alone is not confirmation. Retry an uncertain result with the same operation ID and amount, or reconcile first.

Partial capture stays on hold. Captures/refunds use authenticated balances; split refunds use the aggregate SDK endpoint and may require reconciliation for nonrefundable methods. Gateway refunds **move funds only**. Create native product/tax credit notes and stock returns separately according to your accounting policy, without repeating the gateway refund. The module does not invent product allocation or VAT treatment for a monetary gateway refund.

## Uninstall and upgrades

Uninstall disables native module routing/hooks and removes its admin tab. It deliberately retains the payment ledger, event receipts, operations, encrypted credentials, outbox and native financial states. Reinstall preserves state identifiers/history. Keep server encryption keys and merchant credentials available for reconciliation/refunds through your retention period. Data deletion requires a separate reviewed retention process; uninstall is not a financial erase command.

## Build from source

```bash
composer install --working-dir=tools --no-interaction
php tools/build.php
```

The default build resolves the published `lunixi/php-sdk:1.0.1` package from [Packagist](https://packagist.org/packages/lunixi/php-sdk). Maintainers can instead explicitly supply a reviewed SDK checkout:

```bash
php tools/build.php /absolute/path/to/lunixi-php
```

The local path is used only as a build input. It is not included in the ZIP. PHP-Scoper prefixes the bundled SDK as `Lunixi\PrestaShop\Vendor\Lunixi\Sdk`; neither unscoped SDK aliases nor the site's Composer autoloader are registered. Build output is `dist/lunixipay-1.0.2.zip`. Review its SHA-256 and source before installation. No secrets, test fixtures, development dependencies or internal source paths are shipped.

## Checks

```bash
php tools/vendor/bin/phpcs --standard=phpcs.xml
composer audit --working-dir=tools
php tools/check-artifact.php
# On an installed isolated native platform, after copying build/lunixipay to modules/:
LUNIXI_NATIVE_TEST=1 php tests/native.php /path/to/shop
LUNIXI_NATIVE_TEST=1 php tests/reinstall.php /path/to/shop
php tests/http.php http://127.0.0.1:8080 localhost:8080
PRESTASHOP_ROOT=/path/to/shop php tools/vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

The native suite uses actual PrestaShop customer/cart/order/payment/history/state/translation tables and an injected synthetic gateway. It covers stable-key recovery, native cart/order creation, CSRF/ownership boundaries, encrypted secrets/token, v2 webhook+GET, replay, transaction rollback via an actual database trigger, capture/partial capture/void/refund, unresolved operation blocking and uninstall retention. Separate fresh-process reinstall avoids relying on stale platform process caches. Artifact checks verify the scoped standalone autoloader and ZIP boundaries. Run an authorized TEST-merchant end-to-end checkout/webhook/refund acceptance before live processing.

[Security](SECURITY.md) · [Contributing](CONTRIBUTING.md) · [Changes](CHANGELOG.md) · MIT [license](LICENSE) · developer@lunixi.com

Financial outcomes that remain uncertain are held for review. `FAILED` alone does not authorize a new attempt: the public payment read does not expose definitive provider outcome certainty. Unresolved financial retries stop after 23 hours; authenticated GET reconciliation remains available. A timeout, token expiry or browser callback never generates a fresh financial key.

The informational return accepts only the order locator from the URL query, discards at most 1 MiB of POST body without interpreting any payment fields, then returns an empty 303 to the native shop return URL. The POST does not load an order or call the gateway; unknown order IDs receive the same redirect. The resumed GET retains native customer/guest authentication and order/shop ownership before authenticated reconciliation. Checkout CSRF remains required. Apply matching request-body limits at the reverse proxy as PHP may buffer input before controller execution.


Recorded 0.1.3 baseline verification: **65 native assertions on each of PrestaShop 8.2.4 and 9.2.0** / PHP 8.3.6 / MariaDB 11, **35 real HTTP assertions**, fresh-request reinstall, PHPStan level 8 against each distinct native source root, PHPCS, Composer audits and standalone artifact/secret checks. Fresh activation was repeated after uninstall without manually clearing platform caches. HTTP tests use a genuine native login by a different synthetic customer and prove order ownership still returns 403; all gateway financial responses are synthetic.


Turkish catalog verification initializes the actual native `tr-TR` locale loader, as a Turkish request does, and asserts both Shop and Admin dictionaries. It does not ask an already-English BO translator to discover an uninitialized locale after installation. Version 0.1.4 uses the native ObjectModel shop-association API shared by PrestaShop 8 and 9 for employee access checks. Persisted native employee/profile/session tests verify own-shop access and reject foreign-shop, read-only and logged-out access before financial IO; payment calculations and gateway behavior are unchanged. `VERSION` is the merchant ZIP version source even when a source publisher strips Composer's optional version field.

## Checkout mode and existing attempts

Embedded checkout is the default; optional hosted checkout uses the exact validated `paymentPageUrl` returned by the backend. Both modes open the same Lunixi form and support the methods, multi-card, installments and insurance enabled for the merchant. None of these features requires redirecting. Never derive a payment-page URL from the browser token.

Existing explicit hosted settings are retained. Checkout mode, callback, preauthorization and locale are bound to an attempt's original request. Changing them cannot reset its request hash or idempotency key. If an older attempt has no stored backend payment-page URL, optional redirect fails closed: reconcile that attempt rather than constructing a URL, deleting history or creating a replacement key. Embedded rendering still uses the protected browser configuration. Keep the signed webhook, authenticated settlement checks and informational 3DS/split return routes.

Historical 0.1.4 focused form/URL checks: **40 assertions on each of PrestaShop 8.2.4 and 9.2.0**, PHPStan level 8 against both native roots, PHPCS and standalone artifact checks passed. The earlier full native/HTTP results above remain the recorded 0.1.3 baseline. At that earlier checkpoint, merchant acceptance was deferred; the 0.1.5 native/mock acceptance is recorded below.

[Native upgrade instructions](UPGRADE.md): use Module Manager Upgrade; do not uninstall or reset pending attempts.

Release 0.1.5 was rebuilt from published Packagist PHP SDK 0.29.6 (source `29fb3ac2f0fdff7a3825e189572c7aef4b212cc1`). Fresh PrestaShop 8.2.4 / PHP 8.1.34 and 9.2.0 / PHP 8.5.12 shops on MariaDB 11.8.6 each passed **70 native assertions, fresh-process reinstall and 35 actual HTTP assertions** against the new ZIP. Native 0.1.4→0.1.5 upgrade preserved hashes of encrypted configuration, attempts, event receipts, orders/payments/history and outbox rows. Separate genuine customer login→cart→address→checkout HTTP tests passed embedded and optional exact-backend-URL hosted modes on both versions; signed webhook/replay/tamper and a single native TRY 100.50 payment were verified using a local TLS provider stand-in. No old 0.1.4 test result is counted as a new-ZIP run.

**Historical public merchant ZIP 0.1.4:** [`lunixipay-0.1.4.zip`](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/download/v0.1.4/lunixipay-0.1.4.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/download/v0.1.4/SHA256SUMS) · [Release v0.1.4](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/tag/v0.1.4). The CI-built installation package was downloaded and verified on 2026-10-31. Use this asset rather than GitHub's automatic source archive. Historical SHA-256: `49baf53288856166175afcbf8413899ad98a358ac32dde6909c9946996f538c9`.

The public **0.1.5** archive above has all **313 extracted file contents identical** to the final locally accepted ZIP. Its downloaded public checksum, asset digest, CRC and both native-image CI gates passed. Acceptance evidence is native platform + mocked transport; no browser/CDN/card/bank/3DS acceptance is counted.

## Historical release evidence

**Historical verified merchant ZIP 0.1.5:** [`lunixipay-0.1.5.zip`](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/download/v0.1.5/lunixipay-0.1.5.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/download/v0.1.5/SHA256SUMS) · [Release v0.1.5](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/tag/v0.1.5) · [Passing release CI](https://github.com/Lunixi-Payment/lunixi-prestashop/actions/runs/41242113286).

Public ZIP SHA-256: `ee7032eb50ba9c6250bc492be671254ad547c4284c07e4f5cdc6cc5dfc237725`. The downloaded archive passed public checksum, GitHub asset digest and ZIP CRC checks on 1 November 2026. This receipt applies only to that historical version.
