<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

/**
 * Результат вёрстки в бесконечной вертикальной полосе.
 * atoms — неразрывные куски (строки, строки таблиц, ряды flex/grid, картинки), которые пагинатор раскладывает по страницам;
 * decos — фоны/рамки контейнеров, охватывающие диапазон атомов (режутся по страницам).
 */
final class Flow
{
    /** @var array<int,array{y:float,h:float,ops:array,keepNext?:bool,break?:bool,group?:int,repeat?:array|null,thead?:int,avoid?:int,foot?:array{h:float,ops:array},insert?:array,cols?:ColumnSet,pl?:array}> */
    public array $atoms = [];
    /** @var array<int,array{top:float,bottom:float,first:int,last:int,depth:int,ops?:callable|array,box:array}> */
    public array $decos = [];
    public float $lastMarginBottom = 0;
    public ?array $pendingMarker = null;
    public float $maxRight = 0;
    /** Плавающие блоки: [side, x0, x1, top, bottom]. @var list<array{0:string,1:float,2:float,3:float,4:float}> */
    public array $floats = [];

    /** @param array{keepNext?:bool,break?:bool,group?:int,repeat?:array|null,thead?:int,avoid?:int,foot?:array{h:float,ops:array},insert?:array,cols?:ColumnSet,pl?:array} $extra */
    public function add(float $y, float $h, array $ops, array $extra = []): int
    {
        $this->atoms[] = ['y' => $y, 'h' => $h, 'ops' => $ops] + $extra;
        $this->lastMarginBottom = 0;
        return count($this->atoms) - 1;
    }

    /** Склеить в список операций (для атомарных контекстов: ячейки, flex-элементы). */
    public function flatten(float $dx = 0, float $dy = 0): array
    {
        $ops = [];
        $decos = $this->decos;
        usort($decos, fn($a, $b) => $a['depth'] <=> $b['depth']);
        foreach ($decos as $d) foreach (Ops::box($d['box'], $d['top'], $d['bottom'] - $d['top'], true, true) as $o) $ops[] = $o;
        foreach ($this->atoms as $a) foreach ($a['ops'] as $o) $ops[] = $o;
        return ($dx || $dy) ? Ops::translate($ops, $dx, $dy) : $ops;
    }

    public function height(): float
    {
        $h = 0;
        foreach ($this->atoms as $a) $h = max($h, $a['y'] + $a['h']);
        foreach ($this->decos as $d) $h = max($h, $d['bottom']);
        return $h;
    }
}
