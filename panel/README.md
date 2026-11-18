# Gündoğdu İnşaat Muhasebe Takip Paneli — Laravel + MySQL

Onaylanan HTML/Tailwind arayüzünün sunucuya bağlı sürümü. Ek paket kullanılmaz: yalnızca Laravel 12'nin kendi bileşenleri (oturum, CSRF, Eloquent, Storage, RateLimiter) ve MySQL.

## Kurulum

PHP 8.2+, Composer ve MySQL 8+ gerekir.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

`.env` içinde veritabanı bilgilerini ve ilk süper admin hesabını (`ADMIN_EMAIL`, `ADMIN_PASSWORD`) doldurun. Veritabanını oluşturun:

```sql
CREATE DATABASE gundogdu_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci;
```

```bash
php artisan migrate --seed
php artisan serve
```

`http://127.0.0.1:8000/giris` adresinden süper admin hesabıyla giriş yapın.

`--seed` şunları oluşturur: süper admin, üç örnek rol (Muhasebe Müdürü, Muhasebe Personeli, Şantiye Şefi) ve eski HTML sürümündeki örnek kayıtlar. Örnek kayıtlar istemiyorsanız `database/seeders/DatabaseSeeder.php` içindeki `DemoDataSeeder` satırını kaldırın.

## Yetki modeli

| Kavram | Açıklama |
| --- | --- |
| **Süper admin** | Tüm menüler, tüm inşaatlar; Kullanıcılar, Roller, yedek indirme ve geri yükleme yalnızca süper admin içindir. |
| **Rol** | Her menü için *Erişim yok / Görüntüle / Düzenle*. Düzenle; kayıt ekleme, silme ve durum güncellemeyi açar. |
| **İnşaat erişimi** | Kullanıcı kaydında seçilir: belirli inşaatlar veya “Tüm inşaatlar”. Kullanıcı yalnızca bu inşaatların fatura, ödeme ve çek/senet kayıtlarını görür. |

Kurallar sunucuda uygulanır (`app/Http/Controllers`). Arayüzde gizlenen butonlar yalnızca kolaylık içindir. Yetkisiz menü adresi açılırsa kullanıcı, erişebildiği ilk menüye yönlendirilir. Pasifleştirilen kullanıcının açık oturumları kapatılır. Giriş denemeleri dakikada 5 ile sınırlıdır.

Cari hesaplar (120/320) tüm inşaatlarda ortaktır; bakiyeler kullanıcının görebildiği faturalardan hesaplanır.

## Dosya düzeni

- `app/Support/Access.php`: menü listesi, yetki anahtarları, sabit seçenekler.
- `app/Support/DataService.php`: kullanıcıya göre filtrelenmiş veriyi arayüze hazırlar.
- `app/Http/Controllers/RecordController.php`: fatura, ödeme, çek/senet, cari ve inşaat işlemleri.
- `app/Http/Controllers/SettingsController.php`: firma bilgisi, logo, uyarı günü, şifre değiştirme, yedekleme.
- `app/Http/Controllers/AdminController.php`: kullanıcı ve rol yönetimi.
- `resources/views/app.blade.php`, `login.blade.php`: sayfa iskeleti ve giriş ekranı.
- `public/assets/app.js`: arayüz (eski sürümle aynı ekranlar; veriler `/api` uç noktalarına gönderilir).
- `public/assets/panel.css`: giriş, kullanıcı ve rol ekranları için ek stiller.
- Fatura belgeleri `storage/app/private/invoices` altında saklanır ve yalnızca yetkili kullanıcılara indirilir.

## Gelir / gider, faturasız işlemler, tevkifat ve ortaklar

**İşlem Ekle formu.** Önce iki seçim yapılır: işlem **Faturalı** mı **Faturasız** mı, **Gider** mi **Gelir** mi.
- **Faturasız işlemler:** KDV, tevkifat ve stopaj uygulanmaz. Cari seçmek isteğe bağlıdır; cari seçilmezse karşı tarafın adı yazılır. Belge no boş bırakılırsa otomatik verilir (`FSZ-202611-0001`).
- **Ödeme ya da tahsilat:** "Ödendi / Tahsil edildi olarak kaydet" ile tamamı veya bir kısmı hemen işlenebilir. Kalan tutara Ödemeler ekranından istenen sayıda kısmi ödeme eklenir.

**Tevkifat.**
- **Tür ve oran:** GİB tevkifat türü (601 yapım işleri, 627 demir-çelik vb.) seçilince oran otomatik gelir; oran fatura bazında 1/10 ile 10/10 arasında değiştirilebilir.
- **Stopaj:** %5 (yıllara yaygın inşaat hakedişi), %20 (serbest meslek / kira) gibi gelir veya kurumlar vergisi stopajı seçilebilir.
- **Net tutar:** matrah + KDV − KDV tevkifatı − stopaj.
- **Alış faturası:** tevkif edilen KDV'nin KDV-2 dönemi ve son günü (izleyen ayın 28'i), stopajın Muhtasar son günü (izleyen ayın 26'sı) gösterilir.
- **Satış faturası:** KDV-1'e yazılacak hesaplanan KDV ve iade / mahsup hakkı gösterilir.

**Tevkifat ekranı.**
- **Özet:** KDV-2 tutarı, satıcıya ödenecek KDV, müşterinin tevkif ettiği KDV ve Muhtasar stopajı.
- **Beyan takvimi:** her ay için Bekliyor / Beyan edildi / Ödendi durumu tutulur; süresi geçen dönemler kırmızı görünür.
- **Liste:** alış ve satış faturaları KDV-1 özetiyle listelenir.

**Ayarlar → Tevkifat türleri.** Kodlar ve varsayılan oranlar güncellenir, yeni tür eklenir, türler pasifleştirilir. Tevkifat sınırı da buradan ayarlanır (varsayılan 2.000 ₺). Mevzuat değişebilir; oranları mali müşavirinizle teyit edin.

**Ortaklar.**
- **Hisseler:** her inşaat için ortaklar ve hisse oranları tanımlanır; bir inşaatta toplam en fazla %100 olabilir.
- **Ödeyen / tahsil eden:** her ödeme ve tahsilatta bu alan seçilir; boş bırakılırsa firma sayılır.
- **Rapor:** ortağın ödediği, tahsil ettiği, net katkısı, hissesine düşen tutar ve farkı (alacaklı / borçlu).

**Raporlar.**
- **İnşaat gelir-gider raporu:** faturalı ve faturasız gelir/gider, KDV hariç kâr / zarar, tahsil edilen ve ödenen, kalan alacak ve borç; altında kategori dağılımı.
- **Ortak raporu:** Ortaklar ekranındaki hesabın rapor hâli.

## Banka hesapları (Hesap Ekstresi)

Hesap Ekstresi menüsünde iki sekme vardır: **Banka hesapları** ve **Cari hesap ekstresi**.

- **Hesap tanımı:** her inşaat için banka hesapları eklenir: banka (Garanti, Ziraat vb.), şube, hesap adı, hesap no, IBAN (doğrulanır), para birimi, açılış bakiyesi ve tarihi. Kullanılmayan hesap pasif yapılır; hareketi olan hesap silinemez.
- **Hareketler:** seçilen hesaba giriş veya çıkış yazılır: tutar, tarih, işlem türü (tahsilat, kredi kullanımı, banka masrafı, SGK/vergi ödemesi vb.), açıklama, dekont no.
- **Virman:** hesaplar arası aktarım tek işlemle girilir; gönderen hesaba çıkış, alıcı hesaba giriş yazılır. Silinince iki hareket birlikte kalkar.
- **Ödemelerle bağlantı:** Havale / EFT veya kredi kartıyla girilen ödeme ve tahsilatta (İşlem Ekle → "Ödendi olarak kaydet" dahil) banka hesabı seçilirse hareket ekstreye otomatik yazılır. Bu hareketler Ödemeler ekranından silinir; ödeme silinince banka hareketi de düzeltilir.
- **Ekstre:** tarih aralığı ve giriş/çıkış filtresi, devreden bakiye, yürüyen bakiye; yazdırma/PDF ve CSV.
- **Yetki:** hareket girmek için rolde Hesap Ekstresi "Düzenle" yetkisi gerekir. Kullanıcı yalnızca erişebildiği inşaatların hesaplarını görür.

## e-Fatura ile otomatik fatura girişi

Fatura Ekle penceresinde e-Fatura / e-Arşiv **PDF** veya **XML (UBL-TR)** dosyası seçildiğinde aşağıdaki bilgiler okunur ve form doldurulur:
- fatura no, tarih, vade, ETTN
- satıcının ünvanı ve VKN/TCKN
- kalemler (miktar, birim fiyat, KDV, tutar)
- matrah, KDV oranı, tevkifat oranı, ödenecek tutar

Nasıl çalışır:
- **Cari eşleştirme:** cari önce vergi numarasıyla, bulunamazsa ünvanla eşleştirilir. Kayıtlı değilse fatura ile birlikte yeni cari olarak eklenir; bunun için cari düzenleme yetkisi gerekir.
- **Satış faturası:** Ayarlar'daki firma vergi numarası faturadaki satıcıyla aynıysa fatura satış sayılır; cari olarak alıcı önerilir (120).
- **Mükerrer kontrol:** aynı ETTN ikinci kez kaydedilemez.
- **Tutarlılık kontrolü:** matrah + KDV − tevkifat ile faturadaki ödenecek tutar karşılaştırılır; fark varsa uyarı gösterilir.
- **Paket gerekmez:** okuma `app/Support/PdfText.php` ve `app/Support/InvoiceReader.php` ile yapılır; yalnızca PHP'nin zlib ve DOM eklentileri kullanılır.
- **Okunamayanlar:** taranmış PDF'ler ve fotoğraflar okunamaz (OCR yok). Bu durumda bilgiler elle girilir.

## Çek / senet, düzeltme ve cari ekstre

- **Çek yönü:** her çek/senet ya *alınan* (müşteriden) ya *verilen* (tedarikçiye) olarak tutulur; durum seçenekleri yöne göre değişir.
- **Karşılıksız / iade:** bu durumlara alınan çekle yapılmış ödeme geçersiz sayılır ve fatura yeniden açık görünür. Durum geri alınırsa ödeme tekrar sayılır.
- **Bankaya işleme:** “Tahsil edildi” veya “Ödendi” seçilince inşaatın banka hesabı seçilebilir; hareket ekstreye yazılır, durum geri alınırsa silinir.
- **Düzeltme:** çek/senet düzenlenebilir ve silinebilir (bağlı ödemeler de silinir). Faturalar detay penceresinden düzenlenir; ödemesi olan işlemde inşaat, cari ve gelir-gider yönü değiştirilemez, net tutar ödenenin altına inemez. Cari hesaplar düzenlenip (kullanılmıyorsa) silinebilir, inşaat adı Ayarlar'dan değiştirilir.
- **Fatura no:** cari bazında tekildir; farklı tedarikçilerin aynı numaralı faturaları girilebilir.
- **Cari ekstre:** firmamız açısından tutulur. Satış faturası ve yaptığımız ödeme *Borç*, alış faturası ve aldığımız tahsilat *Alacak* sütununa yazılır; bakiye (B) cari bize borçlu, (A) biz cariye borçluyuz. Başlangıç tarihi seçilince devreden bakiye gösterilir.
- **Dönem filtresi:** ödemeler ödeme tarihine göre filtrelenir; ödeme formunda önceki dönemlerin açık faturaları da listelenir.
- **Veri gizliliği:** kullanıcının menü yetkisi olmayan ekranların verisi tarayıcıya gönderilmez; ortaklar yalnızca erişilen inşaatlarda hissesi varsa görünür.
- **Yedek:** belge yolları, ETTN, fatura kalemleri, beyan takvimi ve tevkifat türleri de yedeklenir; geri yüklemede belgeler korunur.

## İade faturası, vade hatırlatması, fatura aslı ve işlem geçmişi

- **İade faturası:** fatura detayındaki “İade faturası” ile kesilir. KDV, tevkifat ve stopaj oranları asıl faturadan alınır; matrah, henüz iade edilmemiş matrahı aşamaz. İade önce asıl faturanın kalanından düşülür; fatura ödenmişse fazlası tedarikçiden geri alınacak (alış iadesi) veya müşteriye ödenecek (satış iadesi) tutar olarak izlenir ve Ödemeler ekranından kapatılır. Toplamlar, tevkifat ekranı, cari bakiye ve ekstre iadeleri eksi olarak hesaplar. İade faturası düzenlenmez; hatalıysa silinip yeniden girilir.
- **Vade hatırlatması:** üstteki zil simgesi, vadesi geçmiş veya “Vade uyarı süresi” içinde dolacak faturaları ve çek/senetleri sayar ve listeler. Ana sayfada aynı liste, Faturalar ekranında “Vadesi geçmiş / Vadesi yaklaşan” filtreleri ve satırlarda kalan gün bilgisi vardır.
- **Fatura aslı:** fatura detayında PDF önizlenir, “Aslını aç” ile tarayıcıda açılır; e-Fatura XML, içindeki görüntüleme şablonuyla fatura görünümünde gösterilir. Belgesi olmayan faturaya sonradan belge yüklenebilir veya belge değiştirilebilir. Belgeler tarayıcıda betik çalıştıramayacak şekilde sunulur.
- **İşlem geçmişi:** fatura, ödeme, çek, cari, inşaat, ortak, banka, kullanıcı, rol ve ayar kayıtlarının eklenmesi, değiştirilmesi ve silinmesi kullanıcı ve saatle tutulur (eski → yeni değer). “İşlem Geçmişi” menüsünde filtrelenir; fatura detayında o faturanın geçmişi görünür. Kullanıcı yalnızca erişebildiği inşaatların kayıtlarını görür; inşaata bağlı olmayan kayıtlar (cari, ayar, kullanıcı) yalnızca süper admine görünür. Rolde “İşlem Geçmişi” görüntüleme yetkisi gerekir.

## Notlar

- Fatura belgesi sınırı uygulamada 10 MB'tır. PHP'nin varsayılan `upload_max_filesize` değeri 2M'dir; daha büyük dosyalar için php.ini'de `upload_max_filesize` ve `post_max_size` değerlerini artırın.
- Ayarlar → Yedekten geri yükle, eski HTML sürümünden indirilen JSON yedeklerini de kabul eder.
- Canlı ortamda `.env` içinde `APP_ENV=production` ve `APP_DEBUG=false` yapın, HTTPS kullanın.
- Excel aktarımı CSV biçimindedir (noktalı virgül ayraçlı, ondalık virgüllü); PDF çıktısı tarayıcının yazdırma özelliğiyle alınır.
- Şifre değiştirildiğinde kullanıcının diğer cihazlardaki oturumları kapatılır.
