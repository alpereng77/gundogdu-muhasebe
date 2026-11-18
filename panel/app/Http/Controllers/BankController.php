<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Support\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** İnşaat bazlı banka hesapları, hesap hareketleri ve hesaplar arası virman. */
class BankController extends Controller
{
    private const PAGE = 'hesap-ekstresi';

    // ---------------------------------------------------------------- Hesaplar

    public function saveAccount(Request $request, ?BankAccount $account = null)
    {
        $this->requireEdit($request, self::PAGE);
        if ($account?->exists) {
            $this->requireProject($request, $account->project_id);
        }
        $project = (int) $request->input('project');
        $this->requireProject($request, $project);

        $bank = mb_substr(self::str($request->input('bank')), 0, 80);
        if ($bank === '') {
            $this->fail('Banka adını giriniz.');
        }
        $iban = strtoupper(preg_replace('/\s+/', '', self::str($request->input('iban'))));
        if ($iban !== '' && ! self::validIban($iban)) {
            $this->fail('IBAN geçersiz. TR ile başlayan 26 karakterlik IBAN giriniz (boşluklar önemli değil).');
        }
        $currency = self::str($request->input('currency')) ?: 'TRY';
        if (! in_array($currency, Access::CURRENCIES, true)) {
            $this->fail('Geçersiz para birimi.');
        }
        $opening = self::money($request->input('opening') ?: 0);
        if ($opening === null || abs($opening) > 1e12) {
            $this->fail('Açılış bakiyesini sayı olarak giriniz (eksi bakiye için başına - yazın).');
        }
        $openingDate = $request->input('openingDate') ?: null;
        if ($openingDate !== null && ! self::validDate($openingDate)) {
            $this->fail('Geçerli bir açılış tarihi giriniz.');
        }

        if ($account?->exists) {
            if ($account->currency !== $currency && $account->transactions()->exists()) {
                $this->fail('Hareketi olan hesabın para birimi değiştirilemez.');
            }
            $linked = $account->transactions()->whereHas('payments')->exists();
            if ($linked && $account->project_id !== $project) {
                $this->fail('Bu hesaba bağlı ödemeler var; hesabın inşaatı değiştirilemez.');
            }
            if ($linked && $currency !== 'TRY') {
                $this->fail('Bu hesaba bağlı ödemeler var; para birimi TRY olmalıdır.');
            }
            if ($account->project_id !== $project && $account->transactions()->whereNotNull('transfer_id')->exists()) {
                $this->fail('Bu hesapta virman hareketleri var; hesabın inşaatı değiştirilemez.');
            }
        }

        $data = [
            'project_id' => $project, 'bank' => $bank,
            'branch' => mb_substr(self::str($request->input('branch')), 0, 80) ?: null,
            'name' => mb_substr(self::str($request->input('name')), 0, 120) ?: null,
            'iban' => $iban ?: null,
            'account_no' => mb_substr(self::str($request->input('accountNo')), 0, 40) ?: null,
            'currency' => $currency, 'opening_balance' => $opening, 'opening_date' => $openingDate,
            'note' => mb_substr(self::str($request->input('note')), 0, 500) ?: null,
            'active' => $request->has('active') ? $request->boolean('active') : true,
        ];
        if ($account?->exists) {
            $account->update($data);
        } else {
            $account = BankAccount::create($data);
        }

        return $this->ok($request, 'Banka hesabı kaydedildi.', ['bankAccount' => $account->id]);
    }

    public function destroyAccount(Request $request, BankAccount $account)
    {
        $this->requireEdit($request, self::PAGE);
        $this->requireProject($request, $account->project_id);
        if ($account->transactions()->exists()) {
            $this->fail('Hareketi olan banka hesabı silinemez. Hesabı kullanmayacaksanız “Pasif” yapın.');
        }
        $account->delete();

        return $this->ok($request, 'Banka hesabı silindi.');
    }

    // ---------------------------------------------------------------- Hareketler

    public function saveTransaction(Request $request, ?BankTransaction $transaction = null)
    {
        $this->requireEdit($request, self::PAGE);
        if ($transaction?->exists) {
            $this->requireProject($request, $transaction->account->project_id);
            if ($transaction->payments()->exists() || \App\Models\Cheque::where('bank_transaction_id', $transaction->id)->exists()) {
                $this->fail('Bu hareket bir ödeme / tahsilata veya çek tahsiline bağlı; kaynağı olan ekrandan yönetilir.');
            }
            if ($transaction->transfer_id) {
                $this->fail('Virman hareketi düzenlenemez; silip yeniden giriniz.');
            }
        }
        $account = BankAccount::find((int) $request->input('account'));
        if (! $account) {
            $this->fail('Banka hesabını seçiniz.');
        }
        $this->requireProject($request, $account->project_id);

        $direction = self::str($request->input('direction'));
        if (! in_array($direction, ['giris', 'cikis'], true)) {
            $this->fail('Hareket yönünü (giriş / çıkış) seçiniz.');
        }
        $amount = self::money($request->input('amount'));
        if ($amount === null || $amount <= 0) {
            $this->fail('Pozitif bir tutar giriniz.');
        }
        $date = $request->input('date');
        if (! self::validDate($date)) {
            $this->fail('Geçerli bir tarih giriniz.');
        }

        $data = [
            'bank_account_id' => $account->id, 'date' => $date, 'direction' => $direction, 'amount' => $amount,
            'category' => mb_substr(self::str($request->input('category')), 0, 60) ?: null,
            'description' => mb_substr(self::str($request->input('description')), 0, 500) ?: null,
            'document_no' => mb_substr(self::str($request->input('documentNo')), 0, 60) ?: null,
        ];
        if ($transaction?->exists) {
            $transaction->update($data);
        } else {
            BankTransaction::create($data + ['created_by' => $request->user()->id]);
        }

        return $this->ok($request, 'Banka hareketi kaydedildi.');
    }

    public function destroyTransaction(Request $request, BankTransaction $transaction)
    {
        $this->requireEdit($request, self::PAGE);
        $this->requireProject($request, $transaction->account->project_id);
        if ($transaction->payments()->exists()) {
            $this->fail('Bu hareket bir ödeme / tahsilata bağlı. Ödemeler ekranından ödemeyi silince banka hareketi de kalkar.');
        }
        if (\App\Models\Cheque::where('bank_transaction_id', $transaction->id)->exists()) {
            $this->fail('Bu hareket bir çek / senet tahsiline bağlı. Çek / Senet ekranından durumu değiştirin.');
        }
        DB::transaction(function () use ($request, $transaction) {
            if ($transaction->transfer_id && ($pair = BankTransaction::with('account')->find($transaction->transfer_id))) {
                $this->requireProject($request, $pair->account->project_id);
                $pair->delete();
            }
            $transaction->delete();
        });

        return $this->ok($request, $transaction->transfer_id ? 'Virman (iki hesaptaki hareket) silindi.' : 'Banka hareketi silindi.');
    }

    /** Hesaplar arası virman: kaynakta çıkış, hedefte giriş hareketi oluşturur. */
    public function transfer(Request $request)
    {
        $this->requireEdit($request, self::PAGE);
        $from = BankAccount::find((int) $request->input('from'));
        $to = BankAccount::find((int) $request->input('to'));
        if (! $from || ! $to || $from->id === $to->id) {
            $this->fail('Farklı iki banka hesabı seçiniz.');
        }
        $this->requireProject($request, $from->project_id);
        $this->requireProject($request, $to->project_id);
        if (! $from->active || ! $to->active) {
            $this->fail('Pasif hesapla virman yapılamaz.');
        }
        if ($from->currency !== $to->currency) {
            $this->fail('Virman için hesapların para birimi aynı olmalıdır.');
        }
        $amount = self::money($request->input('amount'));
        $date = $request->input('date');
        if ($amount === null || $amount <= 0 || ! self::validDate($date)) {
            $this->fail('Pozitif tutar ve geçerli tarih giriniz.');
        }
        $desc = mb_substr(self::str($request->input('description')), 0, 400);
        $doc = mb_substr(self::str($request->input('documentNo')), 0, 60) ?: null;
        $label = fn (BankAccount $a) => $a->bank.($a->name ? ' · '.$a->name : '');

        DB::transaction(function () use ($request, $from, $to, $amount, $date, $desc, $doc, $label) {
            $out = BankTransaction::create([
                'bank_account_id' => $from->id, 'date' => $date, 'direction' => 'cikis', 'amount' => $amount,
                'category' => 'Virman (giden)', 'description' => trim('→ '.$label($to).($desc ? ' · '.$desc : '')),
                'document_no' => $doc, 'created_by' => $request->user()->id,
            ]);
            $in = BankTransaction::create([
                'bank_account_id' => $to->id, 'date' => $date, 'direction' => 'giris', 'amount' => $amount,
                'category' => 'Virman (gelen)', 'description' => trim('← '.$label($from).($desc ? ' · '.$desc : '')),
                'document_no' => $doc, 'transfer_id' => $out->id, 'created_by' => $request->user()->id,
            ]);
            $out->update(['transfer_id' => $in->id]);
        });

        return $this->ok($request, 'Virman kaydedildi.');
    }

    // ---------------------------------------------------------------- Ödeme bağlantısı

    /**
     * Ödeme / tahsilat için banka hareketi oluşturur ve kimliğini döner.
     * Gider ödemesi hesaptan çıkış, gelir tahsilatı hesaba giriştir.
     */
    public static function paymentTransaction(Request $request, $accountId, string $method, array $projectIds, string $direction, float $amount, string $date, string $description): ?int
    {
        if (! (int) $accountId) {
            return null;
        }
        if (! in_array($method, Access::BANK_METHODS, true)) {
            self::reject('Banka hesabı yalnızca Havale / EFT veya Kredi Kartı ödemelerine bağlanabilir.');
        }
        $projectIds = array_values(array_unique($projectIds));
        if (count($projectIds) !== 1) {
            self::reject('Banka hesabına bağlanan ödemede aynı inşaatın işlemlerini seçiniz.');
        }

        return self::accountTransaction($request, $accountId, (int) $projectIds[0], $direction === 'gelir' ? 'giris' : 'cikis',
            $amount, $date, $direction === 'gelir' ? 'Tahsilat' : 'Ödeme', $description);
    }

    /** İnşaatın aktif TRY banka hesabına hareket yazar (ödeme, çek tahsili / ödemesi). */
    public static function accountTransaction(Request $request, $accountId, int $projectId, string $dir, float $amount, string $date, string $category, string $description): ?int
    {
        $accountId = (int) $accountId;
        if (! $accountId) {
            return null;
        }
        $account = BankAccount::find($accountId);
        if (! $account || ! $request->user()->canAccessProject($account->project_id)) {
            self::reject('Seçilen banka hesabı bulunamadı.');
        }
        if (! $account->active) {
            self::reject('Seçilen banka hesabı pasif.');
        }
        if ($account->currency !== 'TRY') {
            self::reject('Yalnızca TRY banka hesapları bağlanabilir.');
        }
        if ($account->project_id !== $projectId) {
            self::reject('Banka hesabı, işlemle aynı inşaata ait olmalıdır.');
        }

        return BankTransaction::create([
            'bank_account_id' => $account->id, 'date' => $date, 'direction' => $dir, 'amount' => round($amount, 2),
            'category' => $category, 'description' => mb_substr($description, 0, 500), 'created_by' => $request->user()->id,
        ])->id;
    }

    private static function reject(string $message): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message' => $message], 422));
    }

    /** TR IBAN: 26 karakter, mod-97 kontrolü */
    public static function validIban(string $iban): bool
    {
        if (! preg_match('/^TR\d{24}$/', $iban)) {
            return false;
        }
        $r = substr($iban, 4).'2927'.substr($iban, 2, 2); // T=29, R=27
        $mod = 0;
        foreach (str_split($r) as $d) {
            $mod = ($mod * 10 + (int) $d) % 97;
        }
        return $mod === 1;
    }
}
