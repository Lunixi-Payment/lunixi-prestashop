# Contributing

Use an isolated PrestaShop test installation and synthetic credentials. Keep the adapter thin: signing, evidence, money conversion, idempotency and replay logic belong to the shared `lunixi/php-sdk` Commerce kernel.

Run `composer install --working-dir=tools`, build the namespace-scoped ZIP, then PHPCS, PHPStan level 8, Composer audit, artifact checks and native tests. Node/browser card capture must not be added. Preserve the exact primary PDO transaction, durable operations, encrypted outbox and uninstall retention. Any change to a financial/ownership path needs a meaningful regression test.

PrestaShop requires global names for its module/front/admin entry classes; those files are the only namespace exception in phpcs.xml. All reusable adapter classes are under `Lunixi\PrestaShop`. Keep user-visible strings in native `trans()` / Smarty translation calls and update the English/Turkish documentation and XLIFF catalogs. Use the original supplied Lunixi logo.

Do not commit real `.env`, secrets, private keys, platform databases, generated builds/vendor trees or audit logs. Report vulnerabilities privately as described in SECURITY.md. A package registry/marketplace/release acceptance claim must be backed by an actual completed publication/test.
