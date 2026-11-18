<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\WithholdingType;
use App\Support\Access;
use App\Support\InvoiceReader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Faturalar, ödemeler, çek/senetler, cari hesaplar ve inşaatlar. */
class RecordController extends Controller
{
    // ---------------------------------------------------------------- Faturalar

    /** Yüklenen e-Fatura PDF/XML belgesini okur; formu doldurmak için önerileri döner (kayıt yapmaz). */
    public function parseInvoice(Request $request)
    {
        $this->requireEdit($request, 'faturalar');
        $file = $this->documentFile($request);
        if (! $file) {
            $this->fail('Okunacak fatura belgesini seçiniz.');
        }
        try {
            $r = InvoiceReader::read($file->get(), $file->getClientOriginalExtension());
        } catch (\DomainException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->fail('Belge okunamadı. Bilgileri elle girebilirsiniz.');
        }

        // Fatura yönü: satıcı bizsek satış faturası (cari = alıcı, 120); değilse alış faturası (cari = satıcı, 320).
        $digits = fn ($v) => preg_replace('/\D/', '', (string) $v);
        $ours = $digits(Setting::get('tax', ''));
        $sales = $ours !== '' && $digits($r['seller']['tax']) === $ours;
        $counter = $sales ? $r['buyer'] : $r['seller'];
        $type = $sales ? '120 Alıcılar' : '320 Satıcılar';
        if ($ours !== '' && ! $sales && $digits($r['buyer']['tax']) !== '' && $digits($r['buyer']['tax']) !== $ours) {
            $r['warnings'][] = 'Fatura, Ayarlar\'daki firma vergi numaranıza ('.$ours.') kesilmemiş görünüyor (alıcı VKN: '.$r['buyer']['tax'].').';
        }

        $r['direction'] = $sales ? 'gelir' : 'gider';
        if ($r['wcode'] && ! WithholdingType::whereKey($r['wcode'])->exists()) {
            $r['warnings'][] = 'Tevkifat kodu '.$r['wcode'].' tanımlı değil; Ayarlar → Tevkifat türlerinden ekleyebilirsiniz.';
            $r['wcode'] = null;
        }

        $party = null;
        if ($counter['tax']) {
            $party = Party::all()->first(fn ($p) => $digits($p->tax) === $digits($counter['tax']));
        }
        if (! $party && $counter['name']) {
            $norm = fn ($s) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], (string) $s)));
            $party = Party::all()->first(fn ($p) => $norm($p->name) === $norm($counter['name']));
        }
        $newParty = null;
        if (! $party) {
            $newParty = ['name' => $counter['name'] ?? '', 'tax' => $counter['tax'] ?? '', 'type' => $type, 'code' => $this->nextPartyCode($type)];
        }

        // İnşaat tahmini: açıklama/notlarda inşaat adı geçiyorsa
        $haystack = mb_strtolower(($r['notes'] ?? '').' '.($r['desc'] ?? ''));
        $projectId = null;
        foreach (Project::whereIn('id', $request->user()->projectIds())->get() as $p) {
            if (mb_strlen($p->name) > 3 && str_contains($haystack, mb_strtolower($p->name))) {
                $projectId = $p->id;
            }
        }

        // Mükerrer kontrolü: aynı ETTN veya aynı cari + aynı fatura no. Erişilemeyen inşaatın numarası gösterilmez.
        $dup = $r['ettn'] ? Invoice::where('ettn', $r['ettn'])->first() : null;
        if (! $dup && $party && ($r['number'] ?? '') !== '') {
            $dup = Invoice::where('party_id', $party->id)->where('number', $r['number'])->first();
        }
        if ($dup) {
            $r['warnings'][] = 'Bu fatura zaten kayıtlı'.($request->user()->canAccessProject($dup->project_id) ? ': '.$dup->number : ' (erişiminiz olmayan bir inşaatta)').'.';
        }
        if ($r['notes'] && ! str_contains(mb_strtolower($r['desc'] ?? ''), mb_strtolower($r['notes']))) {
            $r['desc'] = trim(($r['desc'] ?? '').' — '.$r['notes'], ' —');
        }

        return response()->json([
            'invoice' => $r,
            'partyId' => $party ? (string) $party->id : null,
            'newParty' => $newParty,
            'canCreateParty' => $request->user()->canEditParties(),
            'projectId' => $projectId,
            'duplicate' => (bool) $dup,
        ]);
    }

    /**
     * Gelir / gider işlemi kaydı.
     *  - Faturalı: fatura no ve cari zorunlu; KDV, KDV tevkifatı (tür kodu + oran) ve stopaj uygulanabilir.
     *  - Faturasız: KDV/tevkifat yok; cari isteğe bağlı (karşı taraf serbest yazılabilir); belge no otomatik verilebilir.
     *  - İsteğe bağlı olarak tamamı / bir kısmı hemen ödendi (tahsil edildi) olarak kaydedilir.
     */
    public function storeInvoice(Request $request)
    {
        return $this->saveInvoice($request, null);
    }

    /** Kayıtlı işlemi düzeltir. Ödemesi olan işlemde inşaat ve yön değiştirilemez; net tutar ödenenden az olamaz. */
    public function updateInvoice(Request $request, Invoice $invoice)
    {
        $this->requireProject($request, $invoice->project_id);
        if ($invoice->isReturn()) {
            $this->fail('İade faturası düzenlenemez; hatalıysa silip yeniden girin.');
        }
        return $this->saveInvoice($request, $invoice);
    }

    private function saveInvoice(Request $request, ?Invoice $existing)
    {
        $this->requireEdit($request, 'faturalar');
        $kind = in_array($request->input('kind'), Access::KINDS, true) ? $request->input('kind') : 'faturali';
        $direction = in_array($request->input('direction'), Access::DIRECTIONS, true) ? $request->input('direction') : 'gider';
        $faturali = $kind === 'faturali';
        $number = self::str($request->input('id'));
        $project = (int) $request->input('project');
        $partyInput = self::str($request->input('party'));
        $date = $request->input('date');
        $due = $request->input('due') ?: $date;
        $base = self::money($request->input('base'));
        $vat = $faturali ? (float) $request->input('vat') : 0.0;
        $rate = $faturali ? round((float) $request->input('rate'), 2) : 0.0;
        $stopaj = $faturali ? (float) $request->input('stopaj') : 0.0;
        $wcode = $faturali ? (self::str($request->input('wcode')) ?: null) : null;
        $counterparty = mb_substr(self::str($request->input('counterparty')), 0, 200) ?: null;

        if (! $faturali && $number === '') {
            $number = $existing ? $existing->number : $this->nextVoucherNumber($date);
        }
        if ($number === '' || ! self::validDate($date) || ! self::validDate($due) || $base === null || $base <= 0) {
            $this->fail($faturali ? 'Fatura numarası, tarih, cari ve pozitif matrah tutarı giriniz.' : 'Tarih ve pozitif tutar giriniz.');
        }
        if ($due < $date) {
            $this->fail('Vade tarihi, işlem tarihinden önce olamaz.');
        }
        if ($faturali && $partyInput === '') {
            $this->fail('Faturalı işlemde cari hesap seçiniz.');
        }
        if (! $faturali && $partyInput === '' && ! $counterparty) {
            $this->fail('Cari seçiniz veya karşı tarafın adını yazınız.');
        }
        if (mb_strlen($number) > 100) {
            $this->fail('Belge numarası en fazla 100 karakter olabilir.');
        }
        $this->requireProject($request, $project);
        if (! in_array($vat, Access::VAT_RATES) || ! in_array($rate, Access::WITHHOLDING_RATES) || ! in_array($stopaj, Access::STOPAJ_RATES)) {
            $this->fail('Geçersiz KDV, tevkifat veya stopaj oranı.');
        }
        if ($wcode && ! WithholdingType::whereKey($wcode)->exists()) {
            $this->fail('Geçersiz tevkifat türü.');
        }
        if ($rate > 0 && $vat == 0) {
            $this->fail('KDV oranı %0 iken tevkifat uygulanamaz.');
        }
        if ($rate == 0) {
            $wcode = null;
        }
        // Belge numarası cari bazında tekildir: farklı tedarikçilerin aynı numaralı faturaları girilebilir.
        if ($partyInput !== 'new') {
            $dup = Invoice::where('number', $number)
                ->when($partyInput !== '', fn ($q) => $q->where('party_id', (int) $partyInput), fn ($q) => $q->whereNull('party_id'))
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
            if ($dup) {
                $this->fail('Bu cari için aynı belge numarası zaten kayıtlı.');
            }
        }

        // Yeni cari: faturadan okunan satıcı/alıcı kaydı ile birlikte oluşturulur.
        $newParty = null;
        if ($partyInput === 'new') {
            if (! $request->user()->canEditParties()) {
                $this->fail('Cari kayıtlı değil ve cari ekleme yetkiniz yok. Yöneticinizden cariyi eklemesini isteyin.', 403);
            }
            $newParty = [
                'name' => mb_substr(self::str($request->input('newPartyName')), 0, 200),
                'code' => self::str($request->input('newPartyCode')),
                'type' => self::str($request->input('newPartyType')),
                'tax' => preg_replace('/\D/', '', self::str($request->input('newPartyTax'))),
            ];
            if ($newParty['name'] === '' || $newParty['code'] === '') {
                $this->fail('Yeni cari için ünvan ve cari kodu zorunludur.');
            }
            if (! in_array($newParty['type'], Access::PARTY_TYPES, true)) {
                $this->fail('Geçersiz hesap türü.');
            }
            if (Party::where('code', $newParty['code'])->exists()) {
                $this->fail('Bu cari kodu zaten kayıtlı.');
            }
        } elseif ($partyInput !== '' && ! Party::whereKey((int) $partyInput)->exists()) {
            $this->fail('Seçilen cari hesap bulunamadı.');
        }

        $net = Invoice::netOf($base, $vat, $rate, $stopaj);
        if ($existing) {
            $paidSoFar = $existing->paidAmount();
            if ($existing->payments()->exists()) {
                if ($existing->project_id !== $project || $existing->direction !== $direction) {
                    $this->fail('Ödemesi / tahsilatı olan işlemin inşaatı ve gelir-gider yönü değiştirilemez. Önce ödemeleri kaldırın.');
                }
                $newPartyId = $partyInput === '' ? null : ($partyInput === 'new' ? -1 : (int) $partyInput);
                if ($existing->party_id !== $newPartyId) {
                    $this->fail('Ödemesi / tahsilatı olan işlemin carisi değiştirilemez. Önce ödemeleri kaldırın.');
                }
            }
            if ($existing->returns()->exists() && ($existing->project_id !== $project || $existing->direction !== $direction)) {
                $this->fail('İade faturası kesilmiş işlemin inşaatı ve gelir-gider yönü değiştirilemez.');
            }
            $credit = $existing->creditAmount();
            if ($net + 0.001 < $paidSoFar + $credit) {
                $this->fail('Yeni net tutar ('.self::fmtMoney($net).') ödenen'.($credit ? ' ve iade edilen' : '').' tutarın ('.self::fmtMoney($paidSoFar + $credit).') altında olamaz.');
            }
        }

        // Hemen ödendi / tahsil edildi (kısmi de olabilir) — yalnızca yeni kayıtta
        $pay = null;
        if (! $existing && $request->boolean('payNow')) {
            $payAmount = self::money($request->input('payAmount'));
            $payAmount = $payAmount === null || $payAmount <= 0 ? $net : $payAmount;
            $payMethod = self::str($request->input('payMethod'));
            $payDate = $request->input('payDate') ?: $date;
            if ($payAmount > $net + 0.001) {
                $this->fail('Ödeme tutarı işlemin net tutarını ('.self::fmtMoney($net).') aşamaz.');
            }
            if (! in_array($payMethod, ['Havale / EFT', 'Nakit', 'Kredi Kartı'], true)) {
                $this->fail('Hemen ödeme için Havale / EFT, Nakit veya Kredi Kartı seçiniz. Çek/senet için Ödemeler ekranını kullanın.');
            }
            if (! self::validDate($payDate)) {
                $this->fail('Geçerli bir ödeme tarihi giriniz.');
            }
            if (! $request->user()->canEdit('odemeler')) {
                $this->fail('Ödeme kaydetme yetkiniz yok. İşlemi ödenmemiş olarak kaydedin.', 403);
            }
            $pay = ['amount' => $payAmount, 'method' => $payMethod, 'date' => $payDate, 'partner' => $this->partnerId($request, 'payPartner', $project), 'bank' => $request->input('payBank')];
        }

        $file = $faturali ? $this->documentFile($request) : null;
        $ettn = $existing?->ettn;
        $items = $existing?->items;
        if ($file && in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'xml'], true)) {
            // Kalemler ve ETTN, kaydedilen belgenin kendisinden okunur.
            try {
                $read = InvoiceReader::read($file->get(), $file->getClientOriginalExtension());
                $ettn = $read['ettn'] ?: $ettn;
                $items = $read['items'] ?: $items;
            } catch (\Throwable) {
            }
            if ($ettn && ($dup = Invoice::where('ettn', $ettn)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->first())) {
                $this->fail('Bu e-Fatura (ETTN) zaten kayıtlı'.($request->user()->canAccessProject($dup->project_id) ? ': '.$dup->number : '').'.');
            }
        }

        $data = compact('kind', 'direction', 'number', 'project', 'date', 'due', 'base', 'vat', 'rate', 'stopaj', 'wcode', 'counterparty', 'ettn', 'items');
        $stored = null;
        try {
            return DB::transaction(function () use ($request, $data, $partyInput, $newParty, $file, $pay, $existing, &$stored) {
                $partyId = $newParty ? Party::create($newParty)->id : ($partyInput !== '' ? (int) $partyInput : null);
                $fileName = $existing?->file_name;
                $filePath = $existing?->file_path;
                if ($data['kind'] === 'faturasiz') {
                    $fileName = $filePath = null;
                }
                if ($file) {
                    $fileName = mb_substr($file->getClientOriginalName(), 0, 200);
                    $filePath = $stored = $file->storeAs('invoices', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
                }
                $values = [
                    'number' => $data['number'], 'kind' => $data['kind'], 'direction' => $data['direction'],
                    'category' => mb_substr(self::str($request->input('category')), 0, 60) ?: null,
                    'ettn' => $data['ettn'], 'project_id' => $data['project'], 'party_id' => $partyId,
                    'counterparty' => $partyId ? null : $data['counterparty'],
                    'date' => $data['date'], 'due' => $data['due'], 'base' => $data['base'], 'vat' => $data['vat'],
                    'rate' => $data['rate'], 'withholding_code' => $data['wcode'], 'stopaj' => $data['stopaj'],
                    'description' => mb_substr(self::str($request->input('desc')), 0, 1000), 'items' => $data['items'],
                    'file_name' => $fileName, 'file_path' => $filePath,
                ];
                if ($existing) {
                    $oldPath = $existing->file_path;
                    $existing->update($values);
                    if ($oldPath && $oldPath !== $filePath) {
                        DB::afterCommit(fn () => Storage::disk('local')->delete($oldPath));
                    }
                    return $this->ok($request, ($data['kind'] === 'faturali' ? 'Fatura' : 'İşlem').' güncellendi.');
                }
                $invoice = Invoice::create($values + ['created_by' => $request->user()->id]);
                if ($pay) {
                    $who = $partyId ? (Party::find($partyId)?->name ?? '') : ($data['counterparty'] ?? '');
                    $bankTx = BankController::paymentTransaction($request, $pay['bank'], $pay['method'], [$data['project']],
                        $data['direction'], $pay['amount'], $pay['date'], trim($who.' · '.$invoice->number, ' ·'));
                    Payment::create([
                        'number' => 'ODM-'.base_convert((string) (int) (microtime(true) * 1000), 10, 36).Str::lower(Str::random(3)).'-'.$invoice->number,
                        'invoice_id' => $invoice->id, 'amount' => round($pay['amount'], 2), 'date' => $pay['date'],
                        'method' => $pay['method'], 'partner_id' => $pay['partner'], 'bank_transaction_id' => $bankTx, 'created_by' => $request->user()->id,
                    ]);
                }
                $what = $data['kind'] === 'faturali' ? ($data['direction'] === 'gelir' ? 'Satış faturası' : 'Alış faturası') : ($data['direction'] === 'gelir' ? 'Faturasız gelir' : 'Faturasız gider');
                $msg = $what.' kaydedildi'.($newParty ? ' (yeni cari: '.$newParty['name'].')' : '').($pay ? '; '.($data['direction'] === 'gelir' ? 'tahsilat' : 'ödeme').' işlendi.' : '.');

                return $this->ok($request, $msg);
            });
        } catch (\Throwable $e) {
            // Kayıt başarısızsa yüklenen belge diskte sahipsiz kalmasın.
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $e;
        }
    }

    /**
     * İade faturası: asıl faturanın KDV, tevkifat ve stopaj oranlarıyla kesilir; matrah, asıl faturanın
     * henüz iade edilmemiş matrahını aşamaz. Ödenmemiş kısımdan düşülür; ödenmişse fazlası karşı taraftan
     * geri alınacak (alış iadesi) veya müşteriye ödenecek (satış iadesi) tutar olarak izlenir.
     */
    public function storeReturn(Request $request, Invoice $invoice)
    {
        $this->requireEdit($request, 'faturalar');
        $this->requireProject($request, $invoice->project_id);
        if ($invoice->isReturn()) {
            $this->fail('İade faturasına iade kesilemez.');
        }
        $number = mb_substr(self::str($request->input('number')), 0, 100);
        $date = $request->input('date');
        $base = self::money($request->input('base'));
        if ($number === '' || ! self::validDate($date) || $base === null || $base <= 0) {
            $this->fail('İade belge numarası, tarih ve pozitif matrah giriniz.');
        }
        if ($date < $invoice->date->format('Y-m-d')) {
            $this->fail('İade tarihi asıl fatura tarihinden önce olamaz.');
        }
        $left = round($invoice->base - (float) $invoice->returns()->sum('base'), 2);
        if ($base > $left + 0.001) {
            $this->fail('İade matrahı, iade edilebilecek tutarı ('.self::fmtMoney($left).') aşamaz.');
        }
        if (Invoice::where('number', $number)->when($invoice->party_id, fn ($q) => $q->where('party_id', $invoice->party_id), fn ($q) => $q->whereNull('party_id'))->exists()) {
            $this->fail('Bu cari için aynı belge numarası zaten kayıtlı.');
        }
        $file = $this->documentFile($request);
        $stored = null;
        try {
            return DB::transaction(function () use ($request, $invoice, $number, $date, $base, $file, &$stored) {
                if ($file) {
                    $stored = $file->storeAs('invoices', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
                }
                Invoice::create([
                    'number' => $number, 'kind' => $invoice->kind, 'direction' => $invoice->direction,
                    'category' => 'İade', 'project_id' => $invoice->project_id, 'party_id' => $invoice->party_id,
                    'counterparty' => $invoice->counterparty, 'return_of_id' => $invoice->id,
                    'date' => $date, 'due' => $date, 'base' => $base, 'vat' => $invoice->vat, 'rate' => $invoice->rate,
                    'withholding_code' => $invoice->withholding_code, 'stopaj' => $invoice->stopaj,
                    'description' => mb_substr(self::str($request->input('desc')) ?: $invoice->number.' faturasının iadesi', 0, 1000),
                    'file_name' => $file ? mb_substr($file->getClientOriginalName(), 0, 200) : null, 'file_path' => $stored,
                    'created_by' => $request->user()->id,
                ]);

                return $this->ok($request, ($invoice->direction === 'gelir' ? 'Satış' : 'Alış').' iade faturası kaydedildi.');
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }
            throw $e;
        }
    }

    /** Faturasız işlem belge no: FSZ-202611-0001 */
    private function nextVoucherNumber($date): string
    {
        $prefix = 'FSZ-'.(self::validDate($date) ? substr(str_replace('-', '', $date), 0, 6) : date('Ym')).'-';
        $n = Invoice::where('number', 'like', $prefix.'%')->count();
        do {
            $number = $prefix.str_pad((string) ++$n, 4, '0', STR_PAD_LEFT);
        } while (Invoice::where('number', $number)->exists());
        return $number;
    }

    /** Ödemeyi yapan / tahsil eden ortak (boş = firma). Ortağın ilgili inşaatta hissesi olmalı. */
    private function partnerId(Request $request, string $field, ?int $projectId): ?int
    {
        $id = (int) $request->input($field);
        if (! $id) {
            return null;
        }
        $partner = Partner::find($id);
        if (! $partner) {
            $this->fail('Seçilen ortak bulunamadı.');
        }
        if ($projectId !== null && ! $partner->projects()->whereKey($projectId)->exists()) {
            $this->fail($partner->name.' bu inşaatın ortağı olarak tanımlı değil (Ortaklar ekranından hisse ekleyin).');
        }
        return $partner->id;
    }

    /** Fatura belgesi: PDF, XML (e-Fatura), JPG veya PNG; en fazla 10 MB. */
    private function documentFile(Request $request): ?\Illuminate\Http\UploadedFile
    {
        $file = $request->file('document');
        if (! $file) {
            return null;
        }
        if (! $file->isValid()) {
            $this->fail('Belge yüklenemedi. Sunucunun dosya sınırı: '.ini_get('upload_max_filesize').' (php.ini → upload_max_filesize).');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        $mimes = ['pdf' => ['application/pdf'], 'xml' => ['text/xml', 'application/xml', 'text/plain'],
            'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];
        if (! isset($mimes[$ext]) || ! in_array($file->getMimeType(), $mimes[$ext], true)) {
            $this->fail('Fatura belgesi PDF, XML (e-Fatura), JPG veya PNG olmalıdır.');
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            $this->fail('Fatura belgesi en fazla 10 MB olabilir.');
        }
        return $file;
    }

    /** Hesap türüne göre sıradaki cari kodu: 320.004, 120.002 … */
    private function nextPartyCode(string $type): string
    {
        $prefix = str_starts_with($type, '120') ? '120.' : '320.';
        $max = Party::where('code', 'like', $prefix.'%')->pluck('code')
            ->map(fn ($c) => (int) preg_replace('/\D/', '', substr($c, 4)))->max() ?? 0;
        do {
            $code = $prefix.str_pad((string) ++$max, 3, '0', STR_PAD_LEFT);
        } while (Party::where('code', $code)->exists());
        return $code;
    }

    public function destroyInvoice(Request $request, Invoice $invoice)
    {
        $this->requireEdit($request, 'faturalar');
        $this->requireProject($request, $invoice->project_id);
        if ($invoice->payments()->exists()) {
            $this->fail('Bağlı ödeme bulunan fatura silinemez. Önce ilgili ödemeleri kaldırınız.');
        }
        if ($invoice->returns()->exists()) {
            $this->fail('Bu faturaya kesilmiş iade faturası var; önce iade faturasını silin.');
        }
        if ($invoice->file_path) {
            Storage::disk('local')->delete($invoice->file_path);
        }
        $invoice->delete();

        return $this->ok($request, 'Fatura silindi.');
    }

    /**
     * Fatura aslı. ?inline=1 ile tarayıcıda açılır: PDF ve resim olduğu gibi,
     * e-Fatura XML ise içindeki görüntüleme şablonuyla (XSLT) fatura görünümüne çevrilir.
     */
    public function invoiceFile(Request $request, Invoice $invoice)
    {
        if (! $request->user()->canAccessProject($invoice->project_id) || ! $invoice->file_path
            || ! Storage::disk('local')->exists($invoice->file_path)) {
            abort(404);
        }
        $name = $invoice->file_name ?: basename($invoice->file_path);
        if (! $request->boolean('inline')) {
            return Storage::disk('local')->download($invoice->file_path, $name);
        }
        // Belge içeriği güvenilmez kabul edilir: betik çalıştırılamaz, dış kaynak yüklenemez.
        $safe = ['Content-Security-Policy' => "sandbox; default-src 'none'; img-src data:; style-src 'unsafe-inline'", 'X-Content-Type-Options' => 'nosniff'];
        $ext = strtolower(pathinfo($invoice->file_path, PATHINFO_EXTENSION));
        $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (isset($types[$ext])) {
            // PDF görüntüleyicisi sandbox içinde çalışmadığından PDF'te yalnızca nosniff gönderilir.
            return response(Storage::disk('local')->get($invoice->file_path), 200, ($ext === 'pdf' ? ['X-Content-Type-Options' => 'nosniff'] : $safe) + [
                'Content-Type' => $types[$ext],
                'Content-Disposition' => 'inline; filename="'.addslashes(preg_replace('/[^\w.\-]+/u', '_', $name)).'"',
            ]);
        }
        if ($ext === 'xml' && ($html = self::renderEInvoice(Storage::disk('local')->get($invoice->file_path)))) {
            return response($html, 200, $safe + ['Content-Type' => 'text/html; charset=utf-8']);
        }
        return Storage::disk('local')->download($invoice->file_path, $name);
    }

    /** e-Fatura XML'inin içine gömülü XSLT şablonu ile HTML görüntü üretir (yoksa null). */
    private static function renderEInvoice(string $xml): ?string
    {
        if (! class_exists(\XSLTProcessor::class)) {
            return null;
        }
        $doc = new \DOMDocument();
        if (! @$doc->loadXML($xml, LIBXML_NONET)) {
            return null;
        }
        $xp = new \DOMXPath($doc);
        $xslt = null;
        foreach ($xp->query('//*[local-name()="EmbeddedDocumentBinaryObject"]') as $node) {
            $fname = strtolower((string) $node->getAttribute('filename'));
            $data = base64_decode(preg_replace('/\s+/', '', $node->textContent), true);
            if ($data !== false && (str_ends_with($fname, '.xslt') || str_ends_with($fname, '.xsl') || str_contains(substr($data, 0, 500), 'xsl:stylesheet'))) {
                $xslt = $data;
                break;
            }
        }
        if (! $xslt) {
            return null;
        }
        $style = new \DOMDocument();
        if (! @$style->loadXML($xslt, LIBXML_NONET)) {
            return null;
        }
        $proc = new \XSLTProcessor();
        $proc->setSecurityPrefs(XSL_SECPREF_READ_FILE | XSL_SECPREF_WRITE_FILE | XSL_SECPREF_CREATE_DIRECTORY | XSL_SECPREF_READ_NETWORK | XSL_SECPREF_WRITE_NETWORK);
        if (! @$proc->importStylesheet($style)) {
            return null;
        }
        $out = @$proc->transformToXml($doc);
        return is_string($out) && $out !== '' ? $out : null;
    }

    /** Kayıtlı işleme fatura aslını (PDF, XML, JPG, PNG) yükler veya değiştirir. */
    public function uploadInvoiceDocument(Request $request, Invoice $invoice)
    {
        $this->requireEdit($request, 'faturalar');
        $this->requireProject($request, $invoice->project_id);
        $file = $this->documentFile($request);
        if (! $file) {
            $this->fail('Yüklenecek belgeyi seçiniz.');
        }
        $ettn = $invoice->ettn;
        $items = $invoice->items;
        if (in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'xml'], true)) {
            try {
                $read = InvoiceReader::read($file->get(), $file->getClientOriginalExtension());
                $ettn = $ettn ?: ($read['ettn'] ?: null);
                $items = $items ?: ($read['items'] ?: null);
            } catch (\Throwable) {
            }
            if ($ettn && ($dup = Invoice::where('ettn', $ettn)->whereKeyNot($invoice->id)->first())) {
                $this->fail('Bu e-Fatura (ETTN) başka bir kayda ait'.($request->user()->canAccessProject($dup->project_id) ? ': '.$dup->number : '').'.');
            }
        }
        $old = $invoice->file_path;
        $path = $file->storeAs('invoices', Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');
        try {
            $invoice->update(['file_path' => $path, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'ettn' => $ettn, 'items' => $items]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return $this->ok($request, $old ? 'Fatura aslı değiştirildi.' : 'Fatura aslı yüklendi.');
    }

    // ---------------------------------------------------------------- Ödemeler

    public function storePayment(Request $request)
    {
        $this->requireEdit($request, 'odemeler');
        $partyId = (int) $request->input('party');
        // Seçilen işlemler veritabanı kimlikleriyle gelir (fatura no farklı carilerde tekrar edebilir).
        $invoicePks = array_values(array_filter(array_map('intval', (array) $request->input('invoicePks', []))));
        $amount = self::money($request->input('amount'));
        $date = $request->input('date');
        $method = self::str($request->input('method'));
        $isCheque = in_array($method, ['Çek', 'Senet'], true);
        $chequeNo = self::str($request->input('number'));
        $chequeDue = $request->input('due');

        if (! in_array($method, Access::PAY_METHODS, true)) {
            $this->fail('Geçersiz ödeme yöntemi.');
        }
        if (! self::validDate($date)) {
            $this->fail('Geçerli bir ödeme tarihi giriniz.');
        }

        $projectIds = $request->user()->projectIds();

        return DB::transaction(function () use ($request, $partyId, $invoicePks, $amount, $date, $method, $isCheque, $chequeNo, $chequeDue, $projectIds) {
            // Eşzamanlı ödemelerde fazla ödemeyi önlemek için faturaları kilitle.
            $rows = Invoice::whereIn('id', $invoicePks)
                ->when($partyId, fn ($q) => $q->where('party_id', $partyId), fn ($q) => $q->whereNull('party_id'))
                ->whereIn('project_id', $projectIds)->orderBy('date')->orderBy('id')->lockForUpdate()->get();
            $rows = $rows->filter(fn ($i) => $i->remaining() > 0)->values();

            if ($rows->isEmpty() || $amount === null || $amount <= 0) {
                $this->fail('En az bir açık fatura seçip geçerli ödeme tutarı giriniz.');
            }
            $limit = round($rows->sum(fn ($i) => $i->remaining()), 2);
            if ($amount > $limit) {
                $this->fail('Fazla ödeme yapılamaz. En fazla '.self::fmtMoney($limit).' giriniz.');
            }

            $cheque = null;
            if ($isCheque) {
                if (! $partyId) {
                    $this->fail('Çek / senetle ödeme için kayıtlı bir cari seçiniz.');
                }
                if ($rows->map(fn ($i) => $i->flow())->unique()->count() > 1) {
                    $this->fail('Çek / senetle ödemede yalnızca gider ya da yalnızca gelir işlemlerini seçiniz.');
                }
                if ($chequeNo === '' || ! self::validDate($chequeDue)) {
                    $this->fail('Çek/senet için belge numarası ve vade zorunludur.');
                }
                if ($rows->pluck('project_id')->unique()->count() > 1) {
                    $this->fail('Çek/senet için aynı inşaatın faturalarını seçiniz.');
                }
                if (Cheque::where('number', $chequeNo)->exists()) {
                    $this->fail('Bu çek/senet numarası zaten kayıtlı.');
                }
                $cheque = Cheque::create([
                    'number' => $chequeNo, 'project_id' => $rows[0]->project_id, 'party_id' => $partyId,
                    'amount' => $amount, 'due' => $chequeDue, 'bank' => self::str($request->input('bank')) ?: '—',
                    'status' => 'Portföyde', 'type' => $method, 'created_by' => $request->user()->id,
                    'direction' => $rows[0]->flow() === 'gelir' ? 'alinan' : 'verilen',
                ]);
            }

            $projectsOf = $rows->pluck('project_id')->unique();
            $partnerId = $this->partnerId($request, 'partner', $projectsOf->count() === 1 ? $projectsOf->first() : null);
            if ($partnerId && $projectsOf->count() > 1) {
                $this->fail('Ortak ödemesinde aynı inşaatın faturalarını seçiniz.');
            }
            $bankTx = null;
            if ($request->input('bankAccount')) {
                $dirs = $rows->map(fn ($i) => $i->flow())->unique();
                if ($dirs->count() > 1) {
                    $this->fail('Banka hesabına bağlarken yalnızca gider ya da yalnızca gelir işlemlerini seçiniz.');
                }
                $who = $rows[0]->party_id ? (Party::find($rows[0]->party_id)?->name ?? '') : ($rows[0]->counterparty ?? '');
                $bankTx = BankController::paymentTransaction($request, $request->input('bankAccount'), $method, $projectsOf->all(),
                    $dirs->first(), $amount, $date, trim($who.' · '.$rows->pluck('number')->implode(', '), ' ·'));
            }
            $group = base_convert((string) (int) (microtime(true) * 1000), 10, 36).Str::lower(Str::random(3));
            $left = $amount;
            foreach ($rows as $invoice) {
                $n = round(min($left, $invoice->remaining()), 2);
                if ($n > 0) {
                    Payment::create([
                        'number' => 'ODM-'.$group.'-'.$invoice->number, 'invoice_id' => $invoice->id,
                        'amount' => $n, 'date' => $date, 'method' => $method,
                        'cheque_id' => $cheque?->id, 'partner_id' => $partnerId, 'bank_transaction_id' => $bankTx, 'created_by' => $request->user()->id,
                    ]);
                }
                $left = round($left - $n, 2);
            }

            return $this->ok($request, 'Kayıt başarıyla eklendi.');
        });
    }

    public function destroyPayment(Request $request, Payment $payment)
    {
        $this->requireEdit($request, 'odemeler');
        $this->requireProject($request, $payment->invoice->project_id);
        if ($payment->cheque_id) {
            $this->fail('Ödeme bir çek/senede bağlıdır. Çek / Senet ekranından çeki silin veya durumunu “Karşılıksız / İade edildi” yapın.');
        }
        $bank = DB::transaction(function () use ($payment) {
            $tx = $payment->bank_transaction_id ? BankTransaction::lockForUpdate()->find($payment->bank_transaction_id) : null;
            $payment->delete();
            if ($tx) {
                // Banka hareketi, bağlı ödemelerin toplamına indirilir; kalmadıysa silinir.
                $left = round($tx->amount - $payment->amount, 2);
                $left > 0.004 && $tx->payments()->exists() ? $tx->update(['amount' => $left]) : $tx->delete();
            }
            return (bool) $tx;
        });

        return $this->ok($request, $bank ? 'Ödeme ve bağlı banka hareketi kaldırıldı.' : 'Ödeme kaldırıldı.');
    }

    // ---------------------------------------------------------------- Çek / Senet

    public function storeCheque(Request $request)
    {
        $this->requireEdit($request, 'cek-senet');
        $number = self::str($request->input('id'));
        $amount = self::money($request->input('amount'));
        $due = $request->input('due');
        $project = (int) $request->input('project');
        $partyId = (int) $request->input('party');
        $type = self::str($request->input('type'));

        if ($number === '' || $amount === null || $amount <= 0 || ! self::validDate($due)) {
            $this->fail('Belge numarası, pozitif tutar ve vade tarihi zorunludur.');
        }
        $this->requireProject($request, $project);
        if (! Party::whereKey($partyId)->exists()) {
            $this->fail('Seçilen cari hesap bulunamadı.');
        }
        if (! in_array($type, ['Çek', 'Senet'], true)) {
            $this->fail('Geçersiz belge türü.');
        }
        $direction = self::str($request->input('direction')) ?: 'alinan';
        if (! isset(Access::CHEQUE_FLOW[$direction])) {
            $this->fail('Çekin alınan mı verilen mi olduğunu seçiniz.');
        }
        if (Cheque::where('number', $number)->exists()) {
            $this->fail('Bu belge numarası zaten kayıtlı.');
        }
        Cheque::create([
            'number' => $number, 'project_id' => $project, 'party_id' => $partyId, 'amount' => $amount,
            'due' => $due, 'bank' => self::str($request->input('bank')) ?: '—', 'status' => 'Portföyde',
            'type' => $type, 'direction' => $direction, 'created_by' => $request->user()->id,
        ]);

        return $this->ok($request, 'Kayıt başarıyla eklendi.');
    }

    /**
     * Çek / senet durumu. Karşılıksız veya iade edilen çekle yapılan ödeme geçersiz sayılır (fatura yeniden açılır).
     * Tahsil edildi / Ödendi durumunda isteğe bağlı olarak banka hesabına hareket yazılır; durum geri alınırsa silinir.
     */
    public function updateChequeStatus(Request $request, Cheque $cheque)
    {
        $this->requireEdit($request, 'cek-senet');
        $this->requireProject($request, $cheque->project_id);
        $status = self::str($request->input('status'));
        $flow = Access::CHEQUE_FLOW[$cheque->direction] ?? Access::CHEQUE_STATUSES;
        if (! in_array($status, $flow, true)) {
            $this->fail($cheque->direction === 'verilen' ? 'Verilen çek için geçersiz durum.' : 'Alınan çek için geçersiz durum.');
        }
        $date = $request->input('date') ?: now()->toDateString();
        if (! self::validDate($date)) {
            $this->fail('Geçerli bir tarih giriniz.');
        }
        $settled = in_array($status, ['Tahsil edildi', 'Ödendi'], true);

        $msg = DB::transaction(function () use ($request, $cheque, $status, $settled, $date) {
            $cheque = Cheque::lockForUpdate()->find($cheque->id);
            $wasVoid = $cheque->isVoid();
            // Çek geçersiz hâlden geri dönüyorsa bağlı ödemeler yeniden sayılır: fazla ödeme oluşmamalı.
            if ($wasVoid && ! in_array($status, Access::VOID_CHEQUE_STATUSES, true)) {
                foreach ($cheque->payments()->with('invoice')->get() as $p) {
                    $other = (float) $p->invoice->payments()->whereKeyNot($p->id)
                        ->where(fn ($q) => $q->whereNull('cheque_id')->orWhereHas('cheque', fn ($c) => $c->whereNotIn('status', Access::VOID_CHEQUE_STATUSES)))->sum('amount');
                    if ($other + $p->amount > $p->invoice->net() + 0.01) {
                        $this->fail($p->invoice->number.' için başka ödeme girilmiş; çek yeniden geçerli yapılırsa fazla ödeme oluşur.');
                    }
                }
            }
            if ($cheque->bank_transaction_id && ! $settled) {
                \App\Models\BankTransaction::whereKey($cheque->bank_transaction_id)->delete();
                $cheque->bank_transaction_id = null;
            }
            if ($settled && ! $cheque->bank_transaction_id && $request->input('bankAccount')) {
                $in = $cheque->direction === 'alinan';
                $cheque->bank_transaction_id = BankController::accountTransaction($request, $request->input('bankAccount'), $cheque->project_id,
                    $in ? 'giris' : 'cikis', $cheque->amount, $date, $in ? 'Çek tahsilatı' : 'Çek ödemesi',
                    $cheque->type.' '.$cheque->number.' · '.(Party::find($cheque->party_id)?->name ?? ''));
            }
            $cheque->status = $status;
            $cheque->save();

            if (in_array($status, Access::VOID_CHEQUE_STATUSES, true) && $cheque->payments()->exists()) {
                return 'Durum güncellendi. Bu çek/senetle yapılan ödeme geçersiz sayıldı; ilgili fatura yeniden açık.';
            }
            return 'Durum güncellendi.'.($cheque->bank_transaction_id && $settled ? ' Banka hesabına işlendi.' : '');
        });

        return $this->ok($request, $msg);
    }

    /** Çek / senet bilgilerini düzeltir. Ödemeye bağlı çekte yalnızca banka ve vade değiştirilebilir. */
    public function updateCheque(Request $request, Cheque $cheque)
    {
        $this->requireEdit($request, 'cek-senet');
        $this->requireProject($request, $cheque->project_id);
        $due = $request->input('due');
        if (! self::validDate($due)) {
            $this->fail('Geçerli bir vade tarihi giriniz.');
        }
        $data = ['due' => $due, 'bank' => mb_substr(self::str($request->input('bank')), 0, 100) ?: '—'];
        if (! $cheque->payments()->exists()) {
            $number = self::str($request->input('id'));
            $amount = self::money($request->input('amount'));
            $project = (int) $request->input('project');
            $partyId = (int) $request->input('party');
            $type = self::str($request->input('type'));
            $direction = self::str($request->input('direction'));
            if ($number === '' || $amount === null || $amount <= 0) {
                $this->fail('Belge numarası ve pozitif tutar zorunludur.');
            }
            $this->requireProject($request, $project);
            if (! Party::whereKey($partyId)->exists() || ! in_array($type, ['Çek', 'Senet'], true) || ! isset(Access::CHEQUE_FLOW[$direction])) {
                $this->fail('Cari, belge türü ve yönü geçerli olmalıdır.');
            }
            if (Cheque::where('number', $number)->whereKeyNot($cheque->id)->exists()) {
                $this->fail('Bu belge numarası zaten kayıtlı.');
            }
            if (! in_array($cheque->status, Access::CHEQUE_FLOW[$direction], true)) {
                $this->fail('Mevcut durum ('.$cheque->status.') seçilen yönde geçerli değil; önce durumu değiştirin.');
            }
            if ($cheque->bank_transaction_id && ($project !== $cheque->project_id || $direction !== $cheque->direction || $amount != $cheque->amount)) {
                $this->fail('Bankaya işlenmiş çekin inşaatı, yönü ve tutarı değiştirilemez; önce durumu “Portföyde” yapın.');
            }
            $data += ['number' => $number, 'amount' => $amount, 'project_id' => $project, 'party_id' => $partyId, 'type' => $type, 'direction' => $direction];
        }
        $cheque->update($data);

        return $this->ok($request, 'Çek / senet güncellendi.');
    }

    /** Çek / senedi ve onunla yapılan ödemeleri birlikte siler (hatalı kayıt düzeltme). */
    public function destroyCheque(Request $request, Cheque $cheque)
    {
        $this->requireEdit($request, 'cek-senet');
        $this->requireProject($request, $cheque->project_id);
        $hasPayments = $cheque->payments()->exists();
        if ($hasPayments) {
            $this->requireEdit($request, 'odemeler');
        }
        DB::transaction(function () use ($cheque) {
            foreach ($cheque->payments as $p) {
                $p->delete();
            }
            if ($cheque->bank_transaction_id) {
                \App\Models\BankTransaction::whereKey($cheque->bank_transaction_id)->delete();
            }
            $cheque->delete();
        });

        return $this->ok($request, $hasPayments ? 'Çek / senet ve bağlı ödemeler silindi; faturalar yeniden açık.' : 'Çek / senet silindi.');
    }

    // ---------------------------------------------------------------- Cari hesaplar

    public function storeParty(Request $request)
    {
        return $this->saveParty($request, null);
    }

    public function updateParty(Request $request, Party $party)
    {
        return $this->saveParty($request, $party);
    }

    private function saveParty(Request $request, ?Party $party)
    {
        if (! $request->user()->canEditParties()) {
            $this->fail('Bu işlem için yetkiniz bulunmuyor.', 403);
        }
        $name = mb_substr(self::str($request->input('name')), 0, 200);
        $code = mb_substr(self::str($request->input('code')), 0, 40);
        $type = self::str($request->input('type'));
        if ($name === '' || $code === '') {
            $this->fail('Ünvan ve cari kodu zorunludur.');
        }
        if (! in_array($type, Access::PARTY_TYPES, true)) {
            $this->fail('Geçersiz hesap türü.');
        }
        if (Party::where('code', $code)->when($party, fn ($q) => $q->whereKeyNot($party->id))->exists()) {
            $this->fail('Bu cari kodu zaten kayıtlı.');
        }
        $data = ['name' => $name, 'code' => $code, 'type' => $type, 'tax' => preg_replace('/\s+/', '', self::str($request->input('tax')))];
        $party ? $party->update($data) : Party::create($data);

        return $this->ok($request, $party ? 'Cari hesap güncellendi.' : 'Kayıt başarıyla eklendi.');
    }

    public function destroyParty(Request $request, Party $party)
    {
        if (! $request->user()->canEditParties()) {
            $this->fail('Bu işlem için yetkiniz bulunmuyor.', 403);
        }
        if (Invoice::where('party_id', $party->id)->exists() || Cheque::where('party_id', $party->id)->exists()) {
            $this->fail('Faturası veya çek/senedi olan cari hesap silinemez.');
        }
        $party->delete();

        return $this->ok($request, 'Cari hesap silindi.');
    }

    public function storeProject(Request $request)
    {
        $this->requireEdit($request, 'ayarlar');
        $name = self::str($request->input('name'));
        if ($name === '') {
            $this->fail('İnşaat adını giriniz.');
        }
        $lower = mb_strtolower($name, 'UTF-8');
        if (Project::pluck('name')->contains(fn ($n) => mb_strtolower($n, 'UTF-8') === $lower)) {
            $this->fail('Bu inşaat zaten kayıtlı.');
        }
        $project = Project::create(['name' => $name]);
        // Kısıtlı kullanıcı kendi eklediği inşaatı görebilsin.
        if (! $request->user()->seesAllProjects()) {
            $request->user()->projects()->attach($project->id);
        }

        return $this->ok($request, 'Kayıt başarıyla eklendi.', ['projectId' => $project->id]);
    }

    public function renameProject(Request $request, Project $project)
    {
        $this->requireEdit($request, 'ayarlar');
        $this->requireProject($request, $project->id);
        $name = mb_substr(self::str($request->input('name')), 0, 150);
        if ($name === '') {
            $this->fail('İnşaat adını giriniz.');
        }
        $lower = mb_strtolower($name, 'UTF-8');
        if (Project::whereKeyNot($project->id)->pluck('name')->contains(fn ($n) => mb_strtolower($n, 'UTF-8') === $lower)) {
            $this->fail('Bu inşaat adı zaten kayıtlı.');
        }
        $project->update(['name' => $name]);

        return $this->ok($request, 'İnşaat adı güncellendi.');
    }

    public function destroyProject(Request $request, Project $project)
    {
        $this->requireEdit($request, 'ayarlar');
        $this->requireProject($request, $project->id);
        if ($project->invoices()->exists() || $project->cheques()->exists() || $project->bankAccounts()->exists()) {
            $this->fail('Bağlı kayıtları olan inşaat silinemez.');
        }
        if (Project::count() === 1) {
            $this->fail('En az bir inşaat bulunmalıdır.');
        }
        $project->delete();

        return $this->ok($request, 'İnşaat kaldırıldı.');
    }
}
