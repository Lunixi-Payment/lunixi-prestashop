---
title: PrestaShop
description: PrestaShop 8/9 için native Lunixi ödeme modülü — kurulum ZIP’i, şifreli bilgiler, gömülü/yönlendirmeli form, doğrulanmış webhook ve kalıcı tahsilat/iade işlemleri.
---


# PrestaShop

1.0.0 kurulum ZIP’i [GitHub sürümlerinde](https://github.com/Lunixi-Payment/lunixi-prestashop/releases) kullanılabilir olduğunda onu indirin ve aynı sürümün `SHA256SUMS` dosyasıyla doğrulayın. Paket PHP SDK 1.0.1 gerektirir. [Para sözleşmesi geçişini](../MIGRATION-1.0.md) uygulayın; 0.x ZIP’leri önceki sayısal para sözleşmesini kullanır.

`lunixipay`, PrestaShop’un native ödeme/sipariş akışını Lunixi’ye bağlar. Mevcut Lunixi formu varsayılan olarak mağaza içinde açılır; yönlendirme isteğe bağlıdır. Açık yöntemler, taksitler, uygun alternatif yöntemler, sigorta ve çoklu kart Lunixi’den yönetilir. Modül PAN/CVV toplamaz.

Mağaza ZIP’i namespace ile izole [PHP SDK](/sdks/php) içerir. İmza, kanonik string tutar, kalıcı deneme, webhook, split-parent kanıtı ve güvenli retry ortak Commerce çekirdeğindedir. Mağaza sunucusunda Composer gerekmez.

## Gereksinimler ve kurulum

- Platformun desteklediği PHP ile **PrestaShop 8.1+ / 9.x**; modül için **64-bit PHP 8.1+**.
- Sodium, cURL, JSON, PDO MySQL; bütün mağaza tabloları **InnoDB**.
- HTTPS mağaza/webhook; kayıtlı Lunixi merchant/key ve webhook secret.
- Veritabanı/public web dizini dışında, bağımsız ve yedekli 32 bayt şifreleme anahtarı.

Kaynak: [Lunixi-Payment/lunixi-prestashop](https://github.com/Lunixi-Payment/lunixi-prestashop). Geçmiş 0.1.5 yayın kanıtı aşağıda korunur. Yayın, PrestaShop Addons onayı anlamına gelmez. Önceki 0.1.3 native doğrulaması resmi PrestaShop 8.2.4 ve 9.2.0 kaynakları üzerinde, ayrı PHP 8.3.6 / MariaDB 11 test mağazalarında sentetik gateway ile çalıştırıldı. Her sürümde 65 native assertion geçti; iki resmi Docker imajındaki CI koşumları bağımsız yayın kapılarıdır.

Sunucuda anahtar üretin:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Secret manager üzerinden PHP worker ortamına `LUNIXI_ENCRYPTION_KEY` olarak ekleyin. Veritabanı/cookie/private/webhook anahtarından türetmeyin. Yükseltme ve yeniden başlatmada koruyun; kayıp anahtar geçmiş şifreli bilgileri okunamaz yapar.

**Module Manager → Upload a module** üzerinden doğrulanmış **`lunixipay-1.0.0.zip`** dosyasını yükleyip **Lunixi Payments** modülünü kurun. ZIP kökü `lunixipay/` olmalıdır. GitHub kaynak arşivi, build edilmiş kurulum ZIP’i değildir.

## Üye işyeri ayarları

Multistore’da tek mağaza seçip kaydedin:

| Ayar | Açıklama |
| --- | --- |
| Merchant ID | Tahsilat kanıtının bağlandığı üye işyeri kimliği |
| Key ID / özel Ed25519 anahtarı | Kayıtlı sunucu imza bilgileri |
| Webhook secret | Merchant webhook bağlantısıyla eşleşen secret |
| Form modu | Mağaza içi gömülü Lunixi formu (varsayılan); isteğe bağlı yönlendirme backend `paymentPageUrl` adresini kullanır |
| Önce provizyon, sonra tahsilat | Capture’a kadar siparişi beklemede tutar |

Secret alanları tekrar gösterilmez; boş bırakmak kayıtlı değeri korur. Bilgiler bağımsız sunucu anahtarıyla authenticated encryption kullanılarak saklanır. Kayıt için native modül düzenleme yetkisi ve admin CSRF gerekir.

Panelde gösterilen **tam webhook URL’sini**, `shop_id` dahil, Lunixi’ye kaydedin. Dokümante merchant ödeme olaylarına abone olun. Para birimi/ülke/kargo/müşteri grubu kısıtlarını native PrestaShop ödeme tercihlerinden yönetin.

Gateway sabit `https://api-gateway.lunixi.com` origin’idir; kullanıcı keyfi URL veremez. TEST/LIVE erişimini merchant anahtarı belirler.

## Ödeme ve sipariş durumu

Tek **Lunixi ile güvenle öde** seçeneği görünür. Token gösterilmeden native müşteri/misafir session’ı, sipariş sahipliği ve mağaza kimliği doğrulanır. İlk ödeme POST’u ayrıca müşteri CSRF token’ı ister.

Adaptör ödenmemiş native sipariş oluşturur, aynı sepetin sipariş dönüşümünü kilitler, DECIMAL toplamı string okur ve ağ çağrısından önce denemeyi kalıcı kaydeder. Zaman aşımı aynı anahtarı korur; tekrar sessizce yeni tahsilat açmaz.

- İsteğe bağlı yönlendirme, backend'in döndürdüğü doğrulanmış `paymentPageUrl` adresine gider; token'dan adres üretilmez.
- Varsayılan gömülü mod, resmi `https://cdn.lunixi.com/lunixi-checkout.min.js` dosyasını platformun asset API’siyle yükleyip güvenli JSON ile bağlar. Gateway HTML’i basılmaz.
- Provizyon ve kısmi capture beklemede kalır. Ödenmiş geçişi tam, doğrulanmış tahsilat ister.

Yöntemleri merchant formu belirler; ayrıca yöntem aç/kapa anahtarı üretilmez. CSP’de resmi formun gerektirdiği origin’lere izin verin.

> 
Bu sürüm sepet başına tek kargo paketini destekler. Çok paketli/multishipping sepet finansal çağrıdan önce reddedilir. Toplam, para birimi, merchant veya form ayarını değiştirmeden mevcut denemeyi sonuçlandırın. Süresi dolmuş/eksik token yeni tahsilat yetkisi değildir.


Native hesaplama hassasiyetindeki toplam, alt birime çevrilmeden PrestaShop’un kesin decimal kütüphanesiyle yuvarlanır; para birimi hassasiyeti gateway ISO üssüyle eşleşmelidir. Alt birimden küçük kesirli half-odd yuvarlama, native kütüphane desteklemediği için mutabakat gerektirir. Rotasyonda şifreli credential geçmişi ve iki önceki webhook secret korunur; ödeme geçmişi bulunan mağaza başka merchant ID’ye bağlanamaz.

## Doğrulanmış webhook ve mutabakat

SDK sınırlanmış özgün baytlarda v2 imza, zaman, kimlik ve replay/çakışma kontrolü yapar; kalıcı intent’i eşleştirip yetkili ödeme GET çağrısı yapar. Tarayıcı dönüş parametreleri siparişi ödenmiş saydıramaz.

SDK ledger’ı **PrestaShop’un kullandığı aynı primary PDO bağlantısına** bağlanır. Olay kaydı ile native sipariş/ödeme/geçmiş/fatura/stok değişiklikleri tek InnoDB transaction’ında commit olur. Hata hepsini geri alır; replay ikinci ödeme/fatura oluşturmaz. Belirsiz, kısmi veya tutarsız tahsilat teslimat başlatmaz.

Split başarısı doğru parent kimliği, dengeli toplam, tam tahsilat ve compensation/mutabakat sorunu olmamasını ister. Tahsil edilen tek bacak yetmez. Müşteri taksit ücreti authenticated yanıttaki kalıcı sipariş fingerprint’iyle doğrulanır. Native sepet tutarı bir kez kaydedilir; brüt tahsilat/iade ledger/panelde ayrıca tutulur.

Native durum/download e-postaları transaction sırasında şifreli outbox’a alınır. Bakımı birkaç dakikada bir zamanlayın:

Cron ve bakım komutlarını **web PHP işçisiyle aynı işletim sistemi hesabında** çalıştırın; gerçek hesabı yayın ortamınızın yapılandırmasından belirleyin. Aynı `LUNIXI_ENCRYPTION_KEY` ve gerekli PHP eklentilerini koruyun. Root ile oluşturulan önbellek dosyaları web işçisinin yazmasını engelleyebilir; `chmod 777` yerine doğru hesabın dosya sahipliğini düzeltin.

```bash
php /magaza/yolu/modules/lunixipay/tools/cron.php /magaza/yolu
```

İş, authenticated mutabakat sorgularını ve bildirim kuyruğunu çalıştırır. Hata/deferred sonuçlarını izleyin. E-posta commit sonrası en az bir kez teslim edilir; worker hatası bildirimi tekrarlayabilir, ödeme geçişini tekrarlamaz. Üçüncü taraf hook’ları da transactional depolama ve commit sonrası yan etki kullanmalıdır.

## Tahsilat, iade, provizyon iptali

Native siparişin Lunixi panelinde **Mutabakat**, **Tahsil et**, **İade**, **Provizyonu iptal et** bulunur. POST, admin CSRF, sipariş/modül düzenleme yetkisi ve mağaza ilişkisi gerekir.

Sipariş para biriminde kesin ondalık tutar girin. Native işlem kimliği çağrıdan önce kaydedilir; aynı ID başka tutar/eylem için kullanılamaz. Belirsiz işlem özgün salt okunur parametreleriyle görünür; authenticated tahsilat farkı doğrulanana kadar yeni finansal ID engellenir. HTTP 2xx onay değildir. Aynı ID/tutarla tekrar deneyin veya önce mutabakat yapın.

> 
Gateway iadesi yalnız fon aktarır. Ürün/vergi alacak dekontu ve stok iadesini muhasebe politikanıza göre ayrıca oluşturun; gateway iadesini tekrarlamayın. Modül parasal iadeye keyfi ürün/KDV dağıtımı yapmaz. İade desteklemeyen split yöntemlerinde mutabakat gerekebilir.


## Kaldırma ve saklama

Kaldırma modülü kapatıp admin sekmesini kaldırır; denemeleri, receipt’leri, işlemleri, şifreli bilgileri, outbox’ı ve native finansal durumları korur. Yeniden kurulum kimlikleri değiştirmez. İade/mutabakat saklama süresi boyunca şifreleme anahtarını ve merchant erişimini tutun. Veri silme ayrıca incelenen bir saklama işlemidir.

## Kaynak build ve kontroller

```bash
composer install --working-dir=tools --no-interaction
php tools/build.php
php tools/check-artifact.php
```

Varsayılan build public `lunixi/php-sdk:1.0.1` paketini çözer. Geliştirmede `php tools/build.php /tam/yol/lunixi-php` ile incelenmiş kaynak verin. Build yolu ZIP’e çıkmaz. PHP-Scoper SDK’yı `Lunixi\PrestaShop\Vendor\Lunixi\Sdk` altında izole eder; kardeş kaynak, scope’suz alias veya site genelinde Composer gerektirmez.

Kontroller PHPCS, gerçek native platform üzerinde PHPStan level 8, Composer audit, ZIP/secret sınır kontrolü ve sentetik gateway’li gerçek platform/veritabanı testlerini içerir. Gerçek DB trigger’ıyla rollback, aynı retry anahtarı, şifreleme, sahiplik/CSRF, webhook/GET, replay, capture/kısmi capture/void/iade ve kaldırmada geçmiş doğrulanır. Yeniden kurulum ayrı PHP sürecinde test edilir.

Canlı işlemden önce kayıtlı TEST merchant ile uçtan uca ödeme/webhook/iade ve mağaza tema kabul testi yapın. [README](https://github.com/Lunixi-Payment/lunixi-prestashop) · [Güvenlik](https://github.com/Lunixi-Payment/lunixi-prestashop/blob/main/SECURITY.md).


Bilgi dönüşü sipariş kimliğini yalnız URL sorgusundan alır; en fazla 1 MiB POST gövdesini ödeme alanlarını yorumlamadan atar ve yerel mağaza dönüş adresine boş 303 verir. POST sipariş yüklemez veya gateway çağırmaz; bilinmeyen sipariş de aynı yönlendirmeyi alır. Sonraki GET, yetkili mutabakattan önce yerel müşteri/misafir kimliğini, sipariş ve mağaza sahipliğini denetler. Checkout CSRF zorunluluğu sürer. PHP controller çalışmadan önce girdiyi belleğe alabileceği için proxy gövde sınırını da uygulayın.


Önceki 0.1.3 temel doğrulaması: PrestaShop 8.2.4 ve 9.2.0 sürümlerinin her birinde / PHP 8.3.6 / MariaDB 11 üzerinde **65 native assertion**, **35 gerçek HTTP assertion**, ayrı süreçte yeniden kurulum, her yerel kaynak kökü için ayrı PHPStan seviye 8, PHPCS, Composer audit ve bağımsız ZIP/sır kontrolleri. Kaldırma sonrası yeniden aktivasyon, platform önbelleği elle temizlenmeden tekrar doğrulandı. HTTP testi başka sentetik müşteriyle gerçek yerel giriş yapar ve sipariş sahipliğinin 403 verdiğini kanıtlar; gateway finans yanıtları sentetiktir.


Önceki 0.1.3 testleri, gerçek yerel `tr-TR` locale loader ile hem Shop hem Admin sözlüğünü doğrular; kurulumdan önce İngilizce açılmış BO translator'ına yüklenmemiş locale sorulmaz. 0.1.3 çalışan yetkisi kontrolünde PrestaShop 8 ve 9’un ortak ObjectModel mağaza eşleştirme API’sini kullanır. Gerçek yerel çalışan, profil ve oturum testleri kendi mağazasında erişimi doğrular; başka mağaza, salt okunur ve oturumsuz erişimi finansal çağrıdan önce reddeder. Bu önceki yetki düzeltmesi ödeme hesaplamalarını ve gateway davranışını değiştirmedi. Aynı 65 assertion resmi 8.2.4 paketinde ayrı yerel PHP 8.3.6/MariaDB ortamında da geçer; iki resmi Docker imajındaki CI koşumları ayrı yayın kapısıdır. Composer version alanı yayıncı tarafından kaldırılsa da ZIP sürümü `VERSION` dosyasından gelir.

## Form modu ve mevcut ödeme denemeleri

Varsayılan, mağaza içinde açılan gömülü Lunixi formudur. İsteğe bağlı yönlendirme, backend'in döndürdüğü doğrulanmış `paymentPageUrl` adresini birebir kullanır. İki mod da aynı formu açar; açık yöntemler, çoklu kart, taksit ve sigorta Lunixi panelinden yönetilir ve yönlendirme gerektirmez. Tarayıcı token'ından ödeme sayfası adresi üretilmez.

Önceden açıkça seçilmiş yönlendirme ayarları korunur. Form modu, dönüş adresi, ön provizyon ve dil özgün ödeme isteğine bağlıdır; değiştirmek denemenin hash veya idempotency anahtarını sıfırlayamaz. Eski bir denemede backend ödeme adresi kayıtlı değilse yönlendirme güvenli biçimde durur; adres üretmek, geçmişi silmek veya yeni anahtar açmak yerine mevcut denemeyi uzlaştırın. Gömülü form korumalı tarayıcı yapılandırmasını kullanır. İmzalı webhook, doğrulanmış tahsilat kontrolleri ve bilgilendirici 3DS/çoklu kart dönüşleri korunur.

Yalnız yerel form/URL regresyonu için `LUNIXI_NATIVE_TEST=1 LUNIXI_NATIVE_FORM_ONLY=1 php tests/native.php /tam/yol/ayri-test-magazasi` kullanılır. Gömülü varsayılan, açık yönlendirme tercihi, backend adresinin kalıcılığı ve değişmez deneme kontrol edilir; canlı ödeme yapılmaz.

Önceki 0.1.4 odaklı form/URL kontrolü: **PrestaShop 8.2.4 ve 9.2.0 üzerinde ayrı ayrı 40 assertion**, iki yerel kaynak kökünde PHPStan seviye 8, PHPCS ve bağımsız ZIP kontrolleri geçti. Yukarıdaki önceki tam native/HTTP sonuçları 0.1.3 temel doğrulamasıdır. O önceki aşamada merchant kabulü ertelenmişti; 0.1.5 native/mock kabulü aşağıda ayrı kaydedilmiştir.

0.1.5 yayımlanmış Packagist PHP SDK 0.29.6’dan yeniden derlendi (kaynak `29fb3ac2f0fdff7a3825e189572c7aef4b212cc1`). MariaDB 11.8.6 kullanan yeni PrestaShop 8.2.4 / PHP 8.1.34 ve 9.2.0 / PHP 8.5.12 mağazalarında yeni ZIP ile ayrı ayrı **70 native assertion, ayrı süreçte yeniden kurulum ve 35 gerçek HTTP assertion** geçti. Native 0.1.4→0.1.5 yükseltmesinde şifreli ayar, deneme, olay, sipariş/ödeme/geçmiş ve outbox kayıtlarının hashleri değişmedi. İki sürümde de gerçek müşteri girişi→sepet→adres→checkout HTTP akışında embedded ve backend’in tam adresine yönlendirme seçenekleri geçti; yerel TLS ödeme servisiyle imzalı webhook/replay/tamper ve tek 100,50 TRY native ödeme doğrulandı. Eski 0.1.4 sonuçları yeni ZIP testi sayılmadı.

Public **0.1.5** arşivindeki **313 dosyanın tamamı**, nihai yerel kabul ZIP’iyle içerik olarak birebir aynıdır. İndirilen arşivin public checksum, asset digest, CRC ve iki native imaj CI kapısı geçti. Kabul, native platform + sentetik transport kapsamındadır; tarayıcı/CDN/kart/banka/3DS kabulü sayılmaz.

## Geçmiş yayın kanıtı

**Geçmiş doğrulanmış mağaza ZIP’i 0.1.5:** [`lunixipay-0.1.5.zip`](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/download/v0.1.5/lunixipay-0.1.5.zip) · [SHA256SUMS](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/download/v0.1.5/SHA256SUMS) · [Yayın v0.1.5](https://github.com/Lunixi-Payment/lunixi-prestashop/releases/tag/v0.1.5) · [Başarılı yayın CI koşumu](https://github.com/Lunixi-Payment/lunixi-prestashop/actions/runs/41242113286).

Public ZIP SHA-256: `ee7032eb50ba9c6250bc492be671254ad547c4284c07e4f5cdc6cc5dfc237725`. İndirilen arşivin checksum, GitHub asset digest ve ZIP CRC kontrolleri 1 Kasım 2026 tarihinde doğrulandı. Bu kanıt yalnız ilgili geçmiş sürüm içindir.
