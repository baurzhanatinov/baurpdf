<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

use BaurPDF\Text\Hyphenator;

/** Разбиение inline-элементов на строки (жадно) и отрисовка строки. */
final class LineBreaker
{
    /** Допуск переполнения строки, pt: наши ширины слов чуть больше, чем у Chromium, и он умещает слово, выходящее за край меньше чем на ~0,5 pt; значение подобрано эмпирически по корпусу tools/compare (annual, contract, cv → F1 1,0). */
    public const FIT_SLACK = 0.5;
    public function __construct(private Engine $e) {}

    /**
     * Разбиение на строки.
     *  - text-align: justify → Knuth-Plass (оптимальное по абзацу, с переносами по словарю);
     *  - иначе жадно, но слово, не помещающееся в остаток строки, переносится по словарю (при hyphens:auto).
     * Слова длиннее строки (URL, коды) режутся посимвольно в обоих режимах.
     */
    /** Сужение первых строк обтеканием float: [число строк, сдвиг слева, ширина]. */
    public ?array $narrow = null;

    /** Оптимальная (Knuth–Plass) разбивка выровненного по ширине текста; по умолчанию — жадная, как в браузерах (2.29). */
    public bool $optimal = false;

    /** Доступная ширина строки $i. */
    public function lineW(int $i, float $w): float
    {
        return $this->narrow && $i < $this->narrow[0] ? $this->narrow[2] : $w;
    }

    public function breakLines(array $items, float $w, float $indent = 0, string $align = 'left'): array
    {
        $justify = $align === 'justify';
        $items = $this->prepare($items, $w, $justify);
        if ($justify && $this->optimal && count($items) > 2) {
            $kp = $this->knuthPlass($items, $w, $indent);
            if ($kp !== null) return $kp;
        }
        return $this->greedy($items, $w, $indent);
    }

    /** Разбивка слов на слоги (элементы 'h' — возможный перенос) и нарезка слов длиннее строки. */
    /** @var array<string, list<array{string, float}>> */
    private static array $pieceCache = [];

    private function prepare(array $items, float $w, bool $justify): array
    {
        // быстрый путь: всё помещается в одну строку — слоги не нужны (типично для ячеек таблиц)
        if ($this->narrow === null) {
            $sum = 0.0; $fits = true;
            foreach ($items as $it) { if ($it['t'] === 'br') { $fits = false; break; } $sum += $it['w'] ?? 0; if ($sum > $w) { $fits = false; break; } }
            if ($fits) return $items;
        }
        $out = [];
        foreach ($items as $it) {
            if ($it['t'] !== 'w' || !empty($it['nobreak'])) { $out[] = $it; continue; }
            $st = $it['run']['st'];
            $hy = strtolower($st['hyphens'] ?? 'manual');
            // словарные переносы: при hyphens:auto (как в CSS, по умолчанию manual) и всегда для слов длиннее строки
            $run = $it['run'];
            $ck = null;
            if ($hy !== 'none' && !($hy === 'manual' && $it['w'] <= $w + 0.01)) {
                // слоги одного и того же слова в том же шрифте и ширине строки — из кэша (длинные таблицы повторяют слова тысячи раз)
                $ck = $it['text'] . "\0" . spl_object_id($run['font']) . "\0" . $run['size'] . "\0" . $run['ls'] . "\0" . ($st['-bp-lang'] ?? '') . "\0"
                    . ($st['hyphenate-limit-chars'] ?? $st['-webkit-hyphenate-limit-chars'] ?? '') . "\0" . ($it['w'] <= $w + 0.01 ? round($w, 2) : 'x');
                $pieces = self::$pieceCache[$ck] ?? null;
                if ($pieces === null) {
                    $pts = Hyphenator::points($it['text'], $st['-bp-lang'] ?? null);
                    if ($pts && $it['w'] <= $w + 0.01) $pts = self::limitPoints($pts, mb_strlen($it['text']), $st, $w);
                    $pieces = [];
                    if ($pts) {
                        $chars = mb_str_split($it['text']); $prev = 0;
                        foreach ([...$pts, count($chars)] as $p) { $pw = $this->e->word($t = implode('', array_slice($chars, $prev, $p - $prev)), $run); $pieces[] = [$t, $pw['w']]; $prev = $p; }
                    }
                    if (count(self::$pieceCache) > 20000) self::$pieceCache = [];
                    self::$pieceCache[$ck] = $pieces;
                }
                if ($pieces) {
                    $hw = $this->e->textWidth($run['font'], '-', $run['size']);
                    $last = count($pieces) - 1;
                    foreach ($pieces as $k => [$t, $pw]) {
                        $piece = ['t' => 'w', 'text' => $t, 'w' => $pw, 'run' => $run, 'nobreak' => false];
                        if (isset($it['ibox'])) $piece['ibox'] = $it['ibox'];
                        $out[] = $piece;
                        if ($k < $last) $out[] = ['t' => 'h', 'w' => 0, 'hw' => $hw, 'run' => $run];
                    }
                    continue;
                }
            }
            if ($it['w'] > $w + 0.01) {
                foreach ($this->splitWord($it, $w, $w) as $k => $piece) { if ($k > 0) $out[] = ['t' => 'h', 'w' => 0, 'hw' => 0, 'run' => $it['run']]; $out[] = $piece; }
                continue;
            }
            $out[] = $it;
        }
        return $out;
    }

    /**
     * Ограничения переносов: CSS `hyphenate-limit-chars: <слово> <до> <после>` (по умолчанию 6 2 2).
     * В узких строках (ячейки таблиц, колонки уже ~10em) короткие слова не делятся: минимум 8 символов и по 3 с каждой стороны.
     * Слово длиннее строки переносится всегда (правило не применяется).
     * @param list<int> $pts @return list<int>
     */
    public static function limitPoints(array $pts, int $len, array $st, float $w): array
    {
        $lim = [6, 2, 2];
        $raw = trim(strtolower((string)($st['hyphenate-limit-chars'] ?? $st['-webkit-hyphenate-limit-chars'] ?? '')));
        if ($raw !== '' && $raw !== 'auto') {
            foreach (array_slice(preg_split('/\s+/', $raw) ?: [], 0, 3) as $k => $v) if (ctype_digit($v)) $lim[$k] = (int)$v; elseif ($v === 'auto' && $k === 2) $lim[2] = $lim[1];
            if (count(preg_split('/\s+/', $raw) ?: []) === 2) $lim[2] = $lim[1];
        }
        if ($w < 10 * (float)($st['_fs'] ?? 12)) { $lim[0] = max($lim[0], 8); $lim[1] = max($lim[1], 3); $lim[2] = max($lim[2], 3); }
        if ($len < $lim[0]) return [];
        return array_values(array_filter($pts, fn($p) => $p >= $lim[1] && $len - $p >= $lim[2]));
    }

    private function greedy(array $items, float $w, float $indent): array
    {
        $lines = []; $cur = []; $cw = $indent; $avail = $this->lineW(0, $w);
        $lastH = -1; $lastHw = 0.0; // последний возможный перенос в текущей строке после последнего пробела
        $push = function (array $part, float $pw, bool $hyphen) use (&$lines, &$avail, $w) {
            while ($part && in_array(end($part)['t'], ['s', 'h'], true)) { $s = array_pop($part); $pw -= $s['w']; }
            while ($part && $part[0]['t'] === 'h') array_shift($part);
            if ($hyphen && $part) { $r = end($part)['run']; $part[] = $this->e->word('-', $r); $pw += $part[count($part) - 1]['w']; }
            $lines[] = $this->metrics(array_values(array_filter($part, fn($x) => $x['t'] !== 'h')), $pw);
            $avail = $this->lineW(count($lines), $w);
        };
        $n = count($items);
        for ($i = 0; $i < $n; $i++) {
            $it = $items[$i];
            if ($it['t'] === 'br') { if (!$cur) $cur[] = ['t' => 'strut', 'w' => 0, 'run' => $it['run']]; $push($cur, $cw, false); $cur = []; $cw = 0; $lastH = -1; continue; }
            if ($it['t'] === 's') { if (!$cur) continue; $cur[] = $it; $cw += $it['w']; if (empty($it['nobreak'])) $lastH = -1; continue; }
            if ($it['t'] === 'h') { if ($cur) { $lastH = count($cur); $lastHw = $cw; $cur[] = $it; } continue; }
            $gw = $it['w']; $j = $i + 1;
            while ($j < $n && ($items[$j]['t'] === 'pad' || $items[$j]['t'] === 'dest')) { $gw += $items[$j]['w']; $j++; }
            if ($cw + $gw > $avail + self::FIT_SLACK && $cur && $it['t'] !== 'pad') {
                $hyFits = $lastH > 0 && $lastHw + $cur[$lastH]['hw'] <= $avail + 0.01;
                // найти последний пробел-разрыв
                $sp = -1; for ($k = count($cur) - 1; $k >= 0; $k--) if ($cur[$k]['t'] === 's' && empty($cur[$k]['nobreak'])) { $sp = $k; break; }
                if ($hyFits && $lastH > $sp) {
                    $head = array_slice($cur, 0, $lastH); $tail = array_slice($cur, $lastH + 1);
                    $push($head, $lastHw, $cur[$lastH]['hw'] > 0);
                    $cur = $tail; $cw = array_sum(array_column($tail, 'w'));
                } elseif ($sp > 0) {
                    $head = array_slice($cur, 0, $sp); $tail = array_slice($cur, $sp + 1);
                    $hw = 0.0; foreach ($head as $x) $hw += $x['w'];
                    $push($head, $hw, false);
                    $cur = $tail; $cw = array_sum(array_column($tail, 'w'));
                } else { $push($cur, $cw, false); $cur = []; $cw = 0; }
                $lastH = -1;
                for ($k = 0; $k < count($cur); $k++) if ($cur[$k]['t'] === 'h') { $lastH = $k; $lastHw = 0; foreach (array_slice($cur, 0, $k) as $x) $lastHw += $x['w']; }
            }
            $cur[] = $it; $cw += $it['w'];
        }
        if ($cur) $push($cur, $cw, false);
        return $lines;
    }

    /**
     * Knuth-Plass: минимизация суммарных «штрафов» абзаца.
     * box = слово/атом, glue = пробел (растяжение 50%, сжатие 33%), penalty = перенос (50 + штраф за два подряд).
     */
    private function knuthPlass(array $items, float $w, float $indent): ?array
    {
        $n = count($items);
        // префиксные суммы ширины / растяжения / сжатия
        $W = [0.0]; $Y = [0.0]; $Z = [0.0];
        $isGlue = [];
        for ($i = 0; $i < $n; $i++) {
            $it = $items[$i]; $g = $it['t'] === 's' && empty($it['nobreak']);
            $isGlue[$i] = $g;
            $W[] = $W[$i] + ($it['t'] === 'h' ? 0 : ($it['w'] ?? 0));
            $Y[] = $Y[$i] + ($g ? $it['w'] * 0.6 : 0);
            $Z[] = $Z[$i] + ($g ? $it['w'] * 0.35 : 0);
        }
        foreach ([1.0, 2.0, 4.0] as $tol) {
            $res = $this->kpPass($items, $n, $W, $Y, $Z, $isGlue, $w, $indent, $tol);
            if ($res !== null) return $res;
        }
        return null;
    }

    private function kpPass(array $items, int $n, array $W, array $Y, array $Z, array $isGlue, float $w, float $indent, float $tol): ?array
    {
        // узел: [pos, line, fitness, demerits, prevIndex, hyphen]
        $nodes = [[-1, 0, 1, 0.0, -1, false]];
        $active = [0];
        $start = function (int $pos) use ($items, $n, $isGlue): int {
            $s = $pos + 1;
            while ($s < $n && ($isGlue[$s] || $items[$s]['t'] === 'h')) $s++;
            return $s;
        };
        for ($b = 0; $b <= $n; $b++) {
            $final = $b === $n;
            if (!$final) {
                $t = $items[$b]['t'];
                $forced = $t === 'br';
                $legal = $forced || $t === 'h' || ($isGlue[$b] && $b > 0 && !$isGlue[$b - 1] && $items[$b - 1]['t'] !== 'h');
                if (!$legal) continue;
            } else $forced = true;
            $hyph = !$final && $items[$b]['t'] === 'h';
            $pw = $hyph ? $items[$b]['hw'] : 0.0;
            $pen = $hyph ? ($pw > 0 ? 50.0 : 200.0) : 0.0;
            $best = []; $keep = [];
            foreach ($active as $ai) {
                [$pos, $line, $fit, $dem] = $nodes[$ai];
                $s = $start($pos);
                $avail = $this->lineW($line, $w) - ($line === 0 ? $indent : 0);
                $L = $W[$b] - $W[$s] + $pw;
                if ($forced) { $r = $L <= $avail + 0.01 ? 0.0 : ($Z[$b] - $Z[$s] > 0 ? ($avail - $L) / ($Z[$b] - $Z[$s]) : -10.0); }
                elseif ($L < $avail) $r = $Y[$b] - $Y[$s] > 0 ? ($avail - $L) / ($Y[$b] - $Y[$s]) : 100.0;
                elseif ($L > $avail) $r = $Z[$b] - $Z[$s] > 0 ? ($avail - $L) / ($Z[$b] - $Z[$s]) : -10.0;
                else $r = 0.0;
                if ($r < -1 || $forced) { /* деактивировать */ } else $keep[] = $ai;
                if ($r < -1 && !($forced && $s >= $b)) {
                    if (count($active) === 1 && !$keep && $r < -1) { /* единственный узел и строка не влезает — пусть сработает иной допуск */ }
                    continue;
                }
                if ($r > $tol && !$forced) continue;
                $bad = 100 * abs($r) ** 3;
                $d = (1 + $bad) ** 2 + $pen * $pen;
                if ($hyph && $nodes[$ai][5]) $d += 3000;
                $fc = $r < -0.5 ? 0 : ($r <= 0.5 ? 1 : ($r <= 1 ? 2 : 3));
                if (abs($fc - $fit) > 1) $d += 3000;
                $total = $dem + $d;
                if (!isset($best[$fc]) || $total < $best[$fc][0]) $best[$fc] = [$total, $ai, $line + 1];
            }
            $active = $keep;
            foreach ($best as $fc => [$total, $ai, $line]) { $nodes[] = [$b, $line, $fc, $total, $ai, $hyph]; $active[] = count($nodes) - 1; }
            if (!$active) return null;
            if ($forced && !$final) {
                // принудительный разрыв: оставить только лучший новый узел
                $bi = null; foreach ($active as $ai) if ($nodes[$ai][0] === $b && ($bi === null || $nodes[$ai][3] < $nodes[$bi][3])) $bi = $ai;
                if ($bi === null) return null;
                $active = [$bi];
            }
        }
        $bi = null; foreach ($active as $ai) if ($nodes[$ai][0] === $n && ($bi === null || $nodes[$ai][3] < $nodes[$bi][3])) $bi = $ai;
        if ($bi === null) return null;
        $breaks = [];
        for ($k = $bi; $k > 0; $k = $nodes[$k][4]) $breaks[] = $k;
        $breaks = array_reverse($breaks);
        $lines = []; $prev = -1;
        foreach ($breaks as $k) {
            $b = $nodes[$k][0]; $s = $start($prev);
            $part = []; $pw = 0.0;
            for ($i = $s; $i < $b; $i++) { if ($items[$i]['t'] === 'h') continue; $part[] = $items[$i]; $pw += $items[$i]['w']; }
            while ($part && end($part)['t'] === 's') { $x = array_pop($part); $pw -= $x['w']; }
            if ($nodes[$k][5] && $items[$b]['hw'] > 0 && $part) { $part[] = $this->e->word('-', end($part)['run']); $pw += end($part)['w']; }
            if (!$part && $b < $n && $items[$b]['t'] === 'br') $part[] = ['t' => 'strut', 'w' => 0, 'run' => $items[$b]['run']];
            if ($part) $lines[] = $this->metrics($part, $pw);
            $prev = $b;
        }
        return $lines;
    }

    private function splitWord(array $it, float $first, float $avail): array
    {
        $chars = mb_str_split($it['text']); $pieces = []; $buf = ''; $lim = max($first, $avail * 0.3) > 0 && $first > 10 ? $first : $avail;
        foreach ($chars as $ch) {
            $try = $buf . $ch;
            if ($buf !== '' && $this->e->textWidth($it['run']['font'], $try, $it['run']['size'], empty($it['run']['nk'])) > $lim) { $pieces[] = $this->e->word($buf, $it['run']); $buf = $ch; $lim = $avail; }
            else $buf = $try;
        }
        if ($buf !== '') $pieces[] = $this->e->word($buf, $it['run']);
        foreach ($pieces as &$p) if (isset($it['ibox'])) $p['ibox'] = $it['ibox'];
        return $pieces;
    }

    private function metrics(array $items, float $w): array
    {
        $above = 0; $below = 0;
        foreach ($items as $it) {
            $r = $it['run'];
            $f = $r['font'];
            $A = $f->ascent / $f->unitsPerEm * $r['size']; $D = -$f->descent / $f->unitsPerEm * $r['size'];
            if ($it['t'] === 'a') {
                $va = $it['va'] ?? 'baseline';
                // strut корневого inline-бокса: строка с одной картинкой всё равно имеет высоту/глубину шрифта (стандартный режим)
                $half = ($r['lh'] - ($A + $D)) / 2;
                $above = max($above, $A + $half); $below = max($below, $D + $half);
                if ($va === 'middle') { $mid = $A * 0.35; $above = max($above, $it['h'] / 2 + $mid); $below = max($below, $it['h'] / 2 - $mid); }
                elseif ($va === 'top' || $va === 'text-top') { $above = max($above, $A); $below = max($below, $it['h'] - $A); }
                elseif (isset($it['base'])) { $above = max($above, $it['base']); $below = max($below, $it['h'] - $it['base']); }
                else $above = max($above, $it['h']);
                continue;
            }
            $half = ($r['lh'] - ($A + $D)) / 2;
            $shift = $r['va'] === 'super' ? -$r['size'] * 0.45 : ($r['va'] === 'sub' ? $r['size'] * 0.2 : 0);
            $above = max($above, $A + $half - $shift);
            $below = max($below, $D + $half + $shift);
        }
        return ['items' => $items, 'w' => $w, 'h' => max(0.1, $above + $below), 'baseline' => $above];
    }

    public function paintLine(array $line, float $x, float $w, string $align, bool $last, float $indent): array
    {
        $ops = [];
        $items = $line['items'];
        $free = $w - $line['w'] - $indent;
        $spaces = 0;
        foreach ($items as $it) if ($it['t'] === 's') $spaces++;
        $extra = 0; $cx = $x + $indent;
        if ($align === 'center') $cx += max(0, $free) / 2;
        elseif ($align === 'right' || $align === 'end') $cx += max(0, $free);
        elseif ($align === 'justify' && !$last && $spaces > 0) $extra = $free / $spaces;
        $bl = $line['baseline'];
        $boxes = []; // inline-фоны: id => [x0,x1,info,run]
        $links = [];
        foreach ($items as $it) {
            $r = $it['run'];
            $iw = $it['w'] + ($it['t'] === 's' ? $extra : 0);
            if (!empty($it['ibox'])) foreach ($it['ibox'] as $bid => $info) {
                if (!isset($boxes[$bid])) $boxes[$bid] = [$cx + ($it['t'] === 'pad' ? $info['ml'] : 0), $cx + $iw, $info, $r];
                else $boxes[$bid][1] = $cx + $iw - ($it['t'] === 'pad' ? $info['mr'] : 0);
            }
            if ($it['t'] === 'w') {
                $shift = $r['va'] === 'super' ? -$r['size'] * 0.45 : ($r['va'] === 'sub' ? $r['size'] * 0.2 : 0);
                $seg = $this->e->segmentsOps($r['font'], $r['fb'], $r['fi'], $it['text'], $cx, $bl + $shift, $r['size'], $r['color'], $r['ls'], empty($r['nk']));
                if (count($seg) === 1 && ($seg[0]['t'] ?? '') === 'text') $seg[0]['_e'] = $cx + $it['w']; // конец слова — для склейки слогов
                foreach ($seg as $o) $ops[] = $o;
                if ($r['u'] || $r['s'] || $r['o']) {
                    $lw = max(0.4, $r['size'] / 16);
                    if ($r['u']) $ops[] = ['t' => 'line', 'x' => $cx, 'y' => $bl + $shift + $r['size'] * 0.12, 'x2' => $cx + $it['w'], 'y2' => $bl + $shift + $r['size'] * 0.12, 'lw' => $lw, 'color' => $r['color']];
                    if ($r['s']) $ops[] = ['t' => 'line', 'x' => $cx, 'y' => $bl + $shift - $r['size'] * 0.3, 'x2' => $cx + $it['w'], 'y2' => $bl + $shift - $r['size'] * 0.3, 'lw' => $lw, 'color' => $r['color']];
                    if ($r['o']) $ops[] = ['t' => 'line', 'x' => $cx, 'y' => $bl + $shift - $r['size'] * 0.8, 'x2' => $cx + $it['w'], 'y2' => $bl + $shift - $r['size'] * 0.8, 'lw' => $lw, 'color' => $r['color']];
                }
            } elseif ($it['t'] === 'a') {
                $va = $it['va'] ?? 'baseline';
                $f = $r['font']; $A = $f->ascent / $f->unitsPerEm * $r['size'];
                $top = $va === 'middle' ? $bl - $A * 0.35 - $it['h'] / 2 : (($va === 'top' || $va === 'text-top') ? $bl - $A : $bl - ($it['base'] ?? $it['h']));
                foreach (Ops::translate($it['ops'], $cx, $top) as $o) $ops[] = $o;
            } elseif ($it['t'] === 'dest') {
                $ops[] = ['t' => 'dest', 'name' => $it['name'], 'y' => 0];
            }
            if (!empty($r['href']) && $it['t'] !== 'pad' && $it['t'] !== 'dest') {
                $h = $r['href'];
                if (isset($links[$h]) && abs($links[$h][1] - $cx) < 1) $links[$h][1] = $cx + $iw;
                else { if (isset($links[$h])) $ops[] = ['t' => 'link', 'x' => $links[$h][0], 'y' => 0, 'w' => $links[$h][1] - $links[$h][0], 'h' => $line['h'], 'href' => $h]; $links[$h] = [$cx, $cx + $iw]; }
            }
            $cx += $iw;
        }
        foreach ($links as $h => [$a, $b]) $ops[] = ['t' => 'link', 'x' => $a, 'y' => 0, 'w' => $b - $a, 'h' => $line['h'], 'href' => $h];
        if ($boxes) {
            $bg = [];
            foreach ($boxes as [$x0, $x1, $info, $r]) {
                $f = $r['font']; $A = $f->ascent / $f->unitsPerEm * $r['size']; $D = -$f->descent / $f->unitsPerEm * $r['size'];
                $top = $bl - $A - $info['pt']; $hh = $A + $D + $info['pt'] + $info['pb'];
                if ($info['bg']) $bg[] = ['t' => 'rect', 'x' => $x0, 'y' => $top, 'w' => $x1 - $x0, 'h' => $hh, 'fill' => $info['bg'], 'r' => $info['radius']];
                if ($info['border']) $bg[] = ['t' => 'rect', 'x' => $x0, 'y' => $top, 'w' => $x1 - $x0, 'h' => $hh, 'stroke' => $info['border'][1], 'lw' => $info['border'][0], 'r' => $info['radius']];
            }
            $ops = array_merge($bg, $ops);
        }
        return self::mergeText($ops);
    }

    /**
     * Слить подряд идущие текстовые операции одной строки и стиля в одну с частями px[] (смещения) / pt[] (тексты):
     * в несколько раз меньше памяти на длинных документах, вывод идентичен.
     */
    public static function mergeText(array $ops): array
    {
        $out = []; $last = -1;
        foreach ($ops as $o) {
            if (($o['t'] ?? '') === 'text' && empty($o['rot']) && $last >= 0) {
                $p = &$out[$last];
                if ($p['y'] === $o['y'] && $p['font'] === $o['font'] && $p['size'] === $o['size'] && $p['color'] === $o['color']
                    && $p['fb'] === $o['fb'] && $p['fi'] === $o['fi'] && ($p['ls'] ?? 0) === ($o['ls'] ?? 0)) {
                    // слог вплотную к предыдущему (переносимое слово) — дописываем в ту же строку
                    if (isset($p['_e']) && abs($o['x'] - $p['_e']) < 0.001) {
                        if (isset($p['px'])) $p['pt'][count($p['pt']) - 1] .= $o['text']; else $p['text'] .= $o['text'];
                    } else {
                        if (!isset($p['px'])) { $p['px'] = [0.0]; $p['pt'] = [$p['text']]; $p['text'] = ''; }
                        $p['px'][] = $o['x'] - $p['x']; $p['pt'][] = $o['text'];
                    }
                    if (isset($o['_e'])) $p['_e'] = $o['_e']; else unset($p['_e']);
                    unset($p);
                    continue;
                }
                unset($p['_e'], $p);
            }
            $out[] = $o;
            $last = (($o['t'] ?? '') === 'text' && empty($o['rot'])) ? count($out) - 1 : -1;
        }
        foreach ($out as &$o) unset($o['_e']);
        unset($o);
        return $out;
    }
}
