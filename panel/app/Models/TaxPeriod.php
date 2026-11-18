<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** KDV-2 / Muhtasar beyan dönemi durumu. */
class TaxPeriod extends Model
{
    use \App\Models\Concerns\Auditable;

    protected string $auditType = 'taxperiod';

    protected function auditLabel(): string
    {
        return strtoupper($this->kind).' '.$this->period;
    }

    protected $fillable = ['kind', 'period', 'status', 'declared_on', 'paid_on', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['declared_on' => 'date:Y-m-d', 'paid_on' => 'date:Y-m-d'];
    }
}
