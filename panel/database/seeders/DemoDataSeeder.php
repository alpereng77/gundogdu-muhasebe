<?php

namespace Database\Seeders;

use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Eski HTML sürümündeki örnek kayıtlar. Veri varsa çalışmaz. */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (Project::exists()) {
            return;
        }
        $projects = collect(['Vadi İstanbul Konutları', 'Mavişehir Rezidans', 'Park Yaşam Evleri'])
            ->map(fn ($n) => Project::create(['name' => $n])->id)->all();
        $parties = [];
        foreach ([
            ['Akçansa Çimento San. A.Ş.', '320.001', '320 Satıcılar'],
            ['Yıldız Demir Çelik Ltd. Şti.', '320.002', '320 Satıcılar'],
            ['Modern Yapı Malzemeleri', '320.003', '320 Satıcılar'],
            ['Emre Yılmaz', '120.001', '120 Alıcılar'],
        ] as $j => [$name, $code, $type]) {
            $parties[$j + 1] = Party::create(compact('name', 'code', 'type'))->id;
        }
        $invoices = [];
        foreach ([
            ['FTR-2026-001', 0, 1, '2026-11-02', '2026-11-18', 450000, 0.5, 'Beton ve çimento tedariği'],
            ['FTR-2026-002', 0, 2, '2026-11-04', '2026-11-08', 680000, 0.7, 'İnşaat demiri alımı'],
            ['FTR-2026-003', 0, 3, '2026-11-06', '2026-11-22', 235000, 0, 'Yalıtım ve yapı malzemeleri'],
            ['FTR-2026-004', 1, 1, '2026-10-22', '2026-11-14', 360000, 0.5, 'Hazır beton alımı'],
            ['FTR-2026-005', 2, 2, '2026-10-25', '2026-11-20', 520000, 0.7, 'Demir bağlantı elemanları'],
            ['FTR-2026-006', 0, 1, '2026-10-18', '2026-11-01', 300000, 0.5, 'Temel beton dökümü'],
        ] as [$no, $p, $party, $date, $due, $base, $rate, $desc]) {
            $invoices[$no] = Invoice::create([
                'number' => $no, 'project_id' => $projects[$p], 'party_id' => $parties[$party], 'date' => $date, 'due' => $due,
                'base' => $base, 'vat' => 20, 'rate' => $rate, 'description' => $desc,
            ])->id;
        }
        foreach ([
            ['ODM-001', 'FTR-2026-001', 250000, '2026-11-05', 'Havale / EFT'],
            ['ODM-002', 'FTR-2026-002', 420000, '2026-11-06', 'Çek'],
            ['ODM-003', 'FTR-2026-003', 282000, '2026-11-08', 'Havale / EFT'],
            ['ODM-004', 'FTR-2026-006', 200000, '2026-10-28', 'Nakit'],
        ] as [$no, $inv, $amount, $date, $method]) {
            Payment::create(['number' => $no, 'invoice_id' => $invoices[$inv], 'amount' => $amount, 'date' => $date, 'method' => $method]);
        }
        foreach ([
            ['CK-001', 0, 2, 420000, '2026-11-08', 'İş Bankası', 'Çek'],
            ['CK-002', 0, 1, 180000, '2026-11-14', 'Garanti BBVA', 'Çek'],
            ['SN-001', 1, 3, 125000, '2026-11-22', '—', 'Senet'],
        ] as [$no, $p, $party, $amount, $due, $bank, $type]) {
            Cheque::create(['number' => $no, 'project_id' => $projects[$p], 'party_id' => $parties[$party], 'amount' => $amount, 'due' => $due, 'bank' => $bank, 'status' => 'Portföyde', 'type' => $type, 'direction' => $no === 'CK-001' ? 'verilen' : 'alinan']);
        }
        // ODM-002, satıcıya verilen CK-001 çekiyle yapılmıştır.
        Payment::where('number', 'ODM-002')->update(['cheque_id' => Cheque::where('number', 'CK-001')->value('id')]);
        Setting::put('company', 'Gündoğdu İnşaat A.Ş.');
        Setting::put('warning', '7');
    }
}
