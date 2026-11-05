<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

use BaurPDF\Css\Length;

/**
 * CSS Grid: явные/неявные треки (len, %, fr, auto, min/max-content, minmax, fit-content, repeat/auto-fill/auto-fit),
 * grid-template-rows / grid-auto-rows, grid-template-areas + grid-area, grid-row/grid-column (линии, span, -1),
 * grid-auto-flow: row|column [dense], align/justify-items/-self, row-gap/column-gap.
 * Ряды, связанные row-span, становятся одним неразрывным атомом для пагинатора.
 */
final class GridLayout
{
    public function __construct(private Engine $e, private FlexLayout $fl) {}

    /** @return list<array{0:string,1:float,2:float}> [kind, value, min] kind: len|fr|auto|min|max */
    public function tracks(string $v, float $w, float $gap, float $fs): array
    {
        $v = trim($v);
        if ($v === '' || $v === 'none') return [];
        $v = (string)preg_replace('/\[[^\]]*\]/', ' ', $v); // именованные линии игнорируются
        $v = preg_replace_callback('/repeat\(\s*(auto-fill|auto-fit|\d+)\s*,\s*((?:[^()]|\([^()]*\))+)\)/i', function ($m) use ($w, $gap, $fs) {
            $n = $m[1];
            if (!ctype_digit($n)) {
                $one = 0.0;
                foreach ($this->tracks($m[2], $w, 0, $fs) as $t) $one += $t[0] === 'len' ? $t[1] : max($t[2], 0);
                $cnt = max(1, preg_match_all('/minmax\([^)]*\)|[^\s]+/', trim($m[2])));
                $n = max(1, (int)floor(($w + $gap) / (max(1.0, $one ?: 100.0) + $gap * $cnt)));
            }
            return trim(str_repeat($m[2] . ' ', min(100, (int)$n)));
        }, $v) ?? $v;
        preg_match_all('/(?:minmax|fit-content)\([^)]*\)|[^\s]+/i', $v, $mm);
        $out = [];
        foreach ($mm[0] as $t) {
            $t = strtolower($t);
            if (str_starts_with($t, 'minmax(')) {
                $parts = array_map('trim', explode(',', substr($t, 7, -1)));
                $lo = $this->one($parts[0] ?? 'auto', $w, $fs); $hi = $this->one($parts[1] ?? 'auto', $w, $fs);
                $min = $lo[0] === 'len' ? $lo[1] : 0.0;
                if ($hi[0] === 'fr') $out[] = ['fr', $hi[1], $min];
                elseif ($hi[0] === 'len') $out[] = $min >= $hi[1] ? ['len', $min, $min] : ['auto', $hi[1], $min];
                else $out[] = ['auto', INF, $min];
            } elseif (str_starts_with($t, 'fit-content(')) {
                $l = Length::toPt(trim(substr($t, 12, -1)), $w, $fs) ?? INF;
                $out[] = ['auto', $l, 0.0];
            } else $out[] = $this->one($t, $w, $fs);
        }
        return $out;
    }

    /** @return array{0:string,1:float,2:float} */
    private function one(string $t, float $w, float $fs): array
    {
        if (preg_match('/^([\d.]+)fr$/', $t, $m)) return ['fr', (float)$m[1], 0.0];
        if ($t === 'auto') return ['auto', INF, 0.0];
        if ($t === 'min-content') return ['min', 0.0, 0.0];
        if ($t === 'max-content') return ['max', INF, 0.0];
        $l = Length::toPt($t, $w, $fs);
        return $l === null ? ['auto', INF, 0.0] : ['len', max(0.0, $l), max(0.0, $l)];
    }

    /** Разбор grid-row / grid-column: [start|null, span]. */
    private function line(array $st, string $axis, array $areas, int $n): array
    {
        $v = strtolower(trim((string)($st['grid-' . $axis] ?? '')));
        if ($v === '' || $v === 'auto') {
            $s = strtolower(trim((string)($st['grid-' . $axis . '-start'] ?? 'auto')));
            $e = strtolower(trim((string)($st['grid-' . $axis . '-end'] ?? 'auto')));
            $v = $s . ' / ' . $e;
        }
        $ga = trim((string)($st['grid-area'] ?? ''));
        if ($ga !== '' && $ga !== 'auto') {
            if (isset($areas[$ga])) { $a = $areas[$ga]; return $axis === 'row' ? [$a[0], $a[2] - $a[0] + 1] : [$a[1], $a[3] - $a[1] + 1]; }
            $p = array_map('trim', explode('/', $ga));
            $i = $axis === 'row' ? 0 : 1;
            $v = ($p[$i] ?? 'auto') . ' / ' . ($p[$i + 2] ?? 'auto');
        }
        $p = array_map('trim', explode('/', $v, 2));
        $a = $p[0]; $b = $p[1] ?? 'auto';
        if (isset($areas[$a])) { $ar = $areas[$a]; return $axis === 'row' ? [$ar[0], $ar[2] - $ar[0] + 1] : [$ar[1], $ar[3] - $ar[1] + 1]; }
        $num = fn(string $s): ?int => preg_match('/^-?\d+$/', $s) ? (int)$s : null;
        $res = fn(int $l): int => $l < 0 ? max(0, $n + 1 + $l) : max(0, $l - 1);
        $start = null; $span = 1;
        if (preg_match('/^span\s+(\d+)$/', $a, $m)) $span = (int)$m[1];
        elseif (($l = $num($a)) !== null && $l !== 0) $start = $res($l);
        if (preg_match('/^span\s+(\d+)$/', $b, $m)) $span = (int)$m[1];
        elseif (($l = $num($b)) !== null && $l !== 0 && $start !== null) { $end = $res($l); if ($end < $start) [$start, $end] = [$end, $start]; $span = max(1, $end - $start); }
        return [$start, max(1, min(1000, $span))];
    }

    /** @return array<string, array{0:int,1:int,2:int,3:int}> name → [r0,c0,r1,c1] */
    private function areas(string $v): array
    {
        $out = [];
        if (!preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $v, $m)) return [];
        foreach ($m[0] as $r => $_) {
            $row = preg_split('/\s+/', trim($m[1][$r] !== '' ? $m[1][$r] : $m[2][$r])) ?: [];
            foreach ($row as $c => $name) {
                if ($name === '' || $name[0] === '.') continue;
                if (!isset($out[$name])) $out[$name] = [$r, $c, $r, $c];
                else { $o = $out[$name]; $out[$name] = [min($o[0], $r), min($o[1], $c), max($o[2], $r), max($o[3], $c)]; }
            }
        }
        return $out;
    }

    public function layout(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth): void
    {
        $fs = (float)$st['_fs'];
        $items = $this->fl->items($el);
        if (!$items) return;
        $rowGap = Length::toPt($st['row-gap'] ?? '0', $w, $fs) ?? 0;
        $colGap = Length::toPt($st['column-gap'] ?? '0', $w, $fs) ?? 0;
        $areas = $this->areas((string)($st['grid-template-areas'] ?? ''));
        $cols = $this->tracks((string)($st['grid-template-columns'] ?? ''), $w, $colGap, $fs);
        $acols = 0; foreach ($areas as $a) $acols = max($acols, $a[3] + 1);
        $autoCol = $this->tracks((string)($st['grid-auto-columns'] ?? 'auto'), $w, 0, $fs)[0] ?? ['auto', INF, 0.0];
        if (count($cols) < $acols) for ($i = count($cols); $i < $acols; $i++) $cols[] = $autoCol;
        $flowDir = strtolower((string)($st['grid-auto-flow'] ?? 'row'));
        $dense = str_contains($flowDir, 'dense'); $byCol = str_contains($flowDir, 'column');
        $rowsT = $this->tracks((string)($st['grid-template-rows'] ?? ''), $this->e->pageContentHeight, $rowGap, $fs);
        $autoRow = $this->tracks((string)($st['grid-auto-rows'] ?? 'auto'), $this->e->pageContentHeight, 0, $fs)[0] ?? ['auto', INF, 0.0];
        $nExplicitRows = count($rowsT); foreach ($areas as $a) $nExplicitRows = max($nExplicitRows, $a[2] + 1);

        // ---- размещение ----
        /** @var array<int, array{0:int,1:int,2:int,3:int}> $place */
        $place = [];
        /** @var array<int, array<int, int>> $occ */
        $occ = [];
        $n = max(1, count($cols));
        /** @var array<int, array{0:?int,1:int,2:?int,3:int}> $parsed */
        $parsed = [];
        foreach ($items as $i => $it) {
            [$cs, $csp] = $this->line($it['st'], 'column', $areas, $n);
            [$rs, $rsp] = $this->line($it['st'], 'row', $areas, max(1, $nExplicitRows));
            $parsed[$i] = [$rs, $rsp, $cs, $csp];
            if ($cs !== null) $n = max($n, $cs + $csp);
            elseif (!$byCol) $n = max($n, min($csp, 1000));
        }
        $n = (int)min($n, 1000);
        while (count($cols) < $n) $cols[] = $autoCol;
        if (!$cols) $cols = [['fr', 1.0, 0.0]];
        $free = function (int $r, int $c, int $rsp, int $csp) use (&$occ, &$n): bool {
            if ($c + $csp > $n) return false;
            for ($i = $r; $i < $r + $rsp; $i++) for ($j = $c; $j < $c + $csp; $j++) if (isset($occ[$i][$j])) return false;
            return true;
        };
        $mark = function (int $r, int $c, int $rsp, int $csp, int $k) use (&$occ): void { for ($i = $r; $i < $r + $rsp; $i++) for ($j = $c; $j < $c + $csp; $j++) $occ[$i][$j] = $k; };
        // 1) полностью явные
        foreach ($parsed as $k => [$rs, $rsp, $cs, $csp]) if ($rs !== null && $cs !== null) { $place[$k] = [$rs, $cs, $rsp, $csp]; $mark($rs, $cs, $rsp, $csp, $k); }
        // 2) явный ряд, авто-колонка
        foreach ($parsed as $k => [$rs, $rsp, $cs, $csp]) if ($rs !== null && $cs === null) {
            $c = 0; while (!$free($rs, $c, $rsp, $csp) && $c < $n) $c++;
            if ($c >= $n || $c + $csp > $n) $c = 0;
            $place[$k] = [$rs, $c, $rsp, min($csp, $n)]; $mark($rs, $c, $rsp, min($csp, $n), $k);
        }
        // 3) авто
        $cr = 0; $cc = 0;
        foreach ($parsed as $k => [$rs, $rsp, $cs, $csp]) {
            if (isset($place[$k])) continue;
            $csp = (int)min($csp, (int)$n);
            if ($dense) { $cr = 0; $cc = 0; }
            if ($cs !== null) {
                if (!$dense && $cs < $cc) $cr++;
                $r = $cr; while (!$free($r, $cs, $rsp, $csp)) $r++;
                $place[$k] = [$r, $cs, $rsp, $csp]; $mark($r, $cs, $rsp, $csp, $k);
                $cr = $r; $cc = $cs + $csp;
                continue;
            }
            if ($byCol) {
                $rowsN = max(1, $nExplicitRows);
                $c = $cc; $r = $cr;
                for ($guard = 0; $guard < 100000; $guard++) {
                    if ($r + $rsp > $rowsN) { $r = 0; $c++; continue; }
                    if ($c + $csp > (int)$n) { for ($q = count($cols); $q < $c + $csp; $q++) $cols[] = $autoCol; $n = $c + $csp; }
                    if ($free($r, $c, $rsp, $csp)) break;
                    $r++;
                }
                $place[$k] = [$r, $c, $rsp, $csp]; $mark($r, $c, $rsp, $csp, $k);
                $cr = $r + $rsp; $cc = $c;
                continue;
            }
            $r = $cr; $c = $cc;
            for ($guard = 0; $guard < 100000; $guard++) {
                if ($c + $csp > $n) { $c = 0; $r++; continue; }
                if ($free($r, $c, $rsp, $csp)) break;
                $c++;
            }
            $place[$k] = [$r, $c, $rsp, $csp]; $mark($r, $c, $rsp, $csp, $k);
            $cr = $r; $cc = $c + $csp;
        }
        $nRows = max($nExplicitRows, 1); foreach ($place as $p) $nRows = max($nRows, $p[0] + $p[2]);
        $nRows = (int)min($nRows, 100000);

        // ---- ширины колонок ----
        $widths = $this->sizeColumns($cols, $items, $place, $w, $colGap);
        $colX = []; $cx = 0.0; foreach ($widths as $i => $cw) { $colX[$i] = $cx; $cx += $cw + $colGap; }
        $total = $cx - $colGap;
        $jc = strtolower((string)($st['justify-content'] ?? 'start'));
        $off = 0.0; $free0 = max(0.0, $w - $total);
        if ($free0 > 0.01) {
            if ($jc === 'center') $off = $free0 / 2;
            elseif ($jc === 'end' || $jc === 'flex-end' || $jc === 'right') $off = $free0;
            elseif (in_array($jc, ['space-between', 'space-around', 'space-evenly'], true) && $n > 0) {
                $g = $jc === 'space-between' ? ($n > 1 ? $free0 / ($n - 1) : 0) : ($jc === 'space-around' ? $free0 / $n : $free0 / ($n + 1));
                $o0 = $jc === 'space-between' ? 0 : ($jc === 'space-around' ? $g / 2 : $g);
                foreach ($colX as $i => &$v) $v += $o0 + $g * $i; unset($v);
            }
        }

        // ---- вёрстка элементов и высоты рядов ----
        $ji = strtolower((string)($st['justify-items'] ?? 'stretch'));
        $ai = strtolower((string)($st['align-items'] ?? 'stretch'));
        $laid = [];
        $rowH = [];
        for ($r = 0; $r < $nRows; $r++) { $t = $rowsT[$r] ?? $autoRow; $rowH[$r] = $t[0] === 'len' ? $t[1] : max(0.0, $t[2]); }
        foreach ($place as $k => [$r, $c, $rsp, $csp]) {
            $it = $items[$k];
            $aw = array_sum(array_slice($widths, $c, $csp)) + $colGap * ($csp - 1);
            $js = strtolower((string)($it['st']['justify-self'] ?? 'auto')); if ($js === 'auto' || $js === 'normal') $js = $ji;
            $iw = $aw; $dx = 0.0;
            if ($js !== 'stretch' && $js !== 'normal' && trim((string)($it['st']['width'] ?? 'auto')) === 'auto') {
                $iw = min($aw, $this->e->minMax($it['el'], $it['st'])[1]);
                $dx = match ($js) { 'center' => ($aw - $iw) / 2, 'end', 'right', 'flex-end', 'self-end' => $aw - $iw, default => 0.0 };
            }
            [$f, $h] = $this->fl->layoutItem($it['el'], $it['st'], $iw);
            $laid[$k] = [$f, $h, $dx];
            if ($rsp === 1) { $t = $rowsT[$r] ?? $autoRow; if ($t[0] !== 'len') $rowH[$r] = max($rowH[$r], min($h, $t[1])); }
        }
        // элементы с row-span: добираем высоту в последние авто-ряды
        foreach ($place as $k => [$r, $c, $rsp, $csp]) {
            if ($rsp < 2) continue;
            $have = array_sum(array_slice($rowH, $r, $rsp)) + $rowGap * ($rsp - 1);
            $need = $laid[$k][1] - $have;
            if ($need <= 0.01) continue;
            $auto = []; for ($i = $r; $i < $r + $rsp; $i++) if ((($rowsT[$i] ?? $autoRow)[0]) !== 'len') $auto[] = $i;
            if (!$auto) $auto = [$r + $rsp - 1];
            foreach ($auto as $i) $rowH[$i] += $need / count($auto);
        }
        // ---- полосы (ряды, связанные row-span) ----
        $bandEnd = [];
        for ($r = 0; $r < $nRows; $r++) $bandEnd[$r] = $r;
        foreach ($place as [$r, $c, $rsp]) for ($i = $r; $i < $r + $rsp; $i++) $bandEnd[$i] = max($bandEnd[$i], $r + $rsp - 1);
        $rowY = []; $yy = 0.0; for ($r = 0; $r < $nRows; $r++) { $rowY[$r] = $yy; $yy += $rowH[$r] + $rowGap; }
        $byStart = []; foreach ($place as $k => $p) $byStart[$p[0]][] = $k;
        $r = 0;
        $first = true;
        while ($r < $nRows) {
            $end = $r; for ($i = $r; $i <= $end; $i++) $end = max($end, $bandEnd[$i]);
            $bandH = $rowY[$end] + $rowH[$end] - $rowY[$r];
            if (!$first) $y += $rowGap;
            $first = false;
            $keys = []; for ($i = $r; $i <= $end; $i++) foreach ($byStart[$i] ?? [] as $k) $keys[] = $k;
            if ($bandH > $this->e->pageContentHeight * 0.95 && count($keys) <= max(1, $n)) {
                // слишком высокая полоса: элементы идут потоком по одному (разрываемо)
                usort($keys, fn($a, $b) => $place[$a][1] <=> $place[$b][1]);
                foreach ($keys as $k) $this->e->layoutBlock($items[$k]['el'], $items[$k]['st'], $x, $w, $flow, $y, $depth);
                $r = $end + 1; continue;
            }
            $ops = [];
            foreach ($keys as $k) {
                [$pr, $pc, $rsp, $csp] = $place[$k];
                [$f, $h, $dx] = $laid[$k];
                $areaH = $rowY[$pr + $rsp - 1] + $rowH[$pr + $rsp - 1] - $rowY[$pr];
                $as = strtolower((string)($items[$k]['st']['align-self'] ?? 'auto')); $a = ($as === 'auto' || $as === 'normal') ? $ai : $as;
                $dy = match ($a) { 'center' => ($areaH - $h) / 2, 'end', 'flex-end', 'self-end' => $areaH - $h, default => 0.0 };
                if ($a === 'stretch' && trim((string)($items[$k]['st']['height'] ?? 'auto')) === 'auto') {
                    $m = $this->e->boxMetrics($items[$k]['st'], $w);
                    foreach ($f->decos as &$d) if ($d['depth'] === 0) $d['bottom'] = $areaH - (is_numeric($m['mb']) ? (float)$m['mb'] : 0); unset($d);
                }
                foreach ($f->flatten($x + $off + $colX[$pc] + $dx, $rowY[$pr] - $rowY[$r] + max(0.0, $dy)) as $o) $ops[] = $o;
            }
            $flow->add($y, $bandH, Ops::translate($ops, 0, $y));
            $y += $bandH;
            $r = $end + 1;
        }
    }

    /**
     * @param list<array{0:string,1:float,2:float}> $cols
     * @return list<float>
     */
    private function sizeColumns(array $cols, array $items, array $place, float $w, float $gap): array
    {
        $n = count($cols);
        $avail = max(0.0, $w - $gap * ($n - 1));
        $base = []; $limit = [];
        $needMM = false; foreach ($cols as $t) if ($t[0] !== 'len' && $t[0] !== 'fr') $needMM = true;
        $mm = [];
        if ($needMM) foreach ($place as $k => $p) if ($p[3] === 1) $mm[$p[1]][] = $this->e->minMax($items[$k]['el'], $items[$k]['st']);
        foreach ($cols as $i => $t) {
            $mins = array_map(fn($v) => $v[0], $mm[$i] ?? []); $maxs = array_map(fn($v) => $v[1], $mm[$i] ?? []);
            $mn = $mins ? max($mins) : 0.0; $mx = $maxs ? max($maxs) : 0.0;
            switch ($t[0]) {
                case 'len': $base[$i] = $t[1]; $limit[$i] = $t[1]; break;
                case 'fr': $base[$i] = $t[2]; $limit[$i] = $t[2]; break;
                case 'min': $base[$i] = $mn; $limit[$i] = $mn; break;
                case 'max': $base[$i] = $mx; $limit[$i] = $mx; break;
                default: $base[$i] = max($t[2], min($mn, $t[1])); $limit[$i] = max($base[$i], min($mx, $t[1]));
            }
        }
        // авто-треки растут от min к max, пока есть место
        $used = array_sum($base);
        $grow = 0.0; foreach ($cols as $i => $t) if ($t[0] === 'auto') $grow += $limit[$i] - $base[$i];
        $hasFr = false; foreach ($cols as $t) if ($t[0] === 'fr') $hasFr = true;
        $space = $avail - $used;
        if ($grow > 0 && $space > 0) { $k = min(1.0, $space / $grow); foreach ($cols as $i => $t) if ($t[0] === 'auto') $base[$i] += ($limit[$i] - $base[$i]) * $k; }
        $space = $avail - array_sum($base);
        if ($hasFr && $space > 0) {
            // fr: делим остаток, уважая минимумы (итеративно исключаем треки, чей минимум больше доли)
            $frs = []; foreach ($cols as $i => $t) if ($t[0] === 'fr') $frs[$i] = $t;
            $rest = $avail; foreach ($cols as $i => $t) if ($t[0] !== 'fr') $rest -= $base[$i];
            for ($iter = 0; $iter < 10; $iter++) {
                $sumFr = 0.0; foreach ($frs as $t) $sumFr += $t[1];
                if ($sumFr <= 0) break;
                $unit = $rest / $sumFr; $changed = false;
                foreach ($frs as $i => $t) if ($t[2] > $unit * $t[1]) { $base[$i] = $t[2]; $rest -= $t[2]; unset($frs[$i]); $changed = true; }
                if (!$changed) { foreach ($frs as $i => $t) $base[$i] = max($t[2], $unit * $t[1]); break; }
            }
        } elseif (!$hasFr && $space > 0.01) {
            // auto-треки растягиваются (justify-content: normal ≈ stretch для auto)
            $autos = array_keys(array_filter($cols, fn($t) => $t[0] === 'auto'));
            if ($autos) foreach ($autos as $i) $base[$i] += $space / count($autos);
        }
        $sum = array_sum($base);
        if ($sum > $avail && $sum > 0) {
            // переполнение: ужимаем не-фиксированные, затем все пропорционально
            $flex = 0.0; foreach ($cols as $i => $t) if ($t[0] !== 'len') $flex += $base[$i];
            $over = $sum - $avail;
            if ($flex > $over) { foreach ($cols as $i => $t) if ($t[0] !== 'len') $base[$i] -= $over * $base[$i] / $flex; }
            else { $k = $avail / $sum; foreach ($base as &$v) $v *= $k; unset($v); }
        }
        return array_values(array_map(fn($v) => max(0.0, (float)$v), $base));
    }
}
