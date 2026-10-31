# Changelog

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
