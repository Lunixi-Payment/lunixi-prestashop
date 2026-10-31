<p align="center"><img src=".github/assets/lunixi-logo.svg" width="300" alt="Lunixi"></p>

# PrestaShop için Lunixi

[English](README.md) · [Türkçe](README.tr.md) · [Dokümantasyon](https://docs.lunixi.com/tr/sdks/prestashop)

**PrestaShop 8.1+ / 9.x** için native ödeme modülü, sürüm **0.1.3**. Mağaza gömülü veya `pay.lunixi.com` yönlendirmeli form seçer. Açık ödeme yöntemleri, taksitler, uygun alternatif yöntemler, sigorta ve çoklu kart Lunixi paneli/formundan yönetilir. Modül PAN/CVV toplamaz.

İmza, token, tutar, split-parent kanıtı, ek ücret korelasyonu, idempotency ve webhook kuralları ortak **Lunixi PHP SDK Commerce** çekirdeğindedir. Kurulum ZIP’i namespace ile izole edilmiş SDK ve bağımsız autoloader içerir; mağazada Composer gerekmez.

## Gereksinimler

- Platformun desteklediği PHP sürümüyle PrestaShop 8.1+ veya 9.x; modül için **64-bit PHP 8.1+**.
- Sodium, cURL, JSON, PDO MySQL; bütün mağaza tabloları **InnoDB**.
- HTTPS mağaza/webhook; sabit `https://api-gateway.lunixi.com` adresine erişim.
- Lunixi merchant/key ID, özel Ed25519 anahtarı ve webhook secret.
- Veritabanından ve public web dizininden ayrı tutulan, yedekli **32 bayt sunucu şifreleme anahtarı**.

Native kabul kontrolleri gerçek **PrestaShop 9.2.0 / PHP 8.3.6 / MariaDB 11** üzerinde çalıştırıldı. Gateway transport sentetiktir; para taşınmaz. PrestaShop 8.2.4 CI matrisine dahildir; bağımsız 8.x doğrulaması için o native koşumun da geçmesi gerekir. Kaynak yayını Addons onayı veya registry yayını anlamına gelmez.

## ZIP kurulumu ve ayarlar

1. Veritabanını ve sunucu secret’larını yedekleyin. Sunucuda `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'` ile anahtar üretin ve PHP worker ortamına `LUNIXI_ENCRYPTION_KEY` olarak kaydedin. Anahtarı başka secret/paroladan türetmeyin; yeniden başlatma ve yükseltmede koruyun. Eski anahtarı kaybetmek geçmiş şifreli bilgileri okunamaz yapar.
2. **Module Manager → Upload a module** üzerinden `lunixipay-0.1.3.zip` yükleyip **Lunixi Payments** modülünü kurun. ZIP kökünde `lunixipay/` bulunur. GitHub kaynak ZIP’i kurulum ZIP’i değildir.
3. Multistore’da tek mağaza seçin. **Merchant ID**, **Key ID**, **Private Ed25519 key**, **Webhook secret**, **Checkout mode** ve isteğe bağlı **ön provizyon** ayarını kaydedin. Ödeme geçmişi oluştuktan sonra mağaza başka merchant ID’ye bağlanamaz. Döndürülen anahtarlar şifreli geçmişte saklanır; rotasyonda mevcut ve iki önceki webhook secret kabul edilir. Secret alanları düzenlemede boş görünür; boş bırakmak mevcut değeri korur. Kayıt için modül düzenleme yetkisi ve admin CSRF gerekir.
4. Panelin gösterdiği **tam webhook URL’sini**, `shop_id` dahil, Lunixi’ye kaydedin. Dokümante merchant ödeme olaylarına abone olun. Para birimi/ülke/kargo/müşteri grubu kısıtlarını PrestaShop’un native ödeme tercihlerinden yönetin.

Gateway origin’i kodda sabittir; kullanıcı keyfi URL veremez. TEST/LIVE erişimini anahtar belirler. Bilgiler bağımsız sunucu anahtarıyla `SecretBox` kullanılarak şifreli saklanır; özel anahtar tarayıcıya gönderilmez.

## Alıcı akışı

Tek **Lunixi ile güvenle öde** seçeneği gösterilir. Native müşteri/misafir session’ı, sipariş sahipliği ve mağaza kimliği doğrulanır. İlk ödeme POST’u müşteri CSRF token’ı ister. Yöntemleri Lunixi formu belirler; ayrıca yöntem aç/kapa listesi yoktur.

Native **Lunixi ödemesi bekleniyor** siparişi oluşturulur. Ödeme denemesi ağ çağrısından önce kalıcı kaydedilir. Native DECIMAL toplamı string olarak okunup ortak tam sayı tutar yardımcısıyla çevrilir. Zaman aşımında aynı deneme/anahtar korunur; tekrar aynı token/anahtarı kullanır.

- **Yönlendirmeli:** sabit `https://pay.lunixi.com/payment/<token>` adresine gider; uygun alternatif/çoklu kart akışları formda sunulur.
- **Gömülü:** PrestaShop asset API’siyle yalnız resmi `https://cdn.lunixi.com/lunixi-checkout.min.js` yüklenir, güvenli JSON ile form bağlanır. Gateway HTML’i basılmaz. CSP’de resmi formun gereken origin’lerine izin verin.
- **Ön provizyon:** authorized ve kısmi capture siparişi beklemede tutar; tam doğrulanmış tahsilat olmadan ödenmiş sayılmaz.

Bu sürüm **sepet başına tek kargo paketi** kabul eder. Çok paketli/multishipping sepet finansal çağrıdan önce reddedilir. Mevcut deneme mutabakatla sonuçlanmadan toplam, para birimi, merchant veya form ayarını değiştirmeyin. Token’ın süresinin dolması yeni tahsilat yetkisi değildir. Başlatma başarısızlığında native pending sipariş kalabilir; yeni sipariş/anahtar açmadan mutabakat yapın.

Native hesaplama hassasiyetindeki toplam, tam sayıya çevrilmeden PrestaShop’un kesin decimal kütüphanesiyle yuvarlanır. Para birimi hassasiyeti gateway ISO üssüyle eşleşmelidir. Alt birimden küçük kesir içeren half-odd yuvarlama, native decimal kütüphanesinde desteklenmediği için mutabakat gerektirir.

## Webhook ve mutabakat

Özgün, boyutu sınırlanmış baytlar bir kez alınır. SDK v2 HMAC imza, zaman, olay kimliği ve replay/çakışmayı doğrular; kalıcı intent ile eşleştirip sunucudan ödeme sorgular. Tarayıcı dönüşü veya `status=paid` parametresi siparişi ödenmiş yapamaz.

Ledger, PrestaShop sipariş/ödeme/geçmiş/fatura/stok yazılarıyla **aynı PDO bağlantısını** kullanır. Olay kaydı ve native değişiklikler birlikte commit edilir; hata hepsini geri alır. Yinelenen webhook ikinci ödeme/fatura üretmez. Belirsiz ve kısmi tahsilat teslimat başlatmaz.

Ortak çekirdek split **parent** kimliğini, toplam dengeyi, tam tahsilatı ve compensation/mutabakat durumunu doğrular; tek bir tahsil edilmiş bacak yetmez. Müşteri taksit ücreti, yalnız authenticated yanıttaki kalıcı sipariş fingerprint’iyle kabul edilir. Native sepet tutarı **bir kez** ödeme kaydına yazılır; brüt tahsilat/iade ledger ve panelde ayrıca tutulur. Ek ücret sepete ikinci kez eklenmez.

E-postalar finansal commit öncesinde gönderilmez; şifreli ve kalıcı outbox’a alınır. Aşağıdaki CLI işini birkaç dakikada bir çalıştırın:

Cron ve bakım komutlarını **web PHP işçisiyle aynı işletim sistemi hesabında** çalıştırın; gerçek hesabı yayın ortamınızın yapılandırmasından belirleyin. Aynı `LUNIXI_ENCRYPTION_KEY` ve gerekli PHP eklentilerini koruyun. Root ile oluşturulan önbellek dosyaları web işçisinin yazmasını engelleyebilir; `chmod 777` yerine doğru hesabın dosya sahipliğini düzeltin.

```bash
php /magaza/yolu/modules/lunixipay/tools/cron.php /magaza/yolu
```

İş sunucu mutabakatı yapar ve bildirim kuyruğunu işler. Hata çıkışlarını izleyin. Commit sonrası e-posta teslimi en az bir kez yapılır; worker gönderimden sonra kapanırsa e-posta tekrarlanabilir, ödeme geçişi tekrarlanmaz. Üçüncü taraf sipariş hook’ları da transaction’a katılıp dış yan etkileri commit sonrasına bırakmalıdır.

## Tahsilat, iade ve provizyon iptali

Native sipariş ekranındaki Lunixi panelinde **Mutabakat**, **Tahsil et**, **İade**, **Provizyonu iptal et** bulunur. Sipariş düzenleme yetkisi, ilgili mağaza ilişkisi, modül controller yetkisi, POST ve CSRF kontrol edilir.

Tutarı sipariş para biriminde kesin ondalık olarak girin. Native işlem kimliği çağrıdan önce saklanır; aynı kimlikle farklı tutar/eylem reddedilir. Belirsiz işlemin özgün ID/eylem/tutarı panelde kalır, salt okunur gösterilir. Sonuç authenticated tahsilat farkıyla doğrulanana kadar yeni finansal ID engellenir. HTTP 2xx tek başına onay değildir. Belirsiz sonucu aynı ID ve tutarla tekrar deneyin veya önce mutabakat yapın.

Gateway iadesi **yalnız fon aktarır**. Ürün/vergi alacak dekontunu ve stok iadesini muhasebe politikanıza göre native araçlarla ayrıca yapın; gateway iadesini tekrarlamayın. Modül parasal iadeye keyfi ürün/KDV dağıtımı uygulamaz. Split iadeleri ortak aggregate API üzerinden yürür; iade desteklemeyen yöntemlerde mutabakat gerekebilir.

## Kaldırma ve geliştirme

Kaldırma modülü devre dışı bırakır; ledger, receipt, işlemler, şifreli bilgiler, outbox ve native durumları silmez. Yeniden kurulum geçmiş kimliklerini korur. Şifreleme anahtarını ve merchant bilgilerini iade/mutabakat saklama süresince tutun; veri silme ayrıca incelenmelidir.

```bash
composer install --working-dir=tools --no-interaction
php tools/build.php
```

Varsayılan build public registry’deki `lunixi/php-sdk:0.29.3` paketini çözer. Sürüm henüz yayımlanmadıysa incelenmiş kaynak kopyasını açıkça verin:

```bash
php tools/build.php /tam/yol/lunixi-php
```

Yerel yol yalnız build girdisidir; ZIP’e taşınmaz. PHP-Scoper SDK’yı `Lunixi\PrestaShop\Vendor\Lunixi\Sdk` altında izole eder. Site genelindeki Composer veya scope’suz alias yüklenmez. Çıktı `dist/lunixipay-0.1.3.zip` dosyasıdır.

```bash
php tools/vendor/bin/phpcs --standard=phpcs.xml
composer audit --working-dir=tools
php tools/check-artifact.php
LUNIXI_NATIVE_TEST=1 php tests/native.php /izole/test/magazasi
LUNIXI_NATIVE_TEST=1 php tests/reinstall.php /izole/test/magazasi
php tests/http.php http://127.0.0.1:8080 localhost:8080
PRESTASHOP_ROOT=/izole/test/magazasi php tools/vendor/bin/phpstan analyse -c phpstan.neon --no-progress
```

Native testler gerçek platform/veritabanı modelleri ve sentetik gateway kullanır. Kriptografi/finans kuralları ortak SDK’da test edilir; adaptör testinde gerçek DB trigger’ıyla rollback, sipariş, ödeme, kısmi tahsilat, provizyon iptali, iade, replay ve geçmişin korunması doğrulanır. Canlıya geçmeden yetkili TEST merchant ile uçtan uca ödeme/webhook/iade kabul testi yapın.

[Güvenlik](SECURITY.md) · [Katkı](CONTRIBUTING.md) · [Değişiklikler](CHANGELOG.md) · [MIT](LICENSE) · developer@lunixi.com

Finansal sonucu belirsiz işlemler incelemede tutulur. `FAILED` tek başına yeni denemeye izin vermez; herkese açık ödeme sorgusu kesin sağlayıcı sonuç kanıtını döndürmez. Sonuçlanmamış finansal tekrarlar 23 saat sonra durur; kimliği doğrulanmış GET mutabakatı devam eder. Zaman aşımı, form token süresi veya tarayıcı dönüşü yeni finansal anahtar üretmez.

Bilgi dönüşü sipariş kimliğini yalnız URL sorgusundan alır; en fazla 1 MiB POST gövdesini ödeme alanlarını yorumlamadan atar ve yerel mağaza dönüş adresine boş 303 verir. POST sipariş yüklemez veya gateway çağırmaz; bilinmeyen sipariş de aynı yönlendirmeyi alır. Sonraki GET, yetkili mutabakattan önce yerel müşteri/misafir kimliğini, sipariş ve mağaza sahipliğini denetler. Checkout CSRF zorunluluğu sürer. PHP controller çalışmadan önce girdiyi belleğe alabileceği için proxy gövde sınırını da uygulayın.


0.1.3 doğrulaması: PrestaShop 8.2.4 ve 9.2.0 sürümlerinin her birinde / PHP 8.3.6 / MariaDB 11 üzerinde **65 native assertion**, **35 gerçek HTTP assertion**, ayrı süreçte yeniden kurulum, her yerel kaynak kökü için ayrı PHPStan seviye 8, PHPCS, Composer audit ve bağımsız ZIP/sır kontrolleri. Kaldırma sonrası yeniden aktivasyon, platform önbelleği elle temizlenmeden tekrar doğrulandı. HTTP testi başka sentetik müşteriyle gerçek yerel giriş yapar ve sipariş sahipliğinin 403 verdiğini kanıtlar; gateway finans yanıtları sentetiktir.


0.1.3 testleri, gerçek yerel `tr-TR` locale loader ile hem Shop hem Admin sözlüğünü doğrular; kurulumdan önce İngilizce açılmış BO translator'ına yüklenmemiş locale sorulmaz. 0.1.3 çalışan yetkisi kontrolünde PrestaShop 8 ve 9’un ortak ObjectModel mağaza eşleştirme API’sini kullanır. Gerçek yerel çalışan, profil ve oturum testleri kendi mağazasında erişimi doğrular; başka mağaza, salt okunur ve oturumsuz erişimi finansal çağrıdan önce reddeder. Ödeme hesaplamaları ve gateway davranışı değişmez. Aynı 65 assertion resmi 8.2.4 paketinde ayrı yerel PHP 8.3.6/MariaDB ortamında da geçer; iki resmi Docker imajındaki CI koşumları ayrı yayın kapısıdır. Composer version alanı yayıncı tarafından kaldırılsa da ZIP sürümü `VERSION` dosyasından gelir.
