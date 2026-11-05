<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

use BaurPDF\Css\Color;
use BaurPDF\Css\Length;

/** Таблицы: auto/fixed ширины колонок, colspan/rowspan, border-collapse, повтор thead и tfoot на каждой странице, caption. */
final class TableLayout
{
    public function __construct(private Engine $e) {}

    public function layout(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, array $m): void
    {
        $fs = (float)$st['_fs'];
        $collapse = strtolower($st['border-collapse'] ?? 'separate') === 'collapse';
        $sp = $collapse ? 0 : (Length::toPt(explode(' ', trim($st['border-spacing'] ?? '2px'))[0], 0, $fs) ?? 0);
        $rows = $this->e->tableRows($el);
        // maxPages: строки, которые заведомо не попадут в документ (ниже предела высоты даже при минимальной высоте строки), не раскладываем
        if ($this->e->maxHeight > 0 && $rows) {
            $cap = (int)ceil(max(0.0, $this->e->maxHeight - $y) / max(4.0, $fs)) + 1;
            if (count($rows) > $cap) { $rows = array_slice($rows, 0, $cap); $this->e->warnings['maxpages-table'] = 'maxPages reached: table truncated'; }
        }
        // caption
        foreach ($el->childNodes as $c) if ($c instanceof \DOMElement && strtolower($c->nodeName) === 'caption') {
            $cs = $this->e->style($c); $cs['display'] = 'block';
            $this->e->layoutBlock($c, $cs, $x, $w, $flow, $y, $depth);
        }
        if (!$rows) return;
        // сетка с учётом colspan/rowspan
        $grid = []; $occupied = []; $ncols = 0;
        foreach ($rows as $ri => $row) {
            $ci = 0;
            foreach ($row['cells'] as $cell) {
                while (isset($occupied[$ri][$ci])) $ci++;
                $cspan = max(1, min(50, (int)($cell->getAttribute('colspan') ?: 1)));
                $rspan = max(1, min(count($rows) - $ri, (int)($cell->getAttribute('rowspan') ?: 1)));
                $grid[$ri][] = ['el' => $cell, 'col' => $ci, 'cspan' => $cspan, 'rspan' => $rspan];
                for ($r = 0; $r < $rspan; $r++) for ($k = 0; $k < $cspan; $k++) $occupied[$ri + $r][$ci + $k] = true;
                $ci += $cspan;
                $ncols = max($ncols, $ci);
            }
        }
        if ($ncols === 0) return;
        $hpad = $m['pl'] + $m['pr'] + $m['bl'] + $m['br'];
        $ml = is_numeric($m['ml']) ? (float)$m['ml'] : 0; $mr = is_numeric($m['mr']) ? (float)$m['mr'] : 0;
        $specW = Length::toPt($st['width'] ?? null, $w, $fs);
        $availInner = ($specW ?? ($w - $ml - $mr)) - ($specW !== null && strtolower($st['box-sizing'] ?? '') === 'border-box' ? $hpad : 0) - ($specW === null ? $hpad : 0);
        $availInner = max(10, min($availInner, $w - $ml - $mr - $hpad));
        // Spacing may exceed available width for very wide tables; keep geometry finite.
        $avail = max(1.0, $availInner - $sp * ($ncols + 1));
        // min/max по колонкам
        $min = array_fill(0, $ncols, 0.0); $max = array_fill(0, $ncols, 0.0); $fixed = array_fill(0, $ncols, null);
        $fixedLayout = strtolower($st['table-layout'] ?? 'auto') === 'fixed';
        foreach ($grid as $ri => $cells) foreach ($cells as $c) {
            $cs = $this->e->style($c['el']);
            $cw = $cs['width'] ?? null;
            if ($c['cspan'] === 1 && $cw !== null) {
                $cm = $this->e->boxMetrics($cs, $avail);
                $v = Length::toPt($cw, $avail, (float)$cs['_fs']);
                if ($v !== null) $fixed[$c['col']] = max($fixed[$c['col']] ?? 0, $v + (strtolower($cs['box-sizing'] ?? '') === 'border-box' ? 0 : $cm['pl'] + $cm['pr'] + $cm['bl'] + $cm['br']));
            }
            if ($fixedLayout && $ri > 0) continue;
            [$a, $b] = $fixedLayout ? [0, 0] : $this->e->minMax($c['el'], $cs);
            if ($collapse && !$fixedLayout) { $cm = $this->e->boxMetrics($cs, $avail); $hb = ($cm['bl'] + $cm['br']) / 2; $a = max(0, $a - $hb); $b = max(0, $b - $hb); } // collapse: половина рамки
            if ($c['cspan'] === 1) { $min[$c['col']] = max($min[$c['col']], $a); $max[$c['col']] = max($max[$c['col']], $b); }
        }
        foreach ($grid as $cells) foreach ($cells as $c) if ($c['cspan'] > 1 && !$fixedLayout) {
            [$a, $b] = $this->e->minMax($c['el']);
            $sa = 0; $sb = 0; for ($k = 0; $k < $c['cspan']; $k++) { $sa += $min[$c['col'] + $k]; $sb += $max[$c['col'] + $k]; }
            if ($a > $sa) for ($k = 0; $k < $c['cspan']; $k++) $min[$c['col'] + $k] += ($a - $sa) / $c['cspan'];
            if ($b > $sb) for ($k = 0; $k < $c['cspan']; $k++) $max[$c['col'] + $k] += ($b - $sb) / $c['cspan'];
        }
        if (!$el->hasAttribute('data-bp-derived') && array_sum($min) > $avail * 1.02 && $ncols > 2) {
            // сначала пробуем перенести длинные слова по символам (как overflow-wrap: anywhere) и только потом ужимать шрифт
            $mode = strtolower(trim($el->getAttribute('data-overflow') ?: ($st['-bp-overflow'] ?? 'auto')));
            $minB = in_array($mode, ['auto', 'wrap', 'shrink'], true) ? $this->breakMin($grid, $ncols, $collapse, $avail) : null;
            if ($minB !== null && array_sum($minB) <= $avail * 1.02 && array_sum($minB) < array_sum($min)) { $min = $minB; $this->e->warnings[] = 'Wide table: long words wrapped inside cells'; }
            elseif ($this->overflow($el, $st, $grid, $min, $avail, $x, $w, $flow, $y, $depth)) return;
        }
        $widths = $this->distribute($min, $max, $fixed, $avail, $specW !== null || $fixedLayout);
        $tableW = array_sum($widths) + $sp * ($ncols + 1) + $hpad;
        // позиция таблицы
        if (($m['ml'] === 'auto') && ($m['mr'] === 'auto')) $tx = $x + ($w - $tableW) / 2;
        elseif ($m['ml'] === 'auto') $tx = $x + $w - $tableW - $mr;
        else $tx = $x + $ml;
        $colX = []; $cx = $tx + $m['bl'] + $m['pl'] + $sp;
        foreach ($widths as $i => $cw) { $colX[$i] = $cx; $cx += $cw + $sp; }
        $colX[$ncols] = $cx - $sp;

        $top = $y;
        $firstAtom = count($flow->atoms);
        $y += $m['bt'] + $m['pt'] + $sp;
        // группировка строк в атомы (rowspan склеивает строки)
        $ri = 0; $n = count($rows); $headerAtom = null; $tableKey = spl_object_id($el);
        // tfoot повторяется внизу каждой страницы, на которой таблица разрывается
        $footAtom = null; $fi = $n;
        while ($fi > 0 && $rows[$fi - 1]['group'] === 'tfoot') $fi--;
        if ($fi < $n && $fi > 0 && strtolower($el->getAttribute('data-repeat-footer')) !== 'false') {
            [$fOps, $fh] = $this->renderRows($grid, $rows, $fi, $n - 1, $colX, $widths, $sp, $collapse, $st);
            $footAtom = ['ops' => $fOps, 'h' => $fh + $sp];
        }
        while ($ri < $n) {
            $end = $ri;
            for ($k = $ri; $k <= $end && $k < $n; $k++) foreach ($grid[$k] ?? [] as $c) $end = max($end, $k + $c['rspan'] - 1);
            [$ops, $h] = $this->renderRows($grid, $rows, $ri, $end, $colX, $widths, $sp, $collapse, $st);
            $isHead = $rows[$ri]['group'] === 'thead';
            $extra = [];
            if ($isHead) $extra['thead'] = $tableKey;
            elseif ($headerAtom) $extra['repeat'] = $headerAtom;
            if ($footAtom && !$isHead && $ri < $fi) $extra['foot'] = $footAtom;
            $limit = $this->e->pageContentHeight - ($headerAtom['h'] ?? 0) - ($footAtom['h'] ?? 0) - 4;
            if (!$isHead && $h > $limit && $limit > 40) {
                // строка выше страницы: аккуратно режем по границам строк текста
                foreach ($this->slice($ops, $h, $limit) as [$sOps, $sh]) { $flow->add($y, $sh, Ops::translate($sOps, 0, $y), $extra); $y += $sh; }
                $y += $sp; $ri = $end + 1; continue;
            }
            $flow->add($y, $h, Ops::translate($ops, 0, $y), $extra);
            if ($footAtom && $end + 1 === $fi) $flow->atoms[count($flow->atoms) - 1]['keepNext'] = true; // последняя строка тела не отрывается от tfoot
            if ($isHead) {
                $flow->atoms[count($flow->atoms) - 1]['keepNext'] = true;
                $headerAtom = ['ops' => $headerAtom ? array_merge($headerAtom['ops'], Ops::translate($ops, 0, $headerAtom['h'])) : $ops, 'h' => ($headerAtom['h'] ?? 0) + $h + $sp];
            }
            $y += $h + $sp;
            $ri = $end + 1;
        }
        $y += $m['pb'] + $m['bb'];
        $deco = $this->e->decoBox($st, $tx, $tableW, $m);
        if ($deco) $flow->decos[] = ['top' => $top, 'bottom' => $y, 'first' => $firstAtom, 'last' => count($flow->atoms) - 1, 'depth' => $depth, 'box' => $deco];
    }

    private function distribute(array $min, array $max, array $fixed, float $avail, bool $fill): array
    {
        $n = count($min);
        $w = [];
        foreach ($min as $i => $v) { if ($fixed[$i] !== null) { $min[$i] = max($min[$i], min($fixed[$i], $avail)); $max[$i] = $min[$i]; } }
        // Every column needs a positive geometric width, even for empty cells.
        foreach ($min as $i => $v) {
            $min[$i] = max(0.01, (float)$v);
            $max[$i] = max($min[$i], (float)$max[$i]);
        }
        $sumMin = array_sum($min); $sumMax = array_sum($max);
        if ($sumMin >= $avail) {
            // не влезает: пропорционально min (с переносом слов внутри ячеек)
            // «водяной уровень»: узкие колонки (числа, статусы) сохраняют min-content,
            // ужимаются только самые широкие (длинные слова/URL переносятся по символам)
            $lo = 0; $hi = max($min) ?: 1;
            for ($it = 0; $it < 40; $it++) { $c = ($lo + $hi) / 2; $s = 0; foreach ($min as $v) $s += min($v, $c); if ($s > $avail) $hi = $c; else $lo = $c; }
            $s = 0; foreach ($min as $i => $v) { $w[$i] = min($v, $lo); $s += $w[$i]; }
            if ($s < $avail) { $big = array_keys(array_filter($min, fn($v) => $v >= $lo - 0.01)); foreach ($big as $i) $w[$i] += ($avail - $s) / max(1, count($big)); }
            if ($lo < 14) { $tot = $sumMin ?: 1; foreach ($min as $i => $v) $w[$i] = $avail * ($v ?: 1) / $tot; } // крайний случай: слишком много колонок
            return $w;
        }
        if ($sumMax <= $avail) {
            $w = $max;
            if ($fill || $sumMax > $avail * 0.0) {
                if ($fill) {
                    $flex = 0; foreach ($w as $i => $v) if ($fixed[$i] === null) $flex += $v;
                    $rest = $avail - $sumMax;
                    $nfree = 0; foreach ($w as $i => $v) if ($fixed[$i] === null) $nfree++;
                    if ($nfree > 0) { foreach ($w as $i => $v) if ($fixed[$i] === null) $w[$i] += $flex > 0 ? $rest * $v / $flex : $rest / $nfree; }
                    else foreach ($w as $i => $v) $w[$i] += $flex > 0 ? 0 : $rest * ($v ?: 1) / max(1e-9, $sumMax ?: $n);
                }
            }
            return $w;
        }
        $range = $sumMax - $sumMin; $room = $avail - $sumMin;
        foreach ($min as $i => $v) $w[$i] = $v + ($range > 0 ? ($max[$i] - $v) * $room / $range : 0);
        return $w;
    }

    /** Минимальные ширины колонок, если длинные слова разрешено рвать по символам. @return list<float> */
    private function breakMin(array $grid, int $ncols, bool $collapse, float $avail): array
    {
        $min = array_fill(0, $ncols, 0.0);
        foreach ($grid as $cells) foreach ($cells as $c) if ($c['cspan'] === 1) {
            $cs = $this->e->style($c['el']);
            [$a] = $this->e->minMaxBreakAll($c['el'], $cs);
            if ($collapse) { $cm = $this->e->boxMetrics($cs, $avail); $a = max(0, $a - ($cm['bl'] + $cm['br']) / 2); }
            $min[$c['col']] = max($min[$c['col']], $a);
        }
        foreach ($grid as $cells) foreach ($cells as $c) if ($c['cspan'] > 1) {
            [$a] = $this->e->minMaxBreakAll($c['el']);
            $sa = 0; for ($k = 0; $k < $c['cspan']; $k++) $sa += $min[$c['col'] + $k];
            if ($a > $sa) for ($k = 0; $k < $c['cspan']; $k++) $min[$c['col'] + $k] += ($a - $sa) / $c['cspan'];
        }
        return $min;
    }

    /**
     * Слишком широкая таблица. Режим: CSS `-bp-overflow` / атрибут data-overflow: auto|shrink|split|wrap|landscape.
     * auto: уменьшить шрифт (до 65%), иначе альбомные страницы (если влезает), иначе разбить колонки на несколько частей с повтором первой (ключевой) колонки.
     */
    /** @param list<float> $min */
    private function overflow(\DOMElement $el, array $st, array $grid, array $min, float $avail, float $x, float $w, Flow $flow, float &$y, int $depth): bool
    {
        $mode = strtolower(trim($el->getAttribute('data-overflow') ?: ($st['-bp-overflow'] ?? 'auto')));
        if ($mode === 'wrap') return false;
        foreach ($grid as $cells) foreach ($cells as $c) if ($c['cspan'] > 1) { if ($mode === 'split') $mode = 'shrink'; if ($mode === 'auto') $mode = 'shrink'; }
        $scale = $avail / array_sum($min);
        $parent = $el->parentNode;
        // альбомная страница: явно (landscape) или в auto, когда шрифт пришлось бы ужать сильнее 65%
        $la = $this->e->landscapeArea;
        if ($la && $flow === $this->e->mainFlow && ($mode === 'landscape' || ($mode === 'auto' && $scale < 0.65 && array_sum($min) * 0.65 <= $la[0] - ($w - $avail)))) {
            $ls = ($la[0] - ($w - $avail)) / array_sum($min);
            $this->landscape($el, $la, $flow, $y, $depth, $ls < 1 ? max(45, (int)floor($ls * 97)) : 100);
            return true;
        }
        if ($mode === 'landscape') $mode = 'auto';
        if ($mode === 'shrink' || ($mode === 'auto' && $scale >= 0.65)) {
            $clone = $el->cloneNode(true); if (!$clone instanceof \DOMElement || $parent === null) return false;
            self::strip($el, $clone);
            $clone->setAttribute('data-bp-derived', '1');
            $pct = max(45, floor($scale * 97));
            $clone->setAttribute('style', trim($el->getAttribute('style') . ';font-size:' . $pct . '%'));
            $parent->insertBefore($clone, $el);
            try { $this->e->layoutBlock($clone, $this->e->style($clone), $x, $w, $flow, $y, $depth); } finally { $parent->removeChild($clone); }
            $this->e->warnings[] = "Wide table: font reduced to {$pct}%";
            return true;
        }
        // split: колонки группами, первая колонка повторяется
        $groups = []; $cur = []; $acc = $min[0];
        for ($i = 1; $i < count($min); $i++) {
            if ($cur && $acc + $min[$i] > $avail) { $groups[] = $cur; $cur = []; $acc = $min[0]; }
            $cur[] = $i; $acc += $min[$i];
        }
        if ($cur) $groups[] = $cur;
        foreach ($groups as $gi => $cols) {
            $keep = array_flip(array_merge([0], $cols));
            $clone = $el->cloneNode(true); if (!$clone instanceof \DOMElement || $parent === null) return false;
            self::strip($el, $clone);
            $clone->setAttribute('data-bp-derived', '1');
            $clone->removeAttribute('id');
            if ($gi > 0) $clone->setAttribute('style', trim($el->getAttribute('style') . ';break-before:page'));
            foreach ($clone->getElementsByTagName('tr') as $tr) {
                $ci = 0;
                foreach (iterator_to_array($tr->childNodes) as $td) {
                    if (!$td instanceof \DOMElement || !in_array(strtolower($td->nodeName), ['td', 'th'], true)) continue;
                    if (!isset($keep[$ci])) $tr->removeChild($td);
                    $ci++;
                }
            }
            $parent->insertBefore($clone, $el);
            try { $this->e->layoutBlock($clone, $this->e->style($clone), $x, $w, $flow, $y, $depth); } finally { $parent->removeChild($clone); }
        }
        $this->e->warnings[] = 'Wide table split into ' . count($groups) . ' parts';
        return true;
    }

    /** Вёрстка таблицы на отдельных альбомных страницах (поток документа продолжается со следующей страницы). */
    private function landscape(\DOMElement $el, array $la, Flow $flow, float &$y, int $depth, int $pct = 100): void
    {
        $parent = $el->parentNode;
        $clone = $el->cloneNode(true); if (!$clone instanceof \DOMElement || $parent === null) return;
        self::strip($el, $clone);
        $clone->setAttribute('data-bp-derived', '1');
        $clone->setAttribute('style', trim($el->getAttribute('style') . ';break-before:auto;break-after:auto' . ($pct < 100 ? ';font-size:' . $pct . '%' : '')));
        if ($pct < 100) $this->e->warnings[] = "Wide table: landscape page, font reduced to {$pct}%";
        $parent->insertBefore($clone, $el);
        $sub = new Flow(); $yy = 0.0; $saveH = $this->e->pageContentHeight; $saveMain = $this->e->mainFlow;
        $this->e->pageContentHeight = $la[1]; $this->e->mainFlow = null;
        try { $this->e->layoutBlock($clone, $this->e->style($clone), 0, $la[0], $sub, $yy, $depth); }
        finally { $parent->removeChild($clone); $this->e->pageContentHeight = $saveH; $this->e->mainFlow = $saveMain; }
        $pages = Paginator::paginate($sub, $la[1]);
        $flow->add($y, 0, [], ['insert' => $pages]);
        $this->e->warnings[] = 'Wide table placed on ' . count($pages) . ' landscape page(s)';
    }

    /** Нарезка операций высокой строки на куски ≤ $limit, без разрезания строк текста. */
    private function slice(array $ops, float $h, float $limit): array
    {
        /** @var list<array{0:float,1:float}> $lines */
        $lines = [];
        foreach ($ops as $o) if (in_array($o['t'], ['text', 'image'], true)) $lines[] = $o['t'] === 'text' ? [$o['y'] - $o['size'] * 1.0, $o['y'] + $o['size'] * 0.3] : [$o['y'], $o['y'] + $o['h']];
        $cuts = [0.0]; $start = 0.0; $guard = 0;
        while ($h - $start > $limit && ++$guard < 1000) {
            $cut = $start + $limit;
            foreach ($lines as $ln) if (($a = (float)$ln[0]) < $cut && (float)$ln[1] > $cut && $a > $start + 1) $cut = min($cut, $a - 0.5);
            if ($cut <= $start + 10) $cut = $start + $limit;
            $cuts[] = $cut; $start = $cut;
        }
        $cuts[] = $h;
        $out = [];
        for ($k = 0; $k + 1 < count($cuts); $k++) {
            [$a, $b] = [$cuts[$k], $cuts[$k + 1]]; $part = [];
            foreach ($ops as $o) {
                switch ($o['t']) {
                    case 'rect':
                        $t = max($a, $o['y']); $bt = min($b, $o['y'] + $o['h']);
                        if ($bt > $t) { $o['y'] = $t; $o['h'] = $bt - $t; $part[] = $o; }
                        break;
                    case 'line':
                        $y1 = min($o['y'], $o['y2']); $y2 = max($o['y'], $o['y2']);
                        if (abs($y2 - $y1) < 0.01) { if ($y1 >= $a - 0.01 && $y1 <= $b + 0.01) $part[] = $o; break; }
                        $t = max($a, $y1); $bt = min($b, $y2);
                        if ($bt > $t) { $o['y'] = $t; $o['y2'] = $bt; $part[] = $o; }
                        break;
                    case 'text': if ($o['y'] > $a && $o['y'] <= $b + 0.3 * $o['size']) $part[] = $o; break;
                    default: $yy = $o['y'] ?? ($o['pts'][0][2] ?? 0); if ($yy >= $a && $yy < $b) $part[] = $o;
                }
            }
            $out[] = [Ops::translate($part, 0, -$a), $b - $a];
        }
        return $out;
    }

    private function renderRows(array $grid, array $rows, int $from, int $to, array $colX, array $widths, float $sp, bool $collapse, array $tst): array
    {
        $rtl = strtolower((string)($tst['direction'] ?? '')) === 'rtl';
        $rowH = []; $cellFlows = [];
        for ($r = $from; $r <= $to; $r++) {
            $rowH[$r] = 0;
            $trs = $this->e->style($rows[$r]['el']);
            $rh = Length::toPt($rows[$r]['el']->getAttribute('height') ? $rows[$r]['el']->getAttribute('height') . 'px' : ($trs['height'] ?? null), 0, (float)$trs['_fs']);
            if ($rh) $rowH[$r] = $rh;
            foreach ($grid[$r] ?? [] as $ci => $c) {
                $cs = $this->e->style($c['el']);
                                $cw = 0; for ($k = 0; $k < $c['cspan']; $k++) $cw += $widths[$c['col'] + $k]; $cw += $sp * ($c['cspan'] - 1);
                $m = $this->e->boxMetrics($cs, $cw);
                $lm = $m;
                if ($collapse) {
                    // collapse: каждой ячейке принадлежит половина общей рамки; общая рамка — большая из двух соседних
                    foreach (['bt', 'br', 'bb', 'bl'] as $bk) $lm[$bk] = $m[$bk] / 2;
                    $up = $this->cellAt($grid, $r - 1, $c['col']); $dn = $this->cellAt($grid, $r + $c['rspan'], $c['col']);
                    if ($up) $lm['bt'] = max($m['bt'], $this->e->boxMetrics($this->e->style($up), $cw)['bb']) / 2;
                    if ($dn) $lm['bb'] = max($m['bb'], $this->e->boxMetrics($this->e->style($dn), $cw)['bt']) / 2;
                }
                $f = new Flow(); $yy = $lm['bt'] + $m['pt'];
                $inner = max(1, $cw - $m['pl'] - $m['pr'] - $lm['bl'] - $lm['br']);
                $tg = $this->e->tags;
                if ($tg) {
                    $tsid = $tg->stack ? end($tg->stack) : null;
                    $grp = $rows[$r]['el']->parentNode;
                    if ($grp instanceof \DOMElement && in_array($gn = strtolower($grp->nodeName), ['thead', 'tbody', 'tfoot'], true)) $tsid = $tg->sidFor($grp, ['thead' => 'THead', 'tbody' => 'TBody', 'tfoot' => 'TFoot'][$gn], $tsid);
                    $rsid = $tg->sidFor($rows[$r]['el'], 'TR', $tsid);
                    $csid = $tg->sidFor($c['el'], strtolower($c['el']->nodeName) === 'th' ? 'TH' : 'TD', $rsid);
                    $tg->stack[] = $csid;
                    try { $this->e->layoutChildren($c['el'], $cs, $lm['bl'] + $m['pl'], $inner, $f, $yy, 1); } finally { array_pop($tg->stack); }
                    $tg->markFlow($f, 0, $csid);
                } else $this->e->layoutChildren($c['el'], $cs, $lm['bl'] + $m['pl'], $inner, $f, $yy, 1);
                $contentH = max($yy - $lm['bt'] - $m['pt'], 0);
                $h = $contentH + $lm['bt'] + $m['pt'] + $m['pb'] + $lm['bb'];
                $hs = Length::toPt($cs['height'] ?? null, 0, (float)$cs['_fs']);
                if ($hs) $h = max($h, $hs + (strtolower($cs['box-sizing'] ?? '') === 'border-box' ? 0 : $lm['bt'] + $m['pt'] + $m['pb'] + $lm['bb'])); // как в браузерах: height ячейки — content-box
                $cellFlows[$r][$ci] = [$f, $h, $cw, $m, $cs, $contentH, $lm];
                if ($c['rspan'] === 1) $rowH[$r] = max($rowH[$r], $h);
            }
        }
        // rowspan: добавить недостающую высоту в последнюю строку
        for ($r = $from; $r <= $to; $r++) foreach ($grid[$r] ?? [] as $ci => $c) if ($c['rspan'] > 1) {
            $sum = 0; for ($k = $r; $k < $r + $c['rspan']; $k++) $sum += $rowH[$k] ?? 0; $sum += $sp * ($c['rspan'] - 1);
            $need = $cellFlows[$r][$ci][1];
            if ($need > $sum) $rowH[$r + $c['rspan'] - 1] += $need - $sum;
        }
        $ops = []; $yOff = []; $acc = 0;
        for ($r = $from; $r <= $to; $r++) { $yOff[$r] = $acc; $acc += $rowH[$r] + ($r < $to ? $sp : 0); }
        $fg = [];
        for ($r = $from; $r <= $to; $r++) {
            $trs = $this->e->style($rows[$r]['el']);
            if ($bg = Color::parse($trs['background-color'] ?? null)) $ops[] = ['t' => 'rect', 'x' => $colX[0], 'y' => $yOff[$r], 'w' => $colX[count($widths)] - $colX[0], 'h' => $rowH[$r], 'fill' => $bg];
            foreach ($grid[$r] ?? [] as $ci => $c) {
                [$f, $h, $cw, $m, $cs, $contentH, $lm] = $cellFlows[$r][$ci];
                $ch = 0; for ($k = $r; $k < $r + $c['rspan']; $k++) $ch += $rowH[$k] ?? 0; $ch += $sp * ($c['rspan'] - 1);
                $cx = $colX[$c['col']]; $cy = $yOff[$r];
                if ($rtl) $cx = $colX[0] + $colX[count($widths)] - $cx - $cw; // RTL: первая колонка справа
                $deco = $this->e->decoBox($cs, $cx, $cw, $m);
                if ($deco) {
                    $deco['shadow'] = null; $bd = $deco['b']; $deco['b'] = null;
                    foreach (Ops::box($deco, $cy, $ch, true, true) as $o) $ops[] = $o;
                    if ($bd) { $only = ['x' => $cx, 'w' => $cw, 'b' => $bd, 'radius' => $deco['radius']]; foreach (Ops::box($only, $cy, $ch, true, true) as $o) $fg[] = $o; }
                }
                $va = strtolower($cs['vertical-align'] ?? 'middle');
                $innerH = $ch - $lm['bt'] - $m['pt'] - $m['pb'] - $lm['bb'];
                $dy = match ($va) { 'middle' => max(0, ($innerH - $contentH) / 2), 'bottom' => max(0, $innerH - $contentH), default => 0 };
                foreach ($f->flatten($cx, $cy + $dy) as $o) $ops[] = $o;
            }
        }
        return [array_merge($ops, $fg), $acc];
    }

    /** Ячейка сетки, покрывающая строку $r и колонку $col (с учётом rowspan/colspan). */
    private function cellAt(array $grid, int $r, int $col): ?\DOMElement
    {
        if ($r < 0) return null;
        for ($rr = $r; $rr >= 0 && $rr > $r - 50; $rr--) {
            if (!isset($grid[$rr])) { if ($rr === $r) return null; continue; }
            foreach ($grid[$rr] as $c) if ($col >= $c['col'] && $col < (int)$c['col'] + (int)$c['cspan'] && $rr + $c['rspan'] > $r) return $c['el'];
        }
        return null;
    }

    /** Снять служебные метки с клона, чтобы стили пересчитались. */
    private static function strip(\DOMElement $el, \DOMElement $clone): void
    {
        $nodes = [$clone];
        foreach ($clone->getElementsByTagName('*') as $n) $nodes[] = $n;
        foreach ($nodes as $nx) $nx->removeAttribute('data-bp-n');
    }
}
