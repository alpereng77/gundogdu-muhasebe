<?php

namespace App\Support;

/**
 * Paneldeki menüler. Anahtar, rol yetkilerinde ve rotalarda kullanılır.
 * editable=true olan menülerde rol "Düzenle" yetkisi verilebilir.
 */
class Access
{
    public const PAGES = [
        'genel-bakis'    => ['label' => 'Genel Bakış',    'path' => '/',               'icon' => 'dashboard', 'editable' => false],
        'faturalar'      => ['label' => 'Faturalar',      'path' => '/faturalar',      'icon' => 'file',      'editable' => true],
        'odemeler'       => ['label' => 'Ödemeler',       'path' => '/odemeler',       'icon' => 'wallet',    'editable' => true],
        'tevkifat'       => ['label' => 'Tevkifat',       'path' => '/tevkifat',       'icon' => 'percent',   'editable' => true],
        'cek-senet'      => ['label' => 'Çek / Senet',    'path' => '/cek-senet',      'icon' => 'bank',      'editable' => true],
        'cari-hesaplar'  => ['label' => 'Cari Hesaplar',  'path' => '/cari-hesaplar',  'icon' => 'users',     'editable' => true],
        'alicilar'       => ['label' => '120 Alıcılar',   'path' => '/alicilar',       'icon' => 'users',     'editable' => true],
        'saticilar'      => ['label' => '320 Satıcılar',  'path' => '/saticilar',      'icon' => 'users',     'editable' => true],
        'hesap-ekstresi' => ['label' => 'Hesap Ekstresi', 'path' => '/hesap-ekstresi', 'icon' => 'transfer',  'editable' => true],
        'ortaklar'       => ['label' => 'Ortaklar',       'path' => '/ortaklar',       'icon' => 'users',      'editable' => true],
        'islem-gecmisi'  => ['label' => 'İşlem Geçmişi',  'path' => '/islem-gecmisi',  'icon' => 'clock',     'editable' => false],
        'raporlar'       => ['label' => 'Raporlar',       'path' => '/raporlar',       'icon' => 'chart',     'editable' => false],
        'ayarlar'        => ['label' => 'Ayarlar',        'path' => '/ayarlar',        'icon' => 'settings',  'editable' => true],
    ];

    /** Yalnızca süper admin'in gördüğü yönetim menüleri */
    public const ADMIN_PAGES = [
        'kullanicilar' => ['label' => 'Kullanıcılar', 'path' => '/kullanicilar', 'icon' => 'users',  'editable' => true],
        'roller'       => ['label' => 'Roller',       'path' => '/roller',       'icon' => 'shield', 'editable' => true],
    ];

    public const PARTY_TYPES = ['120 Alıcılar', '320 Satıcılar'];
    public const PAY_METHODS = ['Havale / EFT', 'Nakit', 'Kredi Kartı', 'Çek', 'Senet'];
    public const CHEQUE_STATUSES = ['Portföyde', 'Tahsil edildi', 'Ödendi', 'Ciro edildi', 'İade edildi', 'Karşılıksız'];
    /** Alınan (müşteri) ve verilen (kendi) çek/senet için geçerli durumlar */
    public const CHEQUE_FLOW = [
        'alinan' => ['Portföyde', 'Tahsil edildi', 'Ciro edildi', 'İade edildi', 'Karşılıksız'],
        'verilen' => ['Portföyde', 'Ödendi', 'İade edildi', 'Karşılıksız'],
    ];
    /** Bu durumdaki çek/senetle yapılan ödeme geçersizdir; fatura yeniden açık sayılır. */
    public const VOID_CHEQUE_STATUSES = ['İade edildi', 'Karşılıksız'];
    public const VAT_RATES = [0, 1, 8, 10, 18, 20];
    public const WITHHOLDING_RATES = [0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1];
    public const STOPAJ_RATES = [0, 1, 2, 3, 5, 10, 15, 17, 20];
    public const KINDS = ['faturali', 'faturasiz'];
    public const DIRECTIONS = ['gider', 'gelir'];
    public const CURRENCIES = ['TRY', 'USD', 'EUR', 'GBP'];
    /** Banka hareketine bağlanabilen ödeme yöntemleri */
    public const BANK_METHODS = ['Havale / EFT', 'Kredi Kartı'];
    public const TAX_STATUSES = ['Bekliyor', 'Beyan edildi', 'Ödendi'];

    /**
     * KDV Uygulama Genel Tebliği (I/C-2.1.3) kısmi tevkifat türleri ve varsayılan oranlar.
     * Oranlar Ayarlar → Tevkifat türleri bölümünden güncellenebilir; mali müşavirinizle teyit ediniz.
     */
    public const DEFAULT_WITHHOLDING_TYPES = [
        ['601', 'Yapım işleri ile birlikte ifa edilen mühendislik-mimarlık ve etüt-proje hizmetleri', 0.4],
        ['602', 'Etüt, plan-proje, danışmanlık, denetim ve benzeri hizmetler', 0.9],
        ['603', 'Makine, teçhizat, demirbaş ve taşıtlara ait tadil, bakım ve onarım hizmetleri', 0.7],
        ['604', 'Yemek servis hizmeti', 0.5],
        ['605', 'Organizasyon hizmeti', 0.5],
        ['606', 'İşgücü temin hizmetleri', 0.9],
        ['607', 'Özel güvenlik hizmeti', 0.9],
        ['608', 'Yapı denetim hizmetleri', 0.9],
        ['609', 'Fason tekstil-konfeksiyon, çanta ve ayakkabı dikim işleri', 0.7],
        ['610', 'Turistik mağazalara verilen müşteri bulma / götürme hizmetleri', 0.9],
        ['611', 'Spor kulüplerinin yayın, reklam ve isim hakkı gelirleri', 0.9],
        ['612', 'Temizlik hizmeti', 0.9],
        ['613', 'Çevre ve bahçe bakım hizmetleri', 0.9],
        ['614', 'Servis taşımacılığı hizmeti', 0.5],
        ['615', 'Her türlü baskı ve basım hizmetleri', 0.7],
        ['616', '5018 sayılı Kanun kapsamındaki idarelere ifa edilen diğer hizmetler', 0.5],
        ['617', 'Hurda metalden elde edilen külçe teslimleri', 0.7],
        ['618', 'Hurda metal dışındaki bakır, çinko, alüminyum külçe teslimleri', 0.7],
        ['619', 'Bakır, çinko ve alüminyum ürünlerinin teslimi', 0.7],
        ['620', 'İstisnadan vazgeçenlerin hurda ve atık teslimi', 0.7],
        ['621', 'Metal, plastik, lastik, kauçuk, kâğıt ve cam hurda/atıklardan elde edilen hammadde teslimi', 0.9],
        ['622', 'Pamuk, tiftik, yün, yapağı ile ham post ve deri teslimleri', 0.9],
        ['623', 'Ağaç ve orman ürünleri teslimi', 0.5],
        ['624', 'Yük taşımacılığı hizmeti', 0.2],
        ['625', 'Ticari reklam hizmetleri', 0.3],
        ['626', 'Diğer teslimler', 0.2],
        ['627', 'Demir-çelik ürünlerinin teslimi', 0.5],
    ];

    public static function allPages(): array
    {
        return self::PAGES + self::ADMIN_PAGES;
    }

    /** Rol formundan gelen yetkileri temizler: yalnızca bilinen menüler ve view/edit değerleri. */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::PAGES as $key => $page) {
            $level = $input[$key] ?? null;
            if ($level === 'edit' && $page['editable']) {
                $out[$key] = 'edit';
            } elseif (in_array($level, ['view', 'edit'], true)) {
                $out[$key] = 'view';
            }
        }
        return $out;
    }
}
