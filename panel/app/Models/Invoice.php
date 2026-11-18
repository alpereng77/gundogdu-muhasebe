<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use \App\Models\Concerns\Auditable;

    protected function auditLabel(): string
    {
        return $this->number;
    }

    protected $fillable = ['number', 'kind', 'direction', 'category', 'counterparty', 'withholding_code', 'stopaj', 'ettn', 'items', 'project_id', 'party_id', 'return_of_id', 'date', 'due', 'base', 'vat', 'rate', 'description', 'file_name', 'file_path', 'created_by'];

    protected function casts(): array
    {
        return ['items' => 'array', 'date' => 'date:Y-m-d', 'due' => 'date:Y-m-d', 'base' => 'float', 'vat' => 'float', 'rate' => 'float', 'stopaj' => 'float'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Ödenecek / tahsil edilecek net = matrah + KDV − KDV tevkifatı − stopaj
     * (ön yüzdeki net() ile aynı). $rate: tevkifat oranı (0,4 = 4/10), $stopaj: yüzde.
     */
    public static function netOf(float $base, float $vat, float $rate, float $stopaj = 0): float
    {
        return round($base * (1 + $vat / 100 - $vat / 100 * $rate - $stopaj / 100), 2);
    }

    public function net(): float
    {
        return self::netOf($this->base, $this->vat, $this->rate, $this->stopaj ?? 0);
    }

    /** Geçerli ödemeler toplamı: karşılıksız / iade edilen çek-senetle yapılan ödemeler sayılmaz. */
    public function paidAmount(): float
    {
        return round((float) $this->payments()
            ->where(fn ($q) => $q->whereNull('cheque_id')->orWhereHas('cheque', fn ($c) => $c->whereNotIn('status', \App\Support\Access::VOID_CHEQUE_STATUSES)))
            ->sum('amount'), 2);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(Invoice::class, 'return_of_id');
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'return_of_id');
    }

    public function isReturn(): bool
    {
        return (bool) $this->return_of_id;
    }

    /** Para akışı yönü: iade faturasında asıl faturanın tersidir (alış iadesinde para bize gelir). */
    public function flow(): string
    {
        return $this->return_of_id ? ($this->direction === 'gelir' ? 'gider' : 'gelir') : $this->direction;
    }

    /** Bu faturaya kesilen iadelerin net toplamı */
    public function creditAmount(): float
    {
        return round((float) $this->returns()->get()->sum(fn ($r) => $r->net()), 2);
    }

    /**
     * Kalan: normal faturada net − geçerli ödemeler − iadeler.
     * İade faturasında: asıl fatura ödendikten sonra artan iade tutarı (karşı taraftan geri alınacak / ödenecek) − bu iadeye yapılan ödemeler.
     */
    public function remaining(): float
    {
        if ($this->return_of_id) {
            return $this->refundable();
        }
        return max(0, round($this->net() - $this->paidAmount() - $this->creditAmount(), 2));
    }

    public function refundable(): float
    {
        $o = $this->originalInvoice;
        if (! $o) {
            return 0;
        }
        $overflow = max(0, $o->paidAmount() + $o->creditAmount() - $o->net());
        foreach ($o->returns()->orderByDesc('id')->get() as $r) {
            $part = min($r->net(), $overflow);
            $overflow = max(0, $overflow - $part);
            if ($r->id === $this->id) {
                return max(0, round($part - $this->paidAmount(), 2));
            }
        }
        return 0;
    }
}
