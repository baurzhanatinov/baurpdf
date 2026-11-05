<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

/**
 * Многоколоночный блок, который раскладывается пагинатором: первый сегмент заполняет остаток текущей страницы,
 * средние — колонки высотой в страницу, последний — сбалансированные колонки.
 */
final class ColumnSet
{
    /**
     * @param list<array{y:float,h:float,ops:array,pl?:array}> $items атомы подпотока (координаты внутри колонки)
     * @param list<array{first:int,last:int,top:float,bottom:float,box:array}> $decos фоны блоков внутри колонок
     * @param array{0:float,1:array}|null $rule ширина и цвет column-rule
     */
    public function __construct(
        public array $items,
        public int $n,
        public float $colW,
        public float $gap,
        public float $x,
        public ?array $rule = null,
        public array $decos = [],
    ) {}

    /** Начало колонки: в самой первой колонке сохраняется верхний отступ первого блока (как в Chromium), после разрыва — обрезается. */
    private function top(int $from): float
    {
        return $from === 0 ? min(0.0, $this->items[0]['y']) : $this->items[$from]['y'];
    }

    /**
     * Заполнение колонок высотой $colH начиная с $from.
     * @return array{0: list<array{0:int,1:int,2:float}>, 1: int} [[item, col, start]], следующий item
     */
    private function fill(int $from, float $colH, int $cols): array
    {
        $res = []; $col = 0; $start = $this->top($from); $first = true; $i = $from;
        for ($cnt = count($this->items); $i < $cnt; $i++) {
            $a = $this->items[$i];
            if (!$first && $a['y'] + $a['h'] - $start > $colH + 0.01) {
                // widows/orphans: перенести в следующую колонку больше строк абзаца, если в текущей что-то останется
                $j = Paginator::lineBreakAt($this->items, $i);
                if ($j < $i && $this->colStart($res, $col) < $j) {
                    while ($res && $res[array_key_last($res)][0] >= $j) array_pop($res);
                    $i = $j; $a = $this->items[$i];
                }
                if (++$col >= $cols) break;
                $start = $a['y'];
            }
            $res[] = [$i, $col, $start]; $first = false;
        }
        return [$res, $i];
    }

    /** Индекс первого элемента колонки $col в раскладке. */
    private function colStart(array $res, int $col): int
    {
        foreach ($res as [$i, $c]) if ($c === $col) return $i;
        return PHP_INT_MAX;
    }

    /** Минимальная высота, при которой элементы с $from помещаются в n колонок. */
    public function balanced(int $from = 0): float
    {
        $last = $this->items[count($this->items) - 1];
        $total = $last['y'] + $last['h'] - $this->top($from);
        $maxA = 0.0; for ($i = $from, $c = count($this->items); $i < $c; $i++) $maxA = max($maxA, $this->items[$i]['h']);
        $lo = max($maxA, $total / $this->n); $hi = max($lo, $total);
        if ($this->fill($from, $lo, $this->n)[1] >= count($this->items)) return $lo;
        for ($k = 0; $k < 30 && $hi - $lo > 0.5; $k++) {
            $mid = ($lo + $hi) / 2;
            if ($this->fill($from, $mid, $this->n)[1] >= count($this->items)) $hi = $mid; else $lo = $mid;
        }
        return $hi;
    }

    /**
     * Сегменты по страницам. Сегмент с h<0 означает «перейти на следующую страницу» (остаток слишком мал).
     * @return list<array{h:float,ops:array}>
     */
    public function segments(float $firstAvail, float $pageH): array
    {
        $segs = []; $from = 0; $avail = $firstAvail; $cnt = count($this->items);
        for ($guard = 0; $from < $cnt && $guard < 10000; $guard++) {
            $bal = $this->balanced($from);
            if ($bal <= $avail + 0.01) { $segs[] = $this->render($this->fill($from, $bal, $this->n)[0], $bal); break; }
            if ($avail < min($this->items[$from]['h'], $pageH) - 0.01 || $avail < 24) { $segs[] = ['h' => -1.0, 'ops' => []]; $avail = $pageH; continue; }
            [$pl, $next] = $this->fill($from, $avail, $this->n);
            if ($next === $from) { [$pl, $next] = [[[$from, 0, $this->top($from)]], $from + 1]; } // элемент выше страницы
            $segs[] = $this->render($pl, $avail);
            $from = $next; $avail = $pageH;
        }
        return $segs;
    }

    /**
     * @param list<array{0:int,1:int,2:float}> $place
     * @return array{h:float,ops:array}
     */
    private function render(array $place, float $colH): array
    {
        $ops = []; $h = 0.0; $cols = []; $where = [];
        foreach ($place as [$i, $c, $start]) {
            $a = $this->items[$i];
            $where[$i] = [$c, $start]; $cols[$c] = true;
            $h = max($h, $a['y'] + $a['h'] - $start);
            if ($a['ops']) $ops[] = ['t' => 'group', 'dx' => $this->x + $c * ($this->colW + $this->gap), 'dy' => -$start, 'ops' => $a['ops']];
        }
        $bg = [];
        foreach ($this->decos as $d) {
            if (!isset($where[$d['first']], $where[$d['last']]) || $where[$d['first']][0] !== $where[$d['last']][0]) continue;
            [$c, $start] = $where[$d['first']];
            $box = $d['box']; $box['x'] += $this->x + $c * ($this->colW + $this->gap);
            foreach (Ops::box($box, $d['top'] - $start, $d['bottom'] - $d['top'], true, true) as $o) $bg[] = $o;
        }
        if ($this->rule) for ($c = 1; $c < $this->n; $c++) if (isset($cols[$c])) {
            $lx = $this->x + $c * ($this->colW + $this->gap) - $this->gap / 2;
            $ops[] = ['t' => 'line', 'x' => $lx, 'y' => 0.0, 'x2' => $lx, 'y2' => $h, 'lw' => $this->rule[0], 'color' => $this->rule[1]];
        }
        return ['h' => $h, 'ops' => array_merge($bg, $ops)];
    }
}
