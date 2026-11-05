<?php
declare(strict_types=1);
namespace BaurPDF\Css;

/** Толерантный парсер CSS: правила, @media (print/all), @page, @font-face. Мусор пропускается. */
final class CssParser
{
    /** @return array{rules:array, page:array, fonts:array} */
    public static function parse(string $css, string $baseDir = ''): array
    {
        $css = preg_replace('#/\*.*?(\*/|$)#s', '', $css) ?? '';
        $out = ['rules' => [], 'page' => [], 'fonts' => []];
        self::block($css, $out, $baseDir);
        return $out;
    }

    private static function block(string $css, array &$out, string $baseDir): void
    {
        $i = 0; $n = strlen($css);
        while ($i < $n) {
            $open = strpos($css, '{', $i);
            if ($open === false) break;
            $prelude = trim(substr($css, $i, $open - $i));
            // @import/@charset без блока
            while (preg_match('/^@(import|charset|namespace)[^;]*;\s*/i', $prelude, $m)) $prelude = trim(substr($prelude, strlen($m[0])));
            $end = self::matchBrace($css, $open);
            $body = substr($css, $open + 1, $end - $open - 1);
            $i = $end + 1;
            if ($prelude === '') continue;
            if ($prelude[0] === '@') {
                $lp = strtolower($prelude);
                if (str_starts_with($lp, '@media')) {
                    $q = trim(substr($lp, 6));
                    if ($q === '' || preg_match('/\b(print|all)\b/', $q) || !preg_match('/\b(screen|speech)\b/', $q)) self::block($body, $out, $baseDir);
                } elseif (str_starts_with($lp, '@page')) {
                    $sel = trim(substr($lp, 5));
                    $out['page'][$sel === '' ? '*' : $sel] = array_merge($out['page'][$sel === '' ? '*' : $sel] ?? [], self::declarations((string)preg_replace('/@[a-z-]+\s*\{[^}]*\}/i', '', $body)));
                } elseif (str_starts_with($lp, '@font-face')) {
                    $d = self::declarations($body);
                    if (isset($d['font-family'], $d['src'])) $out['fonts'][] = ['family' => strtolower(trim($d['font-family'][0], " \"'")), 'src' => $d['src'][0], 'weight' => $d['font-weight'][0] ?? 'normal', 'style' => $d['font-style'][0] ?? 'normal', 'base' => $baseDir];
                } elseif (str_starts_with($lp, '@supports') || str_starts_with($lp, '@layer')) {
                    self::block($body, $out, $baseDir);
                }
                continue;
            }
            $decls = self::declarations($body);
            if (!$decls) continue;
            foreach (self::splitSelectors($prelude) as $s) {
                $sel = new Selector($s);
                if ($sel->valid) $out['rules'][] = ['sel' => $sel, 'decls' => $decls];
            }
        }
    }

    private static function matchBrace(string $s, int $open): int
    {
        $d = 0; $n = strlen($s); $q = null;
        for ($i = $open; $i < $n; $i++) {
            $c = $s[$i];
            if ($q) { if ($c === $q && $s[$i - 1] !== '\\') $q = null; continue; }
            if ($c === '"' || $c === "'") { $q = $c; continue; }
            if ($c === '{') $d++;
            elseif ($c === '}') { $d--; if ($d === 0) return $i; }
        }
        return $n;
    }

    public static function splitSelectors(string $s): array
    {
        $r = []; $d = 0; $cur = '';
        foreach (str_split($s) as $c) {
            if ($c === '(' || $c === '[') $d++;
            if ($c === ')' || $c === ']') $d--;
            if ($c === ',' && $d === 0) { $r[] = trim($cur); $cur = ''; continue; }
            $cur .= $c;
        }
        $r[] = trim($cur);
        return array_filter($r, fn($x) => $x !== '');
    }

    /** "a:b; c:d !important" → ['a'=>['b',false], ...] с раскрытием шорткатов. */
    public static function declarations(string $body): array
    {
        $out = [];
        $parts = self::splitTop($body, ';');
        foreach ($parts as $p) {
            $c = strpos($p, ':');
            if ($c === false) continue;
            $prop = strtolower(trim(substr($p, 0, $c)));
            $val = trim(substr($p, $c + 1));
            if ($prop === '' || $val === '' || !preg_match('/^(--[\w-]+|-?[a-z][a-z0-9-]*)$/', $prop)) continue;
            $imp = false;
            if (preg_match('/\s*!\s*important\s*$/i', $val, $m)) { $imp = true; $val = trim(substr($val, 0, -strlen($m[0]))); }
            if (str_contains($val, 'var(') && !str_starts_with($prop, '--')) { unset($out[$prop]); $out[$prop] = [$val, $imp]; continue; } // раскроем после подстановки var()
            foreach (Shorthand::expand($prop, $val) as $k => $v) $out[$k] = [$v, $imp];
        }
        return $out;
    }

    /** Split on $sep outside quotes and (nested) parentheses. @return list<string> */
    public static function splitTop(string $s, string $sep): array
    {
        if (strpbrk($s, '("\'') === false) return $sep === "" ? [$s] : explode($sep, $s);
        $out = []; $cur = ''; $depth = 0; $q = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $ch = $s[$i];
            if ($q !== '') { $cur .= $ch; if ($ch === '\\' && $i + 1 < $n) { $cur .= $s[++$i]; } elseif ($ch === $q) { $q = ''; } continue; }
            if ($ch === '"' || $ch === "'") { $q = $ch; $cur .= $ch; continue; }
            if ($ch === '(') $depth++;
            elseif ($ch === ')' && $depth > 0) $depth--;
            if ($ch === $sep && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        $out[] = $cur;
        return $out;
    }
}
