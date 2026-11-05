<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

/**
 * Логическая структура для Tagged PDF / PDF/UA: дерево структурных элементов (Document, H1…H6, P, L/LI/LBody,
 * Table/THead/TBody/TFoot/TR/TH/TD, Figure, Caption, BlockQuote, Div, Sect). Операции рисования получают 'sid';
 * всё без sid (фоны, рамки, колонтитулы, повторы шапок таблиц) пишется как Artifact.
 */
final class Tags
{
    /** @var list<array{type:string,parent:?int,alt:?string,attrs:array<string,string>,lang:?string}> */
    public array $elems = [['type' => 'Document', 'parent' => null, 'alt' => null, 'attrs' => [], 'lang' => null]];
    /** @var array<int, array{0:\DOMElement,1:int}> */
    private array $memo = [];
    /** @var list<int> */
    public array $stack = [0];

    private const MAP = ['p' => 'P', 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6',
        'ul' => 'L', 'ol' => 'L', 'dl' => 'L', 'li' => 'LI', 'dt' => 'Lbl', 'dd' => 'LBody', 'table' => 'Table', 'thead' => 'THead', 'tbody' => 'TBody', 'tfoot' => 'TFoot',
        'tr' => 'TR', 'th' => 'TH', 'td' => 'TD', 'caption' => 'Caption', 'figcaption' => 'Caption', 'blockquote' => 'BlockQuote', 'pre' => 'Code',
        'section' => 'Sect', 'article' => 'Art', 'aside' => 'Aside', 'nav' => 'TOC', 'header' => 'Div', 'footer' => 'Div', 'main' => 'Div', 'figure' => 'Div',
        'div' => 'Div', 'address' => 'P', 'img' => 'Figure', 'svg' => 'Figure', 'chart' => 'Figure', 'toc' => 'TOC', 'form' => 'Form'];

    /** Тип элемента: role (ARIA-подобно) → таблица соответствий; null — элемент не создаёт узла структуры. */
    public static function typeOf(\DOMElement $el, array $st): ?string
    {
        $role = strtolower($el->getAttribute('role'));
        if ($role === 'presentation' || $role === 'none' || $el->getAttribute('aria-hidden') === 'true') return null;
        if ($role === 'heading') return 'H' . max(1, min(6, (int)($el->getAttribute('aria-level') ?: 2)));
        if ($el->hasAttribute('data-chart') || isset($st['--chart'])) return 'Figure';
        return self::MAP[strtolower($el->nodeName)] ?? null;
    }

    public function sidFor(\DOMElement $el, string $type, ?int $parent = null): int
    {
        $id = spl_object_id($el);
        if (isset($this->memo[$id]) && $this->memo[$id][0] === $el) return $this->memo[$id][1];
        $tag = strtolower($el->nodeName);
        $alt = $el->getAttribute('alt') ?: $el->getAttribute('aria-label') ?: $el->getAttribute('title') ?: null;
        if ($type === 'Figure' && $alt === null) $alt = $tag === 'img' ? '' : 'Иллюстрация';
        $attrs = [];
        if ($type === 'TH') {
            $sc = strtolower($el->getAttribute('scope'));
            $inHead = $el->parentNode && $el->parentNode->parentNode && strtolower($el->parentNode->parentNode->nodeName) === 'thead';
            $attrs['Scope'] = $sc === 'row' ? 'Row' : ($sc === 'col' || $sc === 'column' || $inHead ? 'Column' : 'Both');
        }
        if (($type === 'TH' || $type === 'TD')) {
            if ((int)$el->getAttribute('colspan') > 1) $attrs['ColSpan'] = (string)(int)$el->getAttribute('colspan');
            if ((int)$el->getAttribute('rowspan') > 1) $attrs['RowSpan'] = (string)(int)$el->getAttribute('rowspan');
        }
        $this->elems[] = ['type' => $type, 'parent' => $parent ?? ($this->stack ? end($this->stack) : null), 'alt' => $alt, 'attrs' => $attrs, 'lang' => $el->getAttribute('lang') ?: null];
        $sid = count($this->elems) - 1;
        $this->memo[$id] = [$el, $sid];
        return $sid;
    }

    /** Новый безымянный узел (LBody внутри LI, Link). */
    public function child(int $parent, string $type, ?string $alt = null): int
    {
        $this->elems[] = ['type' => $type, 'parent' => $parent, 'alt' => $alt, 'attrs' => [], 'lang' => null];
        return count($this->elems) - 1;
    }

    /**
     * Проставить sid содержательным операциям без sid. $all — для Figure: и векторной графике тоже.
     * @param array<int, array> $ops
     */
    public static function mark(array &$ops, int $sid, bool $all = false): void
    {
        foreach ($ops as &$o) {
            $t = $o['t'] ?? '';
            if ($t === 'group') { if (empty($o['artifact'])) self::mark($o['ops'], $sid, $all); continue; }
            if (isset($o['sid'])) continue;
            if ($t === 'text' || $t === 'image' || $t === 'link' || $t === 'field' || ($all && in_array($t, ['rect', 'line', 'path', 'grad', 'shadow'], true))) $o['sid'] = $sid;
        }
        unset($o);
    }

    public function markFlow(Flow $flow, int $from, int $sid, bool $all = false): void
    {
        for ($i = $from, $n = count($flow->atoms); $i < $n; $i++) if ($flow->atoms[$i]['ops']) self::mark($flow->atoms[$i]['ops'], $sid, $all);
    }
}
