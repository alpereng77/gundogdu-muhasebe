<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TaxPeriod;
use App\Models\User;
use App\Models\WithholdingType;

/**
 * Ön yüze gönderilen veri: kullanıcının yetkili olduğu inşaatlarla sınırlıdır.
 * Biçim, eski localStorage veri modeliyle aynıdır (id = belge numarası).
 */
class DataService
{
    public static function snapshot(User $user): array
    {
        $projectIds = $user->projectIds();
        $s = Setting::all_();

        // Menü yetkisine göre veri: kullanıcının göremediği ekranların verisi tarayıcıya gönderilmez.
        $can = fn (array $keys) => collect($keys)->contains(fn ($k) => $user->canView($k));
        $needInvoices = $can(['genel-bakis', 'faturalar', 'odemeler', 'tevkifat', 'cari-hesaplar', 'alicilar', 'saticilar', 'hesap-ekstresi', 'ortaklar', 'raporlar']);
        $needCheques = $can(['genel-bakis', 'cek-senet', 'odemeler', 'raporlar']);
        $needPartners = $can(['ortaklar', 'odemeler', 'faturalar', 'raporlar']);
        $needTax = $can(['tevkifat', 'raporlar']);

        $invoices = $needInvoices ? Invoice::with('party:id,code')->whereIn('project_id', $projectIds)->orderBy('date')->orderBy('id')->get() : collect();
        // Ön yüz anahtarı: belge no; farklı carilerde aynı numara varsa cari kodu eklenir.
        $dupNumbers = $invoices->countBy('number')->filter(fn ($n) => $n > 1);
        $keyOf = fn ($i) => isset($dupNumbers[$i->number]) ? $i->number.' · '.($i->party?->code ?? 'carisiz') : $i->number;
        $invoiceNumbers = $invoices->mapWithKeys(fn ($i) => [$i->id => $keyOf($i)]);
        $payments = Payment::whereIn('invoice_id', $invoices->pluck('id'))->with('cheque:id,number,status')->orderBy('date')->orderBy('id')->get();

        $db = [
            'projects' => Project::whereIn('id', $projectIds)->orderBy('id')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'parties' => Party::orderBy('code')->get()->map(fn ($p) => [
                'id' => (string) $p->id, 'name' => $p->name, 'code' => $p->code, 'type' => $p->type, 'tax' => $p->tax ?? '',
            ])->values(),
            'invoices' => $invoices->map(fn ($i) => [
                'pk' => $i->id, 'id' => $keyOf($i), 'no' => $i->number, 'ret' => $i->return_of_id ? ($invoiceNumbers[$i->return_of_id] ?? null) : null, 'project' => $i->project_id, 'party' => $i->party_id ? (string) $i->party_id : '',
                'kind' => $i->kind, 'dir' => $i->direction, 'cat' => $i->category ?? '', 'cp' => $i->counterparty ?? '',
                'wcode' => $i->withholding_code ?? '', 'stopaj' => $i->stopaj,
                'date' => $i->date->format('Y-m-d'), 'due' => $i->due->format('Y-m-d'),
                'base' => $i->base, 'vat' => $i->vat, 'rate' => $i->rate, 'desc' => $i->description ?? '',
                'file' => $i->file_name ?? '', 'hasFile' => (bool) $i->file_path,
                'ettn' => $i->ettn ?? '', 'items' => $i->items ?? [],
            ])->values(),
            'payments' => $payments->map(fn ($p) => [
                'pk' => $p->id, 'id' => $p->number, 'invoice' => $invoiceNumbers[$p->invoice_id],
                'amount' => $p->amount, 'date' => $p->date->format('Y-m-d'), 'method' => $p->method,
                'checkId' => $p->cheque?->number, 'partner' => $p->partner_id, 'bankTx' => $p->bank_transaction_id,
                // Karşılıksız / iade edilen çekle yapılan ödeme geçersizdir.
                'void' => $p->cheque && in_array($p->cheque->status, Access::VOID_CHEQUE_STATUSES, true),
            ])->values(),
            'checks' => ($needCheques ? Cheque::whereIn('project_id', $projectIds)->orderBy('due')->get() : collect())->map(fn ($c) => [
                'pk' => $c->id, 'id' => $c->number, 'project' => $c->project_id, 'party' => (string) $c->party_id,
                'amount' => $c->amount, 'due' => $c->due->format('Y-m-d'), 'bank' => $c->bank,
                'status' => $c->status, 'type' => $c->type, 'dir' => $c->direction, 'bankTx' => $c->bank_transaction_id,
            ])->values(),
            'company' => $s['company'] ?? 'Gündoğdu İnşaat A.Ş.',
            'tax' => $s['tax'] ?? '',
            'address' => $s['address'] ?? '',
            'logo' => $s['logo'] ?? '',
            'warning' => (int) ($s['warning'] ?? 7),
            'wLimit' => (float) ($s['wLimit'] ?? 2000),
            'withholdingTypes' => WithholdingType::orderBy('sort')->orderBy('code')->get()
                ->map(fn ($w) => ['code' => $w->code, 'name' => $w->name, 'rate' => $w->rate, 'active' => $w->active])->values(),
            // Ortaklar: hisse oranları yalnızca kullanıcının erişebildiği inşaatlar için gönderilir.
            // Ortaklar: yalnızca erişilen inşaatlarda hissesi olan (veya henüz hissesi olmayan) ortaklar.
            'partners' => ($needPartners ? Partner::with(['projects' => fn ($q) => $q->whereIn('projects.id', $projectIds)])
                ->where(fn ($q) => $q->whereHas('projects', fn ($r) => $r->whereIn('projects.id', $projectIds))->orWhereDoesntHave('projects'))
                ->orderBy('name')->get() : collect())
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'phone' => $p->phone ?? '', 'note' => $p->note ?? '',
                    'shares' => $p->projects->mapWithKeys(fn ($pr) => [$pr->id => (float) $pr->pivot->share])])->values(),
            'taxPeriods' => ($needTax ? TaxPeriod::orderBy('period')->get() : collect())->map(fn ($t) => [
                'kind' => $t->kind, 'period' => $t->period, 'status' => $t->status,
                'declared' => $t->declared_on?->format('Y-m-d'), 'paid' => $t->paid_on?->format('Y-m-d'), 'note' => $t->note ?? '',
            ])->values(),
        ];

        // Banka hesapları: ödeme formunda seçilebilmesi için hesap bilgisi; bakiye ve hareketler yalnızca Hesap Ekstresi yetkisiyle.
        $accounts = BankAccount::whereIn('project_id', $projectIds)->orderBy('project_id')->orderBy('bank')->orderBy('id')->get();
        $seeBank = $user->canView('hesap-ekstresi');
        $db['bankAccounts'] = $accounts->map(fn ($a) => [
            'id' => $a->id, 'project' => $a->project_id, 'bank' => $a->bank, 'branch' => $a->branch ?? '', 'name' => $a->name ?? '',
            'iban' => $a->iban ?? '', 'accountNo' => $a->account_no ?? '', 'currency' => $a->currency, 'active' => $a->active,
        ] + ($seeBank ? ['opening' => $a->opening_balance, 'openingDate' => $a->opening_date?->format('Y-m-d'), 'note' => $a->note ?? ''] : []))->values();
        $db['bankTx'] = [];
        if ($seeBank) {
            $txs = BankTransaction::whereIn('bank_account_id', $accounts->pluck('id'))->withCount('payments')->orderBy('date')->orderBy('id')->get();
            $chequeTx = Cheque::whereIn('bank_transaction_id', $txs->pluck('id'))->pluck('number', 'bank_transaction_id');
            $txAccount = BankTransaction::whereIn('id', $txs->pluck('transfer_id')->filter())->pluck('bank_account_id', 'id');
            $db['bankTx'] = $txs->map(fn ($t) => [
                'id' => $t->id, 'account' => $t->bank_account_id, 'date' => $t->date->format('Y-m-d'), 'dir' => $t->direction,
                'amount' => $t->amount, 'cat' => $t->category ?? '', 'desc' => $t->description ?? '', 'doc' => $t->document_no ?? '',
                'transfer' => $t->transfer_id ? ($txAccount[$t->transfer_id] ?? 0) : null, 'linked' => $t->payments_count > 0 || isset($chequeTx[$t->id]), 'cheque' => $chequeTx[$t->id] ?? null,
            ])->values();
        }

        if ($user->is_super_admin) {
            $db['roles'] = Role::withCount('users')->orderBy('name')->get()->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'description' => $r->description ?? '',
                'permissions' => Access::sanitize($r->permissions ?? []), 'users' => $r->users_count,
            ])->values();
            $db['users'] = User::with('projects:id')->orderBy('name')->get()->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role_id,
                'super' => $u->is_super_admin, 'allProjects' => $u->all_projects, 'active' => $u->is_active,
                'projects' => $u->projects->pluck('id')->values(),
                'lastLogin' => $u->last_login_at?->format('Y-m-d H:i'),
            ])->values();
            // Süper admin tüm inşaatları yönetir; kullanıcı formunda listelenir.
            $db['allProjects'] = Project::orderBy('id')->get(['id', 'name']);
        }

        return $db;
    }

    /** Oturumdaki kullanıcının ön yüz bilgisi */
    public static function me(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->is_super_admin ? 'Süper Admin' : ($user->role?->name ?? 'Rol atanmamış'),
            'super' => $user->is_super_admin,
            'perms' => $user->permissionMap(),
        ];
    }
}
