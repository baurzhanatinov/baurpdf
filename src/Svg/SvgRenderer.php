<?php
declare(strict_types=1);
namespace BaurPDF\Svg;

use BaurPDF\Chart\ChartRenderer;
use BaurPDF\Css\Color;
use BaurPDF\Css\CssParser;
use BaurPDF\Layout\Engine;

/** Векторный SVG (подмножество): g/rect/circle/ellipse/line/polyline/polygon/path(все команды)/text, transform, fill/stroke/opacity. */
final class SvgRenderer
{
    private array $ops = [];
    private int $count = 0;

    public function __construct(private Engine $e) {}

    public function naturalSize(\DOMElement $svg): array
    {
        $vb = $this->viewBox($svg);
        $w = self::num($svg->getAttribute('width')); $h = self::num($svg->getAttribute('height'));
        if ($w && $h) return [$w * 0.75, $h * 0.75];
        if ($vb) { $r = $vb[3] > 0 ? $vb[2] / $vb[3] : 1; if ($w) return [$w * .75, $w * .75 / $r]; if ($h) return [$h * .75 * $r, $h * .75]; return [$vb[2] * 0.75, $vb[3] * 0.75]; }
        return [($w ?: 300) * 0.75, ($h ?: 150) * 0.75];
    }

    private static function num(string $v): ?float
    {
        if ($v === '' || str_ends_with($v, '%')) return null;
        $f = (float)$v;
        if (str_ends_with($v, 'pt')) $f /= 0.75; elseif (str_ends_with($v, 'mm')) $f *= 96 / 25.4; elseif (str_ends_with($v, 'cm')) $f *= 96 / 2.54; elseif (str_ends_with($v, 'in')) $f *= 96;
        return $f > 0 ? $f : null;
    }

    private function viewBox(\DOMElement $svg): ?array
    {
        $vb = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox') ?: $svg->getAttribute('viewbox'))) ?: [];
        return count($vb) === 4 && (float)$vb[2] > 0 && (float)$vb[3] > 0 ? array_map('floatval', $vb) : null;
    }

    public function render(\DOMElement $svg, float $w, float $h): array
    {
        $this->ops = []; $this->count = 0; $this->ids = []; $this->sheet = []; $this->useDepth = 0;
        foreach ($svg->getElementsByTagName('*') as $e) {
            if ($e->hasAttribute('id')) $this->ids[$e->getAttribute('id')] ??= $e;
            if (strtolower($e->localName ?? '') === 'style') $this->parseSheet($e->textContent);
        }
        $vb = $this->viewBox($svg) ?? [0, 0, $w / 0.75, $h / 0.75];
        if (!$this->viewBox($svg)) { [$nw, $nh] = $this->naturalSize($svg); $vb = [0, 0, $nw / 0.75, $nh / 0.75]; }
        $s = min($w / $vb[2], $h / $vb[3]);
        $tx = ($w - $vb[2] * $s) / 2 - $vb[0] * $s; $ty = ($h - $vb[3] * $s) / 2 - $vb[1] * $s;
        $m = [$s, 0, 0, $s, $tx, $ty];
        $this->ops[] = ['t' => 'clip', 'x' => 0, 'y' => 0, 'w' => $w, 'h' => $h];
        try { $this->walk($svg, $m, ['fill' => '#000', 'stroke' => 'none', 'stroke-width' => '1', 'opacity' => 1.0, 'fill-opacity' => 1.0, 'stroke-opacity' => 1.0, 'font-size' => '16', 'text-anchor' => 'start', 'font-weight' => 'normal', 'color' => '#000']); }
        catch (\Throwable) {}
        $this->ops[] = ['t' => 'endclip'];
        return $this->ops;
    }

    /** @var array<string, \DOMElement> */
    private array $ids = [];
    /** @var list<array{0:string,1:string,2:array<string,string>}> простые правила <style>: [вид, имя, свойства] */
    private array $sheet = [];
    private int $useDepth = 0;

    /** Простые селекторы из <style> внутри SVG: tag, .class, #id, tag.class. */
    private function parseSheet(string $css): void
    {
        $css = (string)preg_replace('~/\*.*?\*/~s', '', $css);
        if (!preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $mm, PREG_SET_ORDER)) return;
        foreach ($mm as [, $sel, $body]) {
            $d = []; foreach (CssParser::declarations($body) as $k => [$v]) $d[$k] = $v;
            foreach (explode(',', $sel) as $one) {
                $one = trim($one);
                if (preg_match('/^([a-zA-Z][\w-]*)?(?:([.#])([\w-]+))?$/', $one, $p) && $one !== '') $this->sheet[] = [strtolower($p[1] ?? ''), ($p[2] ?? '') . ($p[3] ?? ''), $d];
            }
        }
    }

    private function styleOf(\DOMElement $el, array $inh): array
    {
        $st = $inh;
        if ($this->sheet) {
            $tag = strtolower($el->localName ?? ''); $cls = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: []; $id = $el->getAttribute('id');
            foreach ($this->sheet as [$t, $sel, $d]) {
                if ($t !== '' && $t !== $tag) continue;
                if ($sel !== '' && !($sel[0] === '.' ? in_array(substr($sel, 1), $cls, true) : substr($sel, 1) === $id)) continue;
                foreach ($d as $k => $v) $st[$k] = $v;
            }
        }
        foreach (['fill', 'stroke', 'stroke-width', 'opacity', 'fill-opacity', 'stroke-opacity', 'font-size', 'text-anchor', 'font-weight', 'stroke-dasharray', 'color', 'fill-rule', 'font-family', 'display', 'visibility'] as $a) {
            if ($el->hasAttribute($a)) $st[$a] = $el->getAttribute($a);
        }
        if ($el->hasAttribute('style')) foreach (CssParser::declarations($el->getAttribute('style')) as $k => [$v]) $st[$k] = $v;
        $st['opacity'] = ($inh['opacity'] ?? 1) * (float)($el->hasAttribute('opacity') || isset($st['opacity']) ? ($el->getAttribute('opacity') !== '' ? $el->getAttribute('opacity') : 1) : 1);
        return $st;
    }

    private function walk(\DOMElement $el, array $m, array $inh): void
    {
        if (++$this->count > 20000) return;
        foreach ($el->childNodes as $c) {
            if (!$c instanceof \DOMElement) continue;
            $tag = strtolower($c->localName ?? $c->nodeName);
            if (in_array($tag, ['defs', 'style', 'title', 'desc', 'metadata', 'clippath', 'mask', 'lineargradient', 'radialgradient', 'filter', 'symbol', 'pattern', 'marker'], true)) continue;
            $st = $this->styleOf($c, $inh);
            if (($st['display'] ?? '') === 'none' || ($st['visibility'] ?? '') === 'hidden') continue;
            $cm = $c->hasAttribute('transform') ? self::mul($m, self::transform($c->getAttribute('transform'))) : $m;
            switch ($tag) {
                case 'g': case 'a': case 'switch': $this->walk($c, $cm, $st); break;
                case 'svg': // вложенный svg: x/y + viewBox
                    $nm = self::mul($cm, [1, 0, 0, 1, (float)$c->getAttribute('x'), (float)$c->getAttribute('y')]);
                    $vb = $this->viewBox($c); $nw = self::num($c->getAttribute('width')); $nh = self::num($c->getAttribute('height'));
                    if ($vb && $nw && $nh) { $sc = min($nw / $vb[2], $nh / $vb[3]); $nm = self::mul($nm, [$sc, 0, 0, $sc, -$vb[0] * $sc, -$vb[1] * $sc]); }
                    $this->walk($c, $nm, $st); break;
                case 'rect':
                    $x = (float)$c->getAttribute('x'); $y = (float)$c->getAttribute('y'); $w = (float)$c->getAttribute('width'); $h = (float)$c->getAttribute('height');
                    if ($w <= 0 || $h <= 0) break;
                    $rx = (float)($c->getAttribute('rx') ?: $c->getAttribute('ry'));
                    if ($rx > 0) { $rx = min($rx, $w / 2, $h / 2); $k = .5523 * $rx;
                        $p = [['M', $x + $rx, $y], ['L', $x + $w - $rx, $y], ['C', $x + $w - $rx + $k, $y, $x + $w, $y + $rx - $k, $x + $w, $y + $rx], ['L', $x + $w, $y + $h - $rx], ['C', $x + $w, $y + $h - $rx + $k, $x + $w - $rx + $k, $y + $h, $x + $w - $rx, $y + $h],
                            ['L', $x + $rx, $y + $h], ['C', $x + $rx - $k, $y + $h, $x, $y + $h - $rx + $k, $x, $y + $h - $rx], ['L', $x, $y + $rx], ['C', $x, $y + $rx - $k, $x + $rx - $k, $y, $x + $rx, $y], ['Z']];
                    } else $p = [['M', $x, $y], ['L', $x + $w, $y], ['L', $x + $w, $y + $h], ['L', $x, $y + $h], ['Z']];
                    $this->emit($p, $cm, $st); break;
                case 'circle': $r = (float)$c->getAttribute('r'); if ($r > 0) $this->emit(ChartRenderer::circle((float)$c->getAttribute('cx'), (float)$c->getAttribute('cy'), $r), $cm, $st); break;
                case 'ellipse':
                    $rx = (float)$c->getAttribute('rx'); $ry = (float)$c->getAttribute('ry');
                    if ($rx > 0 && $ry > 0) $this->emit(ChartRenderer::circle(0, 0, 1), self::mul($cm, [$rx, 0, 0, $ry, (float)$c->getAttribute('cx'), (float)$c->getAttribute('cy')]), $st, sqrt($rx * $ry));
                    break;
                case 'line':
                    $st['fill'] = 'none';
                    $this->emit([['M', (float)$c->getAttribute('x1'), (float)$c->getAttribute('y1')], ['L', (float)$c->getAttribute('x2'), (float)$c->getAttribute('y2')]], $cm, $st); break;
                case 'polyline': case 'polygon':
                    preg_match_all('/-?[\d.]+(?:e-?\d+)?/i', $c->getAttribute('points'), $mm); $n = $mm[0];
                    if (count($n) < 4) break;
                    $p = [['M', (float)$n[0], (float)$n[1]]];
                    for ($i = 2; $i + 1 < count($n); $i += 2) $p[] = ['L', (float)$n[$i], (float)$n[$i + 1]];
                    if ($tag === 'polygon') $p[] = ['Z'];
                    $this->emit($p, $cm, $st); break;
                case 'path': $p = self::parsePath($c->getAttribute('d')); if ($p) $this->emit($p, $cm, $st); break;
                case 'text': $this->text($c, $cm, $st); break;
                case 'use': // только локальные ссылки (#id) — внешние ресурсы не загружаются
                    $ref = $c->getAttribute('href') ?: $c->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
                    $t = str_starts_with($ref, '#') ? ($this->ids[substr($ref, 1)] ?? null) : null;
                    if (!$t || $this->useDepth > 8) break;
                    $um = self::mul($cm, [1, 0, 0, 1, (float)$c->getAttribute('x'), (float)$c->getAttribute('y')]);
                    $this->useDepth++;
                    $tt = strtolower($t->localName ?? '');
                    if ($tt === 'symbol' || $tt === 'svg') {
                        $vb = $this->viewBox($t); $uw = self::num($c->getAttribute('width')) ?? ($vb[2] ?? 0); $uh = self::num($c->getAttribute('height')) ?? ($vb[3] ?? 0);
                        if ($vb && $uw > 0 && $uh > 0) { $sc = min($uw / $vb[2], $uh / $vb[3]); $um = self::mul($um, [$sc, 0, 0, $sc, -$vb[0] * $sc, -$vb[1] * $sc]); }
                        $this->walk($t, $um, $this->styleOf($t, $st));
                    } else {
                        $wrap = $t->ownerDocument?->createElement('g');
                        if ($wrap) { $wrap->appendChild($t->cloneNode(true)); $this->walk($wrap, $um, $st); }
                    }
                    $this->useDepth--;
                    break;
            }
        }
    }

    private function color(string $v, array $st, string $which): ?array
    {
        $v = trim($v);
        if ($v === '' || $v === 'none' || str_starts_with($v, 'url(')) return $v === 'none' || $v === '' ? null : [0.5, 0.5, 0.5, 1];
        if (strtolower($v) === 'currentcolor') $v = $st['color'] ?? '#000';
        $c = Color::parse($v);
        if (!$c) return null;
        $c[3] *= (float)$st['opacity'] * (float)($st[$which . '-opacity'] ?? 1);
        return $c;
    }

    private function emit(array $path, array $m, array $st, float $strokeScale = 1): void
    {
        $pts = [];
        foreach ($path as $seg) {
            $o = [$seg[0]];
            for ($i = 1; $i + 1 < count($seg); $i += 2) { [$x, $y] = self::apply($m, $seg[$i], $seg[$i + 1]); $o[] = $x; $o[] = $y; }
            $pts[] = $o;
        }
        $fill = $this->color((string)$st['fill'], $st, 'fill');
        $stroke = $this->color((string)$st['stroke'], $st, 'stroke');
        $scale = sqrt(abs($m[0] * $m[3] - $m[1] * $m[2]));
        $lw = (float)$st['stroke-width'] * $scale / max(1e-6, $strokeScale) * $strokeScale;
        $dash = !empty($st['stroke-dasharray']) && $st['stroke-dasharray'] !== 'none' ? 'dashed' : 'solid';
        $this->ops[] = ['t' => 'path', 'pts' => $pts, 'fill' => $fill, 'stroke' => $stroke, 'lw' => $lw, 'dash' => $dash, 'evenodd' => ($st['fill-rule'] ?? '') === 'evenodd'];
    }

    private function text(\DOMElement $el, array $m, array $st): void
    {
        $x = (float)$el->getAttribute('x'); $y = (float)$el->getAttribute('y');
        $text = trim((string)preg_replace('/\s+/u', ' ', $el->textContent));
        if ($text === '') return;
        [$px, $py] = self::apply($m, $x, $y);
        $scale = sqrt(abs($m[0] * $m[3] - $m[1] * $m[2]));
        $size = (float)$st['font-size'] * $scale;
        $col = $this->color((string)($st['fill'] ?? '#000'), $st, 'fill') ?? [0, 0, 0, 1];
        $anchor = ['start' => 'left', 'middle' => 'center', 'end' => 'right'][$st['text-anchor'] ?? 'start'] ?? 'left';
        $bold = in_array(strtolower((string)$st['font-weight']), ['bold', '600', '700', '800', '900'], true);
        foreach ($this->e->textOps($text, $px, $py, ['font-family' => $st['font-family'] ?? 'sans-serif'], $size, $col, null, $anchor, $bold) as $o) $this->ops[] = $o;
    }

    public static function apply(array $m, float $x, float $y): array { return [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5]]; }
    public static function mul(array $a, array $b): array
    {
        return [$a[0] * $b[0] + $a[2] * $b[1], $a[1] * $b[0] + $a[3] * $b[1], $a[0] * $b[2] + $a[2] * $b[3], $a[1] * $b[2] + $a[3] * $b[3], $a[0] * $b[4] + $a[2] * $b[5] + $a[4], $a[1] * $b[4] + $a[3] * $b[5] + $a[5]];
    }

    public static function transform(string $t): array
    {
        $m = [1, 0, 0, 1, 0, 0];
        preg_match_all('/(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/i', $t, $mm, PREG_SET_ORDER);
        foreach ($mm as $x) {
            $a = array_map('floatval', preg_split('/[\s,]+/', trim($x[2])) ?: []);
            $r = match (strtolower($x[1])) {
                'matrix' => count($a) === 6 ? $a : [1, 0, 0, 1, 0, 0],
                'translate' => [1, 0, 0, 1, $a[0] ?? 0, $a[1] ?? 0],
                'scale' => [$a[0] ?? 1, 0, 0, $a[1] ?? ($a[0] ?? 1), 0, 0],
                'rotate' => (function () use ($a) { $r = deg2rad($a[0] ?? 0); $c = cos($r); $s = sin($r); $cx = $a[1] ?? 0; $cy = $a[2] ?? 0;
                    return self::mul(self::mul([1, 0, 0, 1, $cx, $cy], [$c, $s, -$s, $c, 0, 0]), [1, 0, 0, 1, -$cx, -$cy]); })(),
                'skewx' => [1, 0, tan(deg2rad($a[0] ?? 0)), 1, 0, 0],
                'skewy' => [1, tan(deg2rad($a[0] ?? 0)), 0, 1, 0, 0],
                default => [1, 0, 0, 1, 0, 0],
            };
            $m = self::mul($m, $r);
        }
        return $m;
    }

    /** SVG path → абсолютные M/L/C/Z. */
    public static function parsePath(string $d): array
    {
        preg_match_all('/([MmLlHhVvCcSsQqTtAaZz])|(-?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)/', $d, $mm, PREG_SET_ORDER);
        $out = []; $cmd = ''; $nums = []; $tokens = [];
        foreach ($mm as $t) { if (($t[1] ?? '') !== '') $tokens[] = $t[1]; else $tokens[] = (float)($t[2] ?? 0); }
        $x = $y = $sx = $sy = 0.0; $lcx = $lcy = null; $lq = null; $i = 0; $n = count($tokens);
        $need = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'A' => 7, 'Z' => 0];
        $guard = 0;
        while ($i < $n && ++$guard < 100000) {
            if (is_string($tokens[$i])) { $cmd = $tokens[$i]; $i++; if (strtoupper($cmd) === 'Z') { $out[] = ['Z']; $x = $sx; $y = $sy; continue; } }
            if ($cmd === '') { $i++; continue; }
            $U = strtoupper($cmd); $rel = $cmd !== $U; $k = $need[$U];
            $a = [];
            for ($j = 0; $j < $k; $j++) { if (!isset($tokens[$i + $j]) || is_string($tokens[$i + $j])) return $out; $a[] = $tokens[$i + $j]; }
            $i += $k;
            switch ($U) {
                case 'M': $x = $rel ? $x + $a[0] : $a[0]; $y = $rel ? $y + $a[1] : $a[1]; $sx = $x; $sy = $y; $out[] = ['M', $x, $y]; $cmd = $rel ? 'l' : 'L'; $lcx = $lq = null; break;
                case 'L': $x = $rel ? $x + $a[0] : $a[0]; $y = $rel ? $y + $a[1] : $a[1]; $out[] = ['L', $x, $y]; $lcx = $lq = null; break;
                case 'H': $x = $rel ? $x + $a[0] : $a[0]; $out[] = ['L', $x, $y]; $lcx = $lq = null; break;
                case 'V': $y = $rel ? $y + $a[0] : $a[0]; $out[] = ['L', $x, $y]; $lcx = $lq = null; break;
                case 'C': case 'S':
                    if ($U === 'C') { [$x1, $y1, $x2, $y2, $ex, $ey] = $a; if ($rel) { $x1 += $x; $y1 += $y; $x2 += $x; $y2 += $y; $ex += $x; $ey += $y; } }
                    else { [$x2, $y2, $ex, $ey] = $a; if ($rel) { $x2 += $x; $y2 += $y; $ex += $x; $ey += $y; } $x1 = $lcx !== null ? 2 * $x - $lcx : $x; $y1 = $lcx !== null ? 2 * $y - $lcy : $y; }
                    $out[] = ['C', $x1, $y1, $x2, $y2, $ex, $ey]; $lcx = $x2; $lcy = $y2; $x = $ex; $y = $ey; $lq = null; break;
                case 'Q': case 'T':
                    if ($U === 'Q') { [$qx, $qy, $ex, $ey] = $a; if ($rel) { $qx += $x; $qy += $y; $ex += $x; $ey += $y; } }
                    else { [$ex, $ey] = $a; if ($rel) { $ex += $x; $ey += $y; } $qx = $lq ? 2 * $x - $lq[0] : $x; $qy = $lq ? 2 * $y - $lq[1] : $y; }
                    $out[] = ['C', $x + 2 / 3 * ($qx - $x), $y + 2 / 3 * ($qy - $y), $ex + 2 / 3 * ($qx - $ex), $ey + 2 / 3 * ($qy - $ey), $ex, $ey];
                    $lq = [$qx, $qy]; $x = $ex; $y = $ey; $lcx = null; break;
                case 'A':
                    [$rx, $ry, $phi, $fa, $fs, $ex, $ey] = $a; if ($rel) { $ex += $x; $ey += $y; }
                    foreach (self::arcToBezier($x, $y, $ex, $ey, $rx, $ry, $phi, (bool)$fa, (bool)$fs) as $c) $out[] = $c;
                    $x = $ex; $y = $ey; $lcx = $lq = null; break;
            }
        }
        return $out;
    }

    private static function arcToBezier(float $x1, float $y1, float $x2, float $y2, float $rx, float $ry, float $phi, bool $fa, bool $fs): array
    {
        if ($rx == 0 || $ry == 0 || ($x1 == $x2 && $y1 == $y2)) return [['L', $x2, $y2]];
        $rx = abs($rx); $ry = abs($ry); $p = deg2rad($phi); $c = cos($p); $s = sin($p);
        $dx = ($x1 - $x2) / 2; $dy = ($y1 - $y2) / 2;
        $x1p = $c * $dx + $s * $dy; $y1p = -$s * $dx + $c * $dy;
        $l = $x1p ** 2 / $rx ** 2 + $y1p ** 2 / $ry ** 2; if ($l > 1) { $rx *= sqrt($l); $ry *= sqrt($l); }
        $num = $rx ** 2 * $ry ** 2 - $rx ** 2 * $y1p ** 2 - $ry ** 2 * $x1p ** 2; $den = $rx ** 2 * $y1p ** 2 + $ry ** 2 * $x1p ** 2;
        $co = ($fa === $fs ? -1 : 1) * sqrt(max(0, $num / max(1e-12, $den)));
        $cxp = $co * $rx * $y1p / $ry; $cyp = -$co * $ry * $x1p / $rx;
        $cx = $c * $cxp - $s * $cyp + ($x1 + $x2) / 2; $cy = $s * $cxp + $c * $cyp + ($y1 + $y2) / 2;
        $ang = fn($ux, $uy, $vx, $vy) => atan2($ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy);
        $t1 = $ang(1, 0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $dt = $ang(($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry, (-$x1p - $cxp) / $rx, (-$y1p - $cyp) / $ry);
        if (!$fs && $dt > 0) $dt -= 2 * M_PI; elseif ($fs && $dt < 0) $dt += 2 * M_PI;
        $segs = max(1, (int)ceil(abs($dt) / (M_PI / 2))); $d = $dt / $segs; $out = [];
        for ($i = 0; $i < $segs; $i++) {
            $a1 = $t1 + $d * $i; $a2 = $a1 + $d; $k = 4 / 3 * tan($d / 4);
            $p1 = [cos($a1) - $k * sin($a1), sin($a1) + $k * cos($a1)]; $p2 = [cos($a2) + $k * sin($a2), sin($a2) - $k * cos($a2)]; $p3 = [cos($a2), sin($a2)];
            $tr = fn($pt) => [$c * $rx * $pt[0] - $s * $ry * $pt[1] + $cx, $s * $rx * $pt[0] + $c * $ry * $pt[1] + $cy];
            $q1 = $tr($p1); $q2 = $tr($p2); $q3 = $tr($p3);
            $out[] = ['C', $q1[0], $q1[1], $q2[0], $q2[1], $q3[0], $q3[1]];
        }
        return $out;
    }
}
