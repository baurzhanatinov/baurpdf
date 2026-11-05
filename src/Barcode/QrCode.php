<?php
declare(strict_types=1);
namespace BaurPDF\Barcode;

use BaurPDF\Exception\BaurPdfException;

/** Чистый PHP-кодировщик QR (ISO/IEC 18004): байтовый режим (UTF-8), версии 1–40, уровни L/M/Q/H, выбор маски по штрафам. */
final class QrCode
{
    private const ECC = [ // codewords per block, [L,M,Q,H]
        [-1,7,10,15,20,26,18,20,24,30,18,20,24,26,30,22,24,28,30,28,28,28,28,30,30,26,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
        [-1,10,16,26,18,24,16,18,22,22,26,30,22,22,24,24,28,28,26,26,26,26,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28,28],
        [-1,13,22,18,26,18,24,18,22,20,24,28,26,24,20,30,24,28,28,26,30,28,30,30,30,30,28,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
        [-1,17,28,22,16,22,28,26,26,24,28,24,28,22,24,24,30,28,28,26,28,30,24,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30,30],
    ];
    private const BLOCKS = [
        [-1,1,1,1,1,1,2,2,2,2,4,4,4,4,4,6,6,6,6,7,8,8,9,9,10,12,12,12,13,14,15,16,17,18,19,19,20,21,22,24,25],
        [-1,1,1,1,2,2,4,4,4,5,5,5,8,9,9,10,10,11,13,14,16,17,17,18,20,21,23,25,26,28,29,31,33,35,37,38,40,43,45,47,49],
        [-1,1,1,2,2,4,4,6,6,8,8,8,10,12,16,12,17,16,18,21,20,23,23,25,27,29,34,34,35,38,40,43,45,48,51,53,56,59,62,65,68],
        [-1,1,1,2,4,4,4,5,6,8,8,11,11,16,16,18,16,19,21,25,25,25,34,30,32,35,37,40,42,45,48,51,54,57,60,63,66,70,74,77,81],
    ];
    private const LVL = ['L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3];
    private const FMT_BITS = [1, 0, 3, 2]; // L=01 M=00 Q=11 H=10

    /** @var list<list<bool>> */ private array $m = [];
    /** @var list<list<bool>> */ private array $fn = [];
    private int $size;

    /** @return list<list<bool>> матрица модулей (true = тёмный) */
    public static function matrix(string $data, string $ecl = 'M'): array
    {
        $e = self::LVL[strtoupper($ecl)] ?? throw new BaurPdfException("QR: уровень коррекции '$ecl' неизвестен (L, M, Q, H)");
        $bytes = array_values(unpack('C*', $data) ?: []);
        $n = count($bytes);
        for ($v = 1; $v <= 40; $v++) {
            $cap = self::dataCodewords($v, $e) * 8;
            $need = 4 + ($v < 10 ? 8 : 16) + 8 * $n;
            if ($need <= $cap) break;
        }
        if ($v > 40) throw new BaurPdfException("QR: данные слишком длинные ($n байт)");
        $bits = [];
        $push = function (int $val, int $len) use (&$bits): void { for ($i = $len - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
        $push(4, 4); $push($n, $v < 10 ? 8 : 16);
        foreach ($bytes as $b) $push($b, 8);
        $push(0, min(4, $cap - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) $push($pad, 8);
        $cw = [];
        for ($i = 0; $i < count($bits); $i += 8) { $b = 0; for ($j = 0; $j < 8; $j++) $b = ($b << 1) | $bits[$i + $j]; $cw[] = $b; }
        $q = new self($v);
        $q->drawFunction($v, $e);
        $q->drawCodewords(self::interleave($cw, $v, $e));
        $best = 0; $bestP = PHP_INT_MAX; $base = $q->m;
        for ($mask = 0; $mask < 8; $mask++) {
            $q->m = $base; $q->applyMask($mask); $q->drawFormat($e, $mask);
            $p = $q->penalty();
            if ($p < $bestP) { $bestP = $p; $best = $mask; }
        }
        $q->m = $base; $q->applyMask($best); $q->drawFormat($e, $best);
        return $q->m;
    }

    /** SVG-разметка (вставляется в HTML; рендерится нативно). $size — размер в pt. */
    public static function svg(string $data, float $size = 80, string $ecl = 'M', string $color = '#000'): string
    {
        $m = self::matrix($data, $ecl);
        $n = count($m); $t = $n + 8; $d = '';
        foreach ($m as $y => $row) foreach ($row as $x => $on) if ($on) $d .= 'M' . ($x + 4) . ' ' . ($y + 4) . 'h1v1h-1z';
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%1$spt" height="%1$spt" viewBox="0 0 %2$d %2$d"><rect width="%2$d" height="%2$d" fill="#fff"/><path d="%3$s" fill="%4$s"/></svg>', $size, $t, $d, htmlspecialchars($color));
    }

    private function __construct(int $v)
    {
        $this->size = $v * 4 + 17;
        $this->m = $this->fn = array_fill(0, $this->size, array_fill(0, $this->size, false));
    }

    private static function rawModules(int $v): int
    {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) { $na = intdiv($v, 7) + 2; $r -= (25 * $na - 10) * $na - 55; if ($v >= 7) $r -= 36; }
        return $r;
    }

    private static function dataCodewords(int $v, int $e): int
    { return intdiv(self::rawModules($v), 8) - self::ECC[$e][$v] * self::BLOCKS[$e][$v]; }

    /** @param list<int> $data @return list<int> */
    private static function interleave(array $data, int $v, int $e): array
    {
        $nb = self::BLOCKS[$e][$v]; $ecLen = self::ECC[$e][$v];
        $raw = intdiv(self::rawModules($v), 8);
        $short = $nb - $raw % $nb; $shortLen = intdiv($raw, $nb);
        $gen = self::rsGen($ecLen);
        $blocks = []; $k = 0;
        for ($i = 0; $i < $nb; $i++) {
            $len = $shortLen - $ecLen + ($i < $short ? 0 : 1);
            $dat = array_slice($data, $k, $len); $k += $len;
            $ec = self::rsRem($dat, $gen);
            if ($i < $short) $dat[] = -1; // выравнивание
            $blocks[] = array_merge($dat, $ec);
        }
        $out = [];
        for ($i = 0, $L = count($blocks[0]); $i < $L; $i++) foreach ($blocks as $j => $b) if ($b[$i] !== -1) $out[] = $b[$i];
        return $out;
    }

    private static function gmul(int $x, int $y): int
    { $z = 0; for ($i = 7; $i >= 0; $i--) { $z = ($z << 1) ^ (($z >> 7) * 0x11D); $z ^= (($y >> $i) & 1) * $x; } return $z & 0xFF; }

    /** @return list<int> */
    private static function rsGen(int $deg): array
    {
        $r = array_fill(0, $deg, 0); $r[$deg - 1] = 1; $root = 1;
        for ($i = 0; $i < $deg; $i++) {
            for ($j = 0; $j < $deg; $j++) { $r[$j] = self::gmul($r[$j], $root); if ($j + 1 < $deg) $r[$j] ^= $r[$j + 1]; }
            $root = self::gmul($root, 2);
        }
        return $r;
    }

    /** @param list<int> $data @param list<int> $gen @return list<int> */
    private static function rsRem(array $data, array $gen): array
    {
        $r = array_fill(0, count($gen), 0);
        foreach ($data as $b) {
            $f = $b ^ array_shift($r); $r[] = 0;
            foreach ($gen as $i => $g) $r[$i] ^= self::gmul($g, $f);
        }
        return $r;
    }

    private function set(int $x, int $y, bool $on): void { $this->m[$y][$x] = $on; $this->fn[$y][$x] = true; }

    private function drawFunction(int $v, int $e): void
    {
        $s = $this->size;
        for ($i = 0; $i < $s; $i++) { $this->set(6, $i, $i % 2 === 0); $this->set($i, 6, $i % 2 === 0); }
        foreach ([[3, 3], [$s - 4, 3], [3, $s - 4]] as [$cx, $cy])
            for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
                $d = max(abs($dx), abs($dy)); $x = $cx + $dx; $y = $cy + $dy;
                if ($x >= 0 && $x < $s && $y >= 0 && $y < $s) $this->set($x, $y, $d !== 2 && $d !== 4);
            }
        if ($v > 1) {
            $na = intdiv($v, 7) + 2;
            $step = $v === 32 ? 26 : intdiv($v * 4 + $na * 2 + 1, $na * 2 - 2) * 2;
            $tail = []; for ($p = $s - 7, $i = 0; $i < $na - 1; $i++, $p -= $step) array_unshift($tail, $p);
            /** @var list<int> $pos */
            $pos = array_merge([6], $tail);
            foreach ($pos as $i => $ax) foreach ($pos as $j => $ay) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $na - 1) || ($i === $na - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $this->set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
        $this->drawFormat($e, 0);
        if ($v >= 7) {
            $rem = $v; for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = $v << 12 | $rem;
            for ($i = 0; $i < 18; $i++) { $b = (($bits >> $i) & 1) === 1; $a = $s - 11 + $i % 3; $c = intdiv($i, 3); $this->set($a, $c, $b); $this->set($c, $a, $b); }
        }
    }

    private function drawFormat(int $e, int $mask): void
    {
        $data = self::FMT_BITS[$e] << 3 | $mask; $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = ($data << 10 | $rem) ^ 0x5412; $s = $this->size;
        $g = fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $this->set(8, $i, $g($i));
        $this->set(8, 7, $g(6)); $this->set(8, 8, $g(7)); $this->set(7, 8, $g(8));
        for ($i = 9; $i < 15; $i++) $this->set(14 - $i, 8, $g($i));
        for ($i = 0; $i < 8; $i++) $this->set($s - 1 - $i, 8, $g($i));
        for ($i = 8; $i < 15; $i++) $this->set(8, $s - 15 + $i, $g($i));
        $this->set(8, $s - 8, true);
    }

    /** @param list<int> $cw */
    private function drawCodewords(array $cw): void
    {
        $s = $this->size; $i = 0; $total = count($cw) * 8;
        for ($right = $s - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $s; $vert++) for ($j = 0; $j < 2; $j++) {
                $x = $right - $j; $up = (($right + 1) & 2) === 0;
                $y = $up ? $s - 1 - $vert : $vert;
                if (!$this->fn[$y][$x] && $i < $total) { $this->m[$y][$x] = (($cw[$i >> 3] >> (7 - ($i & 7))) & 1) === 1; $i++; }
            }
        }
    }

    private function applyMask(int $k): void
    {
        $s = $this->size;
        for ($y = 0; $y < $s; $y++) for ($x = 0; $x < $s; $x++) {
            if ($this->fn[$y][$x]) continue;
            $inv = match ($k) {
                0 => ($x + $y) % 2 === 0, 1 => $y % 2 === 0, 2 => $x % 3 === 0, 3 => ($x + $y) % 3 === 0,
                4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0, 5 => $x * $y % 2 + $x * $y % 3 === 0,
                6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0, default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
            };
            if ($inv) $this->m[$y][$x] = !$this->m[$y][$x];
        }
    }

    /** Упрощённый штраф: серии (N1), блоки 2×2 (N2), баланс (N4). */
    private function penalty(): int
    {
        $s = $this->size; $p = 0; $dark = 0; $m = $this->m;
        for ($a = 0; $a < $s; $a++) {
            $rr = $rc = 1;
            for ($b = 1; $b < $s; $b++) {
                if ($m[$a][$b] === $m[$a][$b - 1]) { $rr++; if ($rr === 5) $p += 3; elseif ($rr > 5) $p++; } else $rr = 1;
                if ($m[$b][$a] === $m[$b - 1][$a]) { $rc++; if ($rc === 5) $p += 3; elseif ($rc > 5) $p++; } else $rc = 1;
            }
        }
        for ($y = 0; $y < $s; $y++) for ($x = 0; $x < $s; $x++) {
            if ($m[$y][$x]) $dark++;
            if ($x && $y && $m[$y][$x] === $m[$y - 1][$x] && $m[$y][$x] === $m[$y][$x - 1] && $m[$y][$x] === $m[$y - 1][$x - 1]) $p += 3;
        }
        return $p + intdiv(abs($dark * 20 - $s * $s * 10), $s * $s) * 10;
    }
}
