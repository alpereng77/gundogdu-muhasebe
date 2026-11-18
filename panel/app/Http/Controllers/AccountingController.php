<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TaxPeriod;
use App\Models\WithholdingType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ortaklar, tevkifat türleri, tevkifat sınırı ve KDV-2 / Muhtasar beyan dönemleri. */
class AccountingController extends Controller
{
    // ---------------------------------------------------------------- Ortaklar

    public function savePartner(Request $request, ?Partner $partner = null)
    {
        $this->requireEdit($request, 'ortaklar');
        $name = mb_substr(self::str($request->input('name')), 0, 150);
        if ($name === '') {
            $this->fail('Ortağın adını giriniz.');
        }
        $visible = $request->user()->projectIds();
        // Yalnızca erişilebilen inşaatlardan birinde hissesi olan (veya hiç hissesi olmayan) ortak düzenlenebilir.
        if ($partner && ! $request->user()->seesAllProjects() && $partner->projects()->exists()
            && ! $partner->projects()->whereIn('projects.id', $visible)->exists()) {
            $this->fail('Bu ortağı düzenleme yetkiniz yok.', 403);
        }
        // shares: {projectId: yüzde}; yalnızca kullanıcının erişebildiği inşaatlar değiştirilebilir.
        $shares = [];
        foreach ((array) $request->input('shares', []) as $projectId => $share) {
            $projectId = (int) $projectId;
            $share = self::money($share);
            if (! in_array($projectId, $visible, true) || $share === null || $share <= 0) {
                continue;
            }
            if ($share > 100) {
                $this->fail('Hisse oranı %100\'ü aşamaz.');
            }
            $shares[$projectId] = $share;
        }
        // Ödemesi olan inşaattan hisse kaldırılamaz (ortak raporu bozulur).
        if ($partner) {
            foreach ($partner->projects()->whereIn('projects.id', $visible)->pluck('projects.id') as $pid) {
                if (! isset($shares[$pid]) && Payment::where('partner_id', $partner->id)->whereHas('invoice', fn ($q) => $q->where('project_id', $pid))->exists()) {
                    $this->fail(Project::find($pid)->name.' inşaatında bu ortağın ödemeleri var; hissesi kaldırılamaz.');
                }
            }
        }
        $isNew = ! $partner;
        DB::transaction(function () use ($partner, $request, $name, $shares, $visible) {
            // İnşaat başına toplam hisse %100'ü aşmamalı (eşzamanlı kayıtlara karşı satırlar kilitlenir).
            foreach ($shares as $projectId => $share) {
                $others = DB::table('partner_project')->where('project_id', $projectId)
                    ->when($partner, fn ($q) => $q->where('partner_id', '!=', $partner->id))->lockForUpdate()->sum('share');
                if ($others + $share > 100.001) {
                    $this->fail(Project::find($projectId)->name.' için ortak hisseleri toplamı %100\'ü aşıyor (diğer ortaklar: %'.rtrim(rtrim(number_format($others, 2, ',', ''), '0'), ',').').');
                }
            }
            $partner ??= new Partner();
            $partner->fill(['name' => $name, 'phone' => mb_substr(self::str($request->input('phone')), 0, 40), 'note' => mb_substr(self::str($request->input('note')), 0, 255)])->save();
            // Görünmeyen inşaatlardaki hisseler korunur.
            $keep = $partner->projects()->whereNotIn('projects.id', $visible)->get()->mapWithKeys(fn ($p) => [$p->id => ['share' => $p->pivot->share]])->all();
            $partner->projects()->sync($keep + array_map(fn ($s) => ['share' => $s], $shares));
        });

        return $this->ok($request, $isNew ? 'Ortak eklendi.' : 'Ortak güncellendi.');
    }

    public function destroyPartner(Request $request, Partner $partner)
    {
        $this->requireEdit($request, 'ortaklar');
        if (Payment::where('partner_id', $partner->id)->exists()) {
            $this->fail('Bu ortağa bağlı ödeme/tahsilat kayıtları var; ortak silinemez.');
        }
        $hidden = $partner->projects()->whereNotIn('projects.id', $request->user()->projectIds())->exists();
        if ($hidden) {
            $this->fail('Bu ortak, erişiminiz olmayan inşaatlarda da tanımlı; silme işlemini yöneticiniz yapmalı.', 403);
        }
        $partner->delete();

        return $this->ok($request, 'Ortak silindi.');
    }

    // ---------------------------------------------------------------- Beyan dönemleri

    public function saveTaxPeriod(Request $request)
    {
        $this->requireEdit($request, 'tevkifat');
        $kind = $request->input('kind');
        $period = (string) $request->input('period');
        $status = self::str($request->input('status'));
        if (! in_array($kind, ['kdv2', 'muhtasar'], true) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->fail('Geçersiz beyan dönemi.');
        }
        if (! in_array($status, \App\Support\Access::TAX_STATUSES, true)) {
            $this->fail('Geçersiz durum.');
        }
        $declared = $request->input('declared') ?: null;
        $paid = $request->input('paid') ?: null;
        foreach ([$declared, $paid] as $d) {
            if ($d !== null && ! self::validDate($d)) {
                $this->fail('Geçerli bir tarih giriniz.');
            }
        }
        $today = now()->format('Y-m-d');
        if ($status !== 'Bekliyor') {
            $declared ??= $today;
        }
        if ($status === 'Ödendi') {
            $paid ??= $today;
        }
        TaxPeriod::updateOrCreate(['kind' => $kind, 'period' => $period], [
            'status' => $status, 'declared_on' => $status === 'Bekliyor' ? null : $declared,
            'paid_on' => $status === 'Ödendi' ? $paid : null,
            'note' => mb_substr(self::str($request->input('note')), 0, 255), 'updated_by' => $request->user()->id,
        ]);

        return $this->ok($request, ($kind === 'kdv2' ? 'KDV-2' : 'Muhtasar').' '.$period.' durumu: '.$status.'.');
    }

    // ---------------------------------------------------------------- Tevkifat türleri ve sınır

    public function saveWithholdingType(Request $request)
    {
        $this->requireEdit($request, 'ayarlar');
        $code = self::str($request->input('code'));
        $name = mb_substr(self::str($request->input('name')), 0, 255);
        $rate = round((float) $request->input('rate'), 2);
        if (! preg_match('/^\d{3}$/', $code) || $name === '') {
            $this->fail('Tevkifat kodu 3 haneli olmalı (ör. 601) ve açıklama girilmelidir.');
        }
        if (! in_array($rate, \App\Support\Access::WITHHOLDING_RATES) || $rate <= 0) {
            $this->fail('Tevkifat oranı 1/10 ile 10/10 arasında olmalıdır.');
        }
        $exists = WithholdingType::find($code);
        WithholdingType::updateOrCreate(['code' => $code], [
            'name' => $name, 'rate' => $rate, 'active' => $request->boolean('active', true),
            'sort' => $exists?->sort ?? ((int) WithholdingType::max('sort') + 1),
        ]);

        return $this->ok($request, 'Tevkifat türü '.$code.' kaydedildi.');
    }

    public function destroyWithholdingType(Request $request, string $code)
    {
        $this->requireEdit($request, 'ayarlar');
        if (Invoice::where('withholding_code', $code)->exists()) {
            $this->fail('Bu tevkifat türü faturalarda kullanılıyor; silmek yerine pasif yapın.');
        }
        WithholdingType::whereKey($code)->delete();

        return $this->ok($request, 'Tevkifat türü silindi.');
    }

    public function taxSettings(Request $request)
    {
        $this->requireEdit($request, 'ayarlar');
        $limit = self::money($request->input('wLimit'));
        if ($limit === null || $limit < 0 || $limit > 10000000) {
            $this->fail('Geçerli bir tevkifat sınırı giriniz.');
        }
        Setting::put('wLimit', (string) $limit);

        return $this->ok($request, 'Tevkifat sınırı kaydedildi.');
    }
}
