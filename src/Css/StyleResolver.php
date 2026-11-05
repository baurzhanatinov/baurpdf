<?php
declare(strict_types=1);
namespace BaurPDF\Css;


/** Каскад: UA-стили → авторские (по специфичности и порядку) → inline style → !important; наследование. */
final class StyleResolver
{
    public const INHERITED = ['color' => 1, 'font-family' => 1, 'font-size' => 1, 'font-weight' => 1, 'font-style' => 1, 'line-height' => 1, 'widows' => 1, 'orphans' => 1, 'text-align' => 1,
        'white-space' => 1, 'letter-spacing' => 1, 'font-kerning' => 1, 'word-spacing' => 1, 'text-transform' => 1, 'list-style-type' => 1, 'visibility' => 1, 'border-collapse' => 1,
        'text-indent' => 1, 'border-spacing' => 1, 'word-wrap' => 1, 'overflow-wrap' => 1, 'word-break' => 1, 'hyphens' => 1, 'hyphenate-limit-chars' => 1, 'direction' => 1, 'font-variant' => 1, 'caption-side' => 1, '-bp-lang' => 1];

    public const UA = <<<'CSS'
html, body, div, section, article, header, footer, main, nav, aside, address, figure, figcaption, form, fieldset, details, summary, blockquote, center, dl, dd, dt, hgroup, legend, ul, ol, li, p, pre, h1, h2, h3, h4, h5, h6, hr, table, toc, chart, pagebreak, .slide { display: block }
head, script, style, title, meta, link, noscript, template, base, iframe, object, embed, [hidden], input[type=hidden], datalist, option, optgroup { display: none }
fieldset { margin: 0 2px; padding: .35em .75em .625em; border: 2px groove #c0c0c0 }
legend { padding: 0 2px }
input, select, textarea, button { color: #000; display: inline-block; border: 1px solid #767676; padding: 1px 2px; background-color: #fff; font-size: 13.3px; vertical-align: baseline }
button, input[type=submit], input[type=button], input[type=reset] { background-color: #efefef; padding: 1px 6px; border-radius: 2px }
input[type=checkbox], input[type=radio] { padding: 0; margin: 3px 3px 0 4px }
input[type=radio] { border-radius: 50% }
li { display: list-item }
table { display: table; border-collapse: separate; border-spacing: 2px }
tr { display: table-row } td, th { display: table-cell; padding: 1px; vertical-align: middle }
thead { display: table-header-group } tbody { display: table-row-group } tfoot { display: table-footer-group }
caption { display: table-caption; text-align: center } col, colgroup { display: none }
th { font-weight: bold; text-align: center }
body { margin: 8px; font-family: sans-serif; font-size: 16px; color: #000 }
p, blockquote, dl, figure, ul, ol, pre { margin-top: 1em; margin-bottom: 1em }
blockquote, figure { margin-left: 40px; margin-right: 40px }
ul, ol { padding-left: 40px } [dir=rtl] ul, [dir=rtl] ol, ul[dir=rtl], ol[dir=rtl] { padding-left: 0; padding-right: 40px } [dir=ltr] ul, [dir=ltr] ol, ul[dir=ltr], ol[dir=ltr] { padding-left: 40px; padding-right: 0 } ul { list-style-type: disc } ol { list-style-type: decimal }
ul ul { list-style-type: circle; margin-top: 0; margin-bottom: 0 } ol ol, ol ul, ul ol { margin-top: 0; margin-bottom: 0 }
dd { margin-left: 40px }
h1 { font-size: 2em; margin: .67em 0; font-weight: bold } h2 { font-size: 1.5em; margin: .83em 0; font-weight: bold }
h3 { font-size: 1.17em; margin: 1em 0; font-weight: bold } h4 { margin: 1.33em 0; font-weight: bold }
h5 { font-size: .83em; margin: 1.67em 0; font-weight: bold } h6 { font-size: .67em; margin: 2.33em 0; font-weight: bold }
b, strong { font-weight: bold } i, em, cite, var, dfn { font-style: italic } u, ins { text-decoration: underline } s, del, strike { text-decoration: line-through }
small { font-size: .83em } big { font-size: 1.17em } sub { font-size: .75em; vertical-align: sub } sup { font-size: .75em; vertical-align: super }
code, kbd, samp, tt, pre { font-family: monospace } pre { white-space: pre }
a { color: #0645ad; text-decoration: underline } mark { background-color: #ff0 }
hr { border-top: 1px solid #aaa; margin: .5em 0 } center { text-align: center }
pagebreak { break-before: page }
img, svg { display: inline-block }
bp-pseudo { display: inline }
CSS;

    private array $index = [];
    /** @var array<string, array<string, list<array{0: Selector, 1: array, 2: int}>>> правила ::before / ::after */
    private array $pindex = [];
    /** В таблицах стилей есть counter-reset/increment/set. */
    public bool $counters = false;

    public function hasPseudo(): bool { return $this->pindex !== [] || $this->counters; }

    /**
     * Итоговые объявления (без наследования) для элемента или его псевдоэлемента: свойство → значение.
     * @return array<string, string>
     */
    public function declarations(\DOMElement $el, ?string $pseudo = null): array
    {
        $matched = $pseudo === null ? $this->match($el) : $this->match($el, $this->pindex[$pseudo] ?? []);
        $normal = []; $imp = [];
        foreach ($matched as [, $decls]) foreach ($decls as $p => [$v, $i]) { if ($i) $imp[$p] = $v; else $normal[$p] = $v; }
        if ($pseudo === null && $el->hasAttribute('style')) foreach (CssParser::declarations($el->getAttribute('style')) as $p => [$v, $i]) { if ($i) $imp[$p] = $v; else $normal[$p] = $v; }
        return array_merge($normal, $imp);
    }
    private int $order = 0;

    private const AUTHOR = 1000000000000000;
    /** Тема по умолчанию: выше UA, но ниже любого авторского правила независимо от специфичности. */
    private const THEME = 500000000000000;

    public function __construct(array $sheets, ?array $theme = null)
    {
        if ($theme !== null) $this->addSheet($theme, self::THEME);
        foreach ($sheets as $si => $sheet) {
            $ua = $si === 0 && count($sheets) > 1; // первый лист — UA: ниже любых авторских правил (CSS Cascade §6.1)
            $this->addSheet($sheet, $ua ? 0 : self::AUTHOR);
        }
    }

    private function addSheet(array $sheet, int $level): void
    {
        foreach ($sheet['rules'] as $r) {
            $k = $r['sel']->rightKey() ?? '*';
            if ($r['sel']->pseudoEl !== null && in_array(strtolower(trim($r['decls']['content'][0] ?? 'x')), ['none', 'normal'], true) && count($r['decls']) === 1) continue;
            if ($r['sel']->pseudoEl !== null) { $this->pindex[$r['sel']->pseudoEl][$k][] = [$r['sel'], $r['decls'], $this->order++]; continue; }
            $this->index[$k][] = [$r['sel'], $r['decls'], $this->order++ + $level];
            if (!$this->counters) foreach ($r['decls'] as $p => $_) if (str_starts_with($p, 'counter-')) $this->counters = true;
        }
    }

    /** @var array<string, array{0: array, 1: string}> мемо: (родитель, правила, атрибуты) → стиль */
    private array $memo = [];

    /**
     * Вычисление с мемоизацией: элементы с теми же совпавшими правилами, атрибутами и тем же родительским стилем
     * (типичные строки/ячейки таблиц) получают готовый общий массив без повторного каскада.
     * @return array{0: array, 1: string} [стиль, ключ стиля]
     */
    public function computeKeyed(\DOMElement $el, ?array $parent, string $parentKey): array
    {
        $matched = $this->match($el);
        $ids = ''; foreach ($matched as $r) $ids .= $r[2] . ',';
        $k = $parentKey . '|' . $ids . '|' . serialize(self::presAttrs($el)) . '|' . $el->getAttribute('style') . '|' . $el->getAttribute('lang') . '|' . ($el->hasAttribute('lang') ? 1 : 0);
        $k = md5($k, true);
        if (isset($this->memo[$k])) return $this->memo[$k];
        $st = $this->cascade($el, $parent, $matched);
        if (count($this->memo) > 50000) $this->memo = [];
        return $this->memo[$k] = [$st, $k];
    }

    public function compute(\DOMElement $el, ?array $parent): array
    {
        return $this->cascade($el, $parent, $this->match($el));
    }

    /** @var \WeakMap<object, mixed>|null */
    private ?\WeakMap $anc = null;

    /** Множество тегов/классов/id всех предков (кэш на узел через WeakMap). @return array<string, int> */
    private function ancestors(\DOMElement $el): array
    {
        $p = $el->parentNode;
        if (!$p instanceof \DOMElement) return [];
        if ($this->anc === null) $this->anc = new \WeakMap();
        $map = $this->anc;
        if (isset($map[$p])) { $v = $map[$p]; return is_array($v) ? $v : []; }
        $set = $this->ancestors($p);
        $set[strtolower($p->nodeName)] = 1;
        if (($id = $p->getAttribute('id')) !== '') $set['#' . $id] = 1;
        if (($cls = $p->getAttribute('class')) !== '') foreach (Selector::classSet($cls) as $c => $_) $set['.' . $c] = 1;
        $map[$p] = $set;
        return $set;
    }

    /** @return list<array{0: Selector, 1: array, 2: int}> */
    private function match(\DOMElement $el, ?array $index = null): array
    {
        $index ??= $this->index;
        if (!$index) return [];
        $byKey = [];
        $keys = ['*', strtolower($el->nodeName)];
        if (($id = $el->getAttribute('id')) !== '') $keys[] = '#' . $id;
        $cls = $el->getAttribute('class');
        if ($cls !== '') foreach (Selector::classes($cls) as $c) $keys[] = '.' . $c;
        foreach ($keys as $k) {
            if (!isset($index[$k])) continue;
            foreach ($index[$k] as $r) {
                if ($r[0]->need) {
                    $anc ??= $this->ancestors($el);
                    foreach ($r[0]->need as $nk) if (!isset($anc[$nk])) continue 2;
                }
                // ключ сортировки: специфичность, затем порядок (одно целое — без сравнения массивов)
                $sk = ($r[2] >= self::AUTHOR ? self::AUTHOR : 0) + $r[0]->specificity * 1000000 + $r[2] % self::AUTHOR;
                if (isset($byKey[$sk])) continue;
                if ($r[0]->matches($el)) $byKey[$sk] = $r;
            }
        }
        if (count($byKey) > 1) ksort($byKey);
        unset($anc);
        return array_values($byKey);
    }

    private function cascade(\DOMElement $el, ?array $parent, array $matched): array
    {
        $normal = []; $imp = [];
        $pres = self::presAttrs($el); $presDone = !$pres;
        foreach ($matched as $mr) {
            // presentational hints: выше UA, ниже авторских правил
            if (!$presDone && isset($mr[2]) && $mr[2] >= self::AUTHOR) { foreach ($pres as $p => $v) $normal[$p] = $v; $presDone = true; }
            foreach ($mr[1] as $p => [$v, $i]) { if ($i) $imp[$p] = $v; else $normal[$p] = $v; }
        }
        if (!$presDone) foreach ($pres as $p => $v) $normal[$p] = $v;
        if ($el->hasAttribute('style')) foreach (CssParser::declarations($el->getAttribute('style')) as $p => [$v, $i]) { if ($i) $imp[$p] = $v; else $normal[$p] = $v; }
        if ($el->hasAttribute('lang')) $normal['-bp-lang'] = $el->getAttribute('lang');
        $spec = array_merge($normal, $imp);

        $st = [];
        if ($parent) foreach ($parent as $p => $v) if (isset(self::INHERITED[$p])) $st[$p] = $v;
        foreach ($spec as $p => $v) {
            $lv = strtolower($v);
            if ($lv === 'inherit') { if (isset($parent[$p])) $st[$p] = $parent[$p]; continue; }
            if ($lv === 'initial' || $lv === 'unset') { unset($st[$p]); continue; }
            if (str_contains($lv, 'var(')) {
                $v = self::resolveVars($v, $spec, $parent);
                foreach (Shorthand::expand($p, $v) as $k => $ev) $st[$k] = $ev;
                continue;
            }
            $st[$p] = $v;
        }
        // CSS custom properties наследуются
        if ($parent) foreach ($parent as $p => $v) if (str_starts_with($p, '--') && !isset($st[$p])) $st[$p] = $v;
        foreach ($st as $p => $v) if (is_string($v) && str_contains($v, 'var(')) $st[$p] = self::resolveVars($v, $st, $parent);
        // вычисляем font-size в pt
        $pfs = (float)($parent['_fs'] ?? 12.0);
        $st['_fs'] = isset($spec['font-size']) ? self::fontSize($spec['font-size'], $pfs, $parent === null) : $pfs;
        if (!isset($st['display'])) $st['display'] = 'inline';
        return $st;
    }

    private static function resolveVars(string $v, array $spec, ?array $parent): string
    {
        for ($i = 0; $i < 5 && str_contains($v, 'var('); $i++) {
            $v = preg_replace_callback('/var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*(?:\([^()]*\))?[^()]*))?\)/', function ($m) use ($spec, $parent) {
                return $spec[$m[1]] ?? $parent[$m[1]] ?? trim($m[2] ?? '');
            }, $v) ?? $v;
        }
        return $v;
    }

    public static function fontSize(string $v, float $parent, bool $root): float
    {
        $v = strtolower(trim($v));
        $kw = ['xx-small' => 7, 'x-small' => 7.5, 'small' => 10, 'medium' => 12, 'large' => 13.5, 'x-large' => 18, 'xx-large' => 24, 'xxx-large' => 36];
        if (isset($kw[$v])) return $kw[$v];
        if ($v === 'smaller') return $parent / 1.2;
        if ($v === 'larger') return $parent * 1.2;
        $r = Length::toPt($v, $parent, $parent, $root ? 12 : null);
        return $r !== null && $r > 0 ? min($r, 2000) : $parent;
    }

    private static function presAttrs(\DOMElement $el): array
    {
        $r = [];
        $tag = strtolower($el->nodeName);
        if ($el->hasAttribute('width') && $tag !== 'svg') { $w = $el->getAttribute('width'); $r['width'] = is_numeric($w) ? $w . 'px' : $w; }
        if ($el->hasAttribute('height') && !in_array($tag, ['svg', 'td', 'th', 'tr'], true)) { $h = $el->getAttribute('height'); $r['height'] = is_numeric($h) ? $h . 'px' : $h; }
        if ($el->hasAttribute('align')) { $a = strtolower($el->getAttribute('align')); if ($tag === 'table' || $tag === 'img') { if ($a === 'center') { $r['margin-left'] = 'auto'; $r['margin-right'] = 'auto'; } } else $r['text-align'] = $a; }
        if ($el->hasAttribute('valign')) $r['vertical-align'] = $el->getAttribute('valign');
        if ($el->hasAttribute('bgcolor')) $r['background-color'] = $el->getAttribute('bgcolor');
        if ($el->hasAttribute('dir')) {
            $d = strtolower(trim($el->getAttribute('dir')));
            if ($d === 'auto') { $t = $el->textContent; $d = preg_match('/^[^\p{L}]*[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', (string)$t) ? 'rtl' : 'ltr'; }
            if ($d === 'rtl' || $d === 'ltr') $r['direction'] = $d;
        }
        if (in_array($tag, ['ol', 'ul', 'li'], true) && $el->hasAttribute('type')) {
            $t = trim($el->getAttribute('type'));
            $map = ['1' => 'decimal', 'a' => 'lower-alpha', 'A' => 'upper-alpha', 'i' => 'lower-roman', 'I' => 'upper-roman'];
            $lt = $map[$t] ?? (in_array(strtolower($t), ['disc', 'circle', 'square', 'none'], true) ? strtolower($t) : null);
            if ($lt !== null) $r['list-style-type'] = $lt;
        }
        if ($tag === 'font') { if ($el->hasAttribute('color')) $r['color'] = $el->getAttribute('color'); if ($el->hasAttribute('face')) $r['font-family'] = $el->getAttribute('face'); }
        if ($tag === 'table') {
            if ($el->hasAttribute('border') && (int)$el->getAttribute('border') > 0) { $b = (int)$el->getAttribute('border'); foreach (['top', 'right', 'bottom', 'left'] as $s) { $r["border-$s-width"] = $b . 'px'; $r["border-$s-style"] = 'solid'; $r["border-$s-color"] = '#888'; } }
            if ($el->hasAttribute('cellspacing')) $r['border-spacing'] = (int)$el->getAttribute('cellspacing') . 'px';
        }
        if (($tag === 'td' || $tag === 'th')) {
            for ($t = $el->parentNode; $t && strtolower($t->nodeName) !== 'table'; $t = $t->parentNode);
            if ($t instanceof \DOMElement) {
                if ($t->hasAttribute('cellpadding')) foreach (['top', 'right', 'bottom', 'left'] as $s) $r["padding-$s"] = (int)$t->getAttribute('cellpadding') . 'px';
                if ($t->hasAttribute('border') && (int)$t->getAttribute('border') > 0) foreach (['top', 'right', 'bottom', 'left'] as $s) { $r["border-$s-width"] = '1px'; $r["border-$s-style"] = 'solid'; $r["border-$s-color"] = '#888'; }
            }
            if ($el->hasAttribute('nowrap')) $r['white-space'] = 'nowrap';
        }
        return $r;
    }
}
