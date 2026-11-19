<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Tema ayarları: sistem logosu, renkler, yazı tipi ve yazı boyutu.
 * Ayar yapılmadığında hiçbir stil eklenmez; panel varsayılan görünümüyle çalışır.
 */
class Theme
{
    public const DEFAULTS = ['menu' => '#1b3c48', 'accent' => '#314029', 'bg' => '#f4f5f1', 'font' => 'Inter', 'scale' => 1.0, 'logo' => '', 'layout' => 'side'];

    /** Menü yerleşimi: side = sol menü, top = üst menü */
    public const LAYOUTS = ['side', 'top'];

    /** Seçilebilir yazı tipleri: ad => Google Fonts aile adı (null: sistem yazı tipi, dışarıdan yüklenmez) */
    public const FONTS = [
        'Inter' => 'Inter', 'Roboto' => 'Roboto', 'Open Sans' => 'Open+Sans', 'Lato' => 'Lato', 'Nunito' => 'Nunito',
        'Poppins' => 'Poppins', 'Montserrat' => 'Montserrat', 'Source Sans 3' => 'Source+Sans+3', 'Noto Sans' => 'Noto+Sans',
        'Sistem yazı tipi' => null,
    ];

    public const SCALES = [0.9, 1.0, 1.1, 1.2, 1.3];

    public static function get(): array
    {
        $t = json_decode((string) Setting::get('theme', ''), true);
        return array_merge(self::DEFAULTS, is_array($t) ? $t : []);
    }

    /** Ön yüze gönderilen tema (logo dahil) */
    public static function forClient(): array
    {
        $t = self::get();
        return $t + ['fonts' => array_keys(self::FONTS), 'scales' => self::SCALES, 'defaults' => self::DEFAULTS, 'css' => self::css($t), 'fontUrl' => self::fontUrl($t)];
    }

    public static function fontUrl(?array $t = null): ?string
    {
        $t ??= self::get();
        $family = self::FONTS[$t['font']] ?? null;
        if (! $family || $t['font'] === 'Inter') {
            return null; // Inter zaten yüklü
        }
        return 'https://fonts.googleapis.com/css2?family='.$family.':wght@400;500;600;700&display=swap';
    }

    /** Yalnızca varsayılandan farklı ayarlar için stil üretir. */
    public static function css(?array $t = null): string
    {
        $t ??= self::get();
        $d = self::DEFAULTS;
        $css = '';
        if ($t['menu'] !== $d['menu']) {
            $m = $t['menu'];
            $css .= 'aside{background:linear-gradient(165deg,'.$m.' 0%,'.self::shade($m, -.12).' 70%,'.self::shade($m, -.2).' 100%)!important}'
                .'.loginpage{background:linear-gradient(165deg,'.$m.' 0%,'.self::shade($m, -.12).' 55%,'.self::shade($m, -.2).' 100%)!important}'
                .'.collapse{background:'.self::shade($m, .12).'!important;border-color:'.self::shade($m, .25).'!important}'
                .'.toast.info{background:'.$m.'!important}';
        }
        if ($t['accent'] !== $d['accent']) {
            $a = $t['accent'];
            $grad = 'linear-gradient(110deg,'.$a.','.self::shade($a, .22).')';
            $css .= 'button.primary,.uibtns .uiok,.docupload,.docactions .primarylink{background:'.$grad.'!important;color:#fff!important}'
                .'nav a.active{background:linear-gradient(105deg,'.self::shade($a, .25).','.$a.')!important}'
                .'.brandmark{background:linear-gradient(145deg,'.self::shade($a, .35).','.$a.')!important}'
                .'.link,.textbutton,.btnlink,.settabs button.selected,.tabs .selected{color:'.$a.'!important}'
                .'.settabs button.selected,.tabs .selected{border-bottom-color:'.$a.'!important}'
                .'.bankacc.selected{border-color:'.$a.'!important;box-shadow:0 0 0 2px '.$a.'30!important}'
                .'.seg input:checked+span{color:'.$a.'!important}'
                .'button:focus-visible,input:focus,select:focus,textarea:focus{outline-color:'.$a.'!important}';
        }
        if ($t['bg'] !== $d['bg']) {
            $css .= ':root,.app:not(.dark),.app:not(.dark) main{background:'.$t['bg'].'!important}.app:not(.dark){--bg:'.$t['bg'].'}';
        }
        if ($t['font'] !== $d['font'] && array_key_exists($t['font'], self::FONTS)) {
            $stack = self::FONTS[$t['font']] ? "'".$t['font']."',Arial,sans-serif" : "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif";
            $css .= ':root,body,button,input,select,textarea,.uibox{font-family:'.$stack.'!important}';
        }
        if ((float) $t['scale'] !== 1.0) {
            $css .= 'html{zoom:'.(float) $t['scale'].'}';
        }
        return $css;
    }

    /** Rengi açar (+) veya koyulaştırır (−); oran −1…1 */
    public static function shade(string $hex, float $p): string
    {
        $c = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $c = array_map(fn ($v) => (int) round($p >= 0 ? $v + (255 - $v) * $p : $v * (1 + $p)), $c);
        return sprintf('#%02x%02x%02x', ...$c);
    }

    /** Yazının okunabilmesi için beyaz yazı ile kontrast oranı */
    public static function contrastWithWhite(string $hex): float
    {
        $l = array_map(function ($v) {
            $v = hexdec($v) / 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));
        $lum = 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
        return 1.05 / ($lum + 0.05);
    }
}
