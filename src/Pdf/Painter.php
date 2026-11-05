<?php
declare(strict_types=1);
namespace BaurPDF\Pdf;

/** Превращает операции рисования в PDF content stream. Координаты ops: pt, начало — верх-лево. */
final class Painter
{
    public array $headings = [];

    public function __construct(private Writer $w) {}

    public function paint(int $page, array $ops, float $ox, float $oy, ?array $clip = null): void
    {
        $H = $this->w->pages[$page]['h'];
        $c = '';
        if ($clip) $c .= sprintf("q %.2F %.2F %.2F %.2F re W n\n", $clip[0], $H - $clip[1] - $clip[3], $clip[2], $clip[3]);
        $this->bt = null; $this->mcKey = null;
        $this->ops($page, $ops, $ox, $oy, $H, $c);
        if ($this->bt !== null) { $c .= "ET\nQ\n"; $this->bt = null; }
        $this->closeMc($c);
        if ($clip) $c .= "Q\n";
        $this->w->pages[$page]['content'] .= $c;
    }

    /** Текущая последовательность marked content: 's<sid>' | 'A' (Artifact) | null. */
    private ?string $mcKey = null;
    private const DRAW = ['text' => 1, 'rect' => 1, 'line' => 1, 'path' => 1, 'image' => 1, 'grad' => 1, 'shadow' => 1];

    private function closeMc(string &$c): void
    {
        if ($this->mcKey === null) return;
        if ($this->bt !== null) { $c .= "ET\nQ\n"; $this->bt = null; }
        $c .= "EMC\n"; $this->mcKey = null;
    }

    /** Tagged PDF: содержимое с sid — в BDC с MCID, остальное — Artifact. */
    private function mc(int $page, array $o, bool $art, string &$c): void
    {
        $tags = $this->w->tags;
        if (!$tags) return;
        $t = $o['t'] ?? '';
        if (!isset(self::DRAW[$t])) { $this->closeMc($c); return; }
        $sid = !$art && isset($o['sid']) ? (int)$o['sid'] : null;
        $key = $sid === null ? 'A' : 's' . $sid;
        if ($key === $this->mcKey) return;
        $this->closeMc($c);
        if ($sid === null) $c .= "/Artifact BMC\n";
        else $c .= '/' . $tags->elems[$sid]['type'] . ' << /MCID ' . $this->w->mcid($page, $sid) . " >> BDC\n";
        $this->mcKey = $key;
    }

    private function ops(int $page, array $ops, float $ox, float $oy, float $H, string &$c, bool $art = false): void
    {
        foreach ($ops as $o) {
            try {
                $t = $o['t'] ?? '';
                if ($t === 'group') { $this->ops($page, $o['ops'], $ox + ($o['dx'] ?? 0), $oy + ($o['dy'] ?? 0), $H, $c, $art || !empty($o['artifact'])); continue; }
                if ($t === 'tf') { // CSS transform: K = F·T(O)·M·T(−O)·F, F — переворот оси y страницы
                    if ($this->bt !== null) { $c .= "ET\nQ\n"; $this->bt = null; }
                    $this->closeMc($c);
                    $M = $o['m']; $Ox = $o['ox'] + $ox; $Oy = $o['oy'] + $oy;
                    $F = [1, 0, 0, -1, 0, $H];
                    $K = \BaurPDF\Css\Transform::mul($F, \BaurPDF\Css\Transform::mul([1, 0, 0, 1, $Ox, $Oy], \BaurPDF\Css\Transform::mul($M, \BaurPDF\Css\Transform::mul([1, 0, 0, 1, -$Ox, -$Oy], $F))));
                    $c .= "q\n" . implode(' ', array_map(fn($v) => sprintf('%.5F', $v), $K)) . " cm\n";
                    continue;
                }
                if ($t === 'endtf') { if ($this->bt !== null) { $c .= "ET\nQ\n"; $this->bt = null; } $this->closeMc($c); $c .= "Q\n"; continue; }
                if ($t === 'field' && $art) continue; // копии (повтор шапки таблицы, колонтитулы) не создают второе поле
                if ($this->w->tags) {
                    $this->mc($page, $o, $art, $c);
                    if ($t === 'link' && !$art && isset($o['sid'])) { $x = $o['x'] + $ox; $y = $H - ($o['y'] + $oy); $this->w->link($page, [$x, $y - $o['h'], $x + $o['w'], $y], $o['href'], (int)$o['sid']); continue; }
                }
                if ($t === 'text' && empty($o['rot'])) {
                    if (isset($o['px'])) { $x0 = $o['x']; foreach ($o['px'] as $k => $dx) { $o['x'] = $x0 + $dx; $o['text'] = $o['pt'][$k]; $c .= $this->text($o, $ox, $oy, $H); } }
                    else $c .= $this->text($o, $ox, $oy, $H);
                    continue;
                }
                if ($this->bt !== null) { $c .= "ET\nQ\n"; $this->bt = null; }
                $c .= $this->op($page, $o, $ox, $oy, $H);
            } catch (\Throwable) { /* одна битая операция не ломает документ */ }
        }
    }

    /** Состояние открытого текстового объекта (BT): шрифт, размер, цвет, альфа, Tc, жирность, наклон, позиция строки. */
    private ?array $bt = null;

    /** Компактный текст: подряд идущие слова пишутся в одном BT…ET, состояние меняется только при необходимости, позиция — относительным Td. */
    private function text(array $o, float $ox, float $oy, float $H): string
    {
        if ($o['text'] === '' || !$o['size']) return '';
        $res = $this->w->fontRes($o['font']);
        $hex = $this->w->encodeText($o['font'], $o['text'], empty($o['nk']));
        $col = $o['color']; $skew = !empty($o['fi']) ? 0.21 : 0.0; $fb = !empty($o['fb']); $ls = (float)($o['ls'] ?? 0);
        $x = $o['x'] + $ox; $y = $H - ($o['y'] + $oy);
        $s = '';
        $alpha = ($col[3] ?? 1) >= 0.999 ? 1.0 : round(max(0, $col[3]), 3);
        $b = $this->bt;
        if ($b === null || $b['skew'] !== $skew || $b['alpha'] !== $alpha) {
            if ($b !== null) $s .= "ET\nQ\n";
            $s .= "q\n" . $this->alpha($col) . "BT\n";
            $b = ['font' => '', 'size' => -1.0, 'rgb' => '', 'ls' => 0.0, 'fb' => false, 'skew' => $skew, 'alpha' => $alpha, 'x' => null, 'y' => 0.0, 'w' => ''];
            $s .= sprintf("1 0 %s 1 %s %s Tm\n", self::n($skew), self::n($x), self::n($y));
            $b['x'] = $x; $b['y'] = $y;
        }
        if ($b['font'] !== $res || $b['size'] !== (float)$o['size']) { $s .= "/$res " . self::n($o['size']) . " Tf\n"; $b['font'] = $res; $b['size'] = (float)$o['size']; }
        $rgb = sprintf('%.3F %.3F %.3F', ...array_slice($col, 0, 3));
        if ($b['rgb'] !== $rgb) { $s .= "$rgb rg\n"; $b['rgb'] = $rgb; if ($b['fb']) { $s .= "$rgb RG\n"; } }
        if ($b['ls'] !== $ls) { $s .= self::n($ls) . " Tc\n"; $b['ls'] = $ls; }
        if ($b['fb'] !== $fb) {
            $s .= $fb ? "2 Tr $rgb RG " . self::n($o['size'] * 0.03) . " w\n" : "0 Tr\n";
            $b['fb'] = $fb;
        } elseif ($fb && $b['w'] !== self::n($o['size'] * 0.03)) $s .= self::n($o['size'] * 0.03) . " w\n";
        if ($fb) $b['w'] = self::n($o['size'] * 0.03);
        if ($b['x'] !== $x || $b['y'] !== $y) {
            // Td сдвигает от начала текущей строки; с наклоном (Tm 1 0 k 1) dx учитывает сдвиг по y
            $dy = $y - $b['y']; $dx = $x - $b['x'] - $skew * $dy;
            $s .= self::n($dx) . ' ' . self::n($dy) . " Td\n";
            $b['x'] = $x; $b['y'] = $y;
        }
        $s .= Writer::show($hex, (float)$o['size']) . "\n";
        $this->bt = $b;
        return $s;
    }

    /** Число без лишних нулей: 12.50 → 12.5, 3.00 → 3. */
    private static function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.2F', $v), '0'), '.');
        return $s === '-0' || $s === '' ? '0' : $s;
    }

    private static function f(float $v): string { $s = sprintf('%.2F', $v); return $s === '-0.00' ? '0.00' : $s; }

    private function alpha(?array $c): string
    {
        if (!$c || ($c[3] ?? 1) >= 0.999) return '';
        return '/' . $this->w->alphaRes(max(0, $c[3])) . " gs\n";
    }

    private function op(int $page, array $o, float $ox, float $oy, float $H): string
    {
        $X = fn($x) => self::f($x + $ox);
        $Y = fn($y) => self::f($H - ($y + $oy));
        switch ($o['t']) {
            case 'text':
                if ($o['text'] === '' || !$o['size']) return '';
                $res = $this->w->fontRes($o['font']);
                $hex = $this->w->encodeText($o['font'], $o['text'], empty($o['nk']));
                $s = "q\n" . $this->alpha($o['color']) . sprintf("%.3F %.3F %.3F rg\n", ...array_slice($o['color'], 0, 3));
                $s .= "BT\n/$res " . self::f($o['size']) . " Tf\n";
                if (!empty($o['ls'])) $s .= self::f($o['ls']) . " Tc\n";
                if (!empty($o['fb'])) $s .= sprintf("2 Tr %.3F %.3F %.3F RG %s w\n", ...[...array_slice($o['color'], 0, 3), self::f($o['size'] * 0.03)]);
                $skew = !empty($o['fi']) ? 0.21 : 0;
                if (!empty($o['rot'])) { $r = deg2rad((float)$o['rot']); $s .= sprintf("%.4F %.4F %.4F %.4F %s %s Tm\n%s\nET\nQ\n", cos($r), sin($r), -sin($r), cos($r), $X($o['x']), $Y($o['y']), Writer::show($hex, (float)$o['size'])); return $s; }
                $s .= sprintf("1 0 %.2F 1 %s %s Tm\n%s\nET\nQ\n", $skew, $X($o['x']), $Y($o['y']), Writer::show($hex, (float)$o['size']));
                return $s;
            case 'rect':
                $s = "q\n";
                $fill = $o['fill'] ?? null; $stroke = $o['stroke'] ?? null;
                if (!$fill && !$stroke) return '';
                if ($fill) $s .= $this->alpha($fill) . sprintf("%.3F %.3F %.3F rg\n", ...array_slice($fill, 0, 3));
                if ($stroke) { $s .= ($fill ? '' : $this->alpha($stroke)) . sprintf("%.3F %.3F %.3F RG %s w\n", ...[...array_slice($stroke, 0, 3), self::f($o['lw'] ?? 1)]); $s .= self::dash($o['dash'] ?? 'solid', $o['lw'] ?? 1); }
                $s .= $this->rectPath($o['x'] + $ox, $H - ($o['y'] + $oy) - $o['h'], $o['w'], $o['h'], (float)($o['r'] ?? 0));
                $s .= ($fill && $stroke ? 'B' : ($fill ? 'f' : 'S')) . "\nQ\n";
                return $s;
            case 'line':
                $col = $o['color'] ?? [0, 0, 0, 1];
                return "q\n" . $this->alpha($col) . sprintf("%.3F %.3F %.3F RG %s w\n", ...[...array_slice($col, 0, 3), self::f($o['lw'] ?? 1)]) . self::dash($o['dash'] ?? 'solid', $o['lw'] ?? 1)
                    . $X($o['x']) . ' ' . $Y($o['y']) . ' m ' . $X($o['x2']) . ' ' . $Y($o['y2']) . " l S\nQ\n";
            case 'path':
                $p = '';
                foreach ($o['pts'] as $seg) {
                    $p .= match ($seg[0]) {
                        'M' => $X($seg[1]) . ' ' . $Y($seg[2]) . " m\n",
                        'L' => $X($seg[1]) . ' ' . $Y($seg[2]) . " l\n",
                        'C' => $X($seg[1]) . ' ' . $Y($seg[2]) . ' ' . $X($seg[3]) . ' ' . $Y($seg[4]) . ' ' . $X($seg[5]) . ' ' . $Y($seg[6]) . " c\n",
                        'Z' => "h\n", default => '',
                    };
                }
                if ($p === '') return '';
                $fill = $o['fill'] ?? null; $stroke = $o['stroke'] ?? null;
                if (!$fill && !$stroke) return '';
                $s = "q\n";
                if ($fill) $s .= $this->alpha($fill) . sprintf("%.3F %.3F %.3F rg\n", ...array_slice($fill, 0, 3));
                if ($stroke) $s .= ($fill ? '' : $this->alpha($stroke)) . sprintf("%.3F %.3F %.3F RG %s w 1 J 1 j\n", ...[...array_slice($stroke, 0, 3), self::f($o['lw'] ?? 1)]) . self::dash($o['dash'] ?? 'solid', $o['lw'] ?? 1);
                $evenodd = !empty($o['evenodd']) ? '*' : '';
                return $s . $p . ($fill && $stroke ? "B$evenodd" : ($fill ? "f$evenodd" : 'S')) . "\nQ\n";
            case 'image':
                $r = $this->w->image($o['data'], (float)$o['w'], (float)$o['h']);
                if (!$r) return '';
                [$res, $iw, $ih] = $r; $orient = $r[3] ?? 1;
                $x = $o['x'] + $ox; $y = $o['y'] + $oy; $w = $o['w']; $h = $o['h'];
                $s = "q\n";
                // border-radius у <img>: пиксели режутся по скруглению, как в браузере
                if (!empty($o['r'])) $s .= $this->rectPath($x, $H - $y - $h, $w, $h, (float)$o['r']) . "W n\n";
                if (!empty($o['cover']) || !empty($o['contain'])) {
                    $s .= sprintf("%s %s %s %s re W n\n", self::f($x), self::f($H - $y - $h), self::f($w), self::f($h));
                    $k = !empty($o['cover']) ? max($w / $iw, $h / $ih) : min($w / $iw, $h / $ih);
                    $nw = $iw * $k; $nh = $ih * $k; $x += ($w - $nw) / 2; $y += ($h - $nh) / 2; $w = $nw; $h = $nh;
                }
                $yb = $H - $y - $h; // нижний край в координатах PDF
                // EXIF-ориентация: пиксели не трогаем, поворачиваем матрицей (3 — 180°, 6 — 90° по часовой, 8 — против)
                $cm = match ($orient) {
                    2 => sprintf('%s 0 0 %s %s %s cm', self::f(-$w), self::f($h), self::f($x + $w), self::f($yb)),
                    3 => sprintf('%s 0 0 %s %s %s cm', self::f(-$w), self::f(-$h), self::f($x + $w), self::f($yb + $h)),
                    4 => sprintf('%s 0 0 %s %s %s cm', self::f($w), self::f(-$h), self::f($x), self::f($yb + $h)),
                    5 => sprintf('0 %s %s 0 %s %s cm', self::f(-$h), self::f(-$w), self::f($x + $w), self::f($yb + $h)), // транспонирование
                    6 => sprintf('0 %s %s 0 %s %s cm', self::f(-$h), self::f($w), self::f($x), self::f($yb + $h)),
                    7 => sprintf('0 %s %s 0 %s %s cm', self::f($h), self::f($w), self::f($x), self::f($yb)), // антитранспонирование
                    8 => sprintf('0 %s %s 0 %s %s cm', self::f($h), self::f(-$w), self::f($x + $w), self::f($yb)),
                    default => sprintf('%s 0 0 %s %s %s cm', self::f($w), self::f($h), self::f($x), self::f($yb)),
                };
                return $s . "$cm\n/$res Do\nQ\n";
            case 'shadow': // размытая тень: картинка с SMask, растянутая на внешний прямоугольник
                $res = $this->w->shadowImage($o['bw'], $o['bh'], $o['r'], $o['sigma'], $o['pad'], $o['color']);
                $W = $o['bw'] + 2 * $o['pad']; $Hh = $o['bh'] + 2 * $o['pad'];
                return sprintf("q\n%s 0 0 %s %s %s cm\n/%s Do\nQ\n", self::f($W), self::f($Hh), self::f($o['x'] + $ox), self::f($H - ($o['y'] + $oy) - $Hh), $res);
            case 'grad':
                return $this->gradient($o, $ox, $oy, $H);
            case 'alpha':
                return "q\n/" . $this->w->alphaRes($o['a']) . " gs\n";
            case 'endalpha':
                return "Q\n";
            case 'clip':
                return sprintf("q\n%s %s %s %s re W n\n", $X($o['x']), self::f($H - ($o['y'] + $oy) - $o['h']), self::f($o['w']), self::f($o['h']));
            case 'endclip':
                return "Q\n";
            case 'link':
                $x = $o['x'] + $ox; $y = $H - ($o['y'] + $oy);
                $this->w->link($page, [$x, $y - $o['h'], $x + $o['w'], $y], $o['href']);
                return '';
            case 'field': // поле AcroForm (виджет с собственным оформлением /AP)
                $x = $o['x'] + $ox; $y = $H - ($o['y'] + $oy);
                $this->w->field($page, [$x, $y - $o['h'], $x + $o['w'], $y], $o, isset($o['sid']) ? (int)$o['sid'] : null);
                return '';
            case 'dest':
                $this->w->dests[$o['name']] ??= ['page' => $page, 'y' => $H - ($o['y'] + $oy) + 4];
                return '';
            case 'heading':
                $this->headings[] = ['title' => $o['title'], 'level' => $o['level'], 'page' => $page, 'y' => $H - ($o['y'] + $oy) + 4, 'key' => $o['key']];
                return '';
            case 'raw':
                return $o['pdf'];
        }
        return '';
    }

    private static function dash(string $style, float $lw): string
    {
        return match ($style) { 'dashed' => sprintf("[%s %s] 0 d\n", self::f($lw * 3), self::f($lw * 2)), 'dotted' => sprintf("1 J [0 %s] 0 d\n", self::f($lw * 2)), default => '' };
    }

    public function rectPath(float $x, float $y, float $w, float $h, float $r): string
    {
        $r = min($r, $w / 2, $h / 2);
        if ($r <= 0.1) return sprintf("%s %s %s %s re\n", self::f($x), self::f($y), self::f($w), self::f($h));
        $k = 0.5523 * $r;
        $f = fn(...$a) => implode(' ', array_map(fn($v) => self::f($v), $a));
        return $f($x + $r, $y) . " m\n" . $f($x + $w - $r, $y) . " l\n" . $f($x + $w - $r + $k, $y, $x + $w, $y + $r - $k, $x + $w, $y + $r) . " c\n"
            . $f($x + $w, $y + $h - $r) . " l\n" . $f($x + $w, $y + $h - $r + $k, $x + $w - $r + $k, $y + $h, $x + $w - $r, $y + $h) . " c\n"
            . $f($x + $r, $y + $h) . " l\n" . $f($x + $r - $k, $y + $h, $x, $y + $h - $r + $k, $x, $y + $h - $r) . " c\n"
            . $f($x, $y + $r) . " l\n" . $f($x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y) . " c\nh\n";
    }

    private function gradient(array $o, float $ox, float $oy, float $H): string
    {
        $g = $o['grad'];
        $x = $o['x'] + $ox; $y = $H - ($o['y'] + $oy) - $o['h']; $w = $o['w']; $h = $o['h'];
        $s = "q\n" . $this->rectPath($x, $y, $w, $h, (float)($o['r'] ?? 0)) . "W n\n";
        if (isset($g['radial'])) {
            // радиальный: концентрические эллипсы снаружи внутрь (детерминированно, без Shading)
            $rd = $g['radial']; $cx = $rd['cx'] * $w; $cy = $rd['cy'] * $h;
            $dxs = [$cx, $w - $cx]; $dys = [$cy, $h - $cy];
            $rx = match ($rd['size']) { 'closest-side' => min($dxs), 'farthest-side' => max($dxs), default => max($dxs) * M_SQRT2 };
            $ry = match ($rd['size']) { 'closest-side' => min($dys), 'farthest-side' => max($dys), default => max($dys) * M_SQRT2 };
            if ($rd['circle']) { $rr = in_array($rd['size'], ['closest-side', 'farthest-side'], true) ? ($rd['size'] === 'closest-side' ? min($rx, $ry) : max($rx, $ry)) : sqrt(max($dxs) ** 2 + max($dys) ** 2); $rx = $ry = $rr; }
            $rx = max($rx, 0.5); $ry = max($ry, 0.5);
            $last = end($g['stops'])[0];
            $s .= sprintf("%.3F %.3F %.3F rg %s %s %s %s re f\n", $last[0], $last[1], $last[2], self::f($x), self::f($y), self::f($w), self::f($h));
            $px = $x + $cx; $py = $y + $h - $cy; $steps = 48; $k = 0.5523;
            for ($i = $steps; $i >= 1; $i--) {
                $t = $i / $steps; $c = self::stopColor($g['stops'], ($i - 0.5) / $steps);
                $a = $rx * $t; $b = $ry * $t;
                $s .= sprintf("%.3F %.3F %.3F rg\n", $c[0], $c[1], $c[2]) . sprintf("%s %s m %s %s %s %s %s %s c %s %s %s %s %s %s c %s %s %s %s %s %s c %s %s %s %s %s %s c f\n",
                    self::f($px + $a), self::f($py), self::f($px + $a), self::f($py + $b * $k), self::f($px + $a * $k), self::f($py + $b), self::f($px), self::f($py + $b),
                    self::f($px - $a * $k), self::f($py + $b), self::f($px - $a), self::f($py + $b * $k), self::f($px - $a), self::f($py),
                    self::f($px - $a), self::f($py - $b * $k), self::f($px - $a * $k), self::f($py - $b), self::f($px), self::f($py - $b),
                    self::f($px + $a * $k), self::f($py - $b), self::f($px + $a), self::f($py - $b * $k), self::f($px + $a), self::f($py));
            }
            return $s . "Q\n";
        }
        $a = deg2rad($g['angle']);
        $dx = sin($a); $dy = cos($a); // направление градиента (CSS: 0deg = вверх)
        $len = abs($w * $dx) + abs($h * $dy);
        $cx = $x + $w / 2; $cy = $y + $h / 2;
        $s .= sprintf("1 0 0 1 %s %s cm\n", self::f($cx), self::f($cy));
        // поворот: ось Y полосы вдоль направления
        $ang = atan2($dy, $dx);
        $s .= sprintf("%.5F %.5F %.5F %.5F 0 0 cm\n", cos($ang), sin($ang), -sin($ang), cos($ang));
        $steps = 64; $stops = $g['stops']; $diag = sqrt($w * $w + $h * $h);
        for ($i = 0; $i < $steps; $i++) {
            $t = ($i + 0.5) / $steps;
            $c = self::stopColor($stops, $t);
            $x0 = -$len / 2 + $len * $i / $steps;
            $s .= sprintf("%.3F %.3F %.3F rg %s %s %s %s re f\n", $c[0], $c[1], $c[2], self::f($x0), self::f(-$diag), self::f($len / $steps + 0.6), self::f(2 * $diag));
        }
        return $s . "Q\n";
    }

    private static function stopColor(array $stops, float $t): array
    {
        if ($t <= $stops[0][1]) return $stops[0][0];
        for ($i = 1; $i < count($stops); $i++) {
            if ($t <= $stops[$i][1]) {
                $a = $stops[$i - 1]; $b = $stops[$i];
                $k = ($b[1] - $a[1]) > 0 ? ($t - $a[1]) / ($b[1] - $a[1]) : 0;
                return \BaurPDF\Css\Color::mix($a[0], $b[0], $k);
            }
        }
        return end($stops)[0];
    }
}
