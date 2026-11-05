<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

use BaurPDF\Css\Transform;

use BaurPDF\Css\Color;
use BaurPDF\Css\Length;
use BaurPDF\Css\StyleResolver;
use BaurPDF\Font\FontManager;
use BaurPDF\Font\TrueTypeFont;
use BaurPDF\Chart\ChartRenderer;
use BaurPDF\Svg\SvgRenderer;

/** Движок вёрстки: блоки, inline/строки, списки, таблицы, flex, grid, изображения, SVG, графики. */
final class Engine
{
    private array $styles = [];
    /** Потоковая таблица: при её раскладке запоминаются x, ширина и глубина. */
    public ?\DOMElement $streamTable = null;
    /** @var array{0:float,1:float,2:int}|null */
    public ?array $streamBox = null;
    /** @var array<int, array{0:\DOMNode,1:array{0:float,1:float}}> */
    private array $mm = [];
    public array $warnings = [];
    public array $headings = [];
    private int $headingSeq = 0;
    public float $pageContentHeight = 700;
    /** Определённая высота содержимого текущего блока-контейнера (база для height в %); null — auto. */
    public ?float $cbH = null;
    private bool $cbInit = false;

    /** height/min-height с процентами от определённой высоты контейнера (CSS 2.1 §10.5): иначе auto. */
    public function hLen(?string $v, array $st): ?float
    {
        if ($v === null) return null;
        if (str_contains($v, '%') && !str_contains($v, 'calc(')) {
            $base = $this->cbInit ? $this->cbH : $this->pageContentHeight;
            if ($base === null) return null;
            return $this->len($v, $base, $st);
        }
        return $this->len($v, $this->cbInit ? ($this->cbH ?? $this->pageContentHeight) : $this->pageContentHeight, $st);
    }
    /** Размер области контента альбомной страницы [w, h] (для авто-поворота широких таблиц) и основной поток документа. */
    public ?array $landscapeArea = null;
    public ?Flow $mainFlow = null;
    private float $deadline;
    /** Предел высоты потока (maxPages × высота страницы): дальше не раскладываем, а не только не печатаем. */
    public float $maxHeight = 0.0;
    public array $tocPages = [];

    /** Лимиты времени или объёма исчерпаны — дальнейшая раскладка пропускается. */
    public function exhausted(float $y = 0.0): bool
    {
        if (microtime(true) > $this->deadline) { $this->warnings['time'] ??= 'Time limit reached: document truncated'; return true; }
        if ($this->maxHeight > 0 && $y > $this->maxHeight) { $this->warnings['maxpages'] = 'maxPages reached: layout truncated'; $this->deadline = 0.0; return true; }
        return false;
    }

    public function __construct(
        private StyleResolver $resolver,
        public FontManager $fonts,
        public ResourceLoader $loader,
        float $timeLimit = 60,
    ) {
        $this->deadline = microtime(true) + $timeLimit;
    }

    // ---------- стили ----------
    public function style(\DOMElement $el): array
    {
        $id = spl_object_id($el);
        if (isset($this->styles[$id])) return $this->styles[$id][1];
        $p = null; $pk = '';
        if ($el->parentNode instanceof \DOMElement) { $p = $this->style($el->parentNode); $pk = $this->styles[spl_object_id($el->parentNode)][2]; }
        // мемо каскада + интернирование: одинаковые вычисленные стили (строки таблиц, абзацы) делят один массив (copy-on-write)
        [$st, $k] = $this->resolver->computeKeyed($el, $p, $pk);
        $this->styles[$id] = [$el, $st, $k]; // держим ссылку на $el, чтобы id не переиспользовался
        return $st;
    }

    /** Забыть кэши стилей и min/max для поддерева (потоковый режим освобождает DOM порций). */
    public function forget(\DOMElement $el): void
    {
        $ids = [spl_object_id($el)];
        foreach ($el->getElementsByTagName('*') as $c) $ids[] = spl_object_id($c);
        foreach ($el->childNodes as $c) $ids[] = spl_object_id($c);
        foreach ($ids as $id) unset($this->styles[$id], $this->mm[$id], $this->mmB[$id]);
    }

    public function setStyle(\DOMElement $el, array $st): void { $this->styles[spl_object_id($el)] = [$el, $st, md5(serialize($st), true)]; }

    private function len(?string $v, float $ref, array $st): ?float { return Length::toPt($v, $ref, (float)$st['_fs']); }

    private function display(array $st): string { return strtolower(trim($st['display'] ?? 'inline')); }

    /** Числовые размеры бокса. */
    /** @return array{mt:float|'auto',mr:float|'auto',mb:float|'auto',ml:float|'auto',pt:float,pr:float,pb:float,pl:float,bt:float,br:float,bb:float,bl:float,bct:array,bcr:array,bcb:array,bcl:array,bst:string,bsr:string,bsb:string,bsl:string} */
    public function boxMetrics(array $st, float $cw): array
    {
        $ck = $cw . '|' . $st['_fs'] . '|' . ($st['color'] ?? '');
        foreach (self::BOX_PROPS as $p) $ck .= '|' . ($st[$p] ?? '');
        if (isset($this->bmCache[$ck])) return $this->bmCache[$ck]; // @phpstan-ignore return.type
        if (count($this->bmCache) > 20000) $this->bmCache = [];
        return $this->bmCache[$ck] = $this->boxMetricsRaw($st, $cw);
    }

    private const BOX_PROPS = ['margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color'];
    /** @var array<string, array> */
    private array $bmCache = [];

    /** @return array{mt:float|'auto',mr:float|'auto',mb:float|'auto',ml:float|'auto',pt:float,pr:float,pb:float,pl:float,bt:float,br:float,bb:float,bl:float,bct:array,bcr:array,bcb:array,bcl:array,bst:string,bsr:string,bsb:string,bsl:string} */
    private function boxMetricsRaw(array $st, float $cw): array
    {
        $side = function (string $s) use ($st, $cw): array {
            $mv = (string)($st["margin-$s"] ?? '0');
            $bs = strtolower((string)($st["border-$s-style"] ?? 'none'));
            $col = (string)($st["border-$s-color"] ?? 'currentcolor');
            return [
                strtolower(trim($mv)) === 'auto' ? 'auto' : ($this->len($mv, $cw, $st) ?? 0.0),
                max(0.0, $this->len($st["padding-$s"] ?? '0', $cw, $st) ?? 0.0),
                ($bs === 'none' || $bs === 'hidden') ? 0.0 : max(0.0, $this->len($st["border-$s-width"] ?? 'medium', $cw, $st) ?? 0.0),
                Color::parse(strtolower($col) === 'currentcolor' ? (string)($st['color'] ?? '#000') : $col) ?? [0, 0, 0, 1],
                $bs,
            ];
        };
        [$mt, $pt, $bt, $bct, $bst] = $side('top'); [$mr, $pr, $br, $bcr, $bsr] = $side('right');
        [$mb, $pb, $bb, $bcb, $bsb] = $side('bottom'); [$ml, $pl, $bl, $bcl, $bsl] = $side('left');
        return ['mt' => $mt, 'pt' => $pt, 'bt' => $bt, 'bct' => $bct, 'bst' => $bst, 'mr' => $mr, 'pr' => $pr, 'br' => $br, 'bcr' => $bcr, 'bsr' => $bsr,
            'mb' => $mb, 'pb' => $pb, 'bb' => $bb, 'bcb' => $bcb, 'bsb' => $bsb, 'ml' => $ml, 'pl' => $pl, 'bl' => $bl, 'bcl' => $bcl, 'bsl' => $bsl];
    }

    public function decoBox(array $st, float $x, float $w, array $m): ?array
    {
        $bg = Color::parse($st['background-color'] ?? null);
        $grad = null; $bgimg = null;
        $bi = $st['background-image'] ?? 'none';
        if (stripos($bi, 'gradient(') !== false) $grad = self::parseGradient($bi);
        elseif (preg_match('/url\(\s*[\'"]?([^\'")]+)/i', $bi, $mm)) $bgimg = $this->loader->load($mm[1]);
        $hasB = $m['bt'] || $m['br'] || $m['bb'] || $m['bl'];
        $shadow = null;
        if (!empty($st['box-shadow']) && strtolower(trim($st['box-shadow'])) !== 'none') {
            // несколько теней через запятую (вне скобок): offset-x offset-y [blur [spread]] color; inset пропускается
            foreach (preg_split('/,(?![^(]*\))/', $st['box-shadow']) ?: [] as $one) {
                $parts = preg_split('/\s+(?![^(]*\))/', trim($one)) ?: [];
                $nums = []; $col = [0, 0, 0, .25]; $inset = false;
                foreach ($parts as $p) {
                    if (strtolower($p) === 'inset') { $inset = true; continue; }
                    if (preg_match('/^-?[\d.]/', $p) && ($l = Length::toPt($p, 0, (float)($st['_fs'] ?? 12))) !== null) $nums[] = $l;
                    elseif ($c = Color::parse($p)) $col = $c;
                }
                if (count($nums) >= 2 && !$inset) $shadow[] = [$nums[0], $nums[1], max(0, $nums[2] ?? 0), $nums[3] ?? 0, $col];
            }
            if ($shadow) $shadow = array_slice($shadow, 0, 4);
        }
        $op = isset($st['opacity']) ? max(0, min(1, (float)$st['opacity'])) : 1;
        if (!$bg && !$grad && !$hasB && !$shadow && !$bgimg) return null;
        return ['x' => $x, 'w' => $w, 'bg' => $bg, 'grad' => $grad, 'bgimg' => $bgimg, 'radius' => $this->len($st['border-radius'] ?? '0', $w, $st) ?? 0, 'shadow' => $shadow, 'opacity' => $op,
            'b' => $hasB ? [[$m['bt'], $m['bst'], $m['bct']], [$m['br'], $m['bsr'], $m['bcr']], [$m['bb'], $m['bsb'], $m['bcb']], [$m['bl'], $m['bsl'], $m['bcl']]] : null];
    }

    public static function parseGradient(string $v): ?array
    {
        if (!preg_match('/(linear|radial)-gradient\((.*)\)/is', $v, $m)) return null;
        $args = preg_split('/,(?![^(]*\))/', $m[2]) ?: [];
        $angle = 180; $radial = null;
        $first = strtolower(trim($args[0] ?? ''));
        if (strtolower($m[1]) === 'radial') {
            $radial = ['circle' => false, 'cx' => .5, 'cy' => .5, 'size' => 'farthest-corner'];
            if ($first !== '' && Color::parse($first) === null && !preg_match('/^(#|rgb|hsl)/', $first)) {
                array_shift($args);
                $radial['circle'] = str_contains($first, 'circle');
                foreach (['closest-side', 'closest-corner', 'farthest-side', 'farthest-corner'] as $kw) if (str_contains($first, $kw)) $radial['size'] = $kw;
                if (preg_match('/\bat\s+(\S+)(?:\s+(\S+))?/', $first, $at)) {
                    $p = static fn(string $t, bool $vert): float => match ($t) { 'left', 'top' => 0.0, 'right', 'bottom' => 1.0, 'center' => .5, default => str_ends_with($t, '%') ? (float)$t / 100 : .5 };
                    $a1 = $at[1]; $a2 = $at[2] ?? 'center';
                    if (in_array($a1, ['top', 'bottom'], true)) [$a1, $a2] = [$a2, $a1];
                    $radial['cx'] = $p($a1, false); $radial['cy'] = $p($a2, true);
                }
            }
        }
        elseif (preg_match('/^(-?[\d.]+)deg$/', $first, $a)) { $angle = (float)$a[1]; array_shift($args); }
        elseif (str_starts_with($first, 'to ')) {
            $angle = match (trim(substr($first, 3))) { 'right' => 90, 'left' => 270, 'top' => 0, 'bottom' => 180, 'bottom right', 'right bottom' => 135, 'top right', 'right top' => 45, default => 180 };
            array_shift($args);
        }
        $stops = [];
        foreach ($args as $i => $s) {
            $s = trim($s);
            if (preg_match('/^(.*?)\s+([\d.]+)%$/', $s, $mm)) { $c = Color::parse($mm[1]); $pos = (float)$mm[2] / 100; }
            else { $c = Color::parse($s); $pos = null; }
            if ($c) $stops[] = [$c, $pos];
        }
        $n = count($stops);
        if ($n < 2) return null;
        foreach ($stops as $i => &$s) if ($s[1] === null) $s[1] = $i / ($n - 1);
        return ['angle' => $angle, 'stops' => $stops] + ($radial ? ['radial' => $radial] : []);
    }

    // ---------- блоки ----------
    /** Разложить содержимое элемента (дети) как блочный контейнер. */
    /** @param list<\DOMNode>|null $only подмножество детей (колонки, разделённые column-span) */
    public function layoutChildren(\DOMNode $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, ?array $only = null): void
    {
        if ($this->exhausted($y)) return;
        /** @var list<\DOMNode> $inline */
        $inline = [];
        $flush = function () use (&$inline, $st, $x, $w, $flow, &$y, $depth) {
            if ($inline) { $this->layoutInline($inline, $st, $x, $w, $flow, $y, $depth); $inline = []; }
        };
        foreach ($only ?? iterator_to_array($el->childNodes) as $c) {
            if ($c instanceof \DOMText) { $inline[] = $c; continue; }
            if (!$c instanceof \DOMElement) continue;
            $cs = $this->style($c);
            $d = $this->display($cs);
            if ($d === 'none') continue;
            $tag = strtolower($c->nodeName);
            $pos = strtolower($cs['position'] ?? '');
            $isFloat = in_array(strtolower(trim($cs['float'] ?? 'none')), ['left', 'right'], true);
            if (!$isFloat && in_array($d, ['inline', 'inline-block', 'inline-flex', 'inline-table'], true) && !in_array($tag, ['chart', 'toc'], true) && $pos !== 'absolute' && $pos !== 'fixed') { $inline[] = $c; continue; }
            if ($tag === 'br') { $inline[] = $c; continue; }
            if ($pos === 'absolute' || $pos === 'fixed') { $this->absQueue[] = [$c, $cs]; continue; }
            $fl = strtolower(trim($cs['float'] ?? 'none'));
            if (($fl === 'left' || $fl === 'right') && $pos !== 'absolute') { $flush(); $this->layoutFloat($c, $cs, $fl, $x, $w, $flow, $y, $depth); continue; }
            $flush();
            $this->layoutBlock($c, $cs, $x, $w, $flow, $y, $depth);
            if ($this->maxHeight > 0 && $y > $this->maxHeight && $this->exhausted($y)) return;
        }
        $flush();
    }

    /** Логическая структура (Tagged PDF); null — теги выключены. */
    public ?Tags $tags = null;

    public function layoutBlock(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, ?float $forceOuterW = null): void
    {
        if ($el === $this->streamTable) $this->streamBox = [$x, $w, $depth];
        if (empty($st['_tf']) && strtolower($st['position'] ?? '') === 'relative' && (isset($st['top']) || isset($st['left']) || isset($st['right']) || isset($st['bottom']))) {
            [$dx, $dy] = $this->relativeOffset($st, $w);
            if ($dx != 0 || $dy != 0) {
                $t = trim($st['transform'] ?? 'none');
                $hadTf = $t !== '' && strtolower($t) !== 'none';
                $st['transform'] = sprintf('translate(%.3Fpt, %.3Fpt)', $dx, $dy) . ($hadTf ? ' ' . $t : '');
                if (!$hadTf) $st['_relonly'] = 1;
            }
        }
        if (empty($st['_ovf']) && empty($st['_tf']) && in_array(strtolower(trim($st['overflow'] ?? $st['overflow-y'] ?? '')), ['hidden', 'clip', 'auto', 'scroll'], true)
            && ($hs = $this->hLen($st['height'] ?? null, $st) ?? $this->hLen($st['max-height'] ?? null, $st)) !== null && $hs > 0 && $hs < $this->pageContentHeight * 0.9) {
            $this->layoutClipped($el, $st, $x, $w, $flow, $y, $depth, $forceOuterW);
            return;
        }
        if (isset(self::FORM[strtolower($el->nodeName)]) && empty($st['_tf']) && !isset($st['transform'])) { $this->layoutFormBlock($el, $st, $x, $w, $flow, $y, $forceOuterW); return; }
        if (isset($st['transform']) && strtolower(trim($st['transform'])) !== 'none' && empty($st['_tf'])) { $this->layoutTransformed($el, $st, $x, $w, $flow, $y, $depth, $forceOuterW); return; }
        $this->layoutBlockTagged($el, $st, $x, $w, $flow, $y, $depth, $forceOuterW);
    }

    /** Элемент формы как блок (display:block или flex-элемент): рисуется контролом, а не пустым блоком. */
    private function layoutFormBlock(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, ?float $forceOuterW): void
    {
        $m = $this->boxMetrics($st, $w);
        $mt = is_float($m['mt']) ? $m['mt'] : 0.0; $mb = is_float($m['mb']) ? $m['mb'] : 0.0;
        $ml = is_float($m['ml']) ? $m['ml'] : 0.0; $mr = is_float($m['mr']) ? $m['mr'] : 0.0;
        $kind = self::formKind($el);
        if ($forceOuterW !== null && $kind !== 'checkbox' && $kind !== 'radio') { $st['width'] = max(4.0, $forceOuterW - $ml - $mr) . 'pt'; $st['box-sizing'] = 'border-box'; }
        $a = $this->formAtomic($el, $st, $w, $this->run($st, null));
        $y += max($mt, $flow->lastMarginBottom) - $flow->lastMarginBottom;
        $flow->add($y, $a['h'], Ops::translate($a['ops'], $x, $y));
        $y += $a['h'];
        $flow->lastMarginBottom = $mb; $y += $mb;
    }

    /** Табуляция до следующей позиции, кратной $size символам (от начала строки). */
    private static function expandTabs(string $text, int $size): string
    {
        $out = [];
        foreach (explode("\n", $text) as $ln) {
            $r = ''; $col = 0;
            foreach (explode("\t", $ln) as $i => $part) {
                if ($i > 0) { $n = $size - ($col % $size); $r .= str_repeat(' ', $n); $col += $n; }
                $r .= $part; $col += mb_strlen($part);
            }
            $out[] = $r;
        }
        return implode("\n", $out);
    }

    /** overflow:hidden + definite height: the box keeps its height and content is clipped to the border box (one unbreakable atom). */
    private function layoutClipped(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, ?float $forceOuterW): void
    {
        $sub = new Flow(); $sub->lastMarginBottom = $flow->lastMarginBottom; $sy = $y;
        $st['_ovf'] = 1;
        $this->layoutBlock($el, $st, $x, $w, $sub, $sy, $depth, $forceOuterW);
        if (!$sub->atoms && !$sub->decos) { $y = $sy; return; }
        $box = null; foreach ($sub->decos as $d) if ($d['depth'] === $depth) { $box = $d; break; }
        $m = $this->boxMetrics($st, $w);
        $ml = is_float($m['ml']) ? $m['ml'] : 0.0;
        $top = $box['top'] ?? $y;
        $bottom = $box['bottom'] ?? $sy;
        // max-height без height: бокс не выше предела, лишнее содержимое режется клипом
        $cut = 0.0;
        if (!isset($st['height']) && ($mh = $this->hLen($st['max-height'] ?? null, $st)) !== null) {
            $lim = $top + $mh + (strtolower($st['box-sizing'] ?? '') === 'border-box' ? 0 : $m['pt'] + $m['pb'] + $m['bt'] + $m['bb']);
            if ($bottom > $lim) { $cut = $bottom - $lim; $bottom = $lim; }
        }
        $ws = $this->len($st['width'] ?? null, $w, $st);
        $bw = $forceOuterW ?? ($ws !== null ? $ws + (strtolower($st['box-sizing'] ?? '') === 'border-box' ? 0 : $m['pl'] + $m['pr'] + $m['bl'] + $m['br']) : $w - $ml - (is_float($m['mr']) ? $m['mr'] : 0.0));
        $ops = array_merge([['t' => 'clip', 'x' => $x + $ml, 'y' => $top, 'w' => max(0.0, $bw), 'h' => max(0.0, $bottom - $top)]], $sub->flatten(), [['t' => 'endclip']]);
        $flow->add($top, max(0.0, $bottom - $top), $ops);
        $flow->maxRight = max($flow->maxRight, $sub->maxRight);
        $y = $sy - $cut;
        $flow->lastMarginBottom = $sub->lastMarginBottom;
    }

    /** position:relative offset (left wins over right, top over bottom). @return array{0: float, 1: float} */
    private function relativeOffset(array $st, float $w): array
    {
        $fs = (float)$st['_fs'];
        $l = Length::toPt($st['left'] ?? null, $w, $fs); $r = Length::toPt($st['right'] ?? null, $w, $fs);
        $t = Length::toPt($st['top'] ?? null, 0.0, $fs); $b = Length::toPt($st['bottom'] ?? null, 0.0, $fs);
        $dx = $l ?? ($r !== null ? -$r : 0.0); $dy = $t ?? ($b !== null ? -$b : 0.0);
        $lim = 10000.0;
        return [max(-$lim, min($lim, $dx)), max(-$lim, min($lim, $dy))];
    }

    /** transform: блок верстается как обычно, затем становится одним неразрывным атомом в матрице преобразования (не влияет на поток). */
    private function layoutTransformed(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, ?float $forceOuterW): void
    {
        $sub = new Flow(); $sub->lastMarginBottom = $flow->lastMarginBottom; $sy = $y;
        $st['_tf'] = 1;
        $this->layoutBlockTagged($el, $st, $x, $w, $sub, $sy, $depth, $forceOuterW);
        if (!$sub->atoms && !$sub->decos) { $y = $sy; return; }
        $m = $this->boxMetrics($st, $w);
        $ml = is_float($m['ml']) ? $m['ml'] : 0.0; $mr = is_float($m['mr']) ? $m['mr'] : 0.0;
        $top = INF; foreach ($sub->atoms as $a) $top = min($top, $a['y']); foreach ($sub->decos as $d) $top = min($top, $d['top']);
        $bottom = $sub->height(); $top = is_finite($top) ? $top : $y;
        $ws = $this->len($st['width'] ?? null, $w, $st);
        $bw = $forceOuterW ?? ($ws !== null ? $ws + (strtolower($st['box-sizing'] ?? '') === 'border-box' ? 0 : $m['pl'] + $m['pr'] + $m['bl'] + $m['br']) : $w - $ml - $mr);
        $bx = $x + $ml; $bh = max(0.0, $bottom - $top);
        if (!empty($st['_relonly']) && $bh > $this->pageContentHeight * 0.9) {
            // слишком высокий для неразрывного атома: смещение position:relative опускаем, контент не теряем
            unset($st['transform'], $st['_relonly']);
            $this->layoutBlockTagged($el, $st, $x, $w, $flow, $y, $depth, $forceOuterW);
            return;
        }
        $mx = Transform::parse($st['transform'], $bw, $bh, (float)$st['_fs']);
        $ops = $sub->flatten();
        if ($mx !== null) {
            [$ox, $oy] = Transform::origin($st['transform-origin'] ?? null, $bw, $bh, (float)$st['_fs']);
            $ops = array_merge([['t' => 'tf', 'm' => $mx, 'ox' => $bx + $ox, 'oy' => $top + $oy]], $ops, [['t' => 'endtf']]);
        }
        $flow->add($top, $bh, $ops);
        $flow->maxRight = max($flow->maxRight, $sub->maxRight);
        $y = $sy;
        $flow->lastMarginBottom = $sub->lastMarginBottom;
    }

    private function layoutBlockTagged(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, ?float $forceOuterW = null): void
    {
        $type = $this->tags ? Tags::typeOf($el, $st) : null;
        if ($type === null || !$this->tags) { $this->layoutBlockInner($el, $st, $x, $w, $flow, $y, $depth, $forceOuterW); return; }
        $tg = $this->tags;
        $sid = $tg->sidFor($el, $type);
        $inner = $type === 'LI' ? $tg->child($sid, 'LBody') : $sid;
        $from = count($flow->atoms);
        $tg->stack[] = $inner;
        try { $this->layoutBlockInner($el, $st, $x, $w, $flow, $y, $depth, $forceOuterW); }
        finally { array_pop($tg->stack); }
        $tg->markFlow($flow, $from, $inner, $type === 'Figure');
    }

    private function layoutBlockInner(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, ?float $forceOuterW = null): void
    {
        $tag = strtolower($el->nodeName);
        $d = $this->display($st);
        $bb = strtolower($st['break-before'] ?? '');
        if (in_array($bb, ['page', 'always', 'left', 'right'], true) && $flow->atoms) $flow->add($y, 0, [], ['break' => true]);
        $clr = strtolower(trim($st['clear'] ?? 'none'));
        if ($clr !== 'none' && $clr !== '') $y = max($y, $this->floatBottom($flow, $clr, $y));
        $m = $this->boxMetrics($st, $w);
        $mt = is_float($m['mt']) ? $m['mt'] : 0.0;
        $mb = is_float($m['mb']) ? $m['mb'] : 0.0;
        // схлопывание вертикальных отступов
        $collapse = max($mt, $flow->lastMarginBottom) - $flow->lastMarginBottom;
        if ($mt < 0) $collapse = $mt;
        $y += $collapse;
        $hpad = $m['pl'] + $m['pr'] + $m['bl'] + $m['br'];
        $vpad = $m['pt'] + $m['pb'] + $m['bt'] + $m['bb'];
        $bsz = strtolower($st['box-sizing'] ?? 'content-box') === 'border-box';
        $ml = $m['ml'] === 'auto' ? null : (float)$m['ml']; $mr = $m['mr'] === 'auto' ? null : (float)$m['mr'];
        $cwSpec = $this->len($st['width'] ?? null, $w, $st);
        if ($tag === 'table' || $d === 'table') { $this->layoutTableBox($el, $st, $x, $w, $flow, $y, $depth, $m); $flow->lastMarginBottom = 0; $y += $mb; $flow->lastMarginBottom = $mb; return; }
        if ($forceOuterW !== null) { $bw = $forceOuterW - ($ml ?? 0) - ($mr ?? 0); }
        elseif ($cwSpec !== null) $bw = $bsz ? $cwSpec : $cwSpec + $hpad;
        elseif (in_array($tag, ['img', 'svg'], true)) $bw = null;
        else $bw = $w - ($ml ?? 0) - ($mr ?? 0);
        if ($bw !== null) {
            if (($mx = $this->len($st['max-width'] ?? null, $w, $st)) !== null) $bw = min($bw, $bsz ? $mx : $mx + $hpad);
            if (($mn = $this->len($st['min-width'] ?? null, $w, $st)) !== null) $bw = max($bw, $bsz ? $mn : $mn + $hpad);
            $bw = max($hpad, min($bw, $w + 0.01 - max(0, $ml ?? 0) - max(0, $mr ?? 0)));
        }
        // атомарные блочные элементы
        if (in_array($tag, ['img', 'svg', 'chart', 'hr', 'toc'], true) || isset($st['--chart']) || $el->hasAttribute('data-chart')) {
            $this->layoutReplacedBlock($el, $st, $x, $w, $flow, $y, $depth, $m, $bw);
            $y += $mb; $flow->lastMarginBottom = $mb;
            return;
        }
        $bw ??= $w;
        if ($ml === null && $mr === null) { $ml = $mr = ($w - $bw) / 2; }
        elseif ($ml === null) $ml = $w - $bw - $mr;
        $bx = $x + $ml;
        $top = $y;
        $firstAtom = count($flow->atoms);
        $absMark = count($this->absQueue);
        $this->markers($el, $st, $flow, $y, $tag);
        $cx = $bx + $m['bl'] + $m['pl'];
        $cw = max(1, $bw - $hpad);
        $legend = $tag === 'fieldset' && $d === 'block' && $this->columnCount($st, $cw) === 1 ? $this->renderedLegend($el) : null;
        $legendOff = 0.0; $legendGap = null;
        if ($legend) {
            // legend сидит на верхней рамке: рамка проходит через середину legend с разрывом под ним
            $ls = $this->style($legend);
            $lw = min($this->minMax($legend, $ls)[1], $cw);
            $flow->lastMarginBottom = 0;
            $ly = $top;
            $this->layoutBlock($legend, $ls, $cx, $cw, $flow, $ly, $depth + 1, $lw);
            $lh = max(0.0, $ly - $top);
            $legendOff = max(0.0, ($lh - $m['bt']) / 2);
            $lm = $this->boxMetrics($ls, $cw);
            $lx = $cx + (is_float($lm['ml']) ? $lm['ml'] : 0.0);
            $legendGap = [$lx, $lx + $lw - (is_float($lm['ml']) ? $lm['ml'] : 0.0) - (is_float($lm['mr']) ? $lm['mr'] : 0.0)];
            $y = $top + max($lh, $m['bt']) + $m['pt'];
            $flow->lastMarginBottom = 0;
        } else $y += $m['bt'] + $m['pt'];
        $contentTop = $y;
        if ($m['pt'] > 0 || $m['bt'] > 0) $flow->lastMarginBottom = 0;
        elseif ($mt > 0) $flow->lastMarginBottom = max($flow->lastMarginBottom, $mt); // верхний отступ первого ребёнка схлопывается с отступом родителя
        if ($d === 'list-item') $this->listMarker($el, $st, $cx, $flow, $cw);
        if ($d === 'flex' || $d === 'inline-flex') $this->layoutFlex($el, $st, $cx, $cw, $flow, $y, $depth + 1);
        elseif ($d === 'grid' || $d === 'inline-grid') $this->layoutGrid($el, $st, $cx, $cw, $flow, $y, $depth + 1);
        elseif (($ncol = $this->columnCount($st, $cw)) > 1) $this->layoutColumns($el, $st, $cx, $cw, $ncol, $flow, $y, $depth + 1);
        else {
            $floatMark = count($flow->floats);
            $prevCb = $this->cbH; $prevInit = $this->cbInit;
            $ownH = $this->hLen($st['height'] ?? null, $st);
            $this->cbH = $ownH !== null ? max(0.0, $bsz ? $ownH - $vpad : $ownH) : null; $this->cbInit = true;
            $only = $legend ? array_values(array_filter(iterator_to_array($el->childNodes), fn($c) => $c !== $legend)) : null;
            try { $this->layoutChildren($el, $st, $cx, $cw, $flow, $y, $depth + 1, $only); } finally { $this->cbH = $prevCb; $this->cbInit = $prevInit; }
            // блок охватывает свои float (как BFC / clearfix) — фон и следующий контент не налезают
            if (count($flow->floats) > $floatMark) { $fb = $y; foreach (array_slice($flow->floats, $floatMark) as $f) $fb = max($fb, $f[4]); if ($fb > $y) { $flow->add($y, $fb - $y, []); $y = $fb; } }
        }
        $flow->pendingMarker = null;
        // явная высота / min-height
        $hSpec = $this->hLen($st['height'] ?? null, $st);
        $minH = $this->hLen($st['min-height'] ?? null, $st);
        $want = max($hSpec !== null ? ($bsz ? $hSpec - $vpad : $hSpec) : 0, $minH !== null ? ($bsz ? $minH - $vpad : $minH) : 0);
        if (!empty($st['_ovf']) && $hSpec !== null && $y - $contentTop > $want) $y = $contentTop + $want;
        if ($want > 0 && $y - $contentTop < $want) { $flow->add($y, $contentTop + $want - $y, []); $y = $contentTop + $want; }
        // нижний отступ последнего ребёнка схлопывается с нижним отступом родителя (нет padding/border/height снизу)
        $childMb = ($m['pb'] > 0 || $m['bb'] > 0 || $want > 0 || $hSpec !== null || !empty($st['_ovf'])) ? 0.0 : max(0.0, $flow->lastMarginBottom);
        if ($m['pb'] > 0 || $m['bb'] > 0) $flow->lastMarginBottom = 0;
        $y += $m['pb'] + $m['bb'];
        $deco = $this->decoBox($st, $bx, $bw, $m);
        if ($deco) {
            if (count($flow->atoms) === $firstAtom) $flow->add($top, $y - $top, []);
            if ($legendGap && $m['bt'] > 0) $deco['gap'] = $legendGap;
            $flow->decos[] = ['top' => $top + $legendOff, 'bottom' => $y, 'first' => $firstAtom, 'last' => count($flow->atoms) - 1, 'depth' => $depth, 'box' => $deco];
        }
        if (count($this->absQueue) > $absMark) {
            $abs = array_splice($this->absQueue, $absMark);
            if (count($flow->atoms) === $firstAtom) $flow->add($top, $y - $top, []);
            foreach ($abs as [$ael, $ast]) {
                try { $this->layoutAbsolute($ael, $ast, $bx, $top, $bw, $y - $top, $flow, count($flow->atoms) - 1, $depth); }
                catch (\Throwable $ex) { $this->warnings[] = 'absolute: ' . $ex->getMessage(); }
            }
        }
        $flow->maxRight = max($flow->maxRight, $bx + $bw);
        $bi = strtolower($st['break-inside'] ?? '');
        if (($bi === 'avoid' || $bi === 'avoid-page') && count($flow->atoms) > $firstAtom) {
            for ($i = $firstAtom; $i < count($flow->atoms) - 1; $i++) $flow->atoms[$i]['keepNext'] = true;
        }
        if (preg_match('/^h[1-6]$/', $tag) && count($flow->atoms) > $firstAtom) $flow->atoms[count($flow->atoms) - 1]['keepNext'] = true;
        if ($childMb > 0 && $mb >= 0) { $y += max($mb, $childMb) - $childMb; $flow->lastMarginBottom = max($mb, $childMb); }
        else { $y += $mb; $flow->lastMarginBottom = max(0, $mb); }
        $ba = strtolower($st['break-after'] ?? '');
        if (in_array($ba, ['page', 'always', 'left', 'right'], true)) $flow->add($y, 0, [], ['break' => true]);
    }

    /** Первый ребёнок-legend у fieldset (не float и не absolute), который рисуется на рамке. */
    private function renderedLegend(\DOMElement $el): ?\DOMElement
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMText) { if (trim($c->data) !== '') return null; continue; }
            if (!$c instanceof \DOMElement) continue;
            if (strtolower($c->nodeName) !== 'legend') return null;
            $cs = $this->style($c);
            if ($this->display($cs) === 'none' || in_array(strtolower(trim($cs['float'] ?? 'none')), ['left', 'right'], true) || in_array(strtolower($cs['position'] ?? ''), ['absolute', 'fixed'], true)) return null;
            return $c;
        }
        return null;
    }

    /** id → якорь, h1..h6 → закладка outline. */
    private function markers(\DOMElement $el, array $st, Flow $flow, float $y, string $tag): void
    {
        $ops = [];
        if ($id = $el->getAttribute('id')) $ops[] = ['t' => 'dest', 'name' => $id, 'y' => $y];
        if ($el->hasAttribute('name') && $tag === 'a') $ops[] = ['t' => 'dest', 'name' => $el->getAttribute('name'), 'y' => $y];
        if (preg_match('/^h([1-6])$/', $tag, $mm) && !$this->hasClass($el, 'no-toc') && !$el->hasAttribute('data-no-toc')) {
            $key = 'bp-h' . (++$this->headingSeq);
            $title = trim((string)preg_replace('/\s+/u', ' ', $el->textContent));
            if ($title !== '') {
                $this->headings[] = ['level' => (int)$mm[1], 'title' => $title, 'key' => $key];
                $ops[] = ['t' => 'heading', 'level' => (int)$mm[1], 'title' => $title, 'key' => $key, 'y' => $y];
                $ops[] = ['t' => 'dest', 'name' => $key, 'y' => $y];
            }
        }
        if ($ops) $flow->add($y, 0, $ops, ['keepNext' => true]);
    }

    public function hasClass(\DOMElement $el, string $c): bool { return in_array($c, preg_split('/\s+/', $el->getAttribute('class')) ?: [], true); }

    private function listMarker(\DOMElement $el, array $st, float $cx, Flow $flow, float $cw = 0.0): void
    {
        $type = strtolower(trim($st['list-style-type'] ?? 'disc'));
        if ($type === 'none') return;
        $idx = 1; $start = 1; $parent = $el->parentNode;
        if ($parent instanceof \DOMElement && $parent->hasAttribute('start')) $start = (int)$parent->getAttribute('start');
        for ($s = $el->previousSibling; $s; $s = $s->previousSibling) if ($s instanceof \DOMElement && strtolower($s->nodeName) === 'li') $idx++;
        $n = $idx + $start - 1;
        if ($el->hasAttribute('value')) $n = (int)$el->getAttribute('value');
        $text = match ($type) {
            'disc' => '•', 'circle' => '◦', 'square' => '▪', 'decimal' => $n . '.', 'decimal-leading-zero' => sprintf('%02d.', $n),
            'lower-alpha', 'lower-latin' => self::alpha($n) . '.', 'upper-alpha', 'upper-latin' => strtoupper(self::alpha($n)) . '.',
            'lower-roman' => strtolower(self::roman($n)) . '.', 'upper-roman' => self::roman($n) . '.', 'cyrillic', 'lower-cyrillic' => (mb_substr('абвгдежзиклмнопрстуфхцчшщэюя', ($n - 1) % 28, 1)) . ')',
            default => '•',
        };
        $rtl = strtolower((string)($st['direction'] ?? '')) === 'rtl';
        if ($rtl && preg_match('/^(.+)([.)])$/u', $text, $mm)) $text = $mm[2] . $mm[1]; // «1.» в RTL читается как «.1» (как в Chromium)
        $flow->pendingMarker = ['text' => $text, 'x' => $cx, 'st' => $st, 'xr' => $rtl ? $cx + $cw : null];
    }

    public static function alpha(int $n): string { $s = ''; while ($n > 0) { $n--; $s = chr(97 + $n % 26) . $s; $n = intdiv($n, 26); } return $s; }
    public static function roman(int $n): string
    {
        $r = ''; foreach (['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1] as $k => $v) while ($n >= $v) { $r .= $k; $n -= $v; }
        return $r;
    }

    // ---------- replaced (img/svg/chart/hr) ----------
    private function layoutReplacedBlock(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, array $m, ?float $bw): void
    {
        $tag = strtolower($el->nodeName);
        $this->markers($el, $st, $flow, $y, $tag);
        if ($tag === 'hr') {
            $ml = is_numeric($m['ml']) ? $m['ml'] : 0;
            $bwid = $bw ?? $w - $ml;
            $th = max($m['bt'], 0.75);
            $col = $m['bt'] ? $m['bct'] : [0.7, 0.7, 0.7, 1];
            $flow->add($y, $th, [['t' => 'rect', 'x' => $x + $ml, 'y' => $y, 'w' => $bwid, 'h' => $th, 'fill' => $col]]);
            $y += $th;
            return;
        }
        if ($tag === 'toc') { $this->layoutToc($el, $st, $x, $w, $flow, $y, $depth); return; }
        [$iw, $ih, $ops] = $this->replaced($el, $st, $w);
        $hpad = $m['pl'] + $m['pr'] + $m['bl'] + $m['br']; $vpad = $m['pt'] + $m['pb'] + $m['bt'] + $m['bb'];
        $ow = $iw + $hpad;
        $ml = $m['ml']; $mr = $m['mr'];
        $align = strtolower($st['text-align'] ?? 'left');
        if ($ml === 'auto' && $mr === 'auto') $ox = $x + ($w - $ow) / 2;
        elseif ($ml === 'auto') $ox = $x + $w - $ow - (float)$mr;
        else $ox = $x + (float)$ml;
        $deco = $this->decoBox($st, $ox, $ow, $m);
        $all = $deco ? Ops::box($deco, 0, $ih + $vpad, true, true) : [];
        foreach (Ops::translate(self::clipImage($ops, $deco, $m), $m['bl'] + $m['pl'], $m['bt'] + $m['pt']) as $o) $all[] = $o;
        $flow->add($y, $ih + $vpad, Ops::translate($all, $ox, $y));
        $y += $ih + $vpad;
    }

    /** Внутренний радиус скругления передаётся картинке: содержимое режется по padding-box. */
    private static function clipImage(array $ops, ?array $deco, array $m): array
    {
        $r = $deco ? (float)$deco['radius'] - max($m['bt'], $m['br'], $m['bb'], $m['bl']) : 0.0;
        if ($r <= 0) return $ops;
        foreach ($ops as &$o) if (($o['t'] ?? '') === 'image') $o['r'] = $r;
        return $ops;
    }

    /** Размер и операции для img/svg/chart, вписанные в доступную ширину. @return array{0:float,1:float,2:array} */
    public function replaced(\DOMElement $el, array $st, float $avail): array
    {
        $tag = strtolower($el->nodeName);
        $wSpec = $this->len($st['width'] ?? null, $avail, $st);
        $hSpec = $this->len($st['height'] ?? null, $this->pageContentHeight, $st);
        $maxW = $this->len($st['max-width'] ?? null, $avail, $st) ?? $avail;
        $maxW = min($maxW, $avail);
        $maxH = $this->pageContentHeight - 2;
        if ($tag === 'chart' || $el->hasAttribute('data-chart')) {
            $w = $wSpec ?? $avail; $w = min($w, $maxW);
            $h = $hSpec ?? ($el->hasAttribute('height') ? (float)$el->getAttribute('height') * .75 : $w * 0.5);
            $h = min($h, $maxH);
            $cfg = ChartRenderer::configFromElement($el);
            return [$w, $h, (new ChartRenderer($this))->render($cfg, $w, $h, $st)];
        }
        if ($tag === 'svg') {
            $svg = new SvgRenderer($this);
            [$nw, $nh] = $svg->naturalSize($el);
            [$w, $h] = self::fit($nw, $nh, $wSpec, $hSpec, $maxW, $maxH);
            return [$w, $h, $svg->render($el, $w, $h)];
        }
        // img
        $src = $el->getAttribute('src');
        $data = $src !== '' ? $this->loader->load($src) : null;
        if ($data !== null && (str_contains(substr($data, 0, 300), '<svg') || str_starts_with(ltrim($data), '<?xml'))) {
            $doc = new \DOMDocument();
            if (@$doc->loadXML($data, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) && $doc->documentElement) {
                $svg = new SvgRenderer($this);
                $root = $doc->documentElement;
                [$nw, $nh] = $svg->naturalSize($root);
                [$w, $h] = self::fit($nw, $nh, $wSpec, $hSpec, $maxW, $maxH);
                return [$w, $h, $svg->render($root, $w, $h)];
            }
            $data = null;
        }
        $size = $data !== null ? \BaurPDF\Pdf\Image::size($data) : null;
        if (!$size) {
            if ($src !== '') $this->warnings[] = "Image not loaded: " . substr($src, 0, 120);
            $w = $wSpec ?? 60; $h = $hSpec ?? 45; $w = min($w, $maxW);
            $alt = $el->getAttribute('alt');
            $ops = [['t' => 'rect', 'x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'fill' => [0.94, 0.94, 0.95, 1], 'stroke' => [0.8, 0.8, 0.82, 1], 'lw' => 0.5]];
            if ($alt !== '') $ops = array_merge($ops, $this->textOps($alt, 4, min($h - 3, 10), $st, 7, [0.45, 0.45, 0.5, 1], $w - 8));
            return [$w, $h, $ops];
        }
        [$w, $h] = self::fit($size[0] * 0.75, $size[1] * 0.75, $wSpec, $hSpec, $maxW, $maxH);
        $fit = strtolower($st['object-fit'] ?? 'fill');
        return [$w, $h, [['t' => 'image', 'x' => 0, 'y' => 0, 'w' => $w, 'h' => $h, 'data' => $data, 'cover' => $fit === 'cover', 'contain' => $fit === 'contain']]];
    }

    public static function fit(float $nw, float $nh, ?float $w, ?float $h, float $maxW, float $maxH): array
    {
        $nw = $nw > 0 ? $nw : 300; $nh = $nh > 0 ? $nh : 150;
        if ($w !== null && $h === null) $h = $w * $nh / $nw;
        elseif ($h !== null && $w === null) $w = $h * $nw / $nh;
        elseif ($w === null) { $w = $nw; $h = $nh; }
        if ($w > $maxW) { $h *= $maxW / $w; $w = $maxW; }
        if ($h > $maxH) { $w *= $maxH / $h; $h = $maxH; }
        return [max(1, $w), max(1, $h)];
    }

    /** Простой однострочный текст (для подписей, графиков). */
    public function textOps(string $text, float $x, float $baseline, array $st, float $size, array $color, ?float $maxW = null, string $align = 'left', bool $bold = false): array
    {
        [$font, $fb, $fi] = $this->fonts->get($st['font-family'] ?? 'sans-serif', $bold ? 'bold' : 'normal', 'normal');
        $w = $this->textWidth($font, $text, $size);
        if ($maxW !== null && $w > $maxW && $maxW > 0) {
            while (mb_strlen($text) > 1 && $this->textWidth($font, $text . '…', $size) > $maxW) $text = mb_substr($text, 0, -1);
            $text .= '…'; $w = $this->textWidth($font, $text, $size);
        }
        if ($align === 'center') $x -= $w / 2; elseif ($align === 'right') $x -= $w;
        return $this->segmentsOps($font, $fb, $fi, $text, $x, $baseline, $size, $color);
    }

    /** Разбивка по шрифтам с fallback на DejaVu для отсутствующих глифов. */
    public function segmentsOps(TrueTypeFont $font, bool $fb, bool $fi, string $text, float $x, float $baseline, float $size, array $color, float $ls = 0, bool $kerning = true): array
    {
        $ops = [];
        foreach ($this->segments($font, $text, $fb) as [$f, $t]) {
            $ops[] = ['t' => 'text', 'x' => $x, 'y' => $baseline, 'font' => $f, 'size' => $size, 'color' => $color, 'text' => $t, 'fb' => $fb, 'fi' => $fi, 'ls' => $ls] + ($kerning ? [] : ['nk' => true]);
            $x += $this->textWidth($f, $t, $size, $kerning) + $ls * mb_strlen($t);
        }
        return $ops;
    }

    public function segments(TrueTypeFont $font, string $text, bool $bold = false): array
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text)) return [[$font, $text]];
        $ck = spl_object_id($font) . ($bold ? 'b' : 'r') . $text;
        if (isset($this->segCache[$ck])) return $this->segCache[$ck];
        if (count($this->segCache) > 50000) $this->segCache = [];
        $segs = []; $cur = ''; $curF = $font;
        foreach (mb_str_split($text) as $ch) {
            $cp = mb_ord($ch);
            $f = $font->hasChar($cp) || $cp < 32 ? $font : $this->fonts->fallbackFor($cp, $bold || $font->bold);
            if (!$f->hasChar($cp) && $cp >= 32 && $f !== $font) $f = $font;
            if ($cp > 32 && !$f->hasChar($cp) && !preg_match('/^[\s\x{00AD}\x{200B}-\x{200F}\x{FE0E}\x{FE0F}]$/u', $ch)) $this->warnings['glyph:' . $cp] ??= sprintf('No glyph for U+%04X in any font (add one with ->fallbackFont())', $cp);
            if ($f !== $curF && $cur !== '') { $segs[] = [$curF, $cur]; $cur = ''; }
            $curF = $f; $cur .= $ch;
        }
        if ($cur !== '') $segs[] = [$curF, $cur];
        return $this->segCache[$ck] = $segs;
    }

    /** @var array<string, float> ширины слов в 1/1000 em (слова в документах сильно повторяются) */
    private array $twCache = [];
    /** @var array<string, list<array{0: TrueTypeFont, 1: string}>> */
    private array $segCache = [];

    /** Кернинг (GPOS/kern); выключается ->kerning(false) или CSS font-kerning: none. */
    public bool $kerning = true;

    public function textWidth(TrueTypeFont $f, string $t, float $size, bool $kerning = true): float
    {
        $ck = spl_object_id($f) . ($kerning ? "\0" : "\1") . $t;
        if (isset($this->twCache[$ck])) return $this->twCache[$ck] * $size / 1000;
        if (count($this->twCache) > 100000) $this->twCache = [];
        $w = 0;
        $kern = $kerning && $f->hasKerning(); $prev = -1;
        if (preg_match('/^[\x20-\x7E]*$/', $t)) { for ($i = 0, $n = strlen($t); $i < $n; $i++) { $cp = ord($t[$i]); $w += $f->charWidth($cp); if ($kern && $prev >= 0) $w += $f->kernCp($prev, $cp); $prev = $cp; } }
        else foreach (mb_str_split($t) as $ch) {
            $cp = mb_ord($ch);
            if ($f->hasChar($cp)) { $w += $f->charWidth($cp); if ($kern && $prev >= 0) $w += $f->kernCp($prev, $cp); $prev = $cp; }
            else { $w += $this->fonts->fallbackFor($cp, $f->bold)->charWidth($cp); $prev = -1; }
        }
        $this->twCache[$ck] = $w;
        return $w * $size / 1000;
    }

    // ---------- columns ----------
    /** Число колонок из columns / column-count / column-width. */
    private function columnCount(array $st, float $cw): int
    {
        $cnt = null; $cwid = null;
        foreach (preg_split('/\s+/', trim((string)($st['columns'] ?? ''))) ?: [] as $p) {
            if ($p === '' || $p === 'auto') continue;
            if (ctype_digit($p)) $cnt = (int)$p; else $cwid = $this->len($p, $cw, $st);
        }
        if (isset($st['column-count']) && ctype_digit(trim($st['column-count']))) $cnt = (int)trim($st['column-count']);
        if (isset($st['column-width']) && ($v = $this->len($st['column-width'], $cw, $st))) $cwid = $v;
        $gap = $this->len($st['column-gap'] ?? '1em', $cw, $st) ?? 12;
        if ($cwid && $cwid > 0) { $byW = max(1, (int)floor(($cw + $gap) / ($cwid + $gap))); $cnt = $cnt ? min($cnt, $byW) : $byW; }
        return max(1, min(12, $cnt ?? 1));
    }

    /** Многоколоночная вёрстка: балансировка по высоте, на длинном тексте — сегменты высотой в страницу. */
    private function layoutColumns(\DOMElement $el, array $st, float $x, float $w, int $n, Flow $flow, float &$y, int $depth): void
    {
        // column-span: all — делит набор колонок: колонки до, элемент на всю ширину, колонки после
        $parts = []; $cur = [];
        foreach (iterator_to_array($el->childNodes) as $c) {
            if ($c instanceof \DOMElement && strtolower(trim($this->style($c)['column-span'] ?? '')) === 'all' && $this->display($this->style($c)) !== 'none') { $parts[] = $cur; $parts[] = $c; $cur = []; continue; }
            $cur[] = $c;
        }
        if (!$parts) { $this->columnsPart($el, $st, $x, $w, $n, $flow, $y, $depth, null); return; }
        $parts[] = $cur;
        foreach ($parts as $p) {
            if ($p instanceof \DOMElement) { $this->layoutBlock($p, $this->style($p), $x, $w, $flow, $y, $depth); continue; }
            $hasContent = false; foreach ($p as $nd) if ($nd instanceof \DOMElement || trim((string)$nd->textContent) !== '') { $hasContent = true; break; }
            if ($hasContent) $this->columnsPart($el, $st, $x, $w, $n, $flow, $y, $depth, $p);
        }
    }

    /** @param list<\DOMNode>|null $only */
    private function columnsPart(\DOMElement $el, array $st, float $x, float $w, int $n, Flow $flow, float &$y, int $depth, ?array $only): void
    {
        $gap = $this->len($st['column-gap'] ?? '1em', $w, $st) ?? 12;
        if ($gap > $w / $n) $gap = 0;
        $colW = ($w - $gap * ($n - 1)) / $n;
        $sub = new Flow(); $yy = 0.0;
        $this->layoutChildren($el, $st, 0, $colW, $sub, $yy, $depth, $only);
        $atoms = array_values(array_filter($sub->atoms, fn($a) => empty($a['break']) && empty($a['insert'])));
        if (!$atoms) return;
        $rule = trim((string)($st['column-rule'] ?? ''));
        $ruleW = 0.0; $ruleC = null;
        if ($rule !== '' && $rule !== 'none') {
            foreach (preg_split('/\s+(?![^(]*\))/', $rule) ?: [] as $p) {
                if (($v = $this->len($p, 0, $st)) !== null && preg_match('/^[\d.]/', $p)) $ruleW = $v;
                elseif ($c = Color::parse($p)) $ruleC = $c;
            }
            $ruleC ??= Color::parse($st['color'] ?? '#000') ?? [0, 0, 0, 1];
            if ($ruleW <= 0) $ruleW = 0.75;
        }
        $items = array_map(fn($a) => ['y' => $a['y'], 'h' => $a['h'], 'ops' => $a['ops']] + (isset($a['pl']) ? ['pl' => $a['pl']] : []), $atoms);
        // индексы атомов подпотока → индексы в $items (для фонов внутри колонок)
        $map = []; $k = 0; foreach ($sub->atoms as $i => $a) if (empty($a['break']) && empty($a['insert'])) $map[$i] = $k++;
        $decos = [];
        foreach ($sub->decos as $d) if (isset($map[$d['first']], $map[$d['last']])) $decos[] = ['first' => $map[$d['first']], 'last' => $map[$d['last']], 'top' => $d['top'], 'bottom' => $d['bottom'], 'box' => $d['box']];
        $set = new ColumnSet($items, $n, $colW, $gap, $x, $ruleW > 0 ? [$ruleW, $ruleC ?? [0, 0, 0, 1]] : null, $decos);
        $pageH = $this->pageContentHeight;
        if ($flow === $this->mainFlow) {
            // основной поток: колонки режутся пагинатором по фактическому остатку страницы
            $bal = min($set->balanced(), $pageH);
            $flow->add($y, $bal, [], ['cols' => $set]);
            $y += $bal;
        } else {
            // вложенный контекст (ячейка, flex): сегменты высотой в страницу
            foreach ($set->segments($pageH, $pageH) as $sg) { if ($sg['h'] < 0) continue; $flow->add($y, $sg['h'], Ops::translate($sg['ops'], 0, $y)); $y += $sg['h']; }
        }
        $flow->lastMarginBottom = 0;
        $y += $sub->lastMarginBottom;
        $flow->lastMarginBottom = $sub->lastMarginBottom;
    }

    // ---------- float ----------
    /** Плавающий блок: shrink-to-fit ширина, прижат к краю, текст после него обтекает. */
    private function layoutFloat(\DOMElement $el, array $st, string $side, float $x, float $w, Flow $flow, float &$y, int $depth): void
    {
        $st['float'] = 'none';
        $m = $this->boxMetrics($st, $w);
        $mlr = (is_numeric($m['ml']) ? (float)$m['ml'] : 0) + (is_numeric($m['mr']) ? (float)$m['mr'] : 0);
        $hpad = $m['pl'] + $m['pr'] + $m['bl'] + $m['br'];
        $tag = strtolower($el->nodeName);
        $spec = $this->len($st['width'] ?? null, $w, $st);
        if ($spec !== null) $fw = (strtolower($st['box-sizing'] ?? '') === 'border-box' ? $spec : $spec + $hpad) + $mlr;
        elseif (in_array($tag, ['img', 'svg'], true)) { [$iw] = $this->replaced($el, $st, $w * 0.6); $fw = $iw + $hpad + $mlr; }
        else { [, $mx] = $this->minMax($el, $st); $fw = min($w * 0.6, $mx + $hpad + $mlr + 1); }
        $fw = max(4.0, min($fw, $w));
        // занятые полосы на этой высоте
        [$offL, $wAvail, ] = $this->floatNarrow($flow, $x, $w, $y) ?? [0.0, $w, 0.0];
        if ($fw > $wAvail + 0.01) { $y = max($y, $this->floatBottom($flow, 'both', $y)); $offL = 0.0; $wAvail = $w; }
        $sub = new Flow(); $yy = 0.0;
        $this->layoutBlock($el, $st, 0, $fw, $sub, $yy, $depth + 1, $fw);
        $fh = max($yy, $sub->height());
        $fx = $side === 'left' ? $x + $offL : $x + $offL + $wAvail - $fw;
        $flow->add($y, $fh, $sub->flatten($fx, $y));
        $flow->lastMarginBottom = 0;
        $flow->floats[] = [$side, $fx, $fx + $fw, $y, $y + $fh];
        $flow->maxRight = max($flow->maxRight, $fx + $fw);
    }

    /** Сужение строки на высоте $y: [сдвиг слева, ширина, нижняя кромка] или null. */
    private function floatNarrow(Flow $flow, float $x, float $w, float $y): ?array
    {
        if (!$flow->floats) return null;
        $l = $x; $r = $x + $w; $bottom = 0.0; $hit = false;
        foreach ($flow->floats as [$side, $x0, $x1, $top, $bot]) {
            if ($bot <= $y + 0.01 || $top > $y + 0.01 || $x1 <= $x || $x0 >= $x + $w) continue;
            $hit = true; $bottom = max($bottom, $bot);
            if ($side === 'left') $l = max($l, $x1 + 6); else $r = min($r, $x0 - 6);
        }
        if (!$hit) return null;
        if ($r - $l < 30) return [0.0, $w, $bottom]; // слишком узко — строки под float (упрощение)
        return [$l - $x, $r - $l, $bottom];
    }

    private function floatBottom(Flow $flow, string $clear, float $y): float
    {
        $b = $y;
        foreach ($flow->floats as [$side, , , , $bot]) if ($clear === 'both' || $clear === $side) $b = max($b, $bot);
        return $b;
    }

    // ---------- inline ----------
    private function layoutInline(array $nodes, array $st, float $x, float $w, Flow $flow, float &$y, int $depth): void
    {
        $items = [];
        $space = true;
        $ws = strtolower($st['white-space'] ?? 'normal');
        foreach ($nodes as $n) $this->gather($n, $st, $items, $space, null, $w);
        // убрать пробелы по краям
        while ($items && $items[0]['t'] === 's') array_shift($items);
        while ($items && end($items)['t'] === 's') array_pop($items);
        if (!$items) return;
        $rtl = strtolower(trim($st['direction'] ?? 'ltr')) === 'rtl';
        $align = strtolower($st['text-align'] ?? 'start');
        if ($align === 'start' || $align === '-webkit-auto') $align = $rtl ? 'right' : 'left';
        elseif ($align === 'end') $align = $rtl ? 'left' : 'right';
        $bidi = $rtl; if (!$bidi) foreach ($items as $it) if (!empty($it['rtl'])) { $bidi = true; break; }
        $lb = new LineBreaker($this);
        // обтекание float: первые строки сужаются до нижней кромки плавающих блоков
        $narrow = $this->floatNarrow($flow, $x, $w, $y);
        if ($narrow && $narrow[0] == 0 && $narrow[1] == $w) { $y = $narrow[2]; $narrow = null; } // слишком узко — текст под float
        if ($narrow) {
            $lh = (float)($items[0]['run']['lh'] ?? ((float)$st['_fs'] * 1.3)) ?: 12.0;
            $lb->narrow = [(int)ceil(($narrow[2] - $y) / max(1.0, $lh) - 0.01), $narrow[0], $narrow[1]];
        }
        $lines = $lb->breakLines($items, $w, $this->len($st['text-indent'] ?? '0', $w, $st) ?? 0, $ws === 'nowrap' || str_starts_with($ws, 'pre') ? 'left' : $align);
        $last = count($lines) - 1;
        // widows/orphans (CSS 2.1, по умолчанию 2): минимум строк абзаца до и после разрыва страницы или колонки
        $nl = count($lines);
        $orph = max(1, min(20, (int)($st['orphans'] ?? 2) ?: 2)); $wid = max(1, min(20, (int)($st['widows'] ?? 2) ?: 2));
        foreach ($lines as $i => $line) {
            $off = $lb->narrow && $i < $lb->narrow[0] ? $lb->narrow[1] : 0.0;
            if ($bidi) $line['items'] = \BaurPDF\Text\Bidi::reorderLine(array_values($line['items']), $rtl);
            $ops = $lb->paintLine($line, $x + $off, $lb->lineW($i, $w), $align, $i === $last, $i === 0 ? ($this->len($st['text-indent'] ?? '0', $w, $st) ?? 0) : 0);
            $h = $line['h'];
            if ($flow->pendingMarker) {
                $pm = $flow->pendingMarker; $flow->pendingMarker = null;
                [$f, $fb, $fi] = $this->fonts->get($pm['st']['font-family'] ?? 'sans-serif', $pm['st']['font-weight'] ?? 'normal', 'normal');
                $sz = (float)$pm['st']['_fs'];
                $mw = $this->textWidth($f, $pm['text'], $sz);
                $col = Color::parse($pm['st']['color'] ?? '#000') ?? [0, 0, 0, 1];
                foreach ($this->segmentsOps($f, $fb, $fi, $pm['text'], $pm['xr'] !== null ? $pm['xr'] + $sz * 0.4 : $pm['x'] - $mw - $sz * 0.4, $line['baseline'], $sz, $col) as $o) $ops[] = $o;
            }
            $flow->add($y, $h, Ops::translate($ops, 0, $y), $nl > 1 ? ['pl' => [$i, $nl, $orph, $wid]] : []);
            $y += $h;
        }
    }

    /** Сбор inline-элементов: слова, пробелы, br, атомарные (img, inline-block). */
    public function gather(\DOMNode $n, array $st, array &$items, bool &$space, ?string $href, float $availW): void
    {
        if ($n instanceof \DOMText) {
            $text = $n->nodeValue ?? '';
            $ws = strtolower($st['white-space'] ?? 'normal');
            $tt = strtolower($st['text-transform'] ?? 'none');
            if ($tt === 'uppercase') $text = mb_strtoupper($text); elseif ($tt === 'lowercase') $text = mb_strtolower($text); elseif ($tt === 'capitalize') $text = mb_convert_case($text, MB_CASE_TITLE);
            $run = $this->run($st, $href);
            if ($ws === 'pre' || $ws === 'pre-wrap' || $ws === 'pre-line' || $ws === 'break-spaces') {
                $text = str_replace(["\r\n", "\r"], ["\n", "\n"], $text);
                if (str_contains($text, "\t")) $text = self::expandTabs($text, max(1, min(32, (int)($st['tab-size'] ?? 8))));
                if ($ws === 'pre-line') $text = (string)preg_replace('/[ ]+/', ' ', $text);
                $lines = explode("\n", $text);
                foreach ($lines as $li => $ln) {
                    if ($li > 0) $items[] = ['t' => 'br', 'run' => $run];
                    if ($ln === '') continue;
                    if ($ws === 'pre') { $items[] = $this->word($ln, $run, true); continue; }
                    foreach (preg_split('/( )/', $ln, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $p) $items[] = $p === ' ' ? ['t' => 's', 'w' => $this->spaceW($run), 'run' => $run] : $this->word($p, $run);
                }
                $space = false;
                return;
            }
            $parts = preg_split('/([^\S\x{00A0}]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            $nowrap = $ws === 'nowrap';
            $keepCjk = strtolower(trim($st['word-break'] ?? 'normal')) === 'keep-all';
            foreach ($parts as $p) {
                if (preg_match('/^[\s]+$/u', $p) && !str_contains($p, "\u{00A0}")) {
                    if (!$space) { $items[] = ['t' => 's', 'w' => $this->spaceW($run), 'run' => $run, 'nobreak' => $nowrap]; $space = true; }
                    continue;
                }
                $p = str_replace("\u{00A0}", ' ', $p);
                // CJK: точки переноса между иероглифами/каной (подмножество UAX #14: не после открывающей скобки, не перед 。、）и т. п.)
                // быстрый отсев: SHY (C2 AD), ZWSP (E2 80 8B) и CJK (U+1100+) начинаются с байтов C2 / E1–EF; кириллица и латиница — нет
                $hi = strcspn($p, "\xC2\xE1\xE2\xE3\xE4\xE5\xE6\xE7\xE8\xE9\xEA\xEB\xEC\xED\xEE\xEF") < strlen($p);
                $shy = $hi && (str_contains($p, "\u{00AD}") || str_contains($p, "\u{200B}"));
                if ($hi && !$shy && !$nowrap && !$keepCjk && \BaurPDF\Text\CjkLineBreak::contains($p)) {
                    foreach (\BaurPDF\Text\CjkLineBreak::segments($p) as $ci => $c) {
                        if ($ci > 0) $items[] = ['t' => 'h', 'w' => 0.0, 'hw' => 0.0, 'run' => $run];
                        $items[] = $this->word($c, $run);
                    }
                    $space = false;
                    continue;
                }
                // мягкий перенос (U+00AD) невидим и даёт точку переноса с дефисом; ZWSP (U+200B) — точку без дефиса
                if ($shy) {
                    $hw = $this->textWidth($run['font'], '-', $run['size']);
                    foreach (preg_split('/([\x{00AD}\x{200B}])/u', $p, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $sp) {
                        if ($sp === "\u{00AD}" || $sp === "\u{200B}") { if (!$nowrap) $items[] = ['t' => 'h', 'w' => 0, 'hw' => $sp === "\u{00AD}" ? $hw : 0, 'run' => $run]; }
                        elseif ($sp !== '') $items[] = $this->word($sp, $run, $nowrap);
                    }
                    continue;
                }
                // разрешаем перенос после дефиса/слэша в длинных словах
                $chunks = mb_strlen($p) > 12 ? (preg_split('/(?<=[\-\/_])/u', $p, -1, PREG_SPLIT_NO_EMPTY) ?: [$p]) : [$p];
                foreach ($chunks as $ci => $c) {
                    $wd = $this->word($c, $run, $nowrap || ($keepCjk && \BaurPDF\Text\CjkLineBreak::contains($c))) + ($ci > 0 ? ['glue' => true] : []);
                    if ($ci === 0) $this->kernSpace($items, $wd);
                    $items[] = $wd;
                }
                $space = false;
            }
            return;
        }
        if (!$n instanceof \DOMElement) return;
        $cs = $this->style($n);
        $d = $this->display($cs);
        if ($d === 'none') return;
        $tag = strtolower($n->nodeName);
        if ($tag === 'br') { $items[] = ['t' => 'br', 'run' => $this->run($cs, $href)]; $space = true; return; }
        if ($tag === 'a' && $n->hasAttribute('href')) $href = $n->getAttribute('href');
        if ($id = $n->getAttribute('id')) $items[] = ['t' => 'dest', 'name' => $id, 'w' => 0, 'run' => $this->run($cs, $href)];
        if (in_array($tag, ['img', 'svg'], true) || ($d !== 'inline' && $d !== 'contents')) {
            $items[] = $this->atomic($n, $cs, $availW, $href);
            $space = false;
            return;
        }
        $m = $this->boxMetrics($cs, $availW);
        $bg = Color::parse($cs['background-color'] ?? null);
        $hasBox = $bg || $m['bl'] || $m['br'] || $m['bt'] || $m['bb'] || $m['pl'] || $m['pr'];
        $startIdx = count($items);
        $lead = (is_numeric($m['ml']) ? $m['ml'] : 0) + $m['pl'] + $m['bl'];
        if ($lead > 0) { $items[] = ['t' => 'pad', 'w' => $lead, 'run' => $this->run($cs, $href)]; }
        foreach (iterator_to_array($n->childNodes) as $c) $this->gather($c, $cs, $items, $space, $href, $availW);
        $trail = (is_numeric($m['mr']) ? $m['mr'] : 0) + $m['pr'] + $m['br'];
        if ($trail > 0) $items[] = ['t' => 'pad', 'w' => $trail, 'run' => $this->run($cs, $href)];
        if ($hasBox) {
            $boxId = spl_object_id($n);
            $info = ['bg' => $bg, 'pt' => $m['pt'], 'pb' => $m['pb'], 'radius' => $this->len($cs['border-radius'] ?? '0', 100, $cs) ?? 0,
                'border' => $m['bt'] ? [$m['bt'], $m['bct']] : null, 'ml' => is_numeric($m['ml']) ? $m['ml'] : 0, 'mr' => is_numeric($m['mr']) ? $m['mr'] : 0];
            for ($i = $startIdx; $i < count($items); $i++) $items[$i]['ibox'][$boxId] = $info;
        }
    }

    public function run(array $st, ?string $href): array
    {
        [$font, $fb, $fi] = $this->fonts->get($st['font-family'] ?? 'sans-serif', $st['font-weight'] ?? 'normal', $st['font-style'] ?? 'normal');
        $size = (float)$st['_fs'];
        $lhv = strtolower(trim($st['line-height'] ?? 'normal'));
        if ($lhv === 'normal') $lh = $font->normalLineHeightAt($size);
        elseif (is_numeric($lhv)) $lh = $size * (float)$lhv;
        else $lh = Length::toPt($lhv, $size, $size) ?? $size * 1.2;
        $color = Color::parse(($st['color'] ?? '#000')) ?? [0, 0, 0, 1];
        $deco = strtolower($st['text-decoration'] ?? 'none');
        $va = strtolower($st['vertical-align'] ?? 'baseline');
        return ['font' => $font, 'fb' => $fb, 'fi' => $fi, 'size' => $size, 'lh' => $lh, 'color' => $color, 'href' => $href,
            'u' => str_contains($deco, 'underline'), 's' => str_contains($deco, 'line-through'), 'o' => str_contains($deco, 'overline'),
            'va' => $va, 'ls' => Length::toPt($st['letter-spacing'] ?? null, 0, $size) ?? 0, 'st' => $st] + (!$this->kerning || strtolower(trim($st['font-kerning'] ?? '')) === 'none' ? ['nk' => true] : []) + (self::breaksAnywhere($st) ? ['ba' => true] : []);
    }

    /**
     * Кернинг через пробел (как у Chromium, который меряет текст целиком): пары «буква–пробел» и «пробел–буква»
     * добавляются к ширине пробела между двумя словами одного шрифта/размера.
     */
    private function kernSpace(array &$items, array $next): void
    {
        $k = count($items) - 1;
        if ($k < 1 || $items[$k]['t'] !== 's' || ($items[$k - 1]['t'] ?? '') !== 'w' || !empty($next['rtl']) || !empty($items[$k - 1]['rtl'])) return;
        $sp = $items[$k]; $pw = $items[$k - 1];
        $f = $sp['run']['font'];
        if (!empty($sp['run']['nk']) || $pw['run']['font'] !== $f || $next['run']['font'] !== $f || $pw['run']['size'] != $sp['run']['size'] || $next['run']['size'] != $sp['run']['size'] || !$f->hasKerning()) return;
        $a = mb_ord(mb_substr((string)$pw['text'], -1)); $b = mb_ord(mb_substr((string)$next['text'], 0, 1));
        $adj = (($a !== false && $f->hasChar($a) ? $f->kernCp($a, 32) : 0) + ($b !== false && $f->hasChar($b) ? $f->kernCp(32, $b) : 0)) * $sp['run']['size'] / 1000;
        if ($adj != 0) $items[$k]['w'] += $adj;
    }

    private function spaceW(array $run): float
    {
        return $run['font']->charWidth(32) * $run['size'] / 1000 + $run['ls'] + (Length::toPt($run['st']['word-spacing'] ?? null, 0, $run['size']) ?? 0);
    }

    public function word(string $text, array $run, bool $nobreak = false): array
    {
        $rtl = false;
        if (\BaurPDF\Text\Bidi::hasRtl($text)) {
            $f = $run['font']; $bold = $f->bold || $run['fb'];
            $text = \BaurPDF\Text\Bidi::visualWord(\BaurPDF\Text\Bidi::shape($text, fn(int $cp): bool => $f->hasChar($cp) || $this->fonts->fallbackFor($cp, $bold)->hasChar($cp)), strtolower((string)($run['st']['direction'] ?? '')) === 'rtl');
            $rtl = true; $nobreak = true;
        } elseif (strtolower((string)($run['st']['direction'] ?? '')) === 'rtl' && \BaurPDF\Text\Bidi::hasLtr($text)) $text = \BaurPDF\Text\Bidi::rtlEdges($text);
        return ['t' => 'w', 'text' => $text, 'w' => $this->textWidth($run['font'], $text, $run['size'], empty($run['nk'])) + $run['ls'] * mb_strlen($text), 'run' => $run, 'nobreak' => $nobreak] + ($rtl ? ['rtl' => true] : []);
    }

    private const FORM = ['input' => 1, 'select' => 1, 'textarea' => 1, 'button' => 1];

    /** Тип элемента формы: text|password|checkbox|radio|textarea|select|button. */
    private static function formKind(\DOMElement $n): string
    {
        $tag = strtolower($n->nodeName);
        if ($tag !== 'input') return $tag;
        $t = strtolower($n->getAttribute('type') ?: 'text');
        return match ($t) { 'checkbox', 'radio', 'password' => $t, 'submit', 'button', 'reset', 'image' => 'button', default => 'text' };
    }

    /** Подпись кнопки / выбранное значение списка. */
    private static function formText(\DOMElement $n, string $kind): string
    {
        if ($kind === 'button') return strtolower($n->nodeName) === 'button' ? trim((string)preg_replace('/\s+/u', ' ', $n->textContent)) : ($n->getAttribute('value') ?: (strtolower($n->getAttribute('type')) === 'reset' ? 'Сбросить' : 'Отправить'));
        if ($kind === 'textarea') return $n->textContent;
        if ($kind === 'select') { $first = null; foreach ($n->getElementsByTagName('option') as $o) { $first ??= $o; if ($o->hasAttribute('selected')) return trim($o->textContent); } return $first ? trim($first->textContent) : ''; }
        return $kind === 'password' ? '' : $n->getAttribute('value');
    }

    /** Размер элемента формы (border-box), pt. @return array{0:float,1:float} */
    public function formSize(\DOMElement $n, array $cs, float $availW): array
    {
        $fs = (float)$cs['_fs']; $kind = self::formKind($n);
        $m = $this->boxMetrics($cs, $availW);
        $pad = $m['pl'] + $m['pr'] + $m['bl'] + $m['br']; $vpad = $m['pt'] + $m['pb'] + $m['bt'] + $m['bb'];
        $w = $this->len($cs['width'] ?? null, $availW, $cs); $h = $this->len($cs['height'] ?? null, 0, $cs);
        $bs = strtolower($cs['box-sizing'] ?? '') === 'border-box';
        if ($w !== null && !$bs) $w += $pad;
        if ($h !== null && !$bs) $h += $vpad;
        if ($kind === 'checkbox' || $kind === 'radio') return [$w ?? $fs * 0.95, $h ?? $fs * 0.95];
        $run = $this->run($cs, null);
        if ($kind === 'textarea') { $w ??= max(1, (int)($n->getAttribute('cols') ?: 20)) * $fs * 0.55 + $pad; $h ??= max(1, (int)($n->getAttribute('rows') ?: 2)) * $run['lh'] + $vpad; }
        elseif ($kind === 'button') $w ??= $this->textWidth($run['font'], self::formText($n, $kind), $fs) + $pad;
        elseif ($kind === 'select') { $mx = 0.0; foreach ($n->getElementsByTagName('option') as $o) $mx = max($mx, $this->textWidth($run['font'], trim($o->textContent), $fs)); $w ??= $mx + $fs * 1.6 + $pad; }
        else $w ??= ((int)$n->getAttribute('size') > 0 ? (int)$n->getAttribute('size') * $fs * 0.55 : $fs * 11) + $pad;
        $h ??= $run['lh'] + $vpad;
        return [max(4.0, min($w, $availW)), max(4.0, $h)];
    }

    /** Элемент формы: рамка/фон в содержимом страницы + интерактивное поле AcroForm (кнопки — только рисунок). */
    private function formAtomic(\DOMElement $n, array $cs, float $availW, array $run): array
    {
        $kind = self::formKind($n);
        [$w, $h] = $this->formSize($n, $cs, $availW);
        $m = $this->boxMetrics($cs, $availW);
        $ml = is_float($m['ml']) ? $m['ml'] : 0.0; $mr = is_float($m['mr']) ? $m['mr'] : 0.0;
        $ops = [];
        if (($kind === 'checkbox' || $kind === 'radio') && abs($w - $h) > 0.01) {
            // как в браузерах: контрол рисуется квадратом по центру своего бокса
            $side = min($w, $h);
            $ml += ($w - $side) / 2; $mr += ($w - $side) / 2;
            $w = $side;
        }
        $deco = $this->decoBox($cs, $ml, $w, $m);
        if ($deco) { if ($kind === 'radio') $deco['radius'] = $w / 2; $ops = Ops::box($deco, 0, $h, true, true); }
        $ix = $ml + $m['bl'] + $m['pl']; $iw = max(1, $w - $m['bl'] - $m['pl'] - $m['br'] - $m['pr']);
        $iy = $m['bt'] + $m['pt']; $ih = max(1, $h - $m['bt'] - $m['pt'] - $m['bb'] - $m['pb']);
        $text = self::formText($n, $kind);
        if ($kind === 'button') {
            $tw = $this->textWidth($run['font'], $text, $run['size']);
            foreach ($this->segmentsOps($run['font'], $run['fb'], $run['fi'], $text, $ix + ($iw - $tw) / 2, $iy + ($ih - $run['size']) / 2 + $run['size'] * 0.8, $run['size'], $run['color']) as $o) $ops[] = $o;
        } else {
            $opts = [];
            if ($kind === 'select') foreach ($n->getElementsByTagName('option') as $o) $opts[] = [$o->getAttribute('value') !== '' || $o->hasAttribute('value') ? $o->getAttribute('value') : trim($o->textContent), trim($o->textContent)];
            $name = $n->getAttribute('name') ?: ($n->getAttribute('id') ?: $kind);
            $ops[] = ['t' => 'field', 'kind' => $kind, 'x' => $kind === 'checkbox' || $kind === 'radio' ? $ml : $ix, 'y' => $kind === 'checkbox' || $kind === 'radio' ? 0.0 : $iy,
                'w' => $kind === 'checkbox' || $kind === 'radio' ? $w : $iw, 'h' => $kind === 'checkbox' || $kind === 'radio' ? $h : $ih,
                'name' => $name, 'value' => $kind === 'select' ? ($opts[0][0] ?? '') : $text, 'text' => $text, 'opts' => $opts,
                'export' => $n->getAttribute('value') ?: 'on', 'checked' => $n->hasAttribute('checked'),
                'readonly' => $n->hasAttribute('readonly') || $n->hasAttribute('disabled'), 'required' => $n->hasAttribute('required'),
                'maxlen' => (int)$n->getAttribute('maxlength'), 'tu' => $n->getAttribute('aria-label') ?: ($n->getAttribute('title') ?: ($n->getAttribute('placeholder') ?: $name)),
                'font' => $run['font'], 'size' => $run['size'], 'color' => $run['color']];
            if ($kind === 'select') foreach ($n->getElementsByTagName('option') as $k => $o) if ($o->hasAttribute('selected')) { $ops[count($ops) - 1]['value'] = $opts[$k][0]; break; }
        }
        $res = ['t' => 'a', 'w' => $w + $ml + $mr, 'h' => $h, 'ops' => $ops, 'run' => $run, 'va' => strtolower($cs['vertical-align'] ?? 'baseline')];
        // базовая линия контрола = базовая линия его текста (как в браузерах), чекбокс — низ
        if ($kind !== 'checkbox' && $kind !== 'radio' && $kind !== 'textarea') $res['base'] = min($h, $iy + $ih / 2 + $run['size'] * 0.35);
        return $res;
    }

    private function atomic(\DOMElement $n, array $cs, float $availW, ?string $href): array
    {
        $tag = strtolower($n->nodeName);
        $run = $this->run($cs, $href);
        if (isset(self::FORM[$tag])) return $this->formAtomic($n, $cs, $availW, $run);
        if ($tag === 'img' || $tag === 'svg') {
            // строчная картинка — такой же бокс, как блочная: поля, рамка и фон вокруг пикселей
            $m = $this->boxMetrics($cs, $availW);
            $ml = is_float($m['ml']) ? $m['ml'] : 0.0; $mr = is_float($m['mr']) ? $m['mr'] : 0.0;
            $hpad = $m['pl'] + $m['pr'] + $m['bl'] + $m['br']; $vpad = $m['pt'] + $m['pb'] + $m['bt'] + $m['bb'];
            [$w, $h, $ops] = $this->replaced($n, $cs, max(1.0, $availW - $hpad - $ml - $mr));
            if ($this->tags && ($tt = Tags::typeOf($n, $cs)) !== null) Tags::mark($ops, $this->tags->sidFor($n, $tt), true);
            $deco = $this->decoBox($cs, $ml, $w + $hpad, $m);
            $all = $deco ? Ops::box($deco, 0, $h + $vpad, true, true) : [];
            foreach (Ops::translate(self::clipImage($ops, $deco, $m), $ml + $m['bl'] + $m['pl'], $m['bt'] + $m['pt']) as $o) $all[] = $o;
            return ['t' => 'a', 'w' => $w + $hpad + $ml + $mr, 'h' => $h + $vpad, 'ops' => $all, 'run' => $run, 'va' => strtolower($cs['vertical-align'] ?? 'baseline')];
        }
        // inline-block: shrink-to-fit
        [$min, $max] = $this->minMax($n, $cs);
        $m = $this->boxMetrics($cs, $availW);
        $hp = (is_float($m['ml']) ? $m['ml'] : 0.0) + (is_float($m['mr']) ? $m['mr'] : 0.0);
        $wSpec = $this->len($cs['width'] ?? null, $availW, $cs);
        $outer = $wSpec !== null ? $wSpec + (strtolower($cs['box-sizing'] ?? '') === 'border-box' ? 0 : $m['pl'] + $m['pr'] + $m['bl'] + $m['br']) + $hp : min($availW, max($min, $max));
        $f = new Flow(); $yy = 0;
        $cs2 = $cs; $cs2['display'] = 'block'; $cs2['margin-top'] = $cs2['margin-bottom'] = '0';
        $this->layoutBlock($n, $cs2, 0, $outer, $f, $yy, 0, $outer);
        $h = max($yy, $f->height());
        return ['t' => 'a', 'w' => $outer, 'h' => $h, 'ops' => $f->flatten(), 'run' => $run, 'va' => strtolower($cs['vertical-align'] ?? 'baseline'), 'ib' => true];
    }

    // ---------- min/max-content ----------
    /** @return array{0:float,1:float} min-content и max-content ширина (border-box + margins). */
    public function minMax(\DOMNode $n, ?array $st = null): array
    {
        $id = spl_object_id($n);
        if ($this->breakAnywhere) {
            if (isset($this->mmB[$id])) return $this->mmB[$id][1];
            $r = $this->computeMinMax($n, $st);
            $this->mmB[$id] = [$n, $r];
            return $r;
        }
        if (isset($this->mm[$id])) return $this->mm[$id][1];
        $r = $this->computeMinMax($n, $st);
        $this->mm[$id] = [$n, $r];
        return $r;
    }

    /** Считать min-content так, будто любое слово можно рвать по символам (overflow-wrap: anywhere). */
    public bool $breakAnywhere = false;
    /** @var array<int, array{0: \DOMNode, 1: array{0: float, 1: float}}> */
    private array $mmB = [];

    /** @return array{0:float,1:float} min/max-content при переносе длинных слов по символам */
    public function minMaxBreakAll(\DOMNode $n, ?array $st = null): array
    {
        $save = $this->breakAnywhere; $this->breakAnywhere = true;
        try { return $this->minMax($n, $st); } finally { $this->breakAnywhere = $save; }
    }

    private static function breaksAnywhere(array $st): bool
    {
        return in_array(strtolower(trim($st['overflow-wrap'] ?? $st['word-wrap'] ?? '')), ['anywhere'], true) || strtolower(trim($st['word-break'] ?? '')) === 'break-all';
    }

    /** Самый широкий символ слова — его min-content при разрешённом разрыве внутри слова. */
    private function widestChar(array $it): float
    {
        $f = $it['run']['font']; $sz = $it['run']['size']; $k = empty($it['run']['nk']); $m = 0.0;
        foreach (mb_str_split($it['text']) as $ch) $m = max($m, $this->textWidth($f, $ch, $sz, $k));
        return $m + ($it['run']['ls'] ?? 0);
    }

    /** @return array{0:float,1:float} */
    private function computeMinMax(\DOMNode $n, ?array $st): array
    {
        if (!$n instanceof \DOMElement) return [0, 0];
        $st ??= $this->style($n);
        $tag = strtolower($n->nodeName);
        $m = $this->boxMetrics($st, 0);
        $extra = $m['pl'] + $m['pr'] + $m['bl'] + $m['br'] + (is_numeric($m['ml']) ? $m['ml'] : 0) + (is_numeric($m['mr']) ? $m['mr'] : 0);
        $wSpec = Length::toPt($st['width'] ?? null, 0, (float)$st['_fs']);
        if ($wSpec !== null && !str_contains((string)($st['width'] ?? ''), '%')) { $v = $wSpec + (strtolower($st['box-sizing'] ?? '') === 'border-box' ? (is_numeric($m['ml']) ? $m['ml'] : 0) + (is_numeric($m['mr']) ? $m['mr'] : 0) : $extra); return [$v, $v]; }
        if (in_array($tag, ['img', 'svg'], true)) { [$w] = $this->replaced($n, $st, 10000); return [min($w, 60) + $extra, $w + $extra]; }
        if (isset(self::FORM[$tag])) { [$w] = $this->formSize($n, $st, 10000); $v = $w + (is_numeric($m['ml']) ? $m['ml'] : 0) + (is_numeric($m['mr']) ? $m['mr'] : 0); return [$v, $v]; }
        if ($tag === 'chart' || $n->hasAttribute('data-chart')) return [80, 400];
        $d = $this->display($st);
        if ($tag === 'table' || $d === 'table') {
            $rows = $this->tableRows($n);
            $min = 0; $max = 0;
            foreach ($rows as $row) { $a = 0; $b = 0; foreach ($row['cells'] as $c) { [$cm, $cx] = $this->minMax($c); $a += $cm; $b += $cx; } $min = max($min, $a); $max = max($max, $b); }
            $sp = Length::toPt($st['border-spacing'] ?? '0', 0, (float)$st['_fs']) ?? 0;
            return [$min + $extra + $sp * 4, $max + $extra + $sp * 4];
        }
        $isRow = ($d === 'flex' || $d === 'inline-flex') && !str_starts_with(strtolower($st['flex-direction'] ?? 'row'), 'column');
        $gap = Length::toPt($st['column-gap'] ?? '0', 0, (float)$st['_fs']) ?? 0;
        $min = 0; $max = 0; $lineMax = 0; $cnt = 0;
        $items = []; $space = true;
        foreach ($n->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                $cs = $this->style($c); $cd = $this->display($cs);
                if ($cd === 'none') continue;
                if (!$isRow && in_array($cd, ['block', 'flex', 'grid', 'table', 'list-item'], true) || strtolower($c->nodeName) === 'br') {
                    [$a, $b] = $this->inlineMinMax($items); $min = max($min, $a); $max = max($max, $b); $items = []; $space = true;
                    if (strtolower($c->nodeName) === 'br') continue;
                    [$a, $b] = $this->minMax($c, $cs);
                    if ($isRow) { $min = max($min, $a); $lineMax += $b; $cnt++; }
                    else { $min = max($min, $a); $max = max($max, $b); }
                    continue;
                }
                if ($isRow) { [$a, $b] = $this->minMax($c, $cs); $min = max($min, $a); $lineMax += $b; $cnt++; continue; }
            }
            if ($isRow && $c instanceof \DOMText && trim($c->nodeValue ?? '') === '') continue;
            $this->gather($c, $st, $items, $space, null, 10000);
        }
        [$a, $b] = $this->inlineMinMax($items); $min = max($min, $a); $max = max($max, $b);
        if ($isRow) $max = max($max, $lineMax + max(0, $cnt - 1) * $gap);
        if ($d === 'grid') {
            $cols = count($this->gridTracks($st, 1000));
            $max = $max * max(1, $cols);
        }
        return [$min + $extra, $max + $extra];
    }

    private function inlineMinMax(array $items): array
    {
        $min = 0; $max = 0; $line = 0; $word = 0; $trail = 0; $lead = true;
        foreach ($items as $it) {
            if ($it['t'] === 'br') { $max = max($max, $line - $trail); $line = 0; $trail = 0; $lead = true; $min = max($min, $word); $word = 0; continue; }
            if ($it['t'] === 's' && $lead) continue; // пробелы в начале строки схлопываются
            $line += $it['w'];
            if ($it['t'] === 's') $trail += $it['w']; else { $trail = 0; $lead = false; }
            if ($it['t'] === 's' && empty($it['nobreak'])) { $min = max($min, $word); $word = 0; }
            // overflow-wrap:anywhere / word-break:break-all — любое слово рвётся по символам; авто-режим таблиц рвёт только длинные (URL, идентификаторы)
            elseif ($it['t'] === 'w' && empty($it['nobreak']) && (!empty($it['run']['ba']) || ($this->breakAnywhere && mb_strlen($it['text']) >= 13))) $word = max($word, $this->widestChar($it));
            elseif ($it['t'] !== 's') $word += $it['w'];
        }
        return [max($min, $word), max($max, $line - $trail)];
    }

    // ---------- таблицы ----------
    /** @return array<int,array{el:\DOMElement,cells:\DOMElement[],group:string}> */
    public function tableRows(\DOMElement $table): array
    {
        $rows = [];
        $collect = function (\DOMElement $parent, string $group) use (&$rows, &$collect) {
            foreach ($parent->childNodes as $c) {
                if (!$c instanceof \DOMElement) continue;
                $t = strtolower($c->nodeName);
                if ($t === 'tr') {
                    if ($this->display($this->style($c)) === 'none') continue;
                    $cells = [];
                    foreach ($c->childNodes as $td) if ($td instanceof \DOMElement && in_array(strtolower($td->nodeName), ['td', 'th'], true) && $this->display($this->style($td)) !== 'none') $cells[] = $td;
                    if ($cells) $rows[] = ['el' => $c, 'cells' => $cells, 'group' => $group];
                } elseif (in_array($t, ['thead', 'tbody', 'tfoot'], true)) $collect($c, $t);
            }
        };
        $collect($table, 'tbody');
        // thead первым, tfoot последним
        usort($rows, fn($a, $b) => (['thead' => 0, 'tbody' => 1, 'tfoot' => 2][$a['group']]) <=> (['thead' => 0, 'tbody' => 1, 'tfoot' => 2][$b['group']]));
        return $rows;
    }

    private function layoutTableBox(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth, array $m): void
    {
        (new TableLayout($this))->layout($el, $st, $x, $w, $flow, $y, $depth, $m);
    }

    // ---------- flex ----------
    private function layoutFlex(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth): void
    {
        (new FlexLayout($this))->layout($el, $st, $x, $w, $flow, $y, $depth);
    }

    private function layoutGrid(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth): void
    {
        $fl = new FlexLayout($this); (new GridLayout($this, $fl))->layout($el, $st, $x, $w, $flow, $y, $depth);
    }

    public function gridTracks(array $st, float $w): array
    {
        $v = trim($st['grid-template-columns'] ?? '');
        $gap = Length::toPt($st['column-gap'] ?? '0', $w, (float)$st['_fs']) ?? 0;
        if ($v === '' || $v === 'none') return [['fr', 1]];
        $v = preg_replace_callback('/repeat\(\s*(auto-fill|auto-fit|\d+)\s*,\s*((?:[^()]|\([^()]*\))+)\)/i', function ($m) use ($w, $gap, $st) {
            $n = $m[1];
            if (!ctype_digit($n)) {
                $min = 100;
                if (preg_match('/minmax\(\s*([^,]+),/i', $m[2], $mm)) $min = Length::toPt(trim($mm[1]), $w, (float)$st['_fs']) ?? 100;
                else $min = Length::toPt(trim($m[2]), $w, (float)$st['_fs']) ?? 100;
                $n = max(1, (int)floor(($w + $gap) / (max(1, $min) + $gap)));
            }
            return trim(str_repeat($m[2] . ' ', min(50, (int)$n)));
        }, $v) ?? $v;
        preg_match_all('/minmax\([^)]*\)|[^\s]+/', $v, $mm);
        $tracks = [];
        foreach ($mm[0] as $t) {
            $t = strtolower($t);
            if (str_starts_with($t, 'minmax(')) { $parts = explode(',', substr($t, 7, -1)); $t = trim($parts[1] ?? '1fr'); }
            if (str_ends_with($t, 'fr')) $tracks[] = ['fr', (float)$t];
            elseif ($t === 'auto' || $t === 'min-content' || $t === 'max-content') $tracks[] = ['fr', 1];
            else $tracks[] = ['len', Length::toPt($t, $w, (float)$st['_fs']) ?? 0];
        }
        return $tracks ?: [['fr', 1]];
    }

    // ---------- TOC ----------
    private function layoutToc(\DOMElement $el, array $st, float $x, float $w, Flow $flow, float &$y, int $depth): void
    {
        $title = $el->getAttribute('title') ?: 'Содержание';
        $levels = (int)($el->getAttribute('levels') ?: 3);
        $doc = $el->ownerDocument; if ($doc === null) return;
        $html = '<div class="bp-toc"><div class="bp-toc-title">' . htmlspecialchars($title) . '</div>';
        foreach ($this->tocSource as $i => $h) {
            if ($h['level'] > $levels) continue;
            $page = $this->tocPages[$h['key']] ?? '';
            $html .= '<a class="bp-toc-item bp-toc-l' . $h['level'] . '" href="#' . $h['key'] . '"><span class="bp-toc-text">' . htmlspecialchars($h['title']) . '</span><span class="bp-toc-dots"></span><span class="bp-toc-page">' . $page . '</span></a>';
        }
        $html .= '</div>';
        $frag = new \DOMDocument();
        @$frag->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        $src = $frag->getElementsByTagName('div')->item(0); if ($src === null) return;
        $node = $doc->importNode($src, true);
        $el->appendChild($node);
        $cs = $st; $cs['display'] = 'block';
        $this->layoutChildren($el, $st, $x, $w, $flow, $y, $depth + 1);
        $el->removeChild($node);
    }

    public array $tocSource = [];
    private array $absQueue = [];

    /** position:absolute/fixed относительно бокса родителя (упрощённо: ближайший блок). */
    private function layoutAbsolute(\DOMElement $el, array $st, float $px, float $py, float $pw, float $ph, Flow $flow, int $atom, int $depth): void
    {
        $pt = $el->parentNode instanceof \DOMElement ? strtolower($el->parentNode->nodeName) : '';
        if ($pt === 'body' || $pt === 'html') $ph = max($ph, $this->pageContentHeight); // начальный содержащий блок — страница
        $l = $this->len($st['left'] ?? null, $pw, $st); $r = $this->len($st['right'] ?? null, $pw, $st);
        $t = $this->len($st['top'] ?? null, $ph, $st);
        if ($t !== null && ($bb = $this->len($st['bottom'] ?? null, $ph, $st)) !== null && $this->len($st['height'] ?? null, $ph, $st) === null) {
            $st['height'] = max(0, $ph - $t - $bb) . 'pt'; $st['box-sizing'] = 'border-box';
            unset($st['bottom']);
        } $b = $this->len($st['bottom'] ?? null, $ph, $st);
        $ws = $this->len($st['width'] ?? null, $pw, $st);
        if ($ws !== null) $ow = $ws + (float)$this->boxMetrics($st, $pw)['pl'] + (float)$this->boxMetrics($st, $pw)['pr'];
        elseif ($l !== null && $r !== null) $ow = max(1, $pw - $l - $r);
        else { [, $mx] = $this->minMax($el, $st); $ow = min($pw, $mx + 1); }
        $sub = new Flow(); $sy = 0.0;
        $cs = $st; $cs['position'] = 'static'; $cs['margin-top'] = $cs['margin-top'] ?? '0';
        $this->layoutBlock($el, $cs, 0, $ow, $sub, $sy, $depth + 1, $ow);
        $h = $sub->height();
        $x = $l !== null ? $px + $l : ($r !== null ? $px + $pw - $r - $ow : $px);
        $y = $t !== null ? $py + $t : ($b !== null ? $py + $ph - $b - $h : $py);
        foreach ($sub->flatten($x, $y) as $o) $flow->atoms[$atom]['ops'][] = $o;
    }
}
