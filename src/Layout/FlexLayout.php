<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

use BaurPDF\Css\Length;

/** Flexbox (row/column, wrap, grow/shrink/basis, gap, justify-content, align-items/self, order) и CSS Grid (колонки, span, gap). */
final class FlexLayout
{
    public function __construct(private Engine $e) {}

    public function items(\DOMElement $el): array
    {
        $items = [];
        foreach (iterator_to_array($el->childNodes) as $c) {
            if ($c instanceof \DOMText) {
                if (trim($c->nodeValue ?? '') === '') continue;
                $od = $el->ownerDocument; if ($od === null) continue;
                $wrap = $od->createElement('div');
                $el->replaceChild($wrap, $c); $wrap->appendChild($c);
                $c = $wrap;
            }
            if (!$c instanceof \DOMElement) continue;
            $cs = $this->e->style($c);
            if (strtolower($cs['display'] ?? '') === 'none') continue;
            if (in_array(strtolower($cs['display']), ['inline', 'inline-block', 'list-item'], true) || strtolower($c->nodeName) === 'br') { if (strtolower($c->nodeName) === 'br') continue; $cs['display'] = 'block'; $this->e->setStyle($c, $cs); }
            $items[] = ['el' => $c, 'st' => $cs, 'order' => (int)($cs['order'] ?? 0)];
        }
        usort($items, fn($a, $b) => $a['order'] <=> $b['order']);
        return $items;
    }

    /** Definite content-box height of a flex container (single-line cross size), or null. */
    private function definiteInnerHeight(array $st, float $w): ?float
    {
        $fs = (float)$st['_fs'];
        $h = Length::toPt($st['height'] ?? null, $this->e->pageContentHeight, $fs);
        $mh = Length::toPt($st['min-height'] ?? null, $this->e->pageContentHeight, $fs);
        if ($h === null && $mh === null) return null;
        $h = max($h ?? 0.0, $mh ?? 0.0);
        if (strtolower($st['box-sizing'] ?? '') === 'border-box') {
            $m = $this->e->boxMetrics($st, $w);
            $h -= $m['pt'] + $m['pb'] + $m['bt'] + $m['bb'];
        }
        return max(0.0, $h);
    }

    public function layoutItem(\DOMElement $el, array $st, float $outerW): array
    {
        $f = new Flow(); $y = 0;
        $this->e->layoutBlock($el, $st, 0, $outerW, $f, $y, 0, $outerW);
        return [$f, max($y, $f->height())];
    }

    public function layout(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth): void
    {
        $fs = (float)$st['_fs'];
        $dir = strtolower($st['flex-direction'] ?? 'row');
        $items = $this->items($el);
        if (!$items) return;
        $rowGap = Length::toPt($st['row-gap'] ?? '0', $w, $fs) ?? 0;
        $colGap = Length::toPt($st['column-gap'] ?? '0', $w, $fs) ?? 0;
        $align = strtolower($st['align-items'] ?? 'stretch');
        if (str_starts_with($dir, 'column')) {
            if ($dir === 'column-reverse') $items = array_reverse($items);
            foreach ($items as $i => $it) {
                if ($i > 0) { $y += $rowGap; }
                $ist = $it['st'];
                $as = strtolower($ist['align-self'] ?? 'auto'); $a = $as === 'auto' ? $align : $as;
                if (in_array($a, ['center', 'flex-end', 'end'], true) && ($ist['width'] ?? null) === null) {
                    [, $max] = $this->e->minMax($it['el'], $ist);
                    $iw = min($w, $max);
                    $off = $a === 'center' ? ($w - $iw) / 2 : $w - $iw;
                    $this->e->layoutBlock($it['el'], $ist, $x + $off, $iw, $flow, $y, $depth, $iw);
                } else $this->e->layoutBlock($it['el'], $ist, $x, $w, $flow, $y, $depth);
            }
            return;
        }
        $wrap = !in_array(strtolower($st['flex-wrap'] ?? 'nowrap'), ['nowrap', ''], true);
        // базовые размеры
        foreach ($items as &$it) {
            $ist = $it['st']; $ifs = (float)$ist['_fs'];
            [$min, $max] = $this->e->minMax($it['el'], $ist);
            $m = $this->e->boxMetrics($ist, $w);
            $extra = $m['pl'] + $m['pr'] + $m['bl'] + $m['br'] + (is_numeric($m['ml']) ? $m['ml'] : 0) + (is_numeric($m['mr']) ? $m['mr'] : 0);
            $bsz = strtolower($ist['box-sizing'] ?? '') === 'border-box';
            $basis = null;
            $fb = strtolower(trim($ist['flex-basis'] ?? 'auto'));
            if ($fb !== 'auto' && $fb !== 'content') { $basis = Length::toPt($fb, $w, $ifs); if ($basis !== null) $basis += $bsz ? (is_numeric($m['ml']) ? $m['ml'] : 0) + (is_numeric($m['mr']) ? $m['mr'] : 0) : $extra; }
            if ($basis === null && ($ws = Length::toPt($ist['width'] ?? null, $w, $ifs)) !== null) $basis = $ws + ($bsz ? (is_numeric($m['ml']) ? $m['ml'] : 0) + (is_numeric($m['mr']) ? $m['mr'] : 0) : $extra);
            $basis ??= $max;
            $it['basis'] = $basis; $it['min'] = ($fb === '0%' || $fb === '0' || $fb === '0px') && !isset($ist['min-width']) ? min($min, $basis ?: $min) : $min;
            if (($mw = Length::toPt($ist['min-width'] ?? null, $w, $ifs)) !== null) $it['min'] = $mw + ($bsz ? 0 : $extra);
            $it['maxw'] = ($mx = Length::toPt($ist['max-width'] ?? null, $w, $ifs)) !== null ? $mx + ($bsz ? 0 : $extra) : INF;
            $it['grow'] = (float)($ist['flex-grow'] ?? 0); $it['shrink'] = (float)($ist['flex-shrink'] ?? 1);
        }
        unset($it);
        // строки
        $lines = []; /** @var list<array> $cur */ $cur = []; $sum = 0;
        foreach ($items as $it) {
            $add = $it['basis'] + ($cur ? $colGap : 0);
            if ($wrap && $cur && $sum + $add > $w + 0.01) { $lines[] = $cur; $cur = []; $sum = 0; $add = $it['basis']; }
            $cur[] = $it; $sum += $add;
        }
        $lines[] = $cur;
        if ($dir === 'row-reverse') $lines = array_map('array_reverse', $lines);
        $justify = strtolower($st['justify-content'] ?? 'flex-start');
        foreach ($lines as $li => $line) {
            if ($li > 0) $y += $rowGap;
            $n = count($line);
            $sizes = array_map(fn($i) => $i['basis'], $line);
            $free = $w - array_sum($sizes) - $colGap * ($n - 1);
            // grow/shrink с ограничениями (несколько итераций)
            for ($iter = 0; $iter < 4 && abs($free) > 0.01; $iter++) {
                if ($free > 0) {
                    $g = 0; foreach ($line as $k => $i) if ($sizes[$k] < $i['maxw']) $g += $i['grow'];
                    if ($g <= 0) break;
                    foreach ($line as $k => $i) if ($sizes[$k] < $i['maxw']) $sizes[$k] = min($i['maxw'], $sizes[$k] + $free * $i['grow'] / $g);
                } else {
                    $g = 0; foreach ($line as $k => $i) if ($sizes[$k] > $i['min']) $g += $i['shrink'] * $i['basis'];
                    if ($g <= 0) break;
                    foreach ($line as $k => $i) if ($sizes[$k] > $i['min']) $sizes[$k] = max($i['min'], $sizes[$k] + $free * $i['shrink'] * $i['basis'] / $g);
                }
                $free = $w - array_sum($sizes) - $colGap * ($n - 1);
            }
            // если всё ещё не влезает — пропорционально сжать (никогда не вылезаем за страницу)
            $total = array_sum($sizes) + $colGap * ($n - 1);
            if ($total > $w + 0.01) { $k2 = ($w - $colGap * ($n - 1)) / max(1, array_sum($sizes)); foreach ($sizes as &$s) $s *= max(0.05, $k2); unset($s); $free = 0; }
            $free = max(0, $free);
            $gap = $colGap; $start = 0;
            if ($free > 0) switch ($justify) {
                case 'center': $start = $free / 2; break;
                case 'flex-end': case 'end': case 'right': $start = $free; break;
                case 'space-between': if ($n > 1) $gap += $free / ($n - 1); break;
                case 'space-around': $gap += $free / $n; $start = $free / $n / 2; break;
                case 'space-evenly': $gap += $free / ($n + 1); $start = $free / ($n + 1); break;
            }
            $laid = []; $lineH = 0;
            foreach ($line as $k => $it) { [$f, $h] = $this->layoutItem($it['el'], $it['st'], $sizes[$k]); $laid[$k] = [$f, $h]; $lineH = max($lineH, $h); }
            if (count($lines) === 1 && ($defH = $this->definiteInnerHeight($st, $w)) !== null && $defH <= $this->e->pageContentHeight * 0.95) $lineH = max($lineH, $defH);
            if ($lineH > $this->e->pageContentHeight * 0.95) {
                // слишком высоко для одной страницы — раскладываем элементы друг под другом (контент не теряем)
                foreach ($line as $k => $it) $this->e->layoutBlock($it['el'], $it['st'], $x, $w, $flow, $y, $depth);
                continue;
            }
            $ops = []; $cx = $x + $start;
            foreach ($line as $k => $it) {
                [$f, $h] = $laid[$k];
                $as = strtolower($it['st']['align-self'] ?? 'auto'); $a = $as === 'auto' ? $align : $as;
                $dy = match ($a) { 'center' => ($lineH - $h) / 2, 'flex-end', 'end' => $lineH - $h, default => 0 };
                if ($a === 'stretch' && !isset($it['st']['height'])) {
                    $m = $this->e->boxMetrics($it['st'], $w);
                    foreach ($f->decos as &$d) if ($d['depth'] === 0) $d['bottom'] = $lineH - (is_numeric($m['mb']) ? (float)$m['mb'] : 0); unset($d);
                }
                foreach ($f->flatten($cx, $dy) as $o) $ops[] = $o;
                $cx += $sizes[$k] + $gap;
            }
            $flow->add($y, $lineH, Ops::translate($ops, 0, $y));
            $y += $lineH;
        }
    }

    public function grid(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth): void
    {
        $fs = (float)$st['_fs'];
        $items = $this->items($el);
        if (!$items) return;
        $rowGap = Length::toPt($st['row-gap'] ?? '0', $w, $fs) ?? 0;
        $colGap = Length::toPt($st['column-gap'] ?? '0', $w, $fs) ?? 0;
        $tracks = $this->e->gridTracks($st, $w);
        $n = count($tracks);
        $fixed = 0; $fr = 0;
        foreach ($tracks as $t) { if ($t[0] === 'len') $fixed += $t[1]; else $fr += $t[1]; }
        $rest = max(0, $w - $fixed - $colGap * ($n - 1));
        $widths = array_map(fn($t) => $t[0] === 'len' ? $t[1] : ($fr > 0 ? $rest * $t[1] / $fr : 0), $tracks);
        if ($fixed + $colGap * ($n - 1) > $w) { $k = ($w - $colGap * ($n - 1)) / max(1, array_sum($widths)); $widths = array_map(fn($v) => $v * $k, $widths); }
        $colX = []; $cx = 0; foreach ($widths as $i => $cw) { $colX[$i] = $cx; $cx += $cw + $colGap; }
        // авто-размещение
        $rows = []; $r = 0; $c = 0;
        foreach ($items as $it) {
            $gc = strtolower(trim($it['st']['grid-column'] ?? ''));
            $span = 1; $startCol = null;
            if (preg_match('/span\s+(\d+)/', $gc, $m)) $span = (int)$m[1];
            elseif (preg_match('/^(\d+)\s*\/\s*(-1|\d+)$/', $gc, $m)) { $startCol = (int)$m[1] - 1; $span = $m[2] === '-1' ? $n - $startCol : max(1, (int)$m[2] - (int)$m[1]); }
            elseif (preg_match('/^(\d+)$/', $gc, $m)) $startCol = (int)$m[1] - 1;
            $span = max(1, min($n, $span));
            if ($startCol !== null) { $startCol = max(0, min($n - 1, $startCol)); if ($startCol < $c) { $r++; } $c = $startCol; }
            if ($c + $span > $n) { $r++; $c = 0; }
            $rows[$r][] = ['it' => $it, 'col' => $c, 'span' => $span];
            $c += $span;
            if ($c >= $n) { $r++; $c = 0; }
        }
        $align = strtolower($st['align-items'] ?? 'stretch');
        $first = true;
        foreach ($rows as $cells) {
            if (!$first) $y += $rowGap;
            $first = false;
            $laid = []; $rowH = 0;
            foreach ($cells as $k => $cell) {
                $cw = array_sum(array_slice($widths, $cell['col'], $cell['span'])) + $colGap * ($cell['span'] - 1);
                [$f, $h] = $this->layoutItem($cell['it']['el'], $cell['it']['st'], $cw);
                $laid[$k] = [$f, $h]; $rowH = max($rowH, $h);
            }
            if ($rowH > $this->e->pageContentHeight * 0.95) { foreach ($cells as $cell) $this->e->layoutBlock($cell['it']['el'], $cell['it']['st'], $x, $w, $flow, $y, $depth); continue; }
            $ops = [];
            foreach ($cells as $k => $cell) {
                [$f, $h] = $laid[$k];
                $as = strtolower($cell['it']['st']['align-self'] ?? 'auto'); $a = $as === 'auto' ? $align : $as;
                $dy = match ($a) { 'center' => ($rowH - $h) / 2, 'end', 'flex-end' => $rowH - $h, default => 0 };
                if ($a === 'stretch') { $m = $this->e->boxMetrics($cell['it']['st'], $w); foreach ($f->decos as &$d) if ($d['depth'] === 0) $d['bottom'] = $rowH - (is_numeric($m['mb']) ? (float)$m['mb'] : 0); unset($d); }
                foreach ($f->flatten($x + $colX[$cell['col']], $dy) as $o) $ops[] = $o;
            }
            $flow->add($y, $rowH, Ops::translate($ops, 0, $y));
            $y += $rowH;
        }
    }
}
