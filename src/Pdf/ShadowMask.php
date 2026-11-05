<?php
declare(strict_types=1);
namespace BaurPDF\Pdf;

/**
 * Размытая тень как маска прозрачности (SMask): скруглённый прямоугольник растеризуется в оттенки серого
 * и размывается трёхпроходным box-blur (приближение гауссова размытия, σ = blur/2 по CSS).
 */
final class ShadowMask
{
    /**
     * @param float $w ширина прямоугольника тени, pt (с учётом spread)
     * @param float $pad поле вокруг (≈3σ), pt
     * @return array{0:int,1:int,2:string} [ширина px, высота px, байты маски]
     */
    public static function build(float $w, float $h, float $r, float $sigma, float $pad, float $alpha, int $maxPixels = 60000): array
    {
        $W = $w + 2 * $pad; $H = $h + 2 * $pad;
        $k = min(2.0, sqrt($maxPixels / max(1.0, $W * $H)));
        $mw = max(2, (int)ceil($W * $k)); $mh = max(2, (int)ceil($H * $k));
        $r = max(0.0, min($r, $w / 2, $h / 2));
        // жёсткая маска (с 2×2 субпикселями по краям)
        $px = array_fill(0, $mw * $mh, 0.0);
        for ($j = 0; $j < $mh; $j++) {
            for ($i = 0; $i < $mw; $i++) {
                $v = 0.0;
                foreach ([0.25, 0.75] as $oy) foreach ([0.25, 0.75] as $ox) {
                    $x = ($i + $ox) / $k - $pad; $y = ($j + $oy) / $k - $pad;
                    if ($x < 0 || $y < 0 || $x > $w || $y > $h) continue;
                    $cx = $x < $r ? $r - $x : ($x > $w - $r ? $x - ($w - $r) : 0);
                    $cy = $y < $r ? $r - $y : ($y > $h - $r ? $y - ($h - $r) : 0);
                    if ($cx * $cx + $cy * $cy <= $r * $r + 1e-9) $v += 0.25;
                }
                $px[$j * $mw + $i] = $v;
            }
        }
        $s = $sigma * $k;
        if ($s > 0.3) foreach (self::boxes($s) as $b) { $px = self::blurH($px, $mw, $mh, intdiv($b - 1, 2)); $px = self::blurV($px, $mw, $mh, intdiv($b - 1, 2)); }
        $out = '';
        $a = max(0.0, min(1.0, $alpha)) * 255;
        foreach ($px as $v) $out .= chr((int)max(0, min(255, round($v * $a))));
        return [$mw, $mh, $out];
    }

    /** Размеры трёх box-фильтров, эквивалентных гауссу с σ. @return list<int> */
    private static function boxes(float $sigma): array
    {
        $wIdeal = sqrt(12 * $sigma * $sigma / 3 + 1);
        $wl = (int)floor($wIdeal); if ($wl % 2 === 0) $wl--;
        $wu = $wl + 2;
        $m = (int)round((12 * $sigma * $sigma - 3 * $wl * $wl - 12 * $wl - 9) / (-4 * $wl - 4));
        return [$m > 0 ? $wl : $wu, $m > 1 ? $wl : $wu, $m > 2 ? $wl : $wu];
    }

    /** @param list<float> $p @return list<float> */
    private static function blurH(array $p, int $w, int $h, int $r): array
    {
        if ($r < 1) return $p;
        $o = $p; $d = 2 * $r + 1;
        for ($j = 0; $j < $h; $j++) {
            $row = $j * $w; $acc = 0.0;
            for ($i = -$r; $i <= $r; $i++) $acc += ($i >= 0 && $i < $w) ? $p[$row + $i] : 0.0;
            for ($i = 0; $i < $w; $i++) {
                $o[$row + $i] = $acc / $d;
                $in = $i + $r + 1; $outI = $i - $r;
                if ($in < $w) $acc += $p[$row + $in];
                if ($outI >= 0) $acc -= $p[$row + $outI];
            }
        }
        return $o;
    }

    /** @param list<float> $p @return list<float> */
    private static function blurV(array $p, int $w, int $h, int $r): array
    {
        if ($r < 1) return $p;
        $o = $p; $d = 2 * $r + 1;
        for ($i = 0; $i < $w; $i++) {
            $acc = 0.0;
            for ($j = -$r; $j <= $r; $j++) $acc += ($j >= 0 && $j < $h) ? $p[$j * $w + $i] : 0.0;
            for ($j = 0; $j < $h; $j++) {
                $o[$j * $w + $i] = $acc / $d;
                $in = $j + $r + 1; $outJ = $j - $r;
                if ($in < $h) $acc += $p[$in * $w + $i];
                if ($outJ >= 0) $acc -= $p[$outJ * $w + $i];
            }
        }
        return $o;
    }
}
