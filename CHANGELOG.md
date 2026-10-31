# Changelog

## 0.1.5 — 2026-10-31

- Bundle published Lunixi PHP SDK 0.29.6 for PHP 8.5 cURL compatibility under strict error handlers; the transport relies on automatic handle cleanup instead of deprecated no-op calls.
- Add a native dependency-only upgrade hook. Existing configuration, encrypted credentials, payment attempts, stable request bodies/keys, native order states and financial history are preserved. Embedded remains the default and optional hosted checkout still uses the exact backend paymentPageUrl.

## 0.1.4 — 2026-10-31

- Default new/unset checkout configuration to the existing embedded Lunixi form, retaining explicitly selected hosted mode. Both modes share all merchant-enabled form features.
- Bundle PHP SDK 0.29.5 and use its validated, encrypted backend `paymentPageUrl` for optional redirects; never derive a hosted URL from a browser token.
- Keep historical attempt keys/hashes/settings and 3DS/split callbacks intact; a missing or invalid hosted URL stays pending for reconciliation.

## 0.1.3 — 2026-10-31

- Fix PrestaShop 8 employee administration by using the native ObjectModel `getAssociatedShops()` API shared with PrestaShop 9; retain authenticated session, order-edit permission and strict integer shop association checks.
- Add real native employee/profile/shop/session regression cases for allowed own-shop access, foreign-shop denial, read-only denial and logged-out denial before financial IO. Verify PHPStan against both native source roots.
- Payment calculations, gateway signing and the bundled PHP SDK 0.29.3 are unchanged.

## 0.1.2 — 2026-10-31

- Verify Turkish Shop and Admin catalogs through the actual native locale loader; do not ask an already-English BO translator to load an uninitialized locale after module installation. No financial behavior changes.
- Add an authoritative VERSION file and derive merchant ZIP metadata independently from Composer's optional version field.

## 0.1.1 — 2026-10-31

- Accept the gateway informational POST return through a bounded, stateless same-origin 303 adapter. Ignore submitted payment fields and require native ownership on the resumed GET.
- Verify fresh native activation and clean CI environments.

## 0.1.0 — 2026-10-31

- Native PrestaShop PaymentModule/PaymentOption and return/webhook/admin controllers.
- Hosted or embedded merchant-managed Lunixi form, preauthorization, native session ownership and CSRF controls.
- Shared PHP SDK 0.29 Commerce orchestration with exact native DECIMAL money, encrypted credentials/tokens, durable attempts and settlement operations.
- Same-PDO InnoDB transaction for signed event receipts and native order/payment/history application; split-parent/fee evidence stays in the shared kernel.
- Capture/partial capture/refund/void/reconciliation panel, unresolved operation retries and encrypted notification outbox/CLI worker.
- Namespace-scoped standalone merchant ZIP, original logo, native EN/TR translation catalogs, source guides and independent CI/checks.
- Actual native PrestaShop 9.2.0 acceptance with a synthetic gateway transport; registry/marketplace/live merchant acceptance remain separate release steps.
