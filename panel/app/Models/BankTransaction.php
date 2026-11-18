<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankTransaction extends Model
{
    use \App\Models\Concerns\Auditable;

    protected string $auditType = 'banktx';

    protected function auditLabel(): string
    {
        $a = BankAccount::find($this->bank_account_id);
        return ($a ? $a->bank.' · ' : '').($this->direction === 'giris' ? 'Giriş ' : 'Çıkış ').number_format((float) $this->amount, 2, ',', '.').' ₺';
    }

    protected function auditProjectId(): ?int
    {
        return BankAccount::whereKey($this->bank_account_id)->value('project_id');
    }

    protected $fillable = ['bank_account_id', 'date', 'direction', 'amount', 'category', 'description', 'document_no', 'transfer_id', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
