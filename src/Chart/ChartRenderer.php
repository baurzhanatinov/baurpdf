<?php
declare(strict_types=1);
namespace BaurPDF\Chart;

use BaurPDF\Css\Color;
use BaurPDF\Layout\Engine;

/**
 * Нативные векторные графики без JS: bar, hbar (horizontal), stacked, line, area, pie, donut, progress/gauge.
 * Формат конфига совместим по духу с Chart.js: {type, data:{labels, datasets:[{label,data,color}]}, options:{...}}.
 */
final class ChartRenderer
{
    public const PALETTE = ['#4F46E5', '#06B6D4', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#14B8A6', '#F97316', '#64748B'];

    public function __construct(private Engine $e) {}

    public static function configFromElement(\DOMElement $el): array
    {
        $json = $el->getAttribute('data-chart') ?: $el->getAttribute('config') ?: trim($el->textContent);
        $cfg = json_decode(html_entity_decode($json), true);
        if (!is_array($cfg)) $cfg = [];
        if ($el->hasAttribute('type')) $cfg['type'] = $el->getAttribute('type');
        if ($el->hasAttribute('title')) $cfg['title'] = $el->getAttribute('title');
        while ($el->firstChild) $el->removeChild($el->firstChild); // JSON-текст не рисуем
        return $cfg;
    }

    public static function normalize(array $cfg): array
    {
        $data = $cfg['data'] ?? [];
        $labels = $cfg['labels'] ?? ($data['labels'] ?? []);
        $datasets = $cfg['datasets'] ?? ($data['datasets'] ?? []);
        if ($datasets && !isset($datasets[0]) ) $datasets = [$datasets];
        if (!$datasets && isset($data[0]) && is_numeric($data[0])) $datasets = [['data' => $data]];
        if (!$datasets && $data && !isset($data['labels'])) { $labels = array_keys($data); $datasets = [['data' => array_values($data)]]; }
        $o = $cfg['options'] ?? [];
        foreach (['stacked', 'legend', 'valueLabels', 'unit', 'title', 'colors', 'min', 'max', 'grid', 'donutWidth', 'smooth', 'fill', 'horizontal', 'decimals', 'subtitle'] as $k) if (array_key_exists($k, $cfg)) $o[$k] = $cfg[$k];
        if (isset($o['plugins']['title']['text'])) $o['title'] = $o['plugins']['title']['text'];
        if (isset($o['scales']['x']['stacked'])) $o['stacked'] = $o['scales']['x']['stacked'];
        if (isset($o['indexAxis']) && $o['indexAxis'] === 'y') $o['horizontal'] = true;
        $ds = [];
        foreach ($datasets as $d) {
            if (!is_array($d)) continue;
            $vals = array_map(fn($v) => is_numeric($v) ? (float)$v : 0.0, array_values((array)($d['data'] ?? [])));
            $ds[] = ['label' => (string)($d['label'] ?? ''), 'data' => $vals, 'color' => $d['color'] ?? $d['backgroundColor'] ?? $d['borderColor'] ?? null, 'type' => $d['type'] ?? null];
        }
        return ['type' => strtolower((string)($cfg['type'] ?? 'bar')), 'labels' => array_map('strval', (array)$labels), 'datasets' => $ds, 'o' => $o];
    }

    private function color(int $i, mixed $c = null, array $o = []): array
    {
        if (is_array($c)) $c = $c[$i % max(1, count($c))] ?? null;
        $pal = $o['colors'] ?? self::PALETTE;
        return Color::parse(is_string($c) ? $c : $pal[$i % count($pal)]) ?? [0.3, 0.3, 0.8, 1];
    }

    public function render(array $cfg, float $w, float $h, array $st): array
    {
        try {
            $c = self::normalize($cfg);
            if (!$c['datasets'] || !array_filter($c['datasets'], fn($d) => $d['data'])) return $this->empty($w, $h, $st, 'Нет данных');
            $ops = [];
            $top = 0;
            if (!empty($c['o']['title'])) { $ops = array_merge($ops, $this->e->textOps((string)$c['o']['title'], $w / 2, 12, $st, 11, [0.1, 0.12, 0.16, 1], $w, 'center', true)); $top = 20; }
            $type = $c['type'];
            if (in_array($type, ['pie', 'donut', 'doughnut'], true)) return array_merge($ops, $this->pie($c, $w, $h, $top, $st, $type !== 'pie'));
            if ($type === 'progress' || $type === 'gauge') return array_merge($ops, $this->gauge($c, $w, $h, $top, $st));
            return array_merge($ops, $this->axes($c, $w, $h, $top, $st, $type));
        } catch (\Throwable $ex) {
            return $this->empty($w, $h, $st, 'Ошибка графика');
        }
    }

    private function empty(float $w, float $h, array $st, string $msg): array
    {
        return array_merge([['t' => 'rect', 'x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'fill' => [0.97, 0.97, 0.98, 1], 'stroke' => [0.85, 0.85, 0.88, 1], 'lw' => 0.5, 'r' => 4]],
            $this->e->textOps($msg, $w / 2, $h / 2, $st, 9, [0.5, 0.5, 0.55, 1], $w, 'center'));
    }

    public static function niceScale(float $min, float $max, int $ticks = 5): array
    {
        if ($max == $min) { $max = $min + 1; }
        $range = self::nice($max - $min, false);
        $step = self::nice($range / max(1, $ticks - 1), true);
        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    private static function nice(float $x, bool $round): float
    {
        if ($x <= 0) return 1;
        $e = floor(log10($x)); $f = $x / 10 ** $e;
        $nf = $round ? ($f < 1.5 ? 1 : ($f < 3 ? 2 : ($f < 7 ? 5 : 10))) : ($f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10)));
        return $nf * 10 ** $e;
    }

    public static function fmt(float $v, array $o = []): string
    {
        $dec = $o['decimals'] ?? (abs($v - round($v)) < 1e-9 ? 0 : 1);
        $a = abs($v);
        if (empty($o['decimals']) && $a >= 1e6) $s = rtrim(rtrim(number_format($v / 1e6, 1, ',', ' '), '0'), ',') . ' млн';
        elseif (empty($o['decimals']) && $a >= 1e4) $s = rtrim(rtrim(number_format($v / 1e3, 1, ',', ' '), '0'), ',') . ' тыс';
        else $s = number_format($v, (int)$dec, ',', ' ');
        return $s . (isset($o['unit']) ? ' ' . $o['unit'] : '');
    }

    private function legend(array $c, float $w, float $y, array $st, bool $byLabel = false): array
    {
        $ops = []; $items = [];
        if ($byLabel) foreach ($c['labels'] as $i => $l) $items[] = [$l, $this->color($i, $c['datasets'][0]['color'], $c['o'])];
        else foreach ($c['datasets'] as $i => $d) if ($d['label'] !== '') $items[] = [$d['label'], $this->color($i, is_string($d['color']) ? $d['color'] : null, $c['o'])];
        if (!$items) return [];
        $f = $this->e->fonts->get($st['font-family'] ?? 'sans-serif', 'normal', 'normal')[0];
        $total = 0; foreach ($items as [$l]) $total += $this->e->textWidth($f, $l, 8) + 22;
        $x = max(0, ($w - $total) / 2);
        foreach ($items as [$l, $col]) {
            $ops[] = ['t' => 'rect', 'x' => $x, 'y' => $y - 6.5, 'w' => 8, 'h' => 8, 'fill' => $col, 'r' => 2];
            $ops = array_merge($ops, $this->e->textOps($l, $x + 11, $y, $st, 8, [0.3, 0.33, 0.4, 1]));
            $x += $this->e->textWidth($f, $l, 8) + 22;
        }
        return $ops;
    }

    private function axes(array $c, float $w, float $h, float $top, array $st, string $type): array
    {
        $o = $c['o']; $ops = [];
        $horizontal = $type === 'hbar' || $type === 'horizontalbar' || !empty($o['horizontal']);
        $stacked = !empty($o['stacked']) || $type === 'stacked' || $type === 'stackedbar';
        $isLine = in_array($type, ['line', 'area', 'spline'], true);
        $legend = ($o['legend'] ?? (count($c['datasets']) > 1)) !== false && count(array_filter($c['datasets'], fn($d) => $d['label'] !== '')) > 0;
        $bottom = $h - ($legend ? 18 : 0);
        $n = max(array_map(fn($d) => count($d['data']), $c['datasets']));
        $labels = array_pad($c['labels'], $n, '');
        // диапазон значений
        $vals = [];
        if ($stacked) { for ($i = 0; $i < $n; $i++) { $pos = 0; $neg = 0; foreach ($c['datasets'] as $d) { $v = $d['data'][$i] ?? 0; if ($v >= 0) $pos += $v; else $neg += $v; } $vals[] = $pos; $vals[] = $neg; } }
        else foreach ($c['datasets'] as $d) foreach ($d['data'] as $v) $vals[] = $v;
        $vmin = min(0, min($vals)); $vmax = max(0, max($vals));
        if (isset($o['min'])) $vmin = (float)$o['min']; if (isset($o['max'])) $vmax = (float)$o['max'];
        [$lo, $hi, $step] = self::niceScale($vmin, $vmax, 5);
        $f = $this->e->fonts->get($st['font-family'] ?? 'sans-serif', 'normal', 'normal')[0];
        $axisW = 0; for ($v = $lo; $v <= $hi + $step / 2; $v += $step) $axisW = max($axisW, $this->e->textWidth($f, self::fmt($v, $o + ['unit' => null]), 7.5));
        $grid = [0.9, 0.91, 0.93, 1]; $txt = [0.42, 0.45, 0.52, 1];
        if ($horizontal) {
            $labW = 0; foreach ($labels as $l) $labW = max($labW, $this->e->textWidth($f, $l, 8));
            $labW = min($labW + 6, $w * 0.35);
            $px = $labW; $pw = $w - $labW - 8; $py = $top + 4; $ph = $bottom - $top - 18;
            $sx = fn($v) => $px + ($v - $lo) / ($hi - $lo) * $pw;
            for ($v = $lo; $v <= $hi + $step / 2; $v += $step) {
                $ops[] = ['t' => 'line', 'x' => $sx($v), 'y' => $py, 'x2' => $sx($v), 'y2' => $py + $ph, 'lw' => 0.5, 'color' => $grid];
                $ops = array_merge($ops, $this->e->textOps(self::fmt($v, $o + ['unit' => null]), $sx($v), $py + $ph + 10, $st, 7.5, $txt, null, 'center'));
            }
            $band = $ph / $n; $nd = count($c['datasets']);
            for ($i = 0; $i < $n; $i++) {
                $by = $py + $band * $i;
                $ops = array_merge($ops, $this->e->textOps($labels[$i], $px - 5, $by + $band / 2 + 3, $st, 8, $txt, $labW - 4, 'right'));
                $acc = 0;
                foreach ($c['datasets'] as $di => $d) {
                    $v = $d['data'][$i] ?? 0; $col = $this->color($nd > 1 ? $di : $i, $nd > 1 ? $d['color'] : ($d['color'] ?? null), $o);
                    if ($nd > 1) $col = $this->color($di, is_string($d['color']) ? $d['color'] : null, $o);
                    if ($stacked) { $x0 = $sx($acc); $x1 = $sx($acc + $v); $acc += $v; $bh = $band * 0.6; $yy = $by + $band * 0.2; }
                    else { $bh = $band * 0.7 / $nd; $yy = $by + $band * 0.15 + $bh * $di; $x0 = $sx(0); $x1 = $sx($v); }
                    $ops[] = ['t' => 'rect', 'x' => min($x0, $x1), 'y' => $yy, 'w' => max(0.5, abs($x1 - $x0)), 'h' => $bh, 'fill' => $col, 'r' => min(3, $bh / 3)];
                    if (($o['valueLabels'] ?? true) && !$stacked) $ops = array_merge($ops, $this->e->textOps(self::fmt($v, $o), max($x0, $x1) + 3, $yy + $bh / 2 + 2.8, $st, 7, [0.25, 0.27, 0.33, 1]));
                }
            }
        } else {
            $px = $axisW + 6; $pw = $w - $px - 4; $py = $top + 6; $ph = $bottom - $top - 22;
            $sy = fn($v) => $py + $ph - ($v - $lo) / ($hi - $lo) * $ph;
            for ($v = $lo; $v <= $hi + $step / 2; $v += $step) {
                $ops[] = ['t' => 'line', 'x' => $px, 'y' => $sy($v), 'x2' => $px + $pw, 'y2' => $sy($v), 'lw' => abs($v) < 1e-9 ? 0.8 : 0.5, 'color' => abs($v) < 1e-9 ? [0.75, 0.77, 0.8, 1] : $grid];
                $ops = array_merge($ops, $this->e->textOps(self::fmt($v, $o + ['unit' => null]), $px - 4, $sy($v) + 2.5, $st, 7.5, $txt, null, 'right'));
            }
            $band = $pw / max(1, $n); $nd = count($c['datasets']);
            $every = max(1, (int)ceil($n / max(1, floor($pw / 34))));
            for ($i = 0; $i < $n; $i++) if ($i % $every === 0) $ops = array_merge($ops, $this->e->textOps($labels[$i], $px + $band * ($i + 0.5), $py + $ph + 11, $st, 7.5, $txt, $band * $every - 2, 'center'));
            // бары
            $barSets = []; $lineSets = [];
            foreach ($c['datasets'] as $di => $d) { $t = $d['type'] ?? ($isLine ? 'line' : 'bar'); if ($t === 'line' || $t === 'area') $lineSets[$di] = $d; else $barSets[$di] = $d; }
            $nb = count($barSets); $k = 0; $acc = array_fill(0, $n, 0.0); $accN = array_fill(0, $n, 0.0);
            foreach ($barSets as $di => $d) {
                $single = $nd === 1 && is_array($d['color']);
                for ($i = 0; $i < $n; $i++) {
                    $v = $d['data'][$i] ?? 0;
                    $col = $single ? $this->color($i, $d['color'], $o) : $this->color($di, is_string($d['color']) ? $d['color'] : null, $o);
                    if ($stacked) { $base = $v >= 0 ? $acc[$i] : $accN[$i]; $y0 = $sy($base); $y1 = $sy($base + $v); if ($v >= 0) $acc[$i] += $v; else $accN[$i] += $v; $bw = $band * 0.6; $bx = $px + $band * $i + $band * 0.2; }
                    else { $bw = $band * 0.72 / max(1, $nb); $bx = $px + $band * $i + $band * 0.14 + $bw * $k; $y0 = $sy(0); $y1 = $sy($v); }
                    $ops[] = ['t' => 'rect', 'x' => $bx + 0.4, 'y' => min($y0, $y1), 'w' => max(0.5, $bw - 0.8), 'h' => max(0.3, abs($y1 - $y0)), 'fill' => $col, 'r' => min(2.5, $bw / 4)];
                    if (($o['valueLabels'] ?? ($n * $nb <= 16)) && !$stacked) $ops = array_merge($ops, $this->e->textOps(self::fmt($v, $o), $bx + $bw / 2, min($y0, $y1) - 3, $st, 6.8, [0.25, 0.27, 0.33, 1], null, 'center'));
                }
                $k++;
            }
            foreach ($lineSets as $di => $d) {
                $col = $this->color($di, is_string($d['color']) ? $d['color'] : null, $o);
                $pts = [];
                for ($i = 0; $i < count($d['data']); $i++) $pts[] = [$px + $band * ($i + 0.5), $sy($d['data'][$i])];
                if (!$pts) continue;
                $smooth = $o['smooth'] ?? true;
                $path = [['M', $pts[0][0], $pts[0][1]]];
                for ($i = 1; $i < count($pts); $i++) {
                    if ($smooth) {
                        $p0 = $pts[max(0, $i - 2)]; $p1 = $pts[$i - 1]; $p2 = $pts[$i]; $p3 = $pts[min(count($pts) - 1, $i + 1)];
                        $t = 0.18;
                        $path[] = ['C', $p1[0] + ($p2[0] - $p0[0]) * $t, $p1[1] + ($p2[1] - $p0[1]) * $t, $p2[0] - ($p3[0] - $p1[0]) * $t, $p2[1] - ($p3[1] - $p1[1]) * $t, $p2[0], $p2[1]];
                    } else $path[] = ['L', $pts[$i][0], $pts[$i][1]];
                }
                if ($type === 'area' || ($d['type'] ?? '') === 'area' || !empty($o['fill'])) {
                    $area = $path; $area[] = ['L', end($pts)[0], $sy(max($lo, 0))]; $area[] = ['L', $pts[0][0], $sy(max($lo, 0))]; $area[] = ['Z'];
                    $fc = $col; $fc[3] = 0.15;
                    $ops[] = ['t' => 'path', 'pts' => $area, 'fill' => $fc];
                }
                $ops[] = ['t' => 'path', 'pts' => $path, 'stroke' => $col, 'lw' => 1.8];
                foreach ($pts as $p) $ops[] = ['t' => 'path', 'pts' => self::circle($p[0], $p[1], 2.2), 'fill' => [1, 1, 1, 1], 'stroke' => $col, 'lw' => 1.2];
            }
        }
        if ($legend) $ops = array_merge($ops, $this->legend($c, $w, $h - 4, $st));
        return $ops;
    }

    public static function circle(float $cx, float $cy, float $r): array
    {
        $k = 0.5523 * $r;
        return [['M', $cx + $r, $cy], ['C', $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r], ['C', $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy],
            ['C', $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r], ['C', $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy], ['Z']];
    }

    /** Дуга как набор кубических Безье (≤90° на сегмент). */
    public static function arc(float $cx, float $cy, float $r, float $a0, float $a1, bool $move = true): array
    {
        $segs = []; $n = max(1, (int)ceil(abs($a1 - $a0) / (M_PI / 2)));
        $d = ($a1 - $a0) / $n;
        if ($move) $segs[] = ['M', $cx + $r * cos($a0), $cy + $r * sin($a0)];
        else $segs[] = ['L', $cx + $r * cos($a0), $cy + $r * sin($a0)];
        for ($i = 0; $i < $n; $i++) {
            $s = $a0 + $d * $i; $e = $s + $d; $k = 4 / 3 * tan($d / 4) * $r;
            $segs[] = ['C', $cx + $r * cos($s) - $k * sin($s), $cy + $r * sin($s) + $k * cos($s), $cx + $r * cos($e) + $k * sin($e), $cy + $r * sin($e) - $k * cos($e), $cx + $r * cos($e), $cy + $r * sin($e)];
        }
        return $segs;
    }

    private function pie(array $c, float $w, float $h, float $top, array $st, bool $donut): array
    {
        $o = $c['o']; $ops = [];
        $d = $c['datasets'][0];
        $vals = array_map(fn($v) => max(0, $v), $d['data']);
        $total = array_sum($vals);
        if ($total <= 0) return $this->empty($w, $h, $st, 'Нет данных');
        $legendW = min($w * 0.42, 150);
        $r = min(($w - $legendW - 10) / 2, ($h - $top - 8) / 2);
        $cx = 6 + $r; $cy = $top + 4 + $r + max(0, ($h - $top - 8 - 2 * $r) / 2);
        $a = -M_PI / 2; $ri = $donut ? $r * (1 - (float)($o['donutWidth'] ?? 0.38)) : 0;
        $f = $this->e->fonts->get($st['font-family'] ?? 'sans-serif', 'normal', 'normal')[0];
        foreach ($vals as $i => $v) {
            if ($v <= 0) continue;
            $sweep = $v / $total * 2 * M_PI; $col = $this->color($i, $d['color'], $o);
            if ($sweep >= 2 * M_PI - 1e-6) $sweep = 2 * M_PI - 1e-4;
            $path = self::arc($cx, $cy, $r, $a, $a + $sweep);
            if ($donut) { foreach (array_slice(self::arc($cx, $cy, $ri, $a + $sweep, $a, false), 0) as $sgm) $path[] = $sgm; }
            else $path[] = ['L', $cx, $cy];
            $path[] = ['Z'];
            $ops[] = ['t' => 'path', 'pts' => $path, 'fill' => $col, 'stroke' => [1, 1, 1, 1], 'lw' => 1];
            $pct = $v / $total * 100;
            if ($pct >= 5) {
                $mid = $a + $sweep / 2; $lr = $donut ? ($r + $ri) / 2 : $r * 0.62;
                $ops = array_merge($ops, $this->e->textOps(number_format($pct, 0) . '%', $cx + $lr * cos($mid), $cy + $lr * sin($mid) + 3, $st, 7.5, [1, 1, 1, 1], null, 'center', true));
            }
            $a += $sweep;
        }
        if ($donut && isset($o['center'])) $ops = array_merge($ops, $this->e->textOps((string)$o['center'], $cx, $cy + 4, $st, min(16, $ri / 2.5), [0.1, 0.12, 0.16, 1], $ri * 1.6, 'center', true));
        elseif ($donut) $ops = array_merge($ops, $this->e->textOps(self::fmt($total, $o), $cx, $cy + 4, $st, min(13, $ri / 3), [0.1, 0.12, 0.16, 1], $ri * 1.7, 'center', true));
        // легенда справа
        $lx = $cx + $r + 16; $n = count($vals); $lh = min(16, ($h - $top) / max(1, $n));
        $ly = $cy - $lh * $n / 2 + $lh * 0.7;
        foreach ($vals as $i => $v) {
            $col = $this->color($i, $d['color'], $o);
            $ops[] = ['t' => 'rect', 'x' => $lx, 'y' => $ly - 7, 'w' => 8, 'h' => 8, 'fill' => $col, 'r' => 2];
            $ops = array_merge($ops, $this->e->textOps(($c['labels'][$i] ?? '#' . ($i + 1)), $lx + 12, $ly, $st, 8, [0.25, 0.27, 0.33, 1], min($w - $lx - 60, 100)));
            $ops = array_merge($ops, $this->e->textOps(self::fmt($v, $o), min($w - 2, $lx + 150), $ly, $st, 8, [0.45, 0.47, 0.52, 1], null, 'right', true));
            $ly += $lh;
        }
        return $ops;
    }

    private function gauge(array $c, float $w, float $h, float $top, array $st): array
    {
        $v = $c['datasets'][0]['data'][0] ?? 0; $max = (float)($c['o']['max'] ?? 100);
        $p = $max > 0 ? max(0, min(1, $v / $max)) : 0;
        $r = min($w / 2 - 4, $h - $top - 10); $cx = $w / 2; $cy = $top + $r + 4; $th = $r * 0.22;
        $col = $this->color(0, $c['datasets'][0]['color'], $c['o']);
        $bgp = self::arc($cx, $cy, $r, M_PI, 2 * M_PI); foreach (self::arc($cx, $cy, $r - $th, 2 * M_PI, M_PI, false) as $s) $bgp[] = $s; $bgp[] = ['Z'];
        $fgp = self::arc($cx, $cy, $r, M_PI, M_PI + M_PI * $p); foreach (self::arc($cx, $cy, $r - $th, M_PI + M_PI * $p, M_PI, false) as $s) $fgp[] = $s; $fgp[] = ['Z'];
        $ops = [['t' => 'path', 'pts' => $bgp, 'fill' => [0.92, 0.93, 0.95, 1]]];
        if ($p > 0.001) $ops[] = ['t' => 'path', 'pts' => $fgp, 'fill' => $col];
        return array_merge($ops, $this->e->textOps(self::fmt($v, $c['o']), $cx, $cy - 4, $st, min(20, $r / 3), [0.1, 0.12, 0.16, 1], null, 'center', true));
    }
}
