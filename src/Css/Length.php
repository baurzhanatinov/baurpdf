<?php
declare(strict_types=1);
namespace BaurPDF\Css;

/** Длины CSS → pt (1px = 0.75pt). */
final class Length
{
    public static function toPt(?string $v, float $ref = 0, float $fs = 12, ?float $rem = null): ?float
    {
        static $root = 12.0;
        if ($rem !== null) $root = $rem;
        if ($v === null) return null;
        // быстрый путь: разобранные «число+единица» кешируются (длины в стилях сильно повторяются)
        static $parsed = [];
        $p = $parsed[$v] ??= self::parse($v);
        if ($p !== false) {
            [$n, $u] = $p;
            return match ($u) {
                'px', '' => $n * 0.75, 'pt' => $n, 'mm' => $n * 72 / 25.4, 'cm' => $n * 72 / 2.54, 'in' => $n * 72, 'pc' => $n * 12, 'q' => $n * 18 / 25.4,
                'em' => $n * $fs, 'rem' => $n * $root, 'ex', 'ch' => $n * $fs * 0.5, '%', 'vw', 'vh' => $n * $ref / 100, default => null,
            };
        }
        if (count($parsed) > 20000) $parsed = [];
        $v = strtolower(trim($v));
        if ($v === '' || $v === 'auto' || $v === 'none' || $v === 'normal') return null;
        if ($v === '0') return 0.0;
        if (str_starts_with($v, 'calc(')) return self::calc(substr($v, 5, -1), $ref, $fs);
        if (str_starts_with($v, 'min(') || str_starts_with($v, 'max(') || str_starts_with($v, 'clamp(')) {
            $fn = substr($v, 0, (int)strpos($v, '('));
            $args = array_map(fn($a) => self::toPt(trim($a), $ref, $fs), explode(',', substr($v, strlen($fn) + 1, -1)));
            $args = array_filter($args, fn($a) => $a !== null);
            if (!$args) return null;
            return match ($fn) { 'min' => min($args), 'max' => max($args), default => count($args) === 3 ? max($args[0], min($args[1], $args[2])) : reset($args) };
        }
        if (!preg_match('/^(-?[\d.]+(?:e-?\d+)?)\s*([a-z%]*)$/', $v, $m)) {
            return match ($v) { 'thin' => 0.75, 'medium' => 2.25, 'thick' => 3.75, default => null };
        }
        $n = (float)$m[1];
        return match ($m[2]) {
            'px', '' => $n * 0.75, 'pt' => $n, 'mm' => $n * 72 / 25.4, 'cm' => $n * 72 / 2.54, 'in' => $n * 72, 'pc' => $n * 12, 'q' => $n * 18 / 25.4,
            'em' => $n * $fs, 'rem' => $n * $root, 'ex', 'ch' => $n * $fs * 0.5, '%' => $n * $ref / 100,
            'vw' => $n * $ref / 100, 'vh' => $n * $ref / 100, default => null,
        };
    }

    /** @return array{0: float, 1: string}|false */
    private static function parse(string $v): array|false
    {
        if (!preg_match('/^\s*(-?[\d.]+(?:e-?\d+)?)\s*([a-zA-Z%]*)\s*$/', $v, $m)) return false;
        return [(float)$m[1], strtolower($m[2])];
    }

    private static function calc(string $e, float $ref, float $fs): ?float
    {
        $e = (string)preg_replace_callback('/(-?[\d.]+)\s*([a-z%]+)/i', fn($m) => (string)(self::toPt($m[1] . $m[2], $ref, $fs) ?? 0), $e);
        if (!preg_match('#^[\d.\s+\-*/()]+$#', $e)) return null;
        try { return (float)self::evalExpr($e); } catch (\Throwable) { return null; }
    }

    private static function evalExpr(string $e): float
    {
        $tokens = preg_split('/\s*([+\-*\/()])\s*/', trim($e), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $pos = 0;
        $parse = function ($lvl) use (&$parse, &$tokens, &$pos) {
            if ($lvl === 2) {
                $t = $tokens[$pos++] ?? '0';
                if ($t === '(') { $v = $parse(0); $pos++; return $v; }
                if ($t === '-') return -$parse(2);
                return (float)$t;
            }
            $v = $parse($lvl + 1);
            while (isset($tokens[$pos]) && in_array($tokens[$pos], $lvl === 0 ? ['+', '-'] : ['*', '/'], true)) {
                $op = $tokens[$pos++]; $r = $parse($lvl + 1);
                $v = match ($op) { '+' => $v + $r, '-' => $v - $r, '*' => $v * $r, '/' => $r == 0 ? $v : $v / $r, default => $v };
            }
            return $v;
        };
        return $parse(0);
    }
}
