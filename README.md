# Öztoprak İnşaat Muhasebe Sistemi

Geliştirici: **Alperen Gündoğdu Yazılım** · [alperengundogdu.com.tr](https://alperengundogdu.com.tr)

Onaylanan tasarımın, her ekranı ayrı dosyadan açılan HTML + Tailwind CSS + sade JavaScript sürümü. React, Next.js veya başka bir arayüz framework'ü gerektirmez.

## Başlatma

ZIP dosyasını çıkarın, klasör yapısını koruyun ve `index.html` dosyasını açın. Derlenmiş CSS ve JavaScript dosyaları pakette hazırdır; görüntülemek için npm kurulumu gerekmez.

Sayfalar arasında aynı tarayıcı kayıtlarının paylaşılması için klasörü bir yerel sunucuyla açmak önerilir. VS Code Live Server kullanabilir veya bu klasörde şu komutu çalıştırabilirsiniz:

```bash
python -m http.server 8080
```

Ardından `http://localhost:8080` adresini açın. `file://` üzerinden açıldığında localStorage paylaşımı tarayıcıya göre değişebilir. Inter yazı tipi internetten yüklenir; internet yoksa Arial kullanılır. Stiller, ikonlar ve uygulama betikleri yereldir.

## Sayfalar

| Dosya | Ekran |
| --- | --- |
| index.html | Genel Bakış |
| faturalar.html | Faturalar |
| odemeler.html | Ödemeler |
| tevkifat.html | Tevkifat ve KDV-2 |
| cek-senet.html | Çek / Senet |
| cari-hesaplar.html | Cari Hesaplar |
| alicilar.html | 120 Alıcılar |
| saticilar.html | 320 Satıcılar |
| hesap-ekstresi.html | Hesap Ekstresi |
| raporlar.html | Raporlar |
| ayarlar.html | Ayarlar |

Her HTML dosyası kendi başlığını, menüsünü ve gerçek sayfa içeriğini taşır. Menü, HTML dosyaları arasında bağlantı kurar. `body` üzerindeki `data-page` değeri o sayfanın etkileşimlerini belirler.

## Dosya düzeni

- `assets/tailwind.css`: hazır derlenmiş Tailwind CSS 4 stilleri.
- `src/tailwind.css`: Tailwind kaynak dosyası, renk ve font tanımları.
- `assets/components.css`: onaylanan tasarımın kart, tablo, modal, grafik, responsive ve tema bileşenleri.
- `assets/app.js`: ortak veri modeli, sayfa şablonları, hesaplamalar, formlar ve etkileşimler. Sade JavaScript'tir.
- `assets/favicon.svg`: panel renkleriyle favicon.
- `build-pages.cjs`: ortak şablonlardan 11 HTML dosyasını yeniden üretir.
- `build-css.mjs`: Tailwind kaynaklarını derler.
- `package.json`: sadece yeniden derleme için geliştirme bağımlılıkları.

Ortak menü ve üst alanı ilgili HTML'de düzenleyebilirsiniz. Dinamik tablolar ve formlar, tarayıcı verileriyle güncellendiği için bu alanların kalıcı değişikliklerini `assets/app.js` içindeki ilgili şablon fonksiyonunda yapın. `build-pages.cjs` çalıştırmak HTML dosyalarının üzerine yeniden yazar.

## Tailwind ile düzenleme

Hazır dosyalar doğrudan çalışır. Yeni Tailwind sınıfları eklediğinizde yeniden derlemek için Node.js 20 veya üzeriyle:

```bash
npm install
npm run build:css
```

HTML dosyalarını ortak şablonlardan da yeniden üretmek için:

```bash
npm run build
```

Renkler: `#1b3c48` (petrol), `#62766d` (adaçayı), `#314029` (orman yeşili). Tailwind tema adları `petrol`, `sage`, `forest` olarak tanımlıdır. Örneğin `bg-petrol`, `text-sage` ve `border-forest` kullanabilirsiniz. Başarı, gecikme ve uyarı renkleri ayrıca korunur.

## Çalışan etkileşimler

- İnşaat seçimi, tüm inşaatlar görünümü ve proje ekleme.
- İki adımlı fatura formu; matrah, KDV, tevkifat ve net tutar hesabı.
- Aynı carinin birden fazla açık faturasına ödeme dağıtımı; fazla ödeme ve mükerrer belge engeli.
- Çek/senet kaydı, vade uyarısı ve durum güncellemesi.
- Cari listeleri, cari detayında Hareketler / Faturalar / Ödemeler / Çek-Senet sekmeleri.
- Tarih ve cari filtreli hesap ekstresi, yürüyen bakiye.
- Dört rapor görünümü; CSV dışa aktarma ve tarayıcı üzerinden yazdırma/PDF kaydetme.
- Firma bilgisi ve logo, inşaat yönetimi, uyarı günü ayarı.
- JSON yedek indirme ve ilişkileri doğrulanmış yedek içe aktarma.
- Açık/koyu tema, daraltılabilir menü, mobil menü, Ctrl+K, Escape ile modal kapatma.

## Kapsam ve veri

Bu paket ön yüz uygulamasıdır. Örnek kayıtlarla açılır; değişiklikleri `localStorage` içinde tutar. Gerçek banka işlemi yapmaz. Kullanıcı giriş/yetki sistemi, sunucu veritabanı, cihazlar arası senkronizasyon ve OCR bağlı değildir. Fatura belgesinin yalnızca dosya adı kaydedilir; PDF içeriği sunucuda saklanmaz.

Excel aktarımı **CSV** biçimindedir; XLSX üretmez. PDF çıktısı tarayıcının yazdır/PDF kaydet özelliğiyle alınır. Hesap ekstresi seçilen dönemin hareketlerini gösterir; dönem öncesi devir bakiyesi hesaplamaz. Proje raporu maliyet/fatura/ödeme özetidir, gelir kayıtları bulunmadan gerçek kârlılık raporu olarak kullanılmamalıdır.

Verileri düzenli olarak Ayarlar → Yedek indir ile yedekleyin. Sunucu bağlantısı eklenirken kayıt ekleme/silme, dağıtım ve yetki kontrolleri sunucu tarafında da uygulanmalıdır.
