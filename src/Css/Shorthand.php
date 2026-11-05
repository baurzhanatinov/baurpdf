<?php
declare(strict_types=1);
namespace BaurPDF\Css;

final class Shorthand
{
    public static function tokens(string $v): array
    {
        preg_match_all('/(?:[a-z-]+\([^)]*(?:\([^)]*\)[^)]*)*\)|"[^"]*"|\'[^\']*\'|[^\s,]+)/i', $v, $m);
        return $m[0];
    }

    private static function box(string $p, string $v, string $suffix = ''): array
    {
        $t = self::tokens($v);
        $c = count($t);
        if ($c === 0) return [];
        [$a, $b, $cc, $d] = match ($c) { 1 => [$t[0], $t[0], $t[0], $t[0]], 2 => [$t[0], $t[1], $t[0], $t[1]], 3 => [$t[0], $t[1], $t[2], $t[1]], default => [$t[0], $t[1], $t[2], $t[3]] };
        return ["$p-top$suffix" => $a, "$p-right$suffix" => $b, "$p-bottom$suffix" => $cc, "$p-left$suffix" => $d];
    }

    public static function isColor(string $t): bool
    {
        return (bool)preg_match('/^(#[0-9a-f]{3,8}|rgba?\(|hsla?\(|transparent$|currentcolor$)/i', $t) || isset(Color::NAMES[strtolower($t)]);
    }

    private static function border(string $v): array
    {
        $w = 'medium'; $s = 'none'; $c = 'currentcolor';
        foreach (self::tokens($v) as $t) {
            $lt = strtolower($t);
            if (in_array($lt, ['none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], true)) $s = $lt;
            elseif (preg_match('/^[\d.]/', $t) || in_array($lt, ['thin', 'medium', 'thick'], true)) $w = $t;
            elseif (self::isColor($t)) $c = $t;
        }
        return [$w, $s, $c];
    }

    public static function expand(string $p, string $v): array
    {
        switch ($p) {
            case 'margin': case 'padding': return self::box($p, $v);
            case 'border-width': return self::box('border', $v, '-width');
            case 'border-style': return self::box('border', $v, '-style');
            case 'border-color': return self::box('border', $v, '-color');
            case 'border':
                [$w, $s, $c] = self::border($v); $r = [];
                foreach (['top', 'right', 'bottom', 'left'] as $sd) { $r["border-$sd-width"] = $w; $r["border-$sd-style"] = $s; $r["border-$sd-color"] = $c; }
                return $r;
            case 'border-top': case 'border-right': case 'border-bottom': case 'border-left':
                [$w, $s, $c] = self::border($v);
                return ["$p-width" => $w, "$p-style" => $s, "$p-color" => $c];
            case 'border-radius':
                $t = self::tokens(explode('/', $v)[0]);
                return ['border-radius' => $t[0] ?? '0'];
            case 'background':
                $r = ['background-color' => 'transparent', 'background-image' => 'none'];
                if (preg_match('/((?:linear|radial)-gradient\(.*\)|url\([^)]*\))/i', $v, $m)) { $r['background-image'] = $m[1]; $v = str_replace($m[1], '', $v); }
                foreach (self::tokens($v) as $t) if (self::isColor($t)) $r['background-color'] = $t;
                return $r;
            case 'font':
                $r = [];
                if (preg_match('/^(.*?)(\d[\d.]*(?:px|pt|em|rem|%|mm)|xx-small|x-small|small|medium|large|x-large|xx-large)(?:\s*\/\s*([\d.]+\w*%?))?\s+(.+)$/i', $v, $m)) {
                    foreach (self::tokens($m[1]) as $t) {
                        $lt = strtolower($t);
                        if (in_array($lt, ['italic', 'oblique'], true)) $r['font-style'] = 'italic';
                        elseif (preg_match('/^(bold|bolder|lighter|\d00)$/', $lt)) $r['font-weight'] = $lt;
                    }
                    $r['font-size'] = $m[2]; if (!empty($m[3])) $r['line-height'] = $m[3]; $r['font-family'] = $m[4];
                }
                return $r;
            case 'flex':
                $t = self::tokens($v);
                if (($t[0] ?? '') === 'none') return ['flex-grow' => '0', 'flex-shrink' => '0', 'flex-basis' => 'auto'];
                if (($t[0] ?? '') === 'auto') return ['flex-grow' => '1', 'flex-shrink' => '1', 'flex-basis' => 'auto'];
                $r = ['flex-grow' => '1', 'flex-shrink' => '1', 'flex-basis' => '0%'];
                $nums = array_values(array_filter($t, fn($x) => is_numeric($x)));
                if (isset($nums[0])) $r['flex-grow'] = $nums[0];
                if (isset($nums[1])) $r['flex-shrink'] = $nums[1];
                foreach ($t as $x) if (!is_numeric($x)) $r['flex-basis'] = $x;
                return $r;
            case 'flex-flow':
                $r = [];
                foreach (self::tokens($v) as $t) { if (str_contains($t, 'wrap')) $r['flex-wrap'] = $t; else $r['flex-direction'] = $t; }
                return $r;
            case 'gap': case 'grid-gap':
                $t = self::tokens($v);
                return ['row-gap' => $t[0] ?? '0', 'column-gap' => $t[1] ?? ($t[0] ?? '0')];
            case 'place-items': case 'place-self':
                $t = self::tokens($v); $k = substr($p, 6);
                return ['align-' . $k => $t[0] ?? 'stretch', 'justify-' . $k => $t[1] ?? ($t[0] ?? 'stretch')];
            case 'grid-row-gap': return ['row-gap' => $v];
            case 'grid-column-gap': return ['column-gap' => $v];
            case 'list-style':
                $r = [];
                foreach (self::tokens($v) as $t) if (!str_starts_with($t, 'url') && !in_array($t, ['inside', 'outside'], true)) $r['list-style-type'] = $t;
                return $r;
            case 'page-break-before': case 'page-break-after':
                return [str_replace('page-', '', $p) => $v === 'always' ? 'page' : $v];
            case 'page-break-inside': return ['break-inside' => $v];
            case 'text-decoration-line': return ['text-decoration' => $v];
            case 'overflow-wrap': return ['word-wrap' => $v];
            case 'inset': return self::box('inset', $v);
        }
        return [$p => $v];
    }
}
