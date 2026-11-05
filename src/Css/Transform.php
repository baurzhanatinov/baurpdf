<?php
declare(strict_types=1);
namespace BaurPDF\Css;

/**
 * CSS transform → аффинная матрица [a,b,c,d,e,f] (x' = a·x + c·y + e, y' = b·x + d·y + f; ось y — вниз).
 * Поддерживаются translate[X|Y], scale[X|Y], rotate, skew[X|Y], matrix; transform-origin.
 */
final class Transform
{
    /** @return array{0:float,1:float,2:float,3:float,4:float,5:float}|null null — нет преобразования */
    public static function parse(?string $v, float $w, float $h, float $fs): ?array
    {
        $v = trim((string)$v);
        if ($v === '' || strtolower($v) === 'none') return null;
        if (!preg_match_all('/([a-zA-Z0-9]+)\(([^)]*)\)/', $v, $mm, PREG_SET_ORDER)) return null;
        $m = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        foreach ($mm as [, $fn, $args]) {
            $a = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', trim($args)) ?: []), fn($x) => $x !== ''));
            $len = fn(int $i, float $ref) => isset($a[$i]) ? (Length::toPt($a[$i], $ref, $fs) ?? 0.0) : 0.0;
            $num = fn(int $i, float $def) => isset($a[$i]) && is_numeric(rtrim($a[$i], '%')) ? (str_ends_with($a[$i], '%') ? (float)$a[$i] / 100 : (float)$a[$i]) : $def;
            $t = match (strtolower($fn)) {
                'translate' => [1, 0, 0, 1, $len(0, $w), $len(1, $h)],
                'translatex' => [1, 0, 0, 1, $len(0, $w), 0],
                'translatey' => [1, 0, 0, 1, 0, $len(0, $h)],
                'scale' => [$num(0, 1), 0, 0, $num(1, $num(0, 1)), 0, 0],
                'scalex' => [$num(0, 1), 0, 0, 1, 0, 0],
                'scaley' => [1, 0, 0, $num(0, 1), 0, 0],
                'rotate', 'rotatez' => self::rot(self::angle($a[0] ?? '0')),
                'skew' => [1, tan(self::angle($a[1] ?? '0')), tan(self::angle($a[0] ?? '0')), 1, 0, 0],
                'skewx' => [1, 0, tan(self::angle($a[0] ?? '0')), 1, 0, 0],
                'skewy' => [1, tan(self::angle($a[0] ?? '0')), 0, 1, 0, 0],
                'matrix' => count($a) === 6 ? [(float)$a[0], (float)$a[1], (float)$a[2], (float)$a[3], (float)$a[4] * 0.75, (float)$a[5] * 0.75] : null,
                default => null,
            };
            if ($t === null) continue;
            $m = self::mul($m, array_map('floatval', $t));
        }
        foreach ($m as $x) if (!is_finite($x)) return null;
        if ($m === [1.0, 0.0, 0.0, 1.0, 0.0, 0.0]) return null;
        return [(float)$m[0], (float)$m[1], (float)$m[2], (float)$m[3], (float)$m[4], (float)$m[5]];
    }

    /** transform-origin → точка в координатах бокса. @return array{0:float,1:float} */
    public static function origin(?string $v, float $w, float $h, float $fs): array
    {
        $p = preg_split('/\s+/', strtolower(trim((string)($v ?: '50% 50%')))) ?: [];
        if (count($p) === 1) $p[] = in_array($p[0], ['top', 'bottom'], true) ? 'center' : 'center';
        if (in_array($p[0], ['top', 'bottom'], true) || in_array($p[1], ['left', 'right'], true)) [$p[0], $p[1]] = [$p[1], $p[0]];
        $one = fn(string $t, float $ref) => match ($t) { 'left', 'top' => 0.0, 'right', 'bottom' => $ref, 'center' => $ref / 2, default => Length::toPt($t, $ref, $fs) ?? $ref / 2 };
        return [$one($p[0], $w), $one($p[1], $h)];
    }

    private static function angle(string $s): float
    {
        $s = strtolower(trim($s)); $f = (float)$s;
        if (str_ends_with($s, 'grad')) return deg2rad($f * 0.9);
        if (str_ends_with($s, 'rad')) return $f;
        if (str_ends_with($s, 'turn')) return $f * 2 * M_PI;
        return deg2rad($f);
    }

    /** @return array{0:float,1:float,2:float,3:float,4:float,5:float} */
    private static function rot(float $r): array { return [cos($r), sin($r), -sin($r), cos($r), 0.0, 0.0]; }

    /** A·B (сначала B, затем A). @return array{0:float,1:float,2:float,3:float,4:float,5:float} */
    public static function mul(array $A, array $B): array
    {
        return [$A[0] * $B[0] + $A[2] * $B[1], $A[1] * $B[0] + $A[3] * $B[1], $A[0] * $B[2] + $A[2] * $B[3], $A[1] * $B[2] + $A[3] * $B[3],
            $A[0] * $B[4] + $A[2] * $B[5] + $A[4], $A[1] * $B[4] + $A[3] * $B[5] + $A[5]];
    }
}
