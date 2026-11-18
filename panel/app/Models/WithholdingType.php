<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** KDV tevkifat türü (GİB kodu ve varsayılan oran). */
class WithholdingType extends Model
{
    use \App\Models\Concerns\Auditable;

    protected string $auditType = 'withholding';

    protected function auditLabel(): string
    {
        return $this->code.' · '.$this->name;
    }

    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['code', 'name', 'rate', 'active', 'sort'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'active' => 'boolean'];
    }
}
