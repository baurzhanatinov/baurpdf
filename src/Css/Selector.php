<?php
declare(strict_types=1);
namespace BaurPDF\Css;

/**
 * CSS-селекторы: type, .class, #id, *, [attr], [attr=v], [attr~=v], [attr^=v], [attr$=v], [attr*=v],
 * :first-child, :last-child, :nth-child(an+b|odd|even), :nth-of-type, :not(simple), :root, :empty;
 * комбинаторы: потомок, >, +, ~. Неподдерживаемое → селектор просто не совпадает (без ошибок).
 */
final class Selector
{
    /** @var array<int,array{comb:string,parts:array}> справа налево */
    public array $chain = [];
    public int $specificity = 0;
    public bool $valid = true;
    public ?string $key = null;
    /** @var list<string> теги/.классы/#id, которые должны быть у предков */
    public array $need = [];
    /** псевдоэлемент: 'before' | 'after' | null */
    public ?string $pseudoEl = null; // для индекса: "#id", ".class", "tag" или null

    public function __construct(public string $text)
    {
        try { $this->parse(trim($text)); } catch (\Throwable) { $this->valid = false; }
    }

    private function parse(string $s): void
    {
        $tokens = []; $i = 0; $n = strlen($s); $cur = ''; $comb = ' ';
        $compounds = [];
        while ($i < $n) {
            $c = $s[$i];
            if ($c === '[') { $j = strpos($s, ']', $i); if ($j === false) throw new \Exception(); $cur .= substr($s, $i, $j - $i + 1); $i = $j + 1; continue; }
            if ($c === '(') { $d = 1; $j = $i + 1; while ($j < $n && $d) { if ($s[$j] === '(') $d++; elseif ($s[$j] === ')') $d--; $j++; } $cur .= substr($s, $i, $j - $i); $i = $j; continue; }
            if ($c === '>' || $c === '+' || $c === '~' || ctype_space($c)) {
                if ($cur !== '') { $compounds[] = [$comb, $cur]; $cur = ''; $comb = ' '; }
                if (!ctype_space($c)) $comb = $c;
                $i++; continue;
            }
            $cur .= $c; $i++;
        }
        if ($cur !== '') $compounds[] = [$comb, $cur];
        if (!$compounds) throw new \Exception();
        // chain справа налево: каждая запись хранит комбинатор, связывающий её с левым соседом
        $chain = [];
        for ($k = count($compounds) - 1; $k >= 0; $k--) {
            $chain[] = ['comb' => $k > 0 ? $compounds[$k][0] : '', 'parts' => $this->compound($compounds[$k][1])];
        }
        // комбинатор у compound[k] относится к связи между k-1 и k
        $this->chain = $chain;
        // ключи, обязательные среди предков (для быстрого отсева без обхода дерева)
        foreach ($chain as $idx => $c) {
            if ($idx === count($chain) - 1 || ($c['comb'] !== ' ' && $c['comb'] !== '>')) continue;
            foreach ($chain[$idx + 1]['parts'] as $p) {
                if ($p[0] === 'tag') $this->need[] = $p[1];
                elseif ($p[0] === 'class') $this->need[] = '.' . $p[1];
                elseif ($p[0] === 'id') $this->need[] = '#' . $p[1];
            }
        }
        $this->need = array_values(array_unique($this->need));
    }

    private function compound(string $c): array
    {
        $parts = [];
        if (!preg_match_all('/(\*|[a-zA-Z][\w-]*)|#([\w-]+)|\.([\w-]+)|\[\s*([\w-]+)\s*(?:([~^$*|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]*)))?\s*\]|::?([\w-]+)(?:\(([^)]*)\))?/u', $c, $m, PREG_SET_ORDER)) throw new \Exception();
        $consumed = '';
        foreach ($m as $t) {
            $consumed .= $t[0];
            if (($t[1] ?? '') !== '') { if ($t[1] !== '*') { $parts[] = ['tag', strtolower($t[1])]; $this->specificity += 1; $this->key ??= strtolower($t[1]); } }
            elseif (($t[2] ?? '') !== '') { $parts[] = ['id', $t[2]]; $this->specificity += 10000; $this->key = '#' . $t[2]; }
            elseif (($t[3] ?? '') !== '') { $parts[] = ['class', $t[3]]; $this->specificity += 100; if (!$this->key || !str_starts_with($this->key, '#')) $this->key = '.' . $t[3]; }
            elseif (($t[4] ?? '') !== '') { $val = ($t[6] ?? '') !== '' ? $t[6] : (($t[7] ?? '') !== '' ? $t[7] : ($t[8] ?? '')); $parts[] = ['attr', strtolower($t[4]), $t[5] ?? '', $val]; $this->specificity += 100; }
            elseif (($t[9] ?? '') !== '') {
                $ps = strtolower($t[9]); $arg = $t[10] ?? '';
                if (($ps === 'before' || $ps === 'after') && $this->chain === [] && $this->pseudoEl === null) { $this->pseudoEl = $ps; $this->specificity += 1; continue; }
                if (in_array($ps, ['before', 'after', 'hover', 'focus', 'active', 'visited', 'first-line', 'first-letter', 'selection', 'placeholder', 'marker'], true)) throw new \Exception();
                if ($ps === 'link') { $parts[] = ['tag', 'a']; continue; }
                $parts[] = ['pseudo', $ps, $arg, $ps === 'not' ? $this->compound($arg) : null];
                $this->specificity += 100;
            }
        }
        if (strlen((string)preg_replace('/\s+/', '', $consumed)) !== strlen((string)preg_replace('/\s+/', '', $c))) throw new \Exception();
        return $parts;
    }

    /** Ключ индекса (самый правый compound). */
    public function rightKey(): ?string
    {
        $best = null;
        foreach ($this->chain[0]['parts'] ?? [] as $p) {
            if ($p[0] === 'id') return '#' . $p[1];
            if ($p[0] === 'class') $best = '.' . $p[1];
            elseif ($p[0] === 'tag' && $best === null) $best = $p[1];
        }
        return $best;
    }

    public function matches(\DOMElement $el): bool
    {
        return $this->valid && $this->matchAt($el, 0);
    }

    private function matchAt(\DOMElement $el, int $idx): bool
    {
        if (!self::matchCompound($el, $this->chain[$idx]['parts'])) return false;
        if ($idx === count($this->chain) - 1) return true;
        $comb = $this->chain[$idx]['comb'];
        switch ($comb) {
            case '>': $p = $el->parentNode; return $p instanceof \DOMElement && $this->matchAt($p, $idx + 1);
            case ' ': for ($p = $el->parentNode; $p instanceof \DOMElement; $p = $p->parentNode) if ($this->matchAt($p, $idx + 1)) return true; return false;
            case '+': $p = self::prevEl($el); return $p && $this->matchAt($p, $idx + 1);
            case '~': for ($p = self::prevEl($el); $p; $p = self::prevEl($p)) if ($this->matchAt($p, $idx + 1)) return true; return false;
        }
        return false;
    }

    private static function prevEl(\DOMNode $n): ?\DOMElement
    {
        for ($p = $n->previousSibling; $p; $p = $p->previousSibling) if ($p instanceof \DOMElement && $p->nodeName !== 'bp-pseudo') return $p;
        return null;
    }

    /** @return array<string, int> классы элемента (кэш по строке class) */
    public static function classSet(string $cls): array
    {
        static $cache = [];
        if (isset($cache[$cls])) return $cache[$cls];
        if (count($cache) > 5000) $cache = [];
        $set = [];
        foreach (preg_split('/\s+/', trim($cls)) ?: [] as $c) if ($c !== '') $set[$c] = 1;
        return $cache[$cls] = $set;
    }

    /** @return list<string> */
    public static function classes(string $cls): array { return array_map('strval', array_keys(self::classSet($cls))); }

    public static function matchCompound(\DOMElement $el, array $parts): bool
    {
        foreach ($parts as $p) {
            switch ($p[0]) {
                case 'tag': if (strtolower($el->localName ?? $el->nodeName) !== $p[1]) return false; break;
                case 'id': if ($el->getAttribute('id') !== $p[1]) return false; break;
                case 'class': if (!isset(self::classSet($el->getAttribute('class'))[$p[1]])) return false; break;
                case 'attr':
                    if (!$el->hasAttribute($p[1])) return false;
                    $v = $el->getAttribute($p[1]); $w = $p[3];
                    $ok = match ($p[2]) { '' => true, '=' => $v === $w, '~=' => in_array($w, preg_split('/\s+/', $v) ?: [], true), '^=' => $w !== '' && str_starts_with($v, $w),
                        '$=' => $w !== '' && str_ends_with($v, $w), '*=' => $w !== '' && str_contains($v, $w), '|=' => $v === $w || str_starts_with($v, $w . '-'), default => false };
                    if (!$ok) return false; break;
                case 'pseudo':
                    if (!self::pseudo($el, $p[1], $p[2], $p[3])) return false; break;
            }
        }
        return true;
    }

    private static function pseudo(\DOMElement $el, string $ps, string $arg, ?array $not): bool
    {
        switch ($ps) {
            case 'root': return !($el->parentNode instanceof \DOMElement);
            case 'empty': foreach ($el->childNodes as $c) if (!($c instanceof \DOMElement && $c->nodeName === 'bp-pseudo') && !($c instanceof \DOMComment)) return false; return true;
            case 'not': return !self::matchCompound($el, $not ?? []);
            case 'first-child': return self::index($el, false, false) === 1;
            case 'last-child': return self::index($el, false, true) === 1;
            case 'only-child': return self::index($el, false, false) === 1 && self::index($el, false, true) === 1;
            case 'first-of-type': return self::index($el, true, false) === 1;
            case 'last-of-type': return self::index($el, true, true) === 1;
            case 'nth-child': return self::nth(self::index($el, false, false), $arg);
            case 'nth-last-child': return self::nth(self::index($el, false, true), $arg);
            case 'nth-of-type': return self::nth(self::index($el, true, false), $arg);
            case 'nth-last-of-type': return self::nth(self::index($el, true, true), $arg);
        }
        return false;
    }

    /** Индексы среди соседей считаются одним проходом по родителю и кэшируются в атрибутах (линейно, без O(n²) на :nth-child). */
    private static function index(\DOMElement $el, bool $ofType, bool $fromEnd): int
    {
        $p = $el->parentNode;
        if (!$p instanceof \DOMElement) {
            $i = 1;
            for ($s = $fromEnd ? $el->nextSibling : $el->previousSibling; $s; $s = $fromEnd ? $s->nextSibling : $s->previousSibling)
                if ($s instanceof \DOMElement && $s->nodeName !== 'bp-pseudo' && (!$ofType || $s->nodeName === $el->nodeName)) $i++;
            return $i;
        }
        static $gen = 0;
        $tok = $p->getAttribute('data-bp-n');
        $v = explode(',', $el->getAttribute('data-bp-idx'));
        if ($tok === '' || ($v[4] ?? '') !== $tok) { // кэш индексов: токен родителя должен совпасть с токеном ребёнка
            $n = (string)++$gen;
            $kids = []; foreach ($p->childNodes as $c) if ($c instanceof \DOMElement && $c->nodeName !== 'bp-pseudo') $kids[] = $c;
            $cnt = count($kids); $byType = [];
            foreach ($kids as $c) $byType[$c->nodeName] = ($byType[$c->nodeName] ?? 0) + 1;
            $seen = [];
            foreach ($kids as $k => $c) {
                $t = $seen[$c->nodeName] = ($seen[$c->nodeName] ?? 0) + 1;
                $c->setAttribute('data-bp-idx', ($k + 1) . ',' . $t . ',' . ($cnt - $k) . ',' . ($byType[$c->nodeName] - $t + 1) . ',' . $n);
            }
            $p->setAttribute('data-bp-n', $n);
            $v = explode(',', $el->getAttribute('data-bp-idx'));
        }
        return (int)$v[($fromEnd ? 2 : 0) + ($ofType ? 1 : 0)];
    }

    public static function nth(int $i, string $expr): bool
    {
        $e = strtolower(str_replace(' ', '', $expr));
        if ($e === 'odd') return $i % 2 === 1;
        if ($e === 'even') return $i % 2 === 0;
        if (preg_match('/^([+-]?\d*)n([+-]\d+)?$/', $e, $m)) {
            $a = $m[1] === '' || $m[1] === '+' ? 1 : ($m[1] === '-' ? -1 : (int)$m[1]); $b = (int)($m[2] ?? 0);
            if ($a === 0) return $i === $b;
            return ($i - $b) / $a >= 0 && ($i - $b) % $a === 0;
        }
        return ctype_digit(ltrim($e, '+')) && $i === (int)$e;
    }
}
