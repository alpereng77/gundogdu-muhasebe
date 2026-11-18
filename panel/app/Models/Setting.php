<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use \App\Models\Concerns\Auditable;

    protected function auditLabel(): string
    {
        return $this->key;
    }

    /** Logo içeriği işlem geçmişine yazılmaz. */
    protected function auditHiddenFor(): array
    {
        return $this->key === 'logo' ? ['value'] : [];
    }

    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['key', 'value'];

    public static function get(string $key, $default = null)
    {
        return static::find($key)?->value ?? $default;
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function all_(): array
    {
        return static::pluck('value', 'key')->all();
    }
}
