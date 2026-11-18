<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cheque extends Model
{
    use \App\Models\Concerns\Auditable;

    protected function auditLabel(): string
    {
        return $this->type.' '.$this->number;
    }

    protected $fillable = ['number', 'project_id', 'party_id', 'amount', 'due', 'bank', 'status', 'type', 'direction', 'bank_transaction_id', 'created_by'];

    protected function casts(): array
    {
        return ['due' => 'date:Y-m-d', 'amount' => 'float'];
    }

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isVoid(): bool
    {
        return in_array($this->status, \App\Support\Access::VOID_CHEQUE_STATUSES, true);
    }
}
