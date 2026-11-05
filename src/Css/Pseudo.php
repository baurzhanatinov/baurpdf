<?php
declare(strict_types=1);
namespace BaurPDF\Css;

use BaurPDF\Layout\Engine;

/**
 * ::before / ::after, content и CSS-счётчики (counter-reset / counter-increment / counter-set, counter(), counters()).
 * Перед вёрсткой обходит DOM в порядке документа и вставляет псевдоэлементы как <bp-pseudo> с объявлениями
 * в style — дальше они проходят обычный каскад (наследование от владельца) и вёрстку. Селекторы их не видят.
 */
final class Pseudo
{
    /** @var array<string, list<int>> стек значений счётчиков */
    private array $c = [];
    private int $quote = 0;
    private int $count = 0;

    public function __construct(private StyleResolver $r) {}

    public static function apply(\DOMElement $root, StyleResolver $r): void
    {
        if (!$r->hasPseudo()) return;
        $scope = [];
        (new self($r))->walk($root, $scope);
    }

    /**
     * @param array<string, true> $scope счётчики, созданные на этом уровне (область — до конца родителя)
     */
    private function walk(\DOMElement $el, array &$scope = []): void
    {
        if (++$this->count > 200000) return;
        $d = $this->r->counters ? $this->r->declarations($el) : [];
        if (strtolower(trim($d['display'] ?? '')) === 'none') return;
        $resets = self::pairs($d['counter-reset'] ?? '', 0);
        if (strtolower($el->nodeName) === 'ol' && !isset($d['counter-reset'])) $resets[] = ['list-item', ((int)($el->getAttribute('start') ?: 1)) - 1];
        foreach ($resets as [$n, $v]) {
            if (isset($scope[$n])) $this->set($n, $v); // соседний элемент уже создал счётчик — переиспользуем
            else { $this->c[$n][] = $v; $scope[$n] = true; }
        }
        foreach (self::pairs($d['counter-set'] ?? '', 0) as [$n, $v]) $this->set($n, $v);
        if (strtolower($el->nodeName) === 'li' && !isset($d['counter-increment'])) $this->inc('list-item', 1);
        foreach (self::pairs($d['counter-increment'] ?? '', 1) as [$n, $v]) $this->inc($n, $v);

        $before = $this->r->declarations($el, 'before');
        $kids = []; foreach ($el->childNodes as $k) if ($k instanceof \DOMElement) $kids[] = $k;
        $tag = strtolower($el->nodeName);
        $replaced = in_array($tag, ['img', 'svg', 'input', 'select', 'textarea', 'br', 'hr', 'chart', 'toc', 'barcode', 'qrcode'], true);
        if (!$replaced && $before) $this->insert($el, $before, true);
        $inner = [];
        foreach ($kids as $k) $this->walk($k, $inner);
        foreach (array_keys($inner) as $n) array_pop($this->c[$n]);
        $after = $replaced ? [] : $this->r->declarations($el, 'after');
        if ($after) $this->insert($el, $after, false);
    }

    private function inc(string $n, int $v): void
    {
        if (empty($this->c[$n])) $this->c[$n] = [0];
        $this->c[$n][count($this->c[$n]) - 1] += $v;
    }

    private function set(string $n, int $v): void
    {
        if (empty($this->c[$n])) $this->c[$n] = [0];
        $this->c[$n][count($this->c[$n]) - 1] = $v;
    }

    /** @return list<array{0:string,1:int}> */
    private static function pairs(string $v, int $def): array
    {
        $v = trim($v);
        if ($v === '' || strtolower($v) === 'none') return [];
        preg_match_all('/([A-Za-z_][\w-]*)(?:\s+(-?\d+))?/', $v, $m, PREG_SET_ORDER);
        return array_map(fn($x) => [$x[1], isset($x[2]) ? (int)$x[2] : $def], $m);
    }

    /** @param array<string,string> $d */
    private function insert(\DOMElement $el, array $d, bool $first): void
    {
        $content = trim($d['content'] ?? '');
        if ($content === '' || in_array(strtolower($content), ['none', 'normal'], true)) return;
        foreach (self::pairs($d['counter-reset'] ?? '', 0) as [$n, $v]) $this->c[$n][] = $v; // упрощённо: без отдельной области
        foreach (self::pairs($d['counter-increment'] ?? '', 1) as [$n, $v]) $this->inc($n, $v);
        $text = $this->content($el, $content);
        $doc = $el->ownerDocument;
        if (!$doc) return;
        $p = $doc->createElement('bp-pseudo');
        $style = '';
        foreach ($d as $k => $v) if (!in_array($k, ['content', 'counter-reset', 'counter-increment', 'counter-set'], true)) $style .= $k . ':' . $v . ';';
        if ($style !== '') $p->setAttribute('style', $style);
        $p->setAttribute('data-pseudo', $first ? 'before' : 'after');
        if (preg_match('/url\(\s*[\'"]?([^\'")]+)/i', $content, $u)) { $img = $doc->createElement('img'); $img->setAttribute('src', $u[1]); $img->setAttribute('alt', ''); $p->appendChild($img); }
        if ($text !== '') $p->appendChild($doc->createTextNode($text));
        if ($first) $el->insertBefore($p, $el->firstChild); else $el->appendChild($p);
    }

    private function content(\DOMElement $el, string $v): string
    {
        $out = '';
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|(counters?)\(([^)]*)\)|attr\(\s*([\w-]+)\s*\)|(open-quote|close-quote|no-open-quote|no-close-quote)/i', $v, $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            if (($t[1] ?? '') !== '' || (isset($t[1]) && $t[0][0] === '"')) $out .= self::unescape($t[1]);
            elseif (($t[2] ?? '') !== '' || (isset($t[2]) && $t[0][0] === "'")) $out .= self::unescape($t[2]);
            elseif (($t[3] ?? '') !== '') {
                $args = array_map('trim', preg_split('/,(?=(?:[^"\']*["\'][^"\']*["\'])*[^"\']*$)/', $t[4] ?? '') ?: []);
                $name = $args[0] ?? ''; $vals = $this->c[$name] ?? [0];
                if (strtolower($t[3]) === 'counter') $out .= self::fmt((int)end($vals), $args[1] ?? 'decimal');
                else { $sep = self::unescape(trim($args[1] ?? '"."', '"\'')); $out .= implode($sep, array_map(fn($x) => self::fmt($x, $args[2] ?? 'decimal'), $vals)); }
            }
            elseif (($t[5] ?? '') !== '') $out .= $el->getAttribute($t[5]);
            elseif (($t[6] ?? '') !== '') {
                $q = strtolower($t[6]);
                if ($q === 'open-quote') { $out .= $this->quote % 2 ? '„' : '«'; $this->quote++; }
                elseif ($q === 'close-quote') { $this->quote = max(0, $this->quote - 1); $out .= $this->quote % 2 ? '“' : '»'; }
                elseif ($q === 'no-open-quote') $this->quote++;
                else $this->quote = max(0, $this->quote - 1);
            }
        }
        return $out;
    }

    private static function unescape(string $s): string
    {
        return (string)preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)/su', fn(array $m): string => $m[1] !== '' ? (string)mb_chr((int)hexdec($m[1]) ?: 0xFFFD) : ($m[2] === "\n" ? '' : $m[2]), $s);
    }

    public static function fmt(int $n, string $style): string
    {
        $style = strtolower(trim($style));
        return match ($style) {
            'none' => '',
            'decimal-leading-zero' => sprintf('%02d', $n),
            'lower-alpha', 'lower-latin' => $n > 0 ? Engine::alpha($n) : (string)$n,
            'upper-alpha', 'upper-latin' => $n > 0 ? strtoupper(Engine::alpha($n)) : (string)$n,
            'lower-roman' => $n > 0 && $n < 4000 ? strtolower(Engine::roman($n)) : (string)$n,
            'upper-roman' => $n > 0 && $n < 4000 ? Engine::roman($n) : (string)$n,
            'cyrillic', 'lower-cyrillic' => $n > 0 ? mb_substr('абвгдежзиклмнопрстуфхцчшщэюя', ($n - 1) % 28, 1) : (string)$n,
            'disc' => '•', 'circle' => '◦', 'square' => '▪',
            default => (string)$n,
        };
    }
}
