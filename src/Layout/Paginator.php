<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

/** Раскладка атомов по страницам: принудительные разрывы, keep-with-next, повтор thead/tfoot, нарезка фонов контейнеров. */
final class Paginator
{
    /**
     * @return array<int,array{ops:array}> страницы, координаты — от верхнего левого угла области контента
     */
    public static function paginate(Flow $flow, float $pageH, int $maxPages = 5000, float $startShift = 0.0, array $startHeaders = [], ?array &$info = null): array
    {
        $atoms = $flow->atoms;
        $n = count($atoms);
        $place = []; // idx => [page, shift]
        $page = 0; $shift = $startShift; $pageFirst = 0; $headers = $startHeaders ? [0 => $startHeaders] : []; $footers = []; $inserted = []; $colOps = [];
        $i = 0; $guard = 0; $brokenKeep = [];
        /** @var array<int, true> $forceBreakAt */
        $forceBreakAt = [];
        while ($i < $n) {
            if (++$guard > $n * 4 + 100) break;
            $a = $atoms[$i];
            if (isset($a['insert'])) { // готовые страницы (альбомная таблица)
                $has = false;
                for ($k = $pageFirst; $k < $i; $k++) if (($atoms[$k]['h'] ?? 0) > 0 || !empty($atoms[$k]['ops'])) { $has = true; break; }
                if ($has) $page++;
                foreach ($a['insert'] as $ip) { $inserted[$page] = $ip; $page++; }
                $pageFirst = $i + 1; $shift = -$a['y'];
                $place[$i] = [$page, $shift]; $i++; continue;
            }
            if (!empty($a['break'])) {
                if ($i > $pageFirst || isset($place[$i - 1])) { // новая страница только если на текущей что-то есть
                    $hasContent = false;
                    for ($k = $pageFirst; $k < $i; $k++) if (($atoms[$k]['h'] ?? 0) > 0 || !empty($atoms[$k]['ops'])) { $hasContent = true; break; }
                    if ($hasContent) { $page++; $pageFirst = $i + 1; $shift = -$a['y']; }
                }
                $place[$i] = [$page, $shift]; $i++; continue;
            }
            if (isset($a['cols'])) { // многоколоночный блок: режется по остатку страницы
                $top = $a['y'] + $shift;
                $segs = $a['cols']->segments($top > 0.5 ? $pageH - $top : $pageH, $pageH);
                $new = false;
                foreach ($segs as $sg) {
                    if ($sg['h'] < 0) { $new = true; continue; }
                    if ($new && $top > 0.5 && $page + 1 < $maxPages) { $page++; $pageFirst = $i; $top = 0.0; }
                    $colOps[$page][] = ['t' => 'group', 'dx' => 0.0, 'dy' => $top, 'ops' => $sg['ops']];
                    $top += $sg['h']; $new = true;
                }
                $shift = $top - $a['y'] - $a['h'];
                $place[$i] = [$page, $shift]; $i++; continue;
            }
            $top = $a['y'] + $shift;
            $needBreak = isset($forceBreakAt[$i]) || ($top + $a['h'] + ($a['foot']['h'] ?? 0) > $pageH + 0.01 && $top > 0.5);
            if ($needBreak && $page + 1 >= $maxPages) $needBreak = false;
            if ($needBreak) {
                unset($forceBreakAt[$i]);
                // keep-with-next: откатиться к началу цепочки — но только если цепочка целиком влезает на страницу,
                // иначе блок с break-inside:avoid выше страницы рвался бы по одной строке на страницу
                $j = $i;
                while ($j - 1 > $pageFirst && !empty($atoms[$j - 1]['keepNext']) && empty($atoms[$j - 1]['break'])) $j--;
                // высота всей цепочки — от её настоящего начала, даже если оно на предыдущей странице
                $k = $j;
                while ($k - 1 >= 0 && !empty($atoms[$k - 1]['keepNext']) && empty($atoms[$k - 1]['break'])) $k--;
                $chainH = $a['y'] + $a['h'] - $atoms[$k]['y'];
                if ($j < $i && $j > $pageFirst && $chainH <= $pageH + 0.01) { $i = $j; $a = $atoms[$i]; }
                elseif ($j < $i && $chainH > $pageH + 0.01) $brokenKeep[$k] = true; // одна цепочка — одно предупреждение
                elseif (($j = self::lineBreakAt($atoms, $i)) !== $i && $j > $pageFirst) { $i = $j; $a = $atoms[$i]; }
                $prev = $atoms[$i - 1] ?? null;
                if ($prev && !empty($prev['foot']) && isset($place[$i - 1])) $footers[$page][] = [$prev['foot'], $prev['y'] + $place[$i - 1][1] + $prev['h']];
                $page++; $pageFirst = $i;
                $shift = -$a['y'];
                if (!empty($a['repeat'])) { $headers[$page][] = $a['repeat']; $shift += $a['repeat']['h']; }
            }
            $place[$i] = [$page, $shift];
            $i++;
        }
        $info = ['place' => $place, 'headers' => $headers, 'brokenKeep' => count($brokenKeep)];
        $pages = array_fill(0, $page + 1, ['ops' => [], 'decos' => []]);
        foreach ($headers as $p => $hs) foreach ($hs as $h) foreach ($h['ops'] as $o) $pages[$p]['ops'][] = self::xlate($o, 0, -($o['y'] ?? 0) + ($o['y'] ?? 0));
        // повтор шапок: исходные ops шапки уже в координатах относительно атома
        foreach ($headers as $p => $hs) { $pages[$p]['ops'] = []; $off = 0; foreach ($hs as $h) { $pages[$p]['ops'][] = ['t' => 'group', 'dx' => 0.0, 'dy' => $off, 'ops' => $h['ops'], 'artifact' => true]; $off += $h['h']; } }
        foreach ($atoms as $idx => $a) {
            if (!isset($place[$idx])) continue;
            [$p, $s] = $place[$idx];
            if ($a['ops']) $pages[$p]['ops'][] = ['t' => 'group', 'dx' => 0.0, 'dy' => $s, 'ops' => $a['ops']]; // без копирования операций
        }
        foreach ($colOps as $p => $cs) foreach ($cs as $o) $pages[$p]['ops'][] = $o;
        foreach ($footers as $p => $fs) foreach ($fs as [$f, $fy]) $pages[$p]['ops'][] = ['t' => 'group', 'dx' => 0.0, 'dy' => $fy, 'ops' => $f['ops'], 'artifact' => true];
        // фоны контейнеров
        $decos = $flow->decos;
        usort($decos, fn($a, $b) => $a['depth'] <=> $b['depth']);
        foreach ($decos as $d) {
            if (!isset($place[$d['first']], $place[$d['last']])) continue;
            [$p0, $s0] = $place[$d['first']]; [$p1, $s1] = $place[$d['last']];
            for ($p = $p0; $p <= $p1; $p++) {
                $top = $p === $p0 ? $d['top'] + $s0 : 0;
                $bot = $p === $p1 ? $d['bottom'] + $s1 : $pageH;
                if ($p === $p0 && $p0 !== $p1 && $top < 0) $top = 0;
                if ($bot - $top <= 0.01) continue;
                foreach (Ops::box($d['box'], $top, $bot - $top, $p === $p0, $p === $p1) as $o) $pages[$p]['decos'][] = $o;
            }
        }
        foreach ($pages as &$pg) { $pg['ops'] = array_merge($pg['decos'], $pg['ops']); unset($pg['decos']); }
        unset($pg);
        foreach ($inserted as $p => $ip) $pages[$p] = ['ops' => $ip['ops'], 'landscape' => true];
        return $pages;
    }

    /**
     * widows/orphans: индекс атома, перед которым можно разорвать вместо $i, если $i — строка абзаца.
     * Возвращает $i, если правило уже соблюдено или абзац слишком короток.
     */
    public static function lineBreakAt(array $atoms, int $i): int
    {
        $pl = $atoms[$i]['pl'] ?? null;
        if (!is_array($pl)) return $i;
        [$k, $n, $o, $w] = array_map('intval', $pl);
        if ($k <= 0) return $i;
        $t = $k;
        if ($n - $t < $w) $t = $n - $w;
        if ($t < $o) $t = 0;
        $j = $i - ($k - $t);
        return $j >= 0 && $j < $i ? $j : $i;
    }

    private static function xlate(array $o, float $dx, float $dy): array { return Ops::translate([$o], $dx, $dy)[0]; }
}
