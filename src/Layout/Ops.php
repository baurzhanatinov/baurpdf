<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

/** Операции рисования (локальные координаты, pt, ось Y вниз). */
final class Ops
{
    public static function translate(array $ops, float $dx, float $dy): array
    {
        foreach ($ops as &$o) {
            if (($o['t'] ?? '') === 'group') { $o['dx'] = ($o['dx'] ?? 0) + $dx; $o['dy'] = ($o['dy'] ?? 0) + $dy; continue; }
            if (($o['t'] ?? '') === 'tf') { $o['ox'] += $dx; $o['oy'] += $dy; continue; }
            if (isset($o['x'])) $o['x'] += $dx;
            if (isset($o['y'])) $o['y'] += $dy;
            if (isset($o['x2'])) { $o['x2'] += $dx; $o['y2'] += $dy; }
            if (isset($o['clip'])) { $o['clip'][0] += $dx; $o['clip'][1] += $dy; }
            if (isset($o['pts'])) foreach ($o['pts'] as &$seg) { for ($i = 1; $i < count($seg); $i += 2) { $seg[$i] += $dx; $seg[$i + 1] += $dy; } } unset($seg);
        }
        return $ops;
    }

    /** Обход операций с раскрытием групп (без копирования координат). @return \Generator<int, array> */
    public static function each(array $ops): \Generator
    {
        foreach ($ops as $o) {
            if (($o['t'] ?? '') === 'group') yield from self::each($o['ops']);
            else yield $o;
        }
    }

    /** Раскрыть группы в плоский список с применёнными смещениями. */
    public static function flat(array $ops): array
    {
        $out = [];
        foreach ($ops as $o) {
            if (($o['t'] ?? '') === 'group') { foreach (self::translate(self::flat($o['ops']), $o['dx'] ?? 0, $o['dy'] ?? 0) as $x) $out[] = $x; }
            else $out[] = $o;
        }
        return $out;
    }

    /**
     * Фон + рамка блока. $box: x,w, bg, grad, radius, b:[top,right,bottom,left => [w,style,color]], opacity, shadow.
     * $first/$last — рисовать ли верхнюю/нижнюю рамку (разрыв на страницах).
     */
    public static function box(array $box, float $y, float $h, bool $first, bool $last): array
    {
        $ops = [];
        $x = $box['x']; $w = $box['w'];
        $r = $box['radius'] ?? 0;
        if (!empty($box['shadow'])) {
            $list = is_array($box['shadow'][0] ?? null) ? $box['shadow'] : [$box['shadow']];
            // первая тень — сверху: рисуем с конца. Размытие — картинка с гауссовой маской прозрачности (SMask)
            foreach (array_reverse($list) as $sh) {
                [$sx, $sy, $blur] = $sh; $spread = count($sh) > 4 ? (float)$sh[3] : 0.0; $col = count($sh) > 4 ? $sh[4] : $sh[3];
                if ($blur <= 0) { $ops[] = ['t' => 'rect', 'x' => $x + $sx - $spread, 'y' => $y + $sy - $spread, 'w' => max(0, $w + 2 * $spread), 'h' => max(0, $h + 2 * $spread), 'fill' => $col, 'r' => max(0, $r + $spread)]; continue; }
                // гауссово размытие (σ = blur/2) через маску прозрачности
                $sigma = $blur / 2; $pad = ceil(3 * $sigma);
                $ops[] = ['t' => 'shadow', 'x' => $x + $sx - $spread - $pad, 'y' => $y + $sy - $spread - $pad, 'bw' => max(0.0, $w + 2 * $spread), 'bh' => max(0.0, $h + 2 * $spread),
                    'r' => max(0.0, $r + $spread), 'sigma' => $sigma, 'pad' => $pad, 'color' => $col];
            }
        }
        if (!empty($box['grad'])) $ops[] = ['t' => 'grad', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'grad' => $box['grad'], 'r' => $r];
        elseif (!empty($box['bg'])) $ops[] = ['t' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fill' => $box['bg'], 'r' => $r];
        if (!empty($box['bgimg'])) $ops[] = ['t' => 'image', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'data' => $box['bgimg'], 'cover' => true];
        $b = $box['b'] ?? null;
        if ($b) {
            $same = $b[0] == $b[1] && $b[1] == $b[2] && $b[2] == $b[3];
            if ($same && $b[0][0] > 0 && $first && $last && $b[0][1] !== 'none' && empty($box['gap'])) {
                $bw = $b[0][0];
                $ops[] = ['t' => 'rect', 'x' => $x + $bw / 2, 'y' => $y + $bw / 2, 'w' => $w - $bw, 'h' => $h - $bw, 'stroke' => $b[0][2], 'lw' => $bw, 'r' => max(0, $r - $bw / 2), 'dash' => $b[0][1]];
            } else {
                [$t, $rt, $bt, $l] = $b;
                if ($first && $t[0] > 0 && $t[1] !== 'none') {
                    // разрыв под legend у fieldset
                    $segs = !empty($box['gap']) ? [[$x, max($x, (float)$box['gap'][0])], [min($x + $w, (float)$box['gap'][1]), $x + $w]] : [[$x, $x + $w]];
                    foreach ($segs as [$a, $c]) if ($c - $a > 0.01) $ops[] = ['t' => 'line', 'x' => $a, 'y' => $y + $t[0] / 2, 'x2' => $c, 'y2' => $y + $t[0] / 2, 'lw' => $t[0], 'color' => $t[2], 'dash' => $t[1]];
                }
                if ($last && $bt[0] > 0) $ops[] = ['t' => 'line', 'x' => $x, 'y' => $y + $h - $bt[0] / 2, 'x2' => $x + $w, 'y2' => $y + $h - $bt[0] / 2, 'lw' => $bt[0], 'color' => $bt[2], 'dash' => $bt[1]];
                if ($l[0] > 0) $ops[] = ['t' => 'line', 'x' => $x + $l[0] / 2, 'y' => $y, 'x2' => $x + $l[0] / 2, 'y2' => $y + $h, 'lw' => $l[0], 'color' => $l[2], 'dash' => $l[1]];
                if ($rt[0] > 0) $ops[] = ['t' => 'line', 'x' => $x + $w - $rt[0] / 2, 'y' => $y, 'x2' => $x + $w - $rt[0] / 2, 'y2' => $y + $h, 'lw' => $rt[0], 'color' => $rt[2], 'dash' => $rt[1]];
            }
        }
        if (isset($box['opacity']) && $box['opacity'] < 1 && $ops) { array_unshift($ops, ['t' => 'alpha', 'a' => $box['opacity']]); $ops[] = ['t' => 'endalpha']; }
        return $ops;
    }
}
