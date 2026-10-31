# Upgrade to 0.1.5

Back up the shop database, module files and the separate server encryption key. Stage the update on the supported PrestaShop installation before production.

Replace the complete `lunixipay/` module directory with the verified 0.1.5 ZIP, including its scoped SDK. Run the native Module Manager **Upgrade** action; do not uninstall the payment module as an upgrade procedure. The native `upgrade_module_0_1_5` hook only acknowledges the dependency update. PrestaShop updates its stored module version without changing shop configuration, encrypted credentials, reserved payment bodies/keys, webhook receipts, orders or payment history.

The bundled PHP SDK is published `lunixi/php-sdk:0.29.6`, including PHP 8.5-compatible cURL cleanup. Embedded checkout remains the default; explicit hosted settings and existing saved attempts keep their original behavior. Reconcile unresolved payments with their original identity; this update does not authorize a replacement attempt or charge.

## Türkçe

Veritabanını, modül dosyalarını ve ayrı sunucu şifreleme anahtarını yedekleyin. Doğrulanan 0.1.5 ZIP içindeki `lunixipay/` dizininin tamamını değiştirip native Module Manager **Yükselt** işlemini çalıştırın. Yükseltme için modülü kaldırmayın. Native yükseltme kancası yalnızca bağımlılık güncellemesini onaylar; ayarları, şifreli bilgileri, ödeme denemelerini/anahtarlarını, siparişleri ve finansal geçmişi değiştirmez. Belirsiz ödemeleri mevcut kimlikleriyle mutabakatlandırın; sürüm güncellemesi yeni tahsilat yetkisi değildir.
