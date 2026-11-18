<?php

namespace App\Support;

/**
 * e-Fatura / e-Arşiv belgesinden fatura bilgilerini okur.
 *  - XML (UBL-TR): resmi e-Fatura verisi, en güvenilir kaynak.
 *  - PDF: metin katmanından etiketlere göre ayıklanır (PdfText).
 * Dönen dizi: number, date, due, ettn, seller{name,tax}, buyer{name,tax}, base, discount,
 * vat (oran), vatAmount, rate (tevkifat oranı), withholding, payable, items[], notes, warnings[].
 */
class InvoiceReader
{
    public static function read(string $bytes, string $ext): array
    {
        $ext = strtolower($ext);
        if ($ext === 'xml' || str_starts_with(ltrim(substr($bytes, 0, 200), "\xEF\xBB\xBF \r\n\t"), '<')) {
            return self::fromXml($bytes);
        }
        if (str_starts_with($bytes, '%PDF') || $ext === 'pdf') {
            $text = PdfText::extract($bytes);
            if (mb_strlen(preg_replace('/\s+/u', '', $text)) < 30) {
                throw new \DomainException('PDF içinde okunabilir metin bulunamadı (taranmış/resim PDF olabilir). e-Fatura PDF\'i veya XML dosyasını yükleyin ya da bilgileri elle girin.');
            }
            return self::fromText($text);
        }
        throw new \DomainException('Otomatik okuma için e-Fatura PDF veya XML dosyası yükleyin. Resim (JPG/PNG) dosyaları okunamaz; bilgileri elle girebilirsiniz.');
    }

    // ------------------------------------------------------------------ yardımcılar

    /** "46.000,00" / "3.500" / "9200.00" → float */
    public static function num(?string $s): ?float
    {
        if ($s === null) {
            return null;
        }
        $s = preg_replace('/[^\d,.\-]/', '', $s);
        if ($s === '' || $s === '-') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    private static function lower(string $s): string
    {
        return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $s), 'UTF-8');
    }

    /** En yakın standart oran (0, 2/10 … 10/10) */
    private static function nearestRate(float $r): float
    {
        $best = 0.0;
        foreach (Access::WITHHOLDING_RATES as $opt) {
            if (abs($opt - $r) < abs($best - $r)) {
                $best = $opt;
            }
        }
        return $best;
    }

    private static function companyName(string $line): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line));
        // Unvan ekinde (A.Ş., LTD. ŞTİ. vb.) kes; tekrarlanan kısaltmaları at.
        if (preg_match('/^(.*?\b(?:A\.?\s?Ş\.?|ANONİM ŞİRKETİ|LTD\.?\s*ŞTİ\.?|LİMİTED ŞİRKETİ|ŞTİ\.?|KOOPERATİFİ|KOOP\.))/u', $line, $m)) {
            return trim($m[1]);
        }
        return $line;
    }

    private static function finish(array $r): array
    {
        $r['warnings'] ??= [];
        $r['items'] ??= [];
        $r['wcode'] ??= null;
        $r['stopaj'] ??= 0.0;
        if ($r['stopaj'] && ! in_array($r['stopaj'], Access::STOPAJ_RATES)) {
            $r['warnings'][] = 'Stopaj oranı %'.$r['stopaj'].' listede yok; lütfen kontrol edin.';
        }
        if (! $r['number']) {
            $r['warnings'][] = 'Fatura numarası okunamadı.';
        }
        if (! $r['date']) {
            $r['warnings'][] = 'Fatura tarihi okunamadı.';
        }
        if (! $r['base']) {
            $r['warnings'][] = 'Matrah (mal/hizmet toplamı) okunamadı.';
        }
        $r['due'] = $r['due'] ?: $r['date'];
        if ($r['vat'] !== null && ! in_array($r['vat'], Access::VAT_RATES)) {
            $r['warnings'][] = 'KDV oranı %'.$r['vat'].' listede yok; lütfen kontrol edin.';
        }
        // Okunan tutarlar tutarlı mı? (matrah + KDV − tevkifat = ödenecek)
        if ($r['base'] && $r['payable'] !== null) {
            $net = round($r['base'] * (1 + ($r['vat'] ?? 0) / 100 - ($r['vat'] ?? 0) / 100 * ($r['rate'] ?? 0) - ($r['stopaj'] ?? 0) / 100), 2);
            if (abs($net - $r['payable']) > 0.05) {
                $r['warnings'][] = 'Hesaplanan net ('.number_format($net, 2, ',', '.').' ₺) faturadaki ödenecek tutardan ('
                    .number_format($r['payable'], 2, ',', '.').' ₺) farklı. Oranları kontrol edin.';
            }
        }
        if (! $r['desc'] && $r['items']) {
            $names = array_values(array_unique(array_filter(array_column($r['items'], 'name'))));
            $r['desc'] = mb_substr(implode(', ', $names), 0, 250);
        }
        return $r;
    }

    // ------------------------------------------------------------------ XML (UBL-TR)

    public static function fromXml(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if (! $ok) {
            throw new \DomainException('XML dosyası okunamadı.');
        }
        $x = new \DOMXPath($doc);
        $root = $doc->documentElement;
        if ($root->localName !== 'Invoice') {
            throw new \DomainException('Bu XML bir UBL-TR e-Fatura değil.');
        }
        $q = function (string $path, $ctx = null) use ($x, $root) {
            $p = implode('/', array_map(fn ($s) => $s === '..' ? $s : "*[local-name()='$s']", explode('/', $path)));
            $n = $x->query($p, $ctx ?? $root);
            return $n && $n->length ? trim($n->item(0)->textContent) : null;
        };
        $party = function (string $role) use ($x, $root, $q) {
            $n = $x->query("*[local-name()='$role']/*[local-name()='Party']", $root);
            if (! $n || ! $n->length) {
                return ['name' => null, 'tax' => null];
            }
            $p = $n->item(0);
            $tax = null;
            foreach ($x->query("*[local-name()='PartyIdentification']/*[local-name()='ID']", $p) as $id) {
                if (in_array(strtoupper($id->getAttribute('schemeID')), ['VKN', 'TCKN'], true)) {
                    $tax = trim($id->textContent);
                }
            }
            $name = $q('PartyName/Name', $p) ?: trim(($q('Person/FirstName', $p) ?? '').' '.($q('Person/FamilyName', $p) ?? ''));
            return ['name' => $name ?: null, 'tax' => $tax];
        };

        $vatByRate = [];
        foreach ($x->query("*[local-name()='TaxTotal']/*[local-name()='TaxSubtotal']", $root) as $st) {
            $code = $q('TaxCategory/TaxScheme/TaxTypeCode', $st);
            if (in_array($code, ['0003', '0011'], true)) { // GV / KV stopajı
                $stopaj = (float) ($q('Percent', $st) ?? 0);
                continue;
            }
            if ($code && $code !== '0015') {
                continue; // yalnızca KDV
            }
            $pct = (float) ($q('Percent', $st) ?? 0);
            $vatByRate[(string) $pct] = ($vatByRate[(string) $pct] ?? 0) + (float) $q('TaxableAmount', $st);
            $vatAmount[] = (float) $q('TaxAmount', $st);
        }
        $withholding = (float) ($q('WithholdingTaxTotal/TaxAmount') ?? 0);
        $wPercent = $q('WithholdingTaxTotal/TaxSubtotal/Percent');
        $wcode = $q('WithholdingTaxTotal/TaxSubtotal/TaxCategory/TaxScheme/TaxTypeCode');

        $items = [];
        foreach ($x->query("*[local-name()='InvoiceLine']", $root) as $line) {
            $qty = $x->query("*[local-name()='InvoicedQuantity']", $line)->item(0);
            $items[] = [
                'code' => $q('Item/SellersItemIdentification/ID', $line) ?? '',
                'name' => $q('Item/Name', $line) ?? '',
                'qty' => $qty ? (float) $qty->textContent : null,
                'unit' => $qty ? self::unitName($qty->getAttribute('unitCode')) : '',
                'price' => ($v = $q('Price/PriceAmount', $line)) !== null ? (float) $v : null,
                'vatRate' => ($v = $q('TaxTotal/TaxSubtotal/Percent', $line)) !== null ? (float) $v : null,
                'vatAmount' => ($v = $q('TaxTotal/TaxAmount', $line)) !== null ? (float) $v : null,
                'total' => ($v = $q('LineExtensionAmount', $line)) !== null ? (float) $v : null,
            ];
        }

        $base = $q('LegalMonetaryTotal/TaxExclusiveAmount') ?? $q('LegalMonetaryTotal/LineExtensionAmount');
        $r = [
            'source' => 'xml',
            'number' => $q('ID'),
            'date' => $q('IssueDate'),
            'due' => $q('PaymentMeans/PaymentDueDate'),
            'ettn' => $q('UUID') ? strtoupper($q('UUID')) : null,
            'seller' => $party('AccountingSupplierParty'),
            'buyer' => $party('AccountingCustomerParty'),
            'base' => $base !== null ? (float) $base : null,
            'discount' => (float) ($q('LegalMonetaryTotal/AllowanceTotalAmount') ?? 0),
            'vatAmount' => isset($vatAmount) ? array_sum($vatAmount) : 0,
            'withholding' => $withholding,
            'payable' => ($v = $q('LegalMonetaryTotal/PayableAmount')) !== null ? (float) $v : null,
            'items' => $items,
            'notes' => implode(' · ', array_map(fn ($n) => trim($n->textContent), iterator_to_array($x->query("*[local-name()='Note']", $root)))),
            'warnings' => [],
            'desc' => null,
            'stopaj' => $stopaj ?? 0.0,
            'wcode' => $wcode && preg_match('/^[68]\d{2}$/', $wcode) ? $wcode : null,
        ];
        self::rates($r, $vatByRate, $wPercent !== null ? (float) $wPercent / 100 : null);
        return self::finish($r);
    }

    private static function unitName(string $code): string
    {
        return ['C62' => 'Adet', 'KGM' => 'kg', 'MTR' => 'm', 'MTK' => 'm²', 'MTQ' => 'm³', 'LTR' => 'lt', 'TNE' => 'ton', 'HUR' => 'saat', 'DAY' => 'gün', 'MON' => 'ay', 'SET' => 'set', 'PA' => 'paket', 'BX' => 'kutu'][$code] ?? $code;
    }

    /** KDV oranı ve tevkifat oranını belirler. $vatByRate: oran → matrah */
    private static function rates(array &$r, array $vatByRate, ?float $wRate): void
    {
        $vatByRate = array_filter($vatByRate, fn ($b) => $b > 0) ?: $vatByRate;
        arsort($vatByRate);
        $r['vat'] = $vatByRate ? (float) array_key_first($vatByRate) : null;
        if (count($vatByRate) > 1) {
            $r['warnings'][] = 'Faturada birden fazla KDV oranı var (%'.implode(', %', array_keys($vatByRate)).'). En yüksek matrahlı oran seçildi; net tutarı kontrol edin.';
        }
        if ($r['vat'] === null && $r['base'] && $r['vatAmount']) {
            $r['vat'] = (float) round($r['vatAmount'] / $r['base'] * 100);
        }
        $r['vat'] ??= 0.0;
        $r['rate'] = 0.0;
        if ($r['withholding'] > 0) {
            $raw = $wRate ?? ($r['vatAmount'] ? $r['withholding'] / $r['vatAmount'] : 0);
            $r['rate'] = self::nearestRate($raw);
            if (abs($r['rate'] - $raw) > 0.02) {
                $r['warnings'][] = 'Tevkifat oranı tam eşleşmedi (okunan: '.round($raw * 10, 1).'/10).';
            }
        }
    }

    // ------------------------------------------------------------------ PDF metni

    public static function fromText(string $text): array
    {
        // Yumuşak tire, bölünmez boşluk vb. normalleştir
        $text = str_replace(["\u{00AD}", "\u{00A0}", "\u{2010}", "\u{2011}", "\u{2013}", "\r"], ['-', ' ', '-', '-', '-', ''], $text);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn ($l) => $l !== ''));
        $flat = implode("\n", $lines);
        $money = '(\d{1,3}(?:\.\d{3})*(?:,\d{1,4})?|\d+(?:[.,]\d{1,4})?)';

        $find = function (string $re) use ($flat) {
            return preg_match($re, $flat, $m) ? $m : null;
        };
        $amount = function (string $label) use ($flat, $money) {
            return preg_match('/'.$label.'[^\d\n%]*?(?:\(\s*%?\s*[\d.,]+\s*\)[^\d\n]*)?'.$money.'\s*(?:TL|TRY|₺)?/iu', $flat, $m) ? self::num($m[1]) : null;
        };

        $r = ['source' => 'pdf', 'warnings' => [], 'desc' => null, 'notes' => ''];

        $m = $find('/Fatura\s*No\s*:?\s*([A-Z0-9]{3}20\d{2}\d{9})\b/u') ?? $find('/Fatura\s*(?:No|Numarası)\s*:?\s*([A-Z0-9][A-Z0-9\-\/]{4,30})/u');
        $r['number'] = $m[1] ?? null;
        $m = $find('/Fatura\s*Tarihi\s*:?\s*(\d{1,2})\s*[-.\/]\s*(\d{1,2})\s*[-.\/]\s*(\d{4})/u');
        $r['date'] = $m && checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        $m = $find('/(?:Son\s*Ödeme|Vade|Ödeme)\s*Tarihi\s*:?\s*(\d{1,2})\s*[-.\/]\s*(\d{1,2})\s*[-.\/]\s*(\d{4})/iu');
        $r['due'] = $m && checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        $m = $find('/ETTN\s*:?\s*([0-9A-F]{8}-?[0-9A-F]{4}-?[0-9A-F]{4}-?[0-9A-F]{4}-?[0-9A-F]{12})/iu');
        $r['ettn'] = $m ? strtoupper($m[1]) : null;

        // Taraflar: "SAYIN" bloğu alıcıdır; ondan önceki VKN/TCKN satıcıya aittir.
        $sayin = null;
        $taxes = [];
        foreach ($lines as $i => $l) {
            if ($sayin === null && preg_match('/^SAYIN\b/u', $l)) {
                $sayin = $i;
            }
            if (preg_match_all('/\b(VKN|TCKN|Vergi\s*(?:Kimlik\s*)?No|T\.?C\.?\s*Kimlik\s*No)\s*:?\s*(\d{10,11})\b/iu', $l, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $x) {
                    $taxes[] = ['line' => $i, 'tax' => $x[2]];
                }
            }
        }
        $sellerTax = $buyerTax = null;
        foreach ($taxes as $t) {
            if ($sayin !== null && $t['line'] > $sayin) {
                $buyerTax ??= $t;
            } else {
                $sellerTax ??= $t;
            }
        }
        if (! $sellerTax && count($taxes) > 1) {
            $sellerTax = $taxes[0];
        }
        $sellerName = null;
        $limit = $sellerTax['line'] ?? ($sayin ?? min(8, count($lines)));
        for ($i = 0; $i < $limit; $i++) {
            if (preg_match('/A\.?\s?Ş\.?|ANONİM|LTD|LİMİTED|ŞTİ|ŞİRKETİ|KOOP|SAN\.|TİC\./u', $lines[$i])) {
                $sellerName = self::companyName($lines[$i]);
                break;
            }
        }
        if (! $sellerName && isset($sellerTax)) { // şahıs: blok başındaki ilk etiketsiz satır
            for ($i = max(0, $sellerTax['line'] - 8); $i < $sellerTax['line']; $i++) {
                if (! str_contains($lines[$i], ':') && preg_match('/\p{Lu}{2,}/u', $lines[$i]) && ! preg_match('/e-?FATURA|e-?ARŞİV/iu', $lines[$i])) {
                    $sellerName = trim($lines[$i]);
                    break;
                }
            }
        }
        $buyerName = $sayin !== null && isset($lines[$sayin + 1]) ? self::companyName($lines[$sayin + 1]) : null;
        $r['seller'] = ['name' => $sellerName, 'tax' => $sellerTax['tax'] ?? null];
        $r['buyer'] = ['name' => $buyerName, 'tax' => $buyerTax['tax'] ?? null];

        // Kalemler
        $items = [];
        foreach ($lines as $l) {
            if (! preg_match('/^(\d{1,3})\s+(.+%\s*[\d.,]+.*)$/u', $l, $m)) {
                continue;
            }
            $rest = $m[2];
            $tl = '\s*(?:TL|TRY|₺)?';
            if (preg_match('/^(.+?)\s+'.$money.'\s*([^\d\s%][^\s%]{0,9})?\s+'.$money.$tl.'\s+(?:.*?\s)?%\s*([\d.,]+)\s+'.$money.$tl.'\s+'.$money.$tl.'$/u', $rest, $p)) {
                $name = trim($p[1]);
                $code = '';
                if (preg_match('/^(\S*\d\S*)\s+(.+)$/u', $name, $c)) {
                    [$code, $name] = [$c[1], $c[2]];
                }
                $items[] = ['code' => $code, 'name' => $name, 'qty' => self::num($p[2]), 'unit' => $p[3] ?? '',
                    'price' => self::num($p[4]), 'vatRate' => self::num($p[5]), 'vatAmount' => self::num($p[6]), 'total' => self::num($p[7])];
            } elseif (preg_match_all('/'.$money.'/u', $rest, $nums) && preg_match('/%\s*([\d.,]+)/u', $rest, $vr)) {
                $name = trim(preg_replace('/\s+\d.*$/u', '', $rest));
                $items[] = ['code' => '', 'name' => $name, 'qty' => null, 'unit' => '', 'price' => null,
                    'vatRate' => self::num($vr[1]), 'vatAmount' => null, 'total' => self::num(end($nums[1]))];
            }
        }
        $r['items'] = $items;

        // Toplamlar
        $lineTotal = $amount('Mal\s*\/?\s*Hizmet\s*Toplam\s*Tutar[ıi]');
        $r['discount'] = $amount('Toplam\s*[İI]skonto') ?? 0.0;
        $exclusive = $amount('Vergiler\s*Hari[çc]\s*(?:Toplam\s*)?Tutar');
        $r['payable'] = $amount('Ödenecek\s*Tutar') ?? $amount('Vergiler\s*Dahil\s*Toplam\s*Tutar');
        $r['base'] = $exclusive ?? ($lineTotal !== null ? round($lineTotal - $r['discount'], 2) : ($items ? round(array_sum(array_column($items, 'total')), 2) : null));

        $vatByRate = [];
        $vatSum = 0.0;
        if (preg_match_all('/Hesaplanan\s*KDV\s*(?!Tevkifat)\(?\s*%\s*([\d.,]+)\s*\)?[^\d\n]*'.$money.'/iu', $flat, $vm, PREG_SET_ORDER)) {
            foreach ($vm as $v) {
                $rate = (float) str_replace(',', '.', $v[1]);
                $amt = self::num($v[2]);
                $vatSum += $amt;
                $vatByRate[(string) $rate] = ($vatByRate[(string) $rate] ?? 0) + ($rate > 0 ? $amt / ($rate / 100) : 0);
            }
        } else {
            foreach ($items as $it) {
                if ($it['vatRate'] !== null) {
                    $vatByRate[(string) $it['vatRate']] = ($vatByRate[(string) $it['vatRate']] ?? 0) + (float) $it['total'];
                    $vatSum += (float) $it['vatAmount'];
                }
            }
            if (! $vatSum && ($v = $amount('(?:Toplam\s*)?KDV(?:\s*Tutar[ıi])?'))) {
                $vatSum = $v;
            }
        }
        $r['vatAmount'] = round($vatSum, 2);
        $w = $find('/(?:KDV\s*Tevkifat[ıi]?|Tevkifat\s*Tutar[ıi]|Hesaplanan\s*KDV\s*Tevkifat[ıi]?)\s*(?:\([^)]*\))?[^\d\n]*'.$money.'/iu');
        $r['withholding'] = $w ? self::num($w[1]) : 0.0;
        $wr = $find('/Tevkifat[^\n]*?\b(\d{1,2})\s*\/\s*10\b/iu');
        $wc = $find('/Tevkifat[^\n]{0,80}?\b([68]\d{2})\b/iu') ?? $find('/\b(6[0-2]\d)\s*[-–:]\s*\p{L}/u');
        $r['wcode'] = $r['withholding'] > 0 && $wc ? $wc[1] : null;
        $st = $find('/(?:G\.?\s?V\.?|Gelir\s*Vergisi|K\.?\s?V\.?|Kurumlar\s*Vergisi)\s*Stopaj[ıi]?\s*\(?\s*%\s*([\d.,]+)/iu');
        $r['stopaj'] = $st ? (float) str_replace(',', '.', $st[1]) : 0.0;

        // Açıklama notları (örn. "12.11.2026 ÇİFTLİKKÖY ESKİ ŞANTİYE")
        $notes = [];
        $after = false;
        foreach ($lines as $l) {
            if (preg_match('/Ödenecek\s*Tutar/iu', $l)) {
                $after = true;
                continue;
            }
            if ($after && ! preg_match('/^Yaln[ıi]z|BANKA|IBAN|^TR\d{2}|^\S+\s+(?:R\s+)?TR\d{2}/iu', $l)) {
                $notes[] = $l;
            }
            if ($after && preg_match('/BANKA/iu', $l)) {
                break;
            }
        }
        $r['notes'] = implode(' · ', array_slice($notes, 0, 4));

        self::rates($r, $vatByRate, $wr ? ((int) $wr[1]) / 10 : null);
        return self::finish($r);
    }
}
