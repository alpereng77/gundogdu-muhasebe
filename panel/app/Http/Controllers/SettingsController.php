<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/** Firma bilgileri, logo, uyarı süresi, yedekleme ve şifre değişikliği. */
class SettingsController extends Controller
{
    public function company(Request $request)
    {
        $this->requireEdit($request, 'ayarlar');
        $company = self::str($request->input('company'));
        if ($company === '') {
            $this->fail('Firma ünvanı zorunludur.');
        }
        Setting::put('company', mb_substr($company, 0, 200));
        Setting::put('tax', mb_substr(self::str($request->input('tax')), 0, 50));
        Setting::put('address', mb_substr(self::str($request->input('address')), 0, 500));

        return $this->ok($request, 'Firma bilgileri kaydedildi.');
    }

    public function logo(Request $request)
    {
        $this->requireEdit($request, 'ayarlar');
        $file = $request->file('logo');
        if (! $file || ! $file->isValid() || ! in_array($file->getMimeType(), ['image/png', 'image/jpeg'], true) || $file->getSize() > 500000) {
            $this->fail('500 KB altında PNG veya JPG seçiniz.');
        }
        Setting::put('logo', 'data:'.$file->getMimeType().';base64,'.base64_encode($file->get()));

        return $this->ok($request, 'Logo kaydedildi.');
    }

    public function warning(Request $request)
    {
        $this->requireEdit($request, 'ayarlar');
        $n = (int) $request->input('warning');
        if ($n < 1 || $n > 90) {
            $this->fail('1–90 arasında gün giriniz.');
        }
        Setting::put('warning', (string) $n);

        return $this->ok($request, 'Uyarı süresi kaydedildi.');
    }

    public function password(Request $request)
    {
        $user = $request->user();
        $new = (string) $request->input('password');
        if (! Hash::check((string) $request->input('current'), $user->password)) {
            $this->fail('Mevcut şifre hatalı.');
        }
        if (mb_strlen($new) < 8) {
            $this->fail('Yeni şifre en az 8 karakter olmalıdır.');
        }
        if ($new !== (string) $request->input('password_confirmation')) {
            $this->fail('Yeni şifreler eşleşmiyor.');
        }
        $user->forceFill(['password' => $new, 'remember_token' => \Illuminate\Support\Str::random(60)])->save();
        // Diğer cihazlardaki oturumlar kapatılır; bu oturum açık kalır.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();

        return $this->ok($request, 'Şifreniz güncellendi. Diğer cihazlardaki oturumlar kapatıldı.');
    }

    // ---------------------------------------------------------------- Yedekleme

    /** Eski HTML sürümünün yedek biçimiyle uyumlu JSON (inşaatlar dizi sırasıyla). */
    public function backup(Request $request)
    {
        $this->requireSuper($request);
        $projects = Project::orderBy('id')->get();
        $index = $projects->pluck('id')->flip();
        $chequeNumbers = Cheque::pluck('number', 'id');
        $s = Setting::all_();
        $allInvoices = Invoice::with('party:id,code')->orderBy('id')->get();
        $dup = $allInvoices->countBy('number')->filter(fn ($n) => $n > 1);
        $keys = $allInvoices->mapWithKeys(fn ($i) => [$i->id => isset($dup[$i->number]) ? $i->number.' · '.($i->party?->code ?? 'carisiz') : $i->number]);

        $data = [
            'projects' => $projects->pluck('name')->values(),
            'parties' => Party::orderBy('id')->get()->map(fn ($p) => ['id' => (string) $p->id, 'name' => $p->name, 'code' => $p->code, 'type' => $p->type, 'tax' => $p->tax ?? ''])->values(),
            'invoices' => $allInvoices->map(fn ($i) => [
                'id' => $keys[$i->id], 'number' => $i->number, 'project' => $index[$i->project_id], 'party' => $i->party_id ? (string) $i->party_id : '',
                'kind' => $i->kind, 'direction' => $i->direction, 'category' => $i->category ?? '', 'counterparty' => $i->counterparty ?? '',
                'wcode' => $i->withholding_code ?? '', 'stopaj' => $i->stopaj,
                'date' => $i->date->format('Y-m-d'), 'due' => $i->due->format('Y-m-d'), 'base' => $i->base,
                'vat' => $i->vat, 'rate' => $i->rate, 'desc' => $i->description ?? '', 'file' => $i->file_name ?? '',
                // Belge dosyası sunucuda kalır; yedekte yolu, ETTN ve kalemler tutulur.
                'filePath' => $i->file_path ?? '', 'ettn' => $i->ettn ?? '', 'items' => $i->items ?? [],
                'returnOf' => $i->return_of_id ? $keys[$i->return_of_id] : null,
            ])->values(),
            'payments' => Payment::orderBy('id')->get()->map(fn ($p) => [
                'id' => $p->number, 'invoice' => $keys[$p->invoice_id], 'amount' => $p->amount,
                'date' => $p->date->format('Y-m-d'), 'method' => $p->method,
                'checkId' => $p->cheque_id ? $chequeNumbers[$p->cheque_id] : null, 'partner' => $p->partner_id,
                'bankTx' => $p->bank_transaction_id,
            ])->values(),
            'checks' => Cheque::orderBy('id')->get()->map(fn ($c) => [
                'id' => $c->number, 'project' => $index[$c->project_id], 'party' => (string) $c->party_id,
                'amount' => $c->amount, 'due' => $c->due->format('Y-m-d'), 'bank' => $c->bank, 'status' => $c->status, 'type' => $c->type,
                'direction' => $c->direction, 'bankTx' => $c->bank_transaction_id,
            ])->values(),
            'taxPeriods' => \App\Models\TaxPeriod::orderBy('id')->get()->map(fn ($t) => [
                'kind' => $t->kind, 'period' => $t->period, 'status' => $t->status, 'declared' => $t->declared_on?->format('Y-m-d'),
                'paid' => $t->paid_on?->format('Y-m-d'), 'note' => $t->note ?? '',
            ])->values(),
            'withholdingTypes' => \App\Models\WithholdingType::orderBy('sort')->get()->map(fn ($w) => [
                'code' => $w->code, 'name' => $w->name, 'rate' => (float) $w->rate, 'active' => (bool) $w->active, 'sort' => $w->sort,
            ])->values(),
            'partners' => Partner::with('projects')->orderBy('id')->get()->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'phone' => $p->phone ?? '', 'note' => $p->note ?? '',
                'shares' => $p->projects->map(fn ($pr) => ['project' => $index[$pr->id], 'share' => (float) $pr->pivot->share])->values(),
            ])->values(),
            'bankAccounts' => BankAccount::orderBy('id')->get()->map(fn ($a) => [
                'id' => $a->id, 'project' => $index[$a->project_id], 'bank' => $a->bank, 'branch' => $a->branch ?? '', 'name' => $a->name ?? '',
                'iban' => $a->iban ?? '', 'accountNo' => $a->account_no ?? '', 'currency' => $a->currency, 'opening' => $a->opening_balance,
                'openingDate' => $a->opening_date?->format('Y-m-d'), 'note' => $a->note ?? '', 'active' => $a->active,
            ])->values(),
            'bankTransactions' => BankTransaction::orderBy('id')->get()->map(fn ($t) => [
                'id' => $t->id, 'account' => $t->bank_account_id, 'date' => $t->date->format('Y-m-d'), 'dir' => $t->direction,
                'amount' => $t->amount, 'cat' => $t->category ?? '', 'desc' => $t->description ?? '', 'doc' => $t->document_no ?? '',
                'transfer' => $t->transfer_id,
            ])->values(),
            'company' => $s['company'] ?? 'Gündoğdu İnşaat A.Ş.',
            'tax' => $s['tax'] ?? '',
            'address' => $s['address'] ?? '',
            'logo' => $s['logo'] ?? '',
            'warning' => (int) ($s['warning'] ?? 7),
            'wLimit' => (float) ($s['wLimit'] ?? 2000),
        ];

        return response()->streamDownload(
            fn () => print(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'gundogdu-muhasebe-yedek.json',
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * Yedekten geri yükleme: tüm muhasebe kayıtlarını yedekle değiştirir.
     * Aynı adlı inşaatlar korunur; böylece kullanıcıların inşaat yetkileri kaybolmaz.
     */
    public function restore(Request $request)
    {
        $this->requireSuper($request);
        $file = $request->file('backup');
        $d = $file && $file->isValid() ? json_decode($file->get(), true) : null;
        if (! $this->validBackup($d)) {
            $this->fail('Yedek dosyası veya veri ilişkileri geçersiz. Kayıtlar değiştirilmedi.');
        }

        \App\Models\Concerns\AuditState::$paused = true;
        try {
        DB::transaction(function () use ($d) {
            $oldFiles = Invoice::whereNotNull('file_path')->pluck('file_path');
            Payment::query()->delete();
            BankTransaction::query()->delete();
            BankAccount::query()->delete();
            Invoice::query()->update(['return_of_id' => null]);
            Invoice::query()->delete();
            Partner::query()->delete();
            Cheque::query()->delete();
            Party::query()->delete();

            $names = array_map(fn ($n) => trim($n), $d['projects']);
            Project::whereNotIn('name', $names)->get()->each->delete();
            $projectIds = [];
            foreach ($names as $j => $name) {
                $projectIds[$j] = Project::firstOrCreate(['name' => $name])->id;
            }

            $partyIds = [];
            foreach ($d['parties'] as $p) {
                $partyIds[$p['id']] = Party::create(['name' => $p['name'], 'code' => $p['code'], 'type' => $p['type'], 'tax' => $p['tax'] ?? ''])->id;
            }
            $partnerIds = [];
            foreach ($d['partners'] ?? [] as $p) {
                $partner = Partner::create(['name' => $p['name'], 'phone' => $p['phone'] ?? '', 'note' => $p['note'] ?? '']);
                $partnerIds[$p['id']] = $partner->id;
                foreach ($p['shares'] ?? [] as $sh) {
                    $partner->projects()->attach($projectIds[$sh['project']], ['share' => $sh['share']]);
                }
            }
            $invoiceIds = [];
            foreach ($d['invoices'] as $i) {
                $path = is_string($i['filePath'] ?? null) && str_starts_with($i['filePath'], 'invoices/') && ! str_contains($i['filePath'], '..')
                    && Storage::disk('local')->exists($i['filePath']) ? $i['filePath'] : null;
                $invoiceIds[$i['id']] = Invoice::create([
                    'number' => $i['number'] ?? $i['id'], 'file_path' => $path,
                    'ettn' => ($i['ettn'] ?? '') ?: null, 'items' => is_array($i['items'] ?? null) && $i['items'] ? $i['items'] : null, 'project_id' => $projectIds[$i['project']], 'party_id' => ($i['party'] ?? '') !== '' ? $partyIds[$i['party']] : null,
                    'kind' => in_array($i['kind'] ?? '', Access::KINDS, true) ? $i['kind'] : 'faturali',
                    'direction' => in_array($i['direction'] ?? '', Access::DIRECTIONS, true) ? $i['direction'] : 'gider',
                    'category' => ($i['category'] ?? '') ?: null, 'counterparty' => ($i['counterparty'] ?? '') ?: null,
                    'withholding_code' => ($i['wcode'] ?? '') ?: null, 'stopaj' => (float) ($i['stopaj'] ?? 0),
                    'date' => $i['date'], 'due' => $i['due'], 'base' => $i['base'], 'vat' => $i['vat'], 'rate' => $i['rate'],
                    'description' => $i['desc'] ?? '', 'file_name' => ($i['file'] ?? '') ?: null,
                ])->id;
            }
            foreach ($d['invoices'] as $i) {
                if (($i['returnOf'] ?? null) !== null && isset($invoiceIds[$i['returnOf']])) {
                    Invoice::whereKey($invoiceIds[$i['id']])->update(['return_of_id' => $invoiceIds[$i['returnOf']]]);
                }
            }
            $chequeIds = [];
            $chequeTx = [];
            foreach ($d['checks'] as $c) {
                $chequeIds[$c['id']] = Cheque::create([
                    'number' => $c['id'], 'project_id' => $projectIds[$c['project']], 'party_id' => $partyIds[$c['party']],
                    'amount' => $c['amount'], 'due' => $c['due'], 'bank' => ($c['bank'] ?? '') ?: '—',
                    'status' => $c['status'], 'type' => in_array($c['type'] ?? '', ['Çek', 'Senet'], true) ? $c['type'] : 'Çek',
                    'direction' => in_array($c['direction'] ?? '', ['alinan', 'verilen'], true) ? $c['direction'] : 'alinan',
                ])->id;
                $chequeTx[$c['id']] = $c['bankTx'] ?? null;
            }
            $accountIds = [];
            foreach ($d['bankAccounts'] ?? [] as $a) {
                $accountIds[$a['id']] = BankAccount::create([
                    'project_id' => $projectIds[$a['project']], 'bank' => $a['bank'], 'branch' => ($a['branch'] ?? '') ?: null,
                    'name' => ($a['name'] ?? '') ?: null, 'iban' => ($a['iban'] ?? '') ?: null, 'account_no' => ($a['accountNo'] ?? '') ?: null,
                    'currency' => in_array($a['currency'] ?? 'TRY', Access::CURRENCIES, true) ? ($a['currency'] ?? 'TRY') : 'TRY',
                    'opening_balance' => (float) ($a['opening'] ?? 0), 'opening_date' => ($a['openingDate'] ?? null) ?: null,
                    'note' => ($a['note'] ?? '') ?: null, 'active' => (bool) ($a['active'] ?? true),
                ])->id;
            }
            $txIds = [];
            foreach ($d['bankTransactions'] ?? [] as $t) {
                $txIds[$t['id']] = BankTransaction::create([
                    'bank_account_id' => $accountIds[$t['account']], 'date' => $t['date'], 'direction' => $t['dir'], 'amount' => $t['amount'],
                    'category' => ($t['cat'] ?? '') ?: null, 'description' => ($t['desc'] ?? '') ?: null, 'document_no' => ($t['doc'] ?? '') ?: null,
                ])->id;
            }
            foreach ($d['bankTransactions'] ?? [] as $t) {
                if (isset($t['transfer'], $txIds[$t['transfer']])) {
                    BankTransaction::whereKey($txIds[$t['id']])->update(['transfer_id' => $txIds[$t['transfer']]]);
                }
            }
            foreach ($d['payments'] as $p) {
                Payment::create([
                    'number' => $p['id'], 'invoice_id' => $invoiceIds[$p['invoice']], 'amount' => $p['amount'],
                    'date' => $p['date'], 'method' => $p['method'],
                    'cheque_id' => isset($p['checkId']) && isset($chequeIds[$p['checkId']]) ? $chequeIds[$p['checkId']] : null,
                    'partner_id' => isset($p['partner']) && isset($partnerIds[$p['partner']]) ? $partnerIds[$p['partner']] : null,
                    'bank_transaction_id' => isset($p['bankTx']) && isset($txIds[$p['bankTx']]) ? $txIds[$p['bankTx']] : null,
                ]);
            }

            foreach ($chequeTx as $no => $tx) {
                if ($tx !== null && isset($txIds[$tx])) {
                    Cheque::whereKey($chequeIds[$no])->update(['bank_transaction_id' => $txIds[$tx]]);
                }
            }
            if (isset($d['taxPeriods'])) {
                \App\Models\TaxPeriod::query()->delete();
                foreach ($d['taxPeriods'] as $t) {
                    \App\Models\TaxPeriod::create(['kind' => $t['kind'], 'period' => $t['period'], 'status' => $t['status'],
                        'declared_on' => ($t['declared'] ?? null) ?: null, 'paid_on' => ($t['paid'] ?? null) ?: null, 'note' => ($t['note'] ?? '') ?: null]);
                }
            }
            if (isset($d['withholdingTypes'])) {
                foreach ($d['withholdingTypes'] as $j => $w) {
                    \App\Models\WithholdingType::updateOrCreate(['code' => $w['code']], ['name' => $w['name'], 'rate' => $w['rate'],
                        'active' => (bool) ($w['active'] ?? true), 'sort' => (int) ($w['sort'] ?? $j)]);
                }
            }

            Setting::put('company', $d['company']);
            foreach (['tax', 'address', 'logo'] as $k) {
                Setting::put($k, is_string($d[$k] ?? null) ? $d[$k] : '');
            }
            $w = (int) ($d['warning'] ?? 7);
            Setting::put('warning', (string) ($w >= 1 && $w <= 90 ? $w : 7));
            if (isset($d['wLimit']) && is_numeric($d['wLimit'])) {
                Setting::put('wLimit', (string) (float) $d['wLimit']);
            }

            // Yalnızca geri yüklenen kayıtların artık kullanmadığı belgeler silinir.
            $kept = Invoice::whereNotNull('file_path')->pluck('file_path');
            DB::afterCommit(fn () => Storage::disk('local')->delete($oldFiles->diff($kept)->values()->all()));
        });

        } finally {
            \App\Models\Concerns\AuditState::$paused = false;
        }
        \App\Models\ActivityLog::create(['user_id' => $request->user()->id, 'user_name' => $request->user()->name, 'action' => 'restored',
            'subject_type' => 'backup', 'label' => 'Yedekten geri yükleme ('.count($d['invoices']).' işlem, '.count($d['payments']).' ödeme)', 'ip' => $request->ip()]);

        return $this->ok($request, 'Yedek başarıyla yüklendi.');
    }

    /** Ön yüzdeki validBackup() ile aynı kurallar + benzersiz numara kontrolleri. */
    private function validBackup($d): bool
    {
        if (! is_array($d) || ! isset($d['projects'], $d['parties'], $d['invoices'], $d['payments'], $d['checks'], $d['company'])) {
            return false;
        }
        foreach (['projects', 'parties', 'invoices', 'payments', 'checks'] as $k) {
            if (! is_array($d[$k]) || ! array_is_list($d[$k])) {
                return false;
            }
        }
        if (! $d['projects'] || ! is_string($d['company'])) {
            return false;
        }
        $names = [];
        foreach ($d['projects'] as $p) {
            if (! is_string($p) || trim($p) === '' || isset($names[mb_strtolower(trim($p))])) {
                return false;
            }
            $names[mb_strtolower(trim($p))] = true;
        }
        $pc = count($d['projects']);
        $num = fn ($n) => (is_int($n) || is_float($n)) && is_finite($n) && $n >= 0;
        $str = fn ($s) => is_string($s) && trim($s) !== '';
        $proj = fn ($n) => is_int($n) && $n >= 0 && $n < $pc;

        $partyIds = [];
        $codes = [];
        foreach ($d['parties'] as $p) {
            if (! is_array($p) || ! $str($p['id'] ?? null) || ! $str($p['name'] ?? null) || ! $str($p['code'] ?? null)
                || ! in_array($p['type'] ?? null, Access::PARTY_TYPES, true) || isset($partyIds[$p['id']]) || isset($codes[$p['code']])) {
                return false;
            }
            $partyIds[$p['id']] = true;
            $codes[$p['code']] = true;
        }
        $nets = [];
        $pairs = [];
        foreach ($d['invoices'] as $i) {
            if (is_array($i)) {
                $pair = ($i['party'] ?? '').'|'.($i['number'] ?? $i['id'] ?? '');
                if (isset($pairs[$pair]) || (isset($i['number']) && ! $str($i['number']))) {
                    return false;
                }
                $pairs[$pair] = true;
            }
            if (! is_array($i) || ! $str($i['id'] ?? null) || isset($nets[$i['id']]) || ! $proj($i['project'] ?? null)
                || (! isset($partyIds[$i['party'] ?? '']) && ! (($i['party'] ?? '') === '' && ($i['kind'] ?? '') === 'faturasiz'))
                || ! $num($i['base'] ?? null) || ! $num($i['stopaj'] ?? 0) || ! $num($i['vat'] ?? null)
                || ! $num($i['rate'] ?? null) || $i['rate'] > 1 || ! self::validDate($i['date'] ?? null) || ! self::validDate($i['due'] ?? null)) {
                return false;
            }
            $nets[$i['id']] = Invoice::netOf($i['base'], $i['vat'], $i['rate'], (float) ($i['stopaj'] ?? 0));
        }
        $paid = [];
        $payIds = [];
        foreach ($d['payments'] as $p) {
            if (! is_array($p) || ! $str($p['id'] ?? null) || isset($payIds[$p['id']]) || ! isset($nets[$p['invoice'] ?? ''])
                || ! $num($p['amount'] ?? null) || $p['amount'] <= 0 || ! self::validDate($p['date'] ?? null)
                || ! in_array($p['method'] ?? null, Access::PAY_METHODS, true)) {
                return false;
            }
            $payIds[$p['id']] = true;
            $paid[$p['invoice']] = ($paid[$p['invoice']] ?? 0) + $p['amount'];
        }
        foreach ($paid as $inv => $total) {
            if ($total > $nets[$inv] + 0.01) {
                return false;
            }
        }
        if (isset($d['partners'])) {
            if (! is_array($d['partners'])) {
                return false;
            }
            $pids = [];
            foreach ($d['partners'] as $p) {
                if (! is_array($p) || ! isset($p['id']) || ! $str($p['name'] ?? null) || isset($pids[$p['id']])) {
                    return false;
                }
                $pids[$p['id']] = true;
                foreach ($p['shares'] ?? [] as $sh) {
                    if (! is_array($sh) || ! $proj($sh['project'] ?? null) || ! $num($sh['share'] ?? null) || $sh['share'] > 100) {
                        return false;
                    }
                }
            }
            foreach ($d['payments'] as $p) {
                if (isset($p['partner']) && $p['partner'] !== null && ! isset($pids[$p['partner']])) {
                    return false;
                }
            }
        }
        $chequeIds = [];
        foreach ($d['checks'] as $c) {
            if (! is_array($c) || ! $str($c['id'] ?? null) || isset($chequeIds[$c['id']]) || ! $proj($c['project'] ?? null)
                || ! isset($partyIds[$c['party'] ?? '']) || ! $num($c['amount'] ?? null) || ! self::validDate($c['due'] ?? null)
                || ! in_array($c['status'] ?? null, Access::CHEQUE_STATUSES, true)) {
                return false;
            }
            $chequeIds[$c['id']] = true;
        }
        $accountIds = [];
        foreach ($d['bankAccounts'] ?? [] as $a) {
            if (! is_array($a) || ! isset($a['id']) || isset($accountIds[$a['id']]) || ! $proj($a['project'] ?? null) || ! $str($a['bank'] ?? null)
                || ! is_numeric($a['opening'] ?? 0) || (($a['openingDate'] ?? null) && ! self::validDate($a['openingDate']))) {
                return false;
            }
            $accountIds[$a['id']] = true;
        }
        $txIds = [];
        foreach ($d['bankTransactions'] ?? [] as $t) {
            if (! is_array($t) || ! isset($t['id']) || isset($txIds[$t['id']]) || ! isset($accountIds[$t['account'] ?? ''])
                || ! in_array($t['dir'] ?? null, ['giris', 'cikis'], true) || ! $num($t['amount'] ?? null) || ! self::validDate($t['date'] ?? null)) {
                return false;
            }
            $txIds[$t['id']] = true;
        }
        foreach ($d['invoices'] as $i) {
            if (($i['returnOf'] ?? null) !== null && (! isset($nets[$i['returnOf']]) || $i['returnOf'] === $i['id'])) {
                return false;
            }
        }
        // Ödemelerin çek, banka hareketi ve ortak bağlantıları çözülmeli; aksi hâlde kayıt sessizce değişir.
        $pids = [];
        foreach ($d['partners'] ?? [] as $p) {
            $pids[$p['id']] = true;
        }
        foreach ($d['payments'] as $p) {
            if ((isset($p['checkId']) && $p['checkId'] !== null && ! isset($chequeIds[$p['checkId']]))
                || (isset($p['bankTx']) && $p['bankTx'] !== null && ! isset($txIds[$p['bankTx']]))
                || (isset($p['partner']) && $p['partner'] !== null && ! isset($pids[$p['partner']]))) {
                return false;
            }
        }
        foreach ($d['taxPeriods'] ?? [] as $t) {
            if (! is_array($t) || ! in_array($t['kind'] ?? null, ['kdv2', 'muhtasar'], true) || ! preg_match('/^\d{4}-\d{2}$/', $t['period'] ?? '')
                || ! in_array($t['status'] ?? null, Access::TAX_STATUSES, true)) {
                return false;
            }
        }
        foreach ($d['withholdingTypes'] ?? [] as $w) {
            if (! is_array($w) || ! preg_match('/^\d{3}$/', (string) ($w['code'] ?? '')) || ! $str($w['name'] ?? null)
                || ! is_numeric($w['rate'] ?? null) || $w['rate'] < 0.1 || $w['rate'] > 1) {
                return false;
            }
        }
        return true;
    }
}
