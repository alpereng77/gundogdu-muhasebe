<?php

namespace App\Support;

/** Önbellek kırıcı sürümlü varlık adresi; dosya yoksa sayfa çökmez, sürümsüz adres döner. */
class Asset
{
    public static function url(string $path): string
    {
        $file = public_path($path);
        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    }
}
