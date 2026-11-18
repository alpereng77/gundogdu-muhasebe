<?php

namespace App\Support;

/**
 * Paketsiz PDF metin çıkarıcı (yalnızca PHP + zlib).
 *
 * e-Fatura / e-Arşiv gibi metin katmanı olan PDF'ler içindir. Taranmış (resim) PDF'lerde
 * metin bulunmaz; bunlar için OCR gerekir. Desteklenenler: FlateDecode akışları, nesne akışları
 * (ObjStm), Type0/CID ve basit yazı tipleri, ToUnicode haritaları, Form XObject'ler.
 * Metin, sayfadaki konumuna göre satırlara dizilir.
 */
class PdfText
{
    private string $data;
    /** @var array<int, array{0:mixed,1:?string}> nesne no → [değer, ham akış] */
    private array $objects = [];
    private array $fontCache = [];

    public static function extract(string $bytes, int $maxPages = 10): string
    {
        $pdf = new self();
        $pdf->data = $bytes;
        $pdf->loadObjects();
        $out = [];
        foreach (array_slice($pdf->pages(), 0, $maxPages) as $page) {
            $out[] = $pdf->pageText($page);
        }
        return trim(implode("\n\f\n", $out));
    }

    // ------------------------------------------------------------------ nesneler

    private function loadObjects(): void
    {
        if (! preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->data, $m, PREG_OFFSET_CAPTURE)) {
            return;
        }
        foreach ($m[0] as $k => $match) {
            $num = (int) $m[1][$k][0];
            $pos = $match[1] + strlen($match[0]);
            $p = new PdfLexer($this->data, $pos);
            try {
                $value = $p->parseValue();
            } catch (\Throwable) {
                continue;
            }
            $stream = null;
            $after = $p->pos;
            if (is_array($value) && preg_match('/\G\s*stream\r?\n/A', $this->data, $sm, 0, $after)) {
                $start = $after + strlen($sm[0]);
                $len = $value['Length'] ?? null;
                if ($len instanceof PdfRef) {
                    $len = $this->rawLength($len->num);
                }
                if (is_int($len) && $len > 0 && substr($this->data, $start + $len, 30) !== false
                    && preg_match('/^\s*endstream/', substr($this->data, $start + $len, 30))) {
                    $stream = substr($this->data, $start, $len);
                } else {
                    $end = strpos($this->data, 'endstream', $start);
                    $stream = $end === false ? '' : rtrim(substr($this->data, $start, $end - $start), "\r\n");
                }
            }
            $this->objects[$num] = [$value, $stream]; // sonraki tanım öncekini ezer (artımlı güncelleme)
        }
        // Nesne akışları
        foreach ($this->objects as [$value, $stream]) {
            if (is_array($value) && ($value['Type'] ?? null) === 'ObjStm' && $stream !== null) {
                $this->loadObjStm($value, $this->decode($value, $stream));
            }
        }
    }

    private function rawLength(int $num): ?int
    {
        if (preg_match('/(?<![0-9])'.$num.'\s+0\s+obj\s*(\d+)\s*endobj/', $this->data, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function loadObjStm(array $dict, string $body): void
    {
        $n = (int) ($dict['N'] ?? 0);
        $first = (int) ($dict['First'] ?? 0);
        $nums = preg_split('/\s+/', trim(substr($body, 0, $first)));
        for ($i = 0; $i + 1 < count($nums) && $i / 2 < $n; $i += 2) {
            $num = (int) $nums[$i];
            if (isset($this->objects[$num])) {
                continue;
            }
            try {
                $this->objects[$num] = [(new PdfLexer($body, $first + (int) $nums[$i + 1]))->parseValue(), null];
            } catch (\Throwable) {
            }
        }
    }

    private function resolve($v)
    {
        $guard = 0;
        while ($v instanceof PdfRef && $guard++ < 20) {
            $v = $this->objects[$v->num][0] ?? null;
        }
        return $v;
    }

    private function streamOf($ref): string
    {
        if (! $ref instanceof PdfRef || ! isset($this->objects[$ref->num])) {
            return '';
        }
        [$dict, $raw] = $this->objects[$ref->num];
        return $raw === null ? '' : $this->decode(is_array($dict) ? $dict : [], $raw);
    }

    private function decode(array $dict, string $raw): string
    {
        $filters = $this->resolve($dict['Filter'] ?? []);
        $filters = is_array($filters) ? $filters : [$filters];
        foreach ($filters as $f) {
            $f = $this->resolve($f);
            if ($f === 'FlateDecode' || $f === 'Fl') {
                $out = @gzuncompress($raw);
                if ($out === false) {
                    $out = @gzinflate(substr($raw, 2));
                }
                if ($out === false) {
                    return '';
                }
                $raw = $out;
            } elseif ($f !== null && $f !== '') {
                return ''; // görüntü vb. filtreler metin içermez
            }
        }
        return $raw;
    }

    // ------------------------------------------------------------------ sayfalar

    private function pages(): array
    {
        $root = null;
        foreach ($this->objects as [$v]) {
            if (is_array($v) && ($v['Type'] ?? null) === 'Catalog') {
                $root = $v;
            }
        }
        $pages = [];
        if ($root) {
            $this->walkPages($this->resolve($root['Pages'] ?? null), [], $pages, 0);
        }
        if (! $pages) { // katalog bulunamazsa: sırayla tüm sayfa nesneleri
            foreach ($this->objects as [$v]) {
                if (is_array($v) && ($v['Type'] ?? null) === 'Page') {
                    $pages[] = $v;
                }
            }
        }
        return $pages;
    }

    private function walkPages($node, array $inherited, array &$pages, int $depth): void
    {
        if (! is_array($node) || $depth > 30) {
            return;
        }
        if (isset($node['Resources'])) {
            $inherited['Resources'] = $node['Resources'];
        }
        if (($node['Type'] ?? null) === 'Page' || ! isset($node['Kids'])) {
            $pages[] = $node + $inherited;
            return;
        }
        foreach ((array) $this->resolve($node['Kids']) as $kid) {
            $this->walkPages($this->resolve($kid), $inherited, $pages, $depth + 1);
        }
    }

    private function pageText(array $page): string
    {
        $contents = $this->resolve($page['Contents'] ?? null);
        $refs = is_array($contents) && array_is_list($contents) ? $contents : [$page['Contents'] ?? null];
        $content = '';
        foreach ($refs as $ref) {
            $content .= $this->streamOf($ref)."\n";
        }
        $chunks = [];
        $this->runContent($content, (array) $this->resolve($page['Resources'] ?? []), [1, 0, 0, 1, 0, 0], $chunks, 0);
        return $this->layout($chunks);
    }

    // ------------------------------------------------------------------ içerik akışı

    private function runContent(string $content, array $resources, array $ctm, array &$chunks, int $depth): void
    {
        if ($depth > 5) {
            return;
        }
        $fonts = (array) $this->resolve($resources['Font'] ?? []);
        $xobjects = (array) $this->resolve($resources['XObject'] ?? []);
        $lex = new PdfLexer($content, 0);
        $stack = [];
        $gstack = [];
        $tm = $tlm = [1, 0, 0, 1, 0, 0];
        $font = null;
        $fs = 1;
        $tc = $tw = $tl = 0;
        $th = 1;

        while (true) {
            try {
                $tok = $lex->nextContentToken();
            } catch (\Throwable) {
                break;
            }
            if ($tok === null) {
                break;
            }
            if (! ($tok instanceof PdfOp)) {
                $stack[] = $tok;
                continue;
            }
            $op = $tok->op;
            $a = $stack;
            $stack = [];
            switch ($op) {
                case 'q': $gstack[] = $ctm; break;
                case 'Q': $ctm = array_pop($gstack) ?? $ctm; break;
                case 'cm': if (count($a) >= 6) { $ctm = self::mul(array_map('floatval', array_slice($a, -6)), $ctm); } break;
                case 'BT': $tm = $tlm = [1, 0, 0, 1, 0, 0]; break;
                case 'Tf':
                    $font = $this->font($fonts[$a[0] ?? ''] ?? null);
                    $fs = (float) ($a[1] ?? 1);
                    break;
                case 'Tc': $tc = (float) ($a[0] ?? 0); break;
                case 'Tw': $tw = (float) ($a[0] ?? 0); break;
                case 'Tz': $th = ((float) ($a[0] ?? 100)) / 100; break;
                case 'TL': $tl = (float) ($a[0] ?? 0); break;
                case 'Td': case 'TD':
                    if ($op === 'TD') { $tl = -(float) ($a[1] ?? 0); }
                    $tlm = self::mul([1, 0, 0, 1, (float) ($a[0] ?? 0), (float) ($a[1] ?? 0)], $tlm);
                    $tm = $tlm;
                    break;
                case 'Tm': if (count($a) >= 6) { $tm = $tlm = array_map('floatval', array_slice($a, -6)); } break;
                case 'T*': $tlm = self::mul([1, 0, 0, 1, 0, -$tl], $tlm); $tm = $tlm; break;
                case "'": case '"':
                    if ($op === '"') { $tw = (float) ($a[0] ?? 0); $tc = (float) ($a[1] ?? 0); }
                    $tlm = self::mul([1, 0, 0, 1, 0, -$tl], $tlm);
                    $tm = $tlm;
                    $this->show([end($a)], $font, $fs, $tc, $tw, $th, $tm, $ctm, $chunks);
                    break;
                case 'Tj': $this->show([end($a)], $font, $fs, $tc, $tw, $th, $tm, $ctm, $chunks); break;
                case 'TJ': $this->show(is_array(end($a)) ? end($a) : [], $font, $fs, $tc, $tw, $th, $tm, $ctm, $chunks); break;
                case 'Do':
                    $xo = $xobjects[$a[0] ?? ''] ?? null;
                    $dict = $this->resolve($xo);
                    if (is_array($dict) && ($dict['Subtype'] ?? null) === 'Form') {
                        $matrix = isset($dict['Matrix']) ? array_map('floatval', (array) $this->resolve($dict['Matrix'])) : [1, 0, 0, 1, 0, 0];
                        $res = (array) $this->resolve($dict['Resources'] ?? []) ?: $resources;
                        $this->runContent($this->streamOf($xo), $res, self::mul($matrix, $ctm), $chunks, $depth + 1);
                    }
                    break;
            }
        }
    }

    /** Metin parçalarını gösterir ve metin matrisini yazı genişliği kadar ilerletir. */
    private function show(array $items, ?array $font, float $fs, float $tc, float $tw, float $th, array &$tm, array $ctm, array &$chunks): void
    {
        if (! $font) {
            $font = ['bytes' => 1, 'map' => [], 'widths' => [], 'dw' => 500, 'simple' => true];
        }
        $text = '';
        $start = self::mul($tm, $ctm);
        foreach ($items as $item) {
            if (is_int($item) || is_float($item)) {
                $tx = -$item / 1000 * $fs * $th;
                if ($item < -180) {
                    $text .= ' '; // geniş TJ boşluğu kelime arası sayılır
                }
                $tm = self::mul([1, 0, 0, 1, $tx, 0], $tm);
                continue;
            }
            if (! ($item instanceof PdfString)) {
                continue;
            }
            $bytes = $item->bytes;
            $step = $font['bytes'];
            for ($i = 0; $i + $step <= strlen($bytes); $i += $step) {
                $code = $step === 2 ? (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]) : ord($bytes[$i]);
                $ch = $font['map'][$code] ?? ($font['simple'] ? self::cp1254($code) : '');
                $text .= $ch;
                $w = ($font['widths'][$code] ?? $font['dw']) / 1000;
                $tx = ($w * $fs + $tc + ($step === 1 && $code === 32 ? $tw : 0)) * $th;
                $tm = self::mul([1, 0, 0, 1, $tx, 0], $tm);
            }
        }
        if (trim($text) === '') {
            return;
        }
        $end = self::mul($tm, $ctm);
        $size = abs($fs * sqrt(abs($start[0] * $start[3] - $start[1] * $start[2]))) ?: 1;
        $chunks[] = ['x' => $start[4], 'y' => $start[5], 'x2' => $end[4], 'size' => $size, 'text' => $text];
    }

    /** Parçaları y'ye göre satırlara, x'e göre sıraya dizer. */
    private function layout(array $chunks): string
    {
        usort($chunks, fn ($a, $b) => $b['y'] <=> $a['y']);
        $lines = [];
        foreach ($chunks as $c) {
            $tol = max(1.5, $c['size'] * 0.45);
            $last = count($lines) - 1;
            if ($last >= 0 && abs($lines[$last]['y'] - $c['y']) <= $tol) {
                $lines[$last]['items'][] = $c;
            } else {
                $lines[] = ['y' => $c['y'], 'items' => [$c]];
            }
        }
        $out = [];
        foreach ($lines as $line) {
            usort($line['items'], fn ($a, $b) => $a['x'] <=> $b['x']);
            $s = '';
            $prevEnd = null;
            foreach ($line['items'] as $c) {
                if ($prevEnd !== null && $c['x'] - $prevEnd > $c['size'] * 0.2 && ! str_ends_with($s, ' ')) {
                    $s .= ' ';
                }
                $s .= $c['text'];
                $prevEnd = max($prevEnd ?? $c['x2'], $c['x2']);
            }
            $out[] = rtrim(preg_replace('/[ \t]+/u', ' ', $s));
        }
        return implode("\n", $out);
    }

    // ------------------------------------------------------------------ yazı tipleri

    private function font($ref): ?array
    {
        $key = $ref instanceof PdfRef ? $ref->num : null;
        if ($key !== null && isset($this->fontCache[$key])) {
            return $this->fontCache[$key];
        }
        $f = $this->resolve($ref);
        if (! is_array($f)) {
            return null;
        }
        $type0 = ($f['Subtype'] ?? null) === 'Type0';
        $font = ['bytes' => $type0 ? 2 : 1, 'map' => [], 'widths' => [], 'dw' => $type0 ? 1000 : 500, 'simple' => ! $type0];

        if (isset($f['ToUnicode'])) {
            [$map, $bytes] = $this->cmap($this->streamOf($f['ToUnicode']));
            $font['map'] = $map;
            if ($bytes) {
                $font['bytes'] = $bytes;
            }
        }
        if ($type0) {
            $desc = $this->resolve(((array) $this->resolve($f['DescendantFonts'] ?? []))[0] ?? null);
            if (is_array($desc)) {
                $font['dw'] = (float) ($this->resolve($desc['DW'] ?? 1000));
                $w = (array) $this->resolve($desc['W'] ?? []);
                for ($i = 0; $i < count($w);) {
                    $c = (int) $this->resolve($w[$i]);
                    $next = $this->resolve($w[$i + 1] ?? null);
                    if (is_array($next)) {
                        foreach ($next as $j => $width) {
                            $font['widths'][$c + $j] = (float) $this->resolve($width);
                        }
                        $i += 2;
                    } else {
                        $last = (int) $next;
                        $width = (float) $this->resolve($w[$i + 2] ?? 0);
                        for ($k = $c; $k <= $last && $k - $c < 65536; $k++) {
                            $font['widths'][$k] = $width;
                        }
                        $i += 3;
                    }
                }
            }
        } else {
            $first = (int) $this->resolve($f['FirstChar'] ?? 0);
            foreach ((array) $this->resolve($f['Widths'] ?? []) as $j => $width) {
                $font['widths'][$first + $j] = (float) $this->resolve($width);
            }
            $enc = $this->resolve($f['Encoding'] ?? null);
            if (is_array($enc) && isset($enc['Differences'])) {
                $code = 0;
                foreach ((array) $this->resolve($enc['Differences']) as $d) {
                    if (is_int($d)) {
                        $code = $d;
                    } elseif (is_string($d)) {
                        if (! isset($font['map'][$code]) && ($u = self::glyphName($d)) !== null) {
                            $font['map'][$code] = $u;
                        }
                        $code++;
                    }
                }
            }
        }
        if ($key !== null) {
            $this->fontCache[$key] = $font;
        }
        return $font;
    }

    /** ToUnicode CMap → [kod => metin, kod bayt uzunluğu] */
    private function cmap(string $s): array
    {
        $map = [];
        $bytes = null;
        if (preg_match('/begincodespacerange\s*<([0-9A-Fa-f]+)>/', $s, $m)) {
            $bytes = max(1, intdiv(strlen($m[1]), 2));
        }
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $s, $blocks)) {
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $b, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $p) {
                    $map[hexdec($p[1])] = self::utf16($p[2]);
                }
            }
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $s, $blocks)) {
            foreach ($blocks[1] as $b) {
                preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $b, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $r) {
                    $lo = hexdec($r[1]);
                    $hi = min(hexdec($r[2]), $lo + 65535);
                    if ($r[3][0] === '[') {
                        preg_match_all('/<([0-9A-Fa-f]*)>/', $r[3], $dst);
                        foreach ($dst[1] as $j => $hex) {
                            $map[$lo + $j] = self::utf16($hex);
                        }
                    } else {
                        $hex = trim($r[3], '<>');
                        $base = hexdec(substr($hex, -4));
                        $prefix = substr($hex, 0, -4);
                        for ($c = $lo; $c <= $hi; $c++) {
                            $map[$c] = self::utf16($prefix.str_pad(dechex($base + $c - $lo), 4, '0', STR_PAD_LEFT));
                        }
                    }
                }
            }
        }
        return [$map, $bytes];
    }

    private static function utf16(string $hex): string
    {
        if ($hex === '' || strlen($hex) % 2) {
            return '';
        }
        $s = @mb_convert_encoding(hex2bin($hex), 'UTF-8', 'UTF-16BE');
        return $s === false ? '' : $s;
    }

    private static function cp1254(int $code): string
    {
        if ($code < 32) {
            return '';
        }
        $s = @mb_convert_encoding(chr($code), 'UTF-8', 'Windows-1254');
        return $s === false ? '' : $s;
    }

    private static function glyphName(string $name): ?string
    {
        static $names = [
            'space' => ' ', 'Idotaccent' => 'İ', 'dotlessi' => 'ı', 'Gbreve' => 'Ğ', 'gbreve' => 'ğ', 'Scedilla' => 'Ş',
            'scedilla' => 'ş', 'Ccedilla' => 'Ç', 'ccedilla' => 'ç', 'Odieresis' => 'Ö', 'odieresis' => 'ö',
            'Udieresis' => 'Ü', 'udieresis' => 'ü', 'period' => '.', 'comma' => ',', 'colon' => ':', 'hyphen' => '-',
            'slash' => '/', 'percent' => '%', 'parenleft' => '(', 'parenright' => ')',
        ];
        if (isset($names[$name])) {
            return $names[$name];
        }
        if (preg_match('/^uni([0-9A-Fa-f]{4})$/', $name, $m)) {
            return self::utf16($m[1]);
        }
        return strlen($name) === 1 ? $name : null;
    }

    private static function mul(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2], $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }
}

final class PdfRef
{
    public function __construct(public int $num) {}
}

final class PdfString
{
    public function __construct(public string $bytes) {}
}

final class PdfOp
{
    public function __construct(public string $op) {}
}

/** PDF nesne ve içerik akışı ayrıştırıcısı. İsimler string, diziler/sözlükler PHP dizisi olarak döner. */
final class PdfLexer
{
    public function __construct(private string $s, public int $pos) {}

    private function skip(): void
    {
        $n = strlen($this->s);
        while ($this->pos < $n) {
            $c = $this->s[$this->pos];
            if ($c === '%') {
                while ($this->pos < $n && $this->s[$this->pos] !== "\n" && $this->s[$this->pos] !== "\r") {
                    $this->pos++;
                }
            } elseif (strpos(" \t\r\n\f\0", $c) !== false) {
                $this->pos++;
            } else {
                return;
            }
        }
    }

    public function parseValue()
    {
        $this->skip();
        $s = $this->s;
        $c = $s[$this->pos] ?? '';
        if ($c === '') {
            throw new \RuntimeException('eof');
        }
        if ($c === '<' && ($s[$this->pos + 1] ?? '') === '<') {
            $this->pos += 2;
            $d = [];
            while (true) {
                $this->skip();
                if (substr($s, $this->pos, 2) === '>>') {
                    $this->pos += 2;
                    return $d;
                }
                $key = $this->parseValue();
                if (! is_string($key)) {
                    throw new \RuntimeException('dict key');
                }
                $d[$key] = $this->parseValue();
            }
        }
        if ($c === '[') {
            $this->pos++;
            $arr = [];
            while (true) {
                $this->skip();
                if (($s[$this->pos] ?? ']') === ']') {
                    $this->pos++;
                    return $arr;
                }
                $arr[] = $this->parseValue();
            }
        }
        if ($c === '/') {
            return $this->name();
        }
        if ($c === '(') {
            return $this->literal();
        }
        if ($c === '<') {
            return $this->hex();
        }
        if (preg_match('/\G([+-]?(?:\d+\.?\d*|\.\d+))/A', $s, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            if (preg_match('/\G\s+(\d+)\s+R(?![A-Za-z])/A', $s, $r, 0, $this->pos) && ctype_digit($m[1])) {
                $this->pos += strlen($r[0]);
                return new PdfRef((int) $m[1]);
            }
            return str_contains($m[1], '.') ? (float) $m[1] : (int) $m[1];
        }
        if (preg_match('/\G(true|false|null)/A', $s, $m, 0, $this->pos)) {
            $this->pos += strlen($m[0]);
            return $m[1] === 'true' ? true : ($m[1] === 'false' ? false : null);
        }
        throw new \RuntimeException('unexpected '.$c);
    }

    /** İçerik akışı belirteci: değer veya PdfOp. Satır içi görüntüler atlanır. */
    public function nextContentToken()
    {
        $this->skip();
        if ($this->pos >= strlen($this->s)) {
            return null;
        }
        $c = $this->s[$this->pos];
        if ($c === '<' || $c === '[' || $c === '/' || $c === '(' || preg_match('/[0-9+\-.]/', $c)) {
            return $this->parseValue();
        }
        if ($c === ']' || $c === '>' || $c === ')' || $c === '{' || $c === '}') {
            $this->pos++;
            return $this->nextContentToken();
        }
        preg_match('/\G[^\s\/\[\]<>()%{}]+/A', $this->s, $m, 0, $this->pos);
        $op = $m[0] ?? $c;
        $this->pos += max(1, strlen($op));
        if ($op === 'BI') {
            $end = preg_match('/\sEI(?=\s|$)/', $this->s, $em, PREG_OFFSET_CAPTURE, $this->pos) ? $em[0][1] + 3 : strlen($this->s);
            $this->pos = $end;
            return $this->nextContentToken();
        }
        if ($op === 'true' || $op === 'false' || $op === 'null') {
            return $op === 'true' ? true : ($op === 'false' ? false : null);
        }
        return new PdfOp($op);
    }

    private function name(): string
    {
        preg_match('/\G\/([^\s\/\[\]<>()%{}]*)/A', $this->s, $m, 0, $this->pos);
        $this->pos += strlen($m[0]);
        return preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($x) => chr(hexdec($x[1])), $m[1]);
    }

    private function hex(): PdfString
    {
        $end = strpos($this->s, '>', $this->pos);
        $end = $end === false ? strlen($this->s) : $end;
        $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($this->s, $this->pos + 1, $end - $this->pos - 1));
        $this->pos = $end + 1;
        if (strlen($hex) % 2) {
            $hex .= '0';
        }
        return new PdfString(hex2bin($hex) ?: '');
    }

    private function literal(): PdfString
    {
        $s = $this->s;
        $n = strlen($s);
        $this->pos++;
        $depth = 1;
        $out = '';
        while ($this->pos < $n) {
            $c = $s[$this->pos++];
            if ($c === '\\') {
                $e = $s[$this->pos++] ?? '';
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\'];
                if (isset($map[$e])) {
                    $out .= $map[$e];
                } elseif (ctype_digit($e) && $e < '8') {
                    $oct = $e;
                    while (strlen($oct) < 3 && isset($s[$this->pos]) && $s[$this->pos] >= '0' && $s[$this->pos] <= '7') {
                        $oct .= $s[$this->pos++];
                    }
                    $out .= chr(octdec($oct) & 0xFF);
                } elseif ($e === "\r") {
                    if (($s[$this->pos] ?? '') === "\n") {
                        $this->pos++;
                    }
                } elseif ($e !== "\n") {
                    $out .= $e;
                }
            } elseif ($c === '(') {
                $depth++;
                $out .= $c;
            } elseif ($c === ')') {
                if (--$depth === 0) {
                    break;
                }
                $out .= $c;
            } else {
                $out .= $c;
            }
        }
        return new PdfString($out);
    }
}
