<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use \App\Models\Concerns\Auditable;

    protected string $auditType = 'bankaccount';

    protected function auditLabel(): string
    {
        return $this->bank.($this->name ? ' · '.$this->name : '');
    }

    protected $fillable = ['project_id', 'bank', 'branch', 'name', 'iban', 'account_no', 'currency', 'opening_balance', 'opening_date', 'note', 'active'];

    protected function casts(): array
    {
        return ['opening_balance' => 'float', 'opening_date' => 'date:Y-m-d', 'active' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}
