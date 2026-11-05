<?php
declare(strict_types=1);
namespace BaurPDF\Font;

use BaurPDF\Exception\BaurPdfException;

/** Парсер TrueType (glyf) + метрики + сабсеттинг для встраивания в PDF (CIDFontType2 / Identity-H). */
final class TrueTypeFont
{
    public string $name;
    public int $unitsPerEm;
    public int $ascent; public int $descent; public int $capHeight; public int $lineGap;

    /** line-height: normal как доля кегля (ascent + descent + lineGap), в пределах 1.0–2.0. */
    public function normalLineHeight(): float
    {
        $r = ($this->ascent - $this->descent + max(0, $this->lineGap)) / max(1, $this->unitsPerEm);
        return $r >= 0.8 && $r <= 3.0 ? $r : 1.2; // высокие скрипты (Noto Sans Arabic ≈ 2.11 em) — как в Chromium
    }

    /**
     * line-height: normal в pt для кегля $size, как в Chromium: ascent, descent и lineGap
     * округляются до целых CSS-пикселей (1px = 0.75pt) по отдельности.
     */
    public function normalLineHeightAt(float $size): float
    {
        $r = $this->normalLineHeight();
        $upm = max(1, $this->unitsPerEm);
        if ($r === 1.2 || $size <= 0) return $size * $r;
        $px = $size / 0.75;
        $h = round($this->ascent / $upm * $px) + round(-$this->descent / $upm * $px) + round(max(0, $this->lineGap) / $upm * $px);
        return $h > 0 ? $h * 0.75 : $size * $r;
    }
    public array $bbox;
    public float $italicAngle = 0;
    public int $flags = 32;
    /** Жирное начертание (head.macStyle bit 0 или OS/2 usWeightClass ≥ 600). */
    public bool $bold = false;
    /** OpenType с CFF-контурами (встраивается как FontFile3/CIDFontType0C) */
    public bool $isCff = false;
    /** @var array<int,int> codepoint => glyph id */
    public array $cmap = [];
    /** @var int[] glyph advance widths */
    public array $widths = [];
    /** @var array<string, array{0:int,1:int}> */
    private array $tables = [];
    private string $data;
    private int $numGlyphs;
    private int $indexToLoc;
    /** @var array<int,float> кэш ширин по codepoint (1/1000 em) */
    private array $wcache = [];

    public function __construct(public string $path)
    {
        [$file, $idx] = self::split($path);
        $d = @file_get_contents($file);
        if ($d === false) throw new BaurPdfException("Cannot read font $path");
        $this->data = $d;
        $base = 0;
        if (str_starts_with($d, 'ttcf')) { // TrueType Collection: смещения таблиц — от начала файла
            if ($idx >= $this->u32(8)) throw new BaurPdfException("Font index $idx out of range in collection: $file");
            $base = $this->u32(12 + 4 * $idx);
        }
        $n = $this->u16($base + 4);
        for ($i = 0; $i < $n; $i++) {
            $o = $base + 12 + $i * 16;
            $this->tables[substr($d, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)];
        }
        $this->isCff = !isset($this->tables['glyf']) && isset($this->tables['CFF ']);
        if (!isset($this->tables['glyf']) && !$this->isCff) throw new BaurPdfException("Unsupported font outlines (need glyf or CFF): $path");
        foreach (['head', 'hhea', 'maxp', 'hmtx'] as $t) if (!isset($this->tables[$t])) throw new BaurPdfException("Font table $t missing: $path");
        $h = $this->tables['head'][0];
        $this->unitsPerEm = $this->u16($h + 18);
        $this->bbox = [$this->s16($h + 36), $this->s16($h + 38), $this->s16($h + 40), $this->s16($h + 42)];
        $this->indexToLoc = $this->s16($h + 50);
        $this->bold = ($this->u16($h + 44) & 1) === 1;
        $hh = $this->tables['hhea'][0];
        $this->ascent = $this->s16($hh + 4); $this->descent = $this->s16($hh + 6); $this->lineGap = $this->s16($hh + 8);
        $numHM = $this->u16($hh + 34);
        $this->numGlyphs = $this->u16($this->tables['maxp'][0] + 4);
        if (isset($this->tables['OS/2'])) {
            $os = $this->tables['OS/2'][0];
            $ver = $this->u16($os);
            if ($this->u16($os + 4) >= 600) $this->bold = true;
            $ta = $this->s16($os + 68); $td = $this->s16($os + 70);
            // как Chromium/FreeType: метрики hhea; typo — только при USE_TYPO_METRICS (fsSelection bit 7) или пустом hhea
            $useTypo = ($this->u16($os + 62) & 0x80) !== 0;
            if ($ta > 0 && ($useTypo || $this->ascent <= 0)) { $this->ascent = $ta; $this->descent = $td; $this->lineGap = $this->s16($os + 72); }
            $this->capHeight = $ver >= 2 ? $this->s16($os + 88) : (int)($this->ascent * 0.7);
        } else $this->capHeight = (int)($this->ascent * 0.7);
        if (isset($this->tables['post'])) {
            $p = $this->tables['post'][0];
            $this->italicAngle = $this->s16($p + 4) + $this->u16($p + 6) / 65536;
            if ($this->u32($p + 12)) $this->flags |= 1;
        }
        if ($this->italicAngle != 0) $this->flags |= 64;
        $hm = $this->tables['hmtx'][0]; $last = 0;
        for ($i = 0; $i < $this->numGlyphs; $i++) {
            if ($i < $numHM) $last = $this->u16($hm + $i * 4);
            $this->widths[$i] = $last;
        }
        $this->parseCmap();
        $this->name = $this->parseName();
        if (isset($this->cmap[0x0627]) && !isset($this->cmap[0xFE8E]) && isset($this->tables['GSUB'])) {
            $this->covMemo = [];
            try { $this->synthArabicForms(); } catch (\Throwable) {} // битый GSUB — без синтеза форм
            $this->covMemo = null;
        }
    }

    private function u16(int $o): int { $v = unpack('n', $this->data, $o); return is_array($v) ? (int)$v[1] : 0; }
    private function s16(int $o): int { $v = $this->u16($o); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $o): int { $v = unpack('N', $this->data, $o); return is_array($v) ? (int)$v[1] : 0; }

    private function parseCmap(): void
    {
        $c = $this->tables['cmap'][0]; $n = $this->u16($c + 2); $best = null; $bestScore = -1;
        for ($i = 0; $i < $n; $i++) {
            $pid = $this->u16($c + 4 + $i * 8); $eid = $this->u16($c + 6 + $i * 8); $off = $c + $this->u32($c + 8 + $i * 8);
            $fmt = $this->u16($off);
            $score = ($fmt === 12 ? 10 : ($fmt === 4 ? 5 : 0)) + (($pid === 3 && ($eid === 1 || $eid === 10)) || $pid === 0 ? 1 : 0);
            if (($fmt === 4 || $fmt === 12) && $score > $bestScore) { $best = $off; $bestScore = $score; }
        }
        if ($best === null) throw new BaurPdfException('No unicode cmap');
        if ($this->u16($best) === 12) {
            $groups = $this->u32($best + 12);
            for ($g = 0; $g < $groups; $g++) {
                $o = $best + 16 + $g * 12; $s = $this->u32($o); $e = $this->u32($o + 4); $gid = $this->u32($o + 8);
                for ($cp = $s; $cp <= $e && $cp < 0x30000; $cp++) $this->cmap[$cp] = $gid + $cp - $s;
            }
            return;
        }
        $seg = $this->u16($best + 6) >> 1;
        $ends = $best + 14; $starts = $ends + $seg * 2 + 2; $deltas = $starts + $seg * 2; $ranges = $deltas + $seg * 2;
        for ($i = 0; $i < $seg; $i++) {
            $e = $this->u16($ends + $i * 2); $s = $this->u16($starts + $i * 2); $d = $this->s16($deltas + $i * 2); $ro = $this->u16($ranges + $i * 2);
            for ($cp = $s; $cp <= $e && $cp !== 0xFFFF; $cp++) {
                if ($ro === 0) $g = ($cp + $d) & 0xFFFF;
                else { $gi = $ranges + $i * 2 + $ro + ($cp - $s) * 2; $g = $this->u16($gi); if ($g) $g = ($g + $d) & 0xFFFF; }
                if ($g) $this->cmap[$cp] = $g;
            }
        }
    }

    private function parseName(): string
    {
        $base = (string)preg_replace('/[^A-Za-z0-9\-]/', '', pathinfo($this->path, PATHINFO_FILENAME));
        if (!isset($this->tables['name'])) return $base;
        $o = $this->tables['name'][0]; $n = $this->u16($o + 2); $so = $o + $this->u16($o + 4);
        for ($i = 0; $i < $n; $i++) {
            $r = $o + 6 + $i * 12;
            if ($this->u16($r + 6) === 6) {
                $s = substr($this->data, $so + $this->u16($r + 10), $this->u16($r + 8));
                if ($this->u16($r) === 3 || $this->u16($r) === 0) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-16BE');
                $s = (string)preg_replace('/[^A-Za-z0-9\-]/', '', (string)$s);
                if ($s !== '') return $s;
            }
        }
        return $base;
    }

    public function glyph(int $cp): int { return $this->cmap[$cp] ?? 0; }
    public function hasChar(int $cp): bool { return isset($this->cmap[$cp]); }

    /** Ширина символа в 1/1000 em. */
    public function charWidth(int $cp): float
    {
        return $this->wcache[$cp] ??= ($this->widths[$this->cmap[$cp] ?? 0] ?? 0) * 1000 / $this->unitsPerEm;
    }

    public function glyphWidth(int $gid): float { return ($this->widths[$gid] ?? 0) * 1000 / $this->unitsPerEm; }

    /** 'fonts.ttc#2' → ['fonts.ttc', 2]; без индекса — 0. */
    public static function split(string $spec): array
    {
        return preg_match('/^(.+\.(?:ttc|otc))#(\d+)$/i', $spec, $m) ? [$m[1], (int)$m[2]] : [$spec, 0];
    }

    /** Проверить файл шрифта без полной загрузки: null — годится, иначе причина. */
    public static function probe(string $spec): ?string
    {
        [$file, $idx] = self::split($spec);
        if (!is_file($file) || !is_readable($file)) return "Font not found: $spec";
        $h = @fopen($file, 'rb');
        if (!$h) return "Font not found: $spec";
        try {
            $hd = (string)fread($h, 12);
            if (str_starts_with($hd, 'ttcf')) {
                $n = (int)(unpack('N', $hd, 8) ?: [1 => 0])[1];
                if ($idx >= $n) return "Font index $idx out of range (collection has $n fonts): $file";
                fseek($h, 12 + 4 * $idx);
                fseek($h, (int)(unpack('N', (string)fread($h, 4)) ?: [1 => 0])[1]);
                $hd = (string)fread($h, 12);
            }
            $v = substr($hd, 0, 4);
            if ($v !== "\x00\x01\x00\x00" && $v !== 'true' && $v !== 'OTTO') return "Unsupported font format (need TrueType or OpenType/CFF): $spec";
            $nt = strlen($hd) >= 6 ? (int)(unpack('n', $hd, 4) ?: [1 => 0])[1] : 0;
            $dir = $nt > 0 ? (string)fread($h, 16 * min($nt, 200)) : '';
            for ($i = 0; $i + 16 <= strlen($dir); $i += 16) if (in_array(substr($dir, $i, 4), ['glyf', 'CFF '], true)) return null;
            return "Unsupported font format (need glyf or CFF outlines): $spec";
        } finally { fclose($h); }
    }

    // ---------- кернинг (GPOS PairPos 'kern', иначе таблица kern формата 0) ----------
    /** @var array<int,int>|null пары (left<<16|right) => сдвиг в единицах шрифта (формат 1 / kern) */
    private ?array $kernPairs = null;
    /** @var list<array{cov:array<int,int>,c1:array<int,int>,c2:array<int,int>,m:array<int,array<int,int>>}> PairPos формата 2 */
    private array $kernClasses = [];
    /** @var array<int,float> кэш kernCp */
    private array $kcache = [];

    /** Кернинг пары символов в 1/1000 em (обычно отрицательный). */
    public function kernCp(int $a, int $b): float
    {
        $k = ($a << 21) | $b;
        if (isset($this->kcache[$k])) return $this->kcache[$k];
        $l = $this->cmap[$a] ?? 0; $r = $this->cmap[$b] ?? 0;
        $v = ($l && $r) ? $this->kern($l, $r) * 1000 / $this->unitsPerEm : 0.0;
        if (count($this->kcache) < 200000) $this->kcache[$k] = $v;
        return $v;
    }

    public function hasKerning(): bool
    {
        if ($this->kernPairs !== null) return $this->kernPairs || $this->kernClasses;
        return isset($this->tables['GPOS']) || isset($this->tables['kern']); // дешёвая проверка; разбор — лениво в kern()
    }

    /** Кернинг пары глифов в единицах шрифта. */
    public function kern(int $l, int $r): int
    {
        $this->loadKern();
        $k = ($l << 16) | $r;
        if (isset($this->kernPairs[$k])) return $this->kernPairs[$k];
        foreach ($this->kernClasses as $t) {
            if (!isset($t['cov'][$l])) continue;
            return $t['m'][$t['c1'][$l] ?? 0][$t['c2'][$r] ?? 0] ?? 0;
        }
        return 0;
    }

    /** @var array<string,array{p:array<int,int>,c:list<array{cov:array<int,int>,c1:array<int,int>,c2:array<int,int>,m:array<int,array<int,int>>}>,k:array<int,float>}> кэш кернинга на процесс */
    private static array $kernShared = [];

    private function loadKern(): void
    {
        if ($this->kernPairs !== null) return;
        $tb = $this->tables['GPOS'] ?? $this->tables['kern'] ?? [0, 0];
        $key = $this->path . '|' . strlen($this->data) . '|' . $tb[0] . '|' . crc32(substr($this->data, $tb[0], $tb[1]));
        if (isset(self::$kernShared[$key])) {
            $e = &self::$kernShared[$key];
            $this->kernPairs = $e['p']; $this->kernClasses = $e['c']; $this->kcache = &$e['k'];
            return;
        }
        $this->kernPairs = [];
        try {
            if (!(isset($this->tables['GPOS']) && $this->gposKern($this->tables['GPOS'][0])) && isset($this->tables['kern'])) $this->legacyKern($this->tables['kern'][0]);
        } catch (\Throwable) { $this->kernPairs = []; $this->kernClasses = []; } // битая таблица — без кернинга
        if (count(self::$kernShared) >= 32) self::$kernShared = [];
        self::$kernShared[$key] = ['p' => $this->kernPairs ?? [], 'c' => $this->kernClasses, 'k' => $this->kcache];
        $this->kcache = &self::$kernShared[$key]['k'];
    }

    private function gposKern(int $g): bool
    {
        $fl = $g + $this->u16($g + 6); $ll = $g + $this->u16($g + 8);
        $lookups = [];
        for ($i = 0, $n = $this->u16($fl); $i < $n; $i++) {
            if (substr($this->data, $fl + 2 + $i * 6, 4) !== 'kern') continue;
            $ft = $fl + $this->u16($fl + 2 + $i * 6 + 4);
            for ($j = 0, $m = $this->u16($ft + 2); $j < $m; $j++) $lookups[$this->u16($ft + 4 + $j * 2)] = true;
        }
        if (!$lookups) return false;
        ksort($lookups);
        $nl = $this->u16($ll);
        foreach (array_keys($lookups) as $li) {
            if ($li >= $nl) continue;
            $lt = $ll + $this->u16($ll + 2 + $li * 2);
            $type = $this->u16($lt);
            for ($j = 0, $m = $this->u16($lt + 4); $j < $m; $j++) {
                $st = $lt + $this->u16($lt + 6 + $j * 2);
                $t = $type;
                if ($t === 9) { $t = $this->u16($st + 2); $st += $this->u32($st + 4); }
                if ($t === 2) $this->pairPos($st);
            }
        }
        return true;
    }

    /** @var list<array{0:array<int,int>,1:array<int,int>,2:int,3:int,4:int}>|null GPOS mark-to-base (тип 4): [markCov, baseCov, classCount, markArray, baseArray] */
    private ?array $markSubs = null;
    /** @var list<array{0:array<int,int>,1:array<int,int>,2:int,3:int,4:int}> GPOS mark-to-mark (тип 6, feature mkmk) */
    private array $mkmkSubs = [];
    /** @var array<int,array{0:int,1:int}|null> */
    private array $markMemo = [];

    /**
     * GPOS mark-to-base (feature mark): смещение начала диакритики относительно начала базы, в единицах шрифта.
     * @return array{0:int,1:int}|null
     */
    public function markAnchor(int $base, int $mark, bool $onMark = false): ?array
    {
        $k = ($base << 16) | $mark | ($onMark ? 1 << 40 : 0);
        if (array_key_exists($k, $this->markMemo)) return $this->markMemo[$k];
        if ($this->markSubs === null) {
            $this->markSubs = []; $this->mkmkSubs = [];
            try { if (isset($this->tables['GPOS'])) { $this->loadMarks($this->tables['GPOS'][0]); $this->loadMarks($this->tables['GPOS'][0], 'mkmk', 6); } } catch (\Throwable) { $this->markSubs = []; $this->mkmkSubs = []; }
        }
        $r = null;
        foreach (($onMark ? $this->mkmkSubs : $this->markSubs) ?? [] as [$mc, $bc, $nc, $ma, $ba]) {
            if (!isset($mc[$mark], $bc[$base])) continue;
            $mr = $ma + 2 + $mc[$mark] * 4; $cls = $this->u16($mr);
            if ($cls >= $nc) break;
            $mAnc = $ma + $this->u16($mr + 2);
            $bo = $this->u16($ba + 2 + ($bc[$base] * $nc + $cls) * 2);
            if (!$bo) continue;
            $bAnc = $ba + $bo;
            $r = [$this->s16($bAnc + 2) - $this->s16($mAnc + 2), $this->s16($bAnc + 4) - $this->s16($mAnc + 4)];
            break;
        }
        if (count($this->markMemo) < 20000) $this->markMemo[$k] = $r;
        return $r;
    }

    public function hasMarks(): bool { return isset($this->tables['GPOS']); }

    public function isMarkGlyph(int $g): bool
    {
        if ($this->markSubs === null) $this->markAnchor(0, 0);
        foreach ($this->markSubs ?? [] as $sub) if (isset($sub[0][$g])) return true;
        return false;
    }

    private function loadMarks(int $g, string $tag = 'mark', int $want = 4): void
    {
        $fl = $g + $this->u16($g + 6); $ll = $g + $this->u16($g + 8);
        $lookups = [];
        for ($i = 0, $n = $this->u16($fl); $i < $n; $i++) {
            if (substr($this->data, $fl + 2 + $i * 6, 4) !== $tag) continue;
            $ft = $fl + $this->u16($fl + 2 + $i * 6 + 4);
            for ($j = 0, $m = $this->u16($ft + 2); $j < $m; $j++) $lookups[$this->u16($ft + 4 + $j * 2)] = true;
        }
        ksort($lookups); $nl = $this->u16($ll);
        foreach (array_keys($lookups) as $li) {
            if ($li >= $nl) continue;
            $lt = $ll + $this->u16($ll + 2 + $li * 2); $type = $this->u16($lt);
            for ($j = 0, $m = $this->u16($lt + 4); $j < $m && $j < 64; $j++) {
                $st = $lt + $this->u16($lt + 6 + $j * 2); $t = $type;
                if ($t === 9) { $t = $this->u16($st + 2); $st += $this->u32($st + 4); }
                if ($t !== $want || $this->u16($st) !== 1) continue;
                if ($want === 6) $this->mkmkSubs[] = [$this->coverage($st + $this->u16($st + 2)), $this->coverage($st + $this->u16($st + 4)), $this->u16($st + 6), $st + $this->u16($st + 8), $st + $this->u16($st + 10)];
                else $this->markSubs[] = [$this->coverage($st + $this->u16($st + 2)), $this->coverage($st + $this->u16($st + 4)), $this->u16($st + 6), $st + $this->u16($st + 8), $st + $this->u16($st + 10)];
            }
        }
    }

    private static function vrSize(int $vf): int { $n = 0; for ($b = $vf & 0xFF; $b; $b >>= 1) $n += $b & 1; return $n * 2; }
    /** Смещение XAdvance внутри ValueRecord или -1. */
    private static function xAdvOff(int $vf): int { return ($vf & 4) ? (($vf & 1) ? 2 : 0) + (($vf & 2) ? 2 : 0) : -1; }

    private function pairPos(int $st): void
    {
        $fmt = $this->u16($st); $vf1 = $this->u16($st + 4); $vf2 = $this->u16($st + 6);
        $xo = self::xAdvOff($vf1); $s1 = self::vrSize($vf1); $s2 = self::vrSize($vf2);
        $cov = $this->coverage($st + $this->u16($st + 2));
        if ($fmt === 1) {
            if ($xo < 0) return;
            $first = array_flip($cov); // index => gid
            for ($i = 0, $n = $this->u16($st + 8); $i < $n; $i++) {
                if (!isset($first[$i])) continue;
                $ps = $st + $this->u16($st + 10 + $i * 2); $l = $first[$i] << 16;
                for ($j = 0, $m = $this->u16($ps); $j < $m; $j++) {
                    $rec = $ps + 2 + $j * (2 + $s1 + $s2);
                    $k = $l | $this->u16($rec);
                    if (!isset($this->kernPairs[$k])) { $v = $this->s16($rec + 2 + $xo); if ($v) $this->kernPairs[$k] = $v; }
                }
            }
        } elseif ($fmt === 2) {
            $c1 = $this->classDef($st + $this->u16($st + 8)); $c2 = $this->classDef($st + $this->u16($st + 10));
            $n1 = $this->u16($st + 12); $n2 = $this->u16($st + 14);
            $mx = [];
            if ($xo >= 0) for ($a = 0; $a < $n1; $a++) for ($b = 0; $b < $n2; $b++) {
                $v = $this->s16($st + 16 + ($a * $n2 + $b) * ($s1 + $s2) + $xo);
                if ($v) $mx[$a][$b] = $v;
            }
            $this->kernClasses[] = ['cov' => $cov, 'c1' => $c1, 'c2' => $c2, 'm' => $mx];
        }
    }

    /** @return array<int,int> gid => coverage index */
    /** @var array<int,array<int,int>>|null мемо coverage на время синтеза форм */
    private ?array $covMemo = null;

    private function coverage(int $o): array
    {
        if ($this->covMemo !== null && isset($this->covMemo[$o])) return $this->covMemo[$o];
        $r = [];
        if ($this->u16($o) === 1) { for ($i = 0, $n = $this->u16($o + 2); $i < $n; $i++) $r[$this->u16($o + 4 + $i * 2)] = $i; }
        else for ($i = 0, $n = $this->u16($o + 2); $i < $n; $i++) {
            $p = $o + 4 + $i * 6; $a = $this->u16($p); $b = $this->u16($p + 2); $ci = $this->u16($p + 4);
            for ($g = $a; $g <= $b && $g - $a < 65536; $g++) $r[$g] = $ci + $g - $a;
        }
        if ($this->covMemo !== null) $this->covMemo[$o] = $r;
        return $r;
    }

    /** @return array<int,int> gid => class */
    private function classDef(int $o): array
    {
        $r = [];
        if ($this->u16($o) === 1) { $s = $this->u16($o + 2); for ($i = 0, $n = $this->u16($o + 4); $i < $n; $i++) { $c = $this->u16($o + 6 + $i * 2); if ($c) $r[$s + $i] = $c; } }
        else for ($i = 0, $n = $this->u16($o + 2); $i < $n; $i++) {
            $p = $o + 4 + $i * 6; $a = $this->u16($p); $b = $this->u16($p + 2); $c = $this->u16($p + 4);
            if ($c) for ($g = $a; $g <= $b && $g - $a < 65536; $g++) $r[$g] = $c;
        }
        return $r;
    }

    private function legacyKern(int $k): void
    {
        $p = $k + 4;
        for ($t = 0, $n = $this->u16($k + 2); $t < $n; $t++) {
            $len = $this->u16($p + 2); $cov = $this->u16($p + 4);
            if (($cov >> 8) === 0 && ($cov & 1)) { // формат 0, горизонтальный
                for ($i = 0, $m = $this->u16($p + 6); $i < $m; $i++) {
                    $q = $p + 14 + $i * 6;
                    $kk = ($this->u16($q) << 16) | $this->u16($q + 2);
                    $this->kernPairs[$kk] ??= $this->s16($q + 4);
                }
            }
            $p += max(6, $len);
        }
    }

    // ---------- GSUB: арабские формы для шрифтов без Presentation Forms в cmap ----------
    /**
     * Шрифты вроде Harmattan/Mada/Rubik не отображают U+FB50–FEFF, а получают формы через GSUB (isol/fina/init/medi, rlig).
     * Применяем эти одиночные/лигатурные подстановки к базовым глифам и добавляем результат в cmap под кодами
     * Presentation Forms — дальше конвейер (Bidi::shape, ширины, ToUnicode) работает без изменений.
     */
    private function synthArabicForms(): void
    {
        $g = $this->tables['GSUB'][0];
        $fl = $g + $this->u16($g + 6); $ll = $g + $this->u16($g + 8);
        $byTag = [];
        for ($i = 0, $n = $this->u16($fl); $i < $n; $i++) {
            $tag = substr($this->data, $fl + 2 + $i * 6, 4);
            if (!in_array($tag, ['isol', 'fina', 'init', 'medi', 'rlig', 'liga'], true)) continue;
            $f = $fl + $this->u16($fl + 6 + $i * 6);
            for ($k = 0, $m = $this->u16($f + 2); $k < $m; $k++) $byTag[$tag][$this->u16($f + 4 + $k * 2)] = true;
        }
        if (!$byTag) return;
        $nL = $this->u16($ll);
        $subtables = function (int $li) use ($ll, $nL): array { // [тип, смещение] с разворачиванием Extension (7)
            if ($li >= $nL) return [];
            $l = $ll + $this->u16($ll + 2 + $li * 2); $type = $this->u16($l); $out = [];
            for ($k = 0, $m = $this->u16($l + 4); $k < $m; $k++) {
                $st = $l + $this->u16($l + 6 + $k * 2); $t = $type;
                if ($t === 7) { $t = $this->u16($st + 2); $st += $this->u32($st + 4); }
                $out[] = [$t, $st];
            }
            return $out;
        };
        $single = function (string $tag, int $gid) use ($byTag, $subtables): ?int {
            foreach (array_keys($byTag[$tag] ?? []) as $li) foreach ($subtables($li) as [$t, $st]) {
                if ($t !== 1 && $t !== 2) continue;
                $cov = $this->coverage($st + $this->u16($st + 2));
                if (!isset($cov[$gid])) continue;
                if ($t === 2) { $sq = $st + $this->u16($st + 6 + 2 * $cov[$gid]); return $this->u16($sq) === 1 ? $this->u16($sq + 2) : null; } // Multiple: только 1→1
                return $this->u16($st) === 1 ? ($gid + $this->s16($st + 4)) & 0xFFFF : $this->u16($st + 6 + 2 * $cov[$gid]);
            }
            return null;
        };
        $map = [0 => 'isol', 1 => 'fina', 2 => 'init', 3 => 'medi'];
        $formGid = [];
        foreach (\BaurPDF\Text\Bidi::forms() as $base => $fs) {
            $bg = $this->cmap[$base] ?? 0; if (!$bg) continue;
            foreach ($fs as $i => $pcp) {
                if (isset($this->cmap[$pcp])) continue;
                $r = $single($map[$i], $bg);
                $gid = $r ?? ($i === 0 ? $bg : null); // isol без подстановки — базовый глиф
                if ($gid !== null && $gid > 0 && $gid < $this->numGlyphs) { $this->cmap[$pcp] = $gid; $formGid[$base][$i] = $gid; }
            }
        }
        // лам-алиф: лигатура (rlig/liga) из [лам init|medi, алиф fina] либо из базовых глифов
        $lam = $this->cmap[0x0644] ?? 0;
        if (!$lam) return;
        foreach (\BaurPDF\Text\Bidi::lamAlef() as $alef => [$iso, $fin]) {
            $ag = $this->cmap[$alef] ?? 0; if (!$ag) continue;
            foreach ([[$iso, 2], [$fin, 3]] as [$pcp, $lamForm]) {
                if (isset($this->cmap[$pcp])) continue;
                $cands = array_unique(array_filter([[$formGid[0x0644][$lamForm] ?? 0, $formGid[$alef][1] ?? 0], [$lam, $ag]], fn($c) => $c[0] && $c[1]), SORT_REGULAR);
                foreach ($cands as [$a, $b]) {
                    $lig = $this->ligature(['rlig', 'liga'], $byTag, $subtables, $a, $b);
                    if ($lig !== null) { $this->cmap[$pcp] = $lig; break; }
                }
            }
        }
    }

    /** @param list<string> $tags @param array<string,array<int,true>> $byTag */
    private function ligature(array $tags, array $byTag, callable $subtables, int $a, int $b): ?int
    {
        foreach ($tags as $tag) foreach (array_keys($byTag[$tag] ?? []) as $li) foreach ($subtables($li) as [$t, $st]) {
            if ($t !== 4) continue;
            $cov = $this->coverage($st + $this->u16($st + 2));
            if (!isset($cov[$a])) continue;
            $ls = $st + $this->u16($st + 6 + 2 * $cov[$a]);
            for ($k = 0, $m = $this->u16($ls); $k < $m; $k++) {
                $lg = $ls + $this->u16($ls + 2 + 2 * $k);
                if ($this->u16($lg + 2) === 2 && $this->u16($lg + 4) === $b) return $this->u16($lg);
            }
        }
        return null;
    }

    private function loca(int $gid): int
    {
        $l = $this->tables['loca'][0];
        return $this->indexToLoc ? $this->u32($l + $gid * 4) : $this->u16($l + $gid * 2) * 2;
    }

    /** Создать subset-шрифт с сохранением glyph id (неиспользуемые глифы пустые). */
    public function subset(array $gids): string
    {
        $gids[0] = 0;
        if ($this->isCff) {
            $cff = substr($this->data, $this->tables['CFF '][0], $this->tables['CFF '][1]);
            try { return (new CffSubsetter($cff))->subset($gids); }
            catch (\Throwable) { return $cff; } // нестандартный CFF — встраиваем таблицу целиком, но не падаем
        }
        $glyfOff = $this->tables['glyf'][0];
        $queue = array_keys($gids); $keep = [];
        while ($queue) {
            $g = array_pop($queue);
            if (isset($keep[$g]) || $g >= $this->numGlyphs) continue;
            $keep[$g] = true;
            $s = $this->loca($g); $e = $this->loca($g + 1);
            if ($e > $s && $this->s16($glyfOff + $s) < 0) { // composite
                $p = $glyfOff + $s + 10;
                do {
                    $flags = $this->u16($p); $queue[] = $this->u16($p + 2);
                    $p += 4 + (($flags & 1) ? 4 : 2) + (($flags & 8) ? 2 : (($flags & 0x40) ? 4 : (($flags & 0x80) ? 8 : 0)));
                } while ($flags & 0x20);
            }
        }
        // обрезка до последнего используемого глифа: loca/hmtx не тянут тысячи пустых записей
        $last = $keep ? max(array_keys($keep)) : 0;
        $num = $last + 1;
        $glyf = ''; $loca = [];
        for ($g = 0; $g < $num; $g++) {
            $loca[] = strlen($glyf);
            if (isset($keep[$g])) {
                $s = $this->loca($g); $e = $this->loca($g + 1);
                $glyf .= substr($this->data, $glyfOff + $s, $e - $s);
                if (strlen($glyf) % 4) $glyf .= str_repeat("\0", 4 - strlen($glyf) % 4);
            }
        }
        $loca[] = strlen($glyf);
        $short = strlen($glyf) < 0x20000;
        $locaBin = $short ? pack('n*', ...array_map(fn($v) => $v >> 1, $loca)) : pack('N*', ...$loca);
        $head = substr($this->data, $this->tables['head'][0], $this->tables['head'][1]);
        $head = substr_replace($head, "\0\0\0\0", 8, 4);   // checkSumAdjustment
        $head = substr_replace($head, pack('n', $short ? 0 : 1), 50, 2); // формат loca
        $out = ['head' => $head, 'loca' => $locaBin, 'glyf' => $glyf];
        $hhea = substr($this->data, $this->tables['hhea'][0], $this->tables['hhea'][1]);
        $nhm = (int)(unpack('n', $hhea, 34) ?: [1 => 0])[1];
        $hmtxRaw = substr($this->data, $this->tables['hmtx'][0], $this->tables['hmtx'][1]);
        $hmtx = '';
        for ($g = 0; $g < $num; $g++) {
            if ($g < $nhm) $hmtx .= substr($hmtxRaw, $g * 4, 4);
            else $hmtx .= substr($hmtxRaw, ($nhm - 1) * 4, 2) . substr($hmtxRaw, $nhm * 4 + ($g - $nhm) * 2, 2);
        }
        $out['hmtx'] = $hmtx;
        $out['hhea'] = substr_replace($hhea, pack('n', $num), 34, 2);
        $maxp = substr($this->data, $this->tables['maxp'][0], $this->tables['maxp'][1]);
        $out['maxp'] = substr_replace($maxp, pack('n', $num), 4, 2);
        foreach (['cvt ', 'fpgm', 'prep'] as $t) {
            if (isset($this->tables[$t])) $out[$t] = substr($this->data, $this->tables[$t][0], $this->tables[$t][1]);
        }
        ksort($out, SORT_STRING);
        $n = count($out); $es = (int)floor(log($n, 2)); $sr = (2 ** $es) * 16;
        $font = pack('Nnnnn', 0x00010000, $n, $sr, $es, $n * 16 - $sr);
        $offset = 12 + $n * 16; $dir = ''; $body = '';
        foreach ($out as $tag => $tb) {
            $len = strlen($tb);
            $pad = $tb . str_repeat("\0", (4 - $len % 4) % 4);
            $dir .= $tag . pack('NNN', self::checksum($pad), $offset, $len);
            $body .= $pad; $offset += strlen($pad);
        }
        $font .= $dir . $body;
        $adj = (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF;
        $hp = strpos($dir, 'head');
        $headOffset = (int)(unpack('N', $dir, $hp + 8) ?: [1 => 0])[1];
        return substr_replace($font, pack('N', $adj), $headOffset + 8, 4);
    }

    private static function checksum(string $d): int
    {
        $d .= str_repeat("\0", (4 - strlen($d) % 4) % 4);
        $sum = 0;
        foreach (unpack('N*', $d) ?: [] as $v) $sum = ($sum + $v) & 0xFFFFFFFF;
        return $sum;
    }
}
