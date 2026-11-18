<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use \App\Models\Concerns\Auditable;

    protected function auditLabel(): string
    {
        return $this->number.' · '.number_format((float) $this->amount, 2, ',', '.').' ₺';
    }

    protected function auditProjectId(): ?int
    {
        return Invoice::whereKey($this->invoice_id)->value('project_id');
    }

    protected $fillable = ['number', 'invoice_id', 'amount', 'date', 'method', 'cheque_id', 'partner_id', 'bank_transaction_id', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }
}
