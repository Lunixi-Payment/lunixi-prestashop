# Security policy

Report vulnerabilities privately to **developer@lunixi.com** with version, synthetic reproduction and impact. Do not send merchant private keys, webhook/access/checkout tokens, card data or customer PII. Do not publish exploitable details before coordination. Security fixes target the latest release.

- Keep the independent 32-byte encryption key outside source control, public web root and database. Back it up and plan key rotation before changing it. Uninstall retains encrypted financial records.
- Gateway origin is fixed and HTTPS. Only browser-safe form tokens reach the storefront; PAN/CVV is never collected by the module. Methods are managed in Lunixi.
- Buyer tokens require authenticated native customer/guest session, ownership and shop identity. Initial checkout/admin mutations require POST and native CSRF; employees also need order/module permissions and shop association.
- Raw v2 webhooks plus authenticated settlement reads are authoritative. Shared SDK evidence binds merchant/order/intent/currency/amount, complete split parent capture and fee fingerprint. Returns and partial/unknown states cannot fulfill orders.
- The same primary PDO/InnoDB transaction covers receipts and native order/payment/history/invoice/stock writes. Custom order hooks must use transactional storage and defer external effects; the module's native email interception writes an encrypted outbox.
- Persist financial operation ids before I/O. Do not replace a key after uncertainty. New operations are blocked while an existing one is unresolved; retries must use the exact original parameters.
- Never enable raw payment/request/response logging. Production platform debug/profiler configuration must not expose secret POST fields. Module error screens/logs omit exception payloads.
- Monetary gateway refunds are separate from product/tax credit notes and stock returns. Apply your accounting policy without calling the gateway twice.
- Release ZIPs contain a namespace-scoped SDK and standalone autoloader. No unscoped aliases, sibling sources, developer vendor tree or arbitrary remote script are included.

Run PHPCS, PHPStan level 8 against a native installed platform, Composer audit, artifact checks and the native test suite before release. CI's synthetic gateway does not replace registered TEST-merchant acceptance or marketplace review. No certification is implied.
