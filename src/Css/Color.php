<?php
declare(strict_types=1);
namespace BaurPDF\Css;

/** Цвета CSS → [r,g,b,a] (0..1). Неизвестное → null. */
final class Color
{
    public const NAMES = [
        'black' => '000000', 'white' => 'ffffff', 'red' => 'ff0000', 'green' => '008000', 'blue' => '0000ff', 'yellow' => 'ffff00', 'orange' => 'ffa500',
        'purple' => '800080', 'gray' => '808080', 'grey' => '808080', 'silver' => 'c0c0c0', 'maroon' => '800000', 'navy' => '000080', 'teal' => '008080',
        'olive' => '808000', 'lime' => '00ff00', 'aqua' => '00ffff', 'cyan' => '00ffff', 'fuchsia' => 'ff00ff', 'magenta' => 'ff00ff', 'pink' => 'ffc0cb',
        'brown' => 'a52a2a', 'gold' => 'ffd700', 'indigo' => '4b0082', 'violet' => 'ee82ee', 'crimson' => 'dc143c', 'coral' => 'ff7f50', 'tomato' => 'ff6347',
        'salmon' => 'fa8072', 'khaki' => 'f0e68c', 'beige' => 'f5f5dc', 'ivory' => 'fffff0', 'lavender' => 'e6e6fa', 'tan' => 'd2b48c', 'skyblue' => '87ceeb',
        'steelblue' => '4682b4', 'royalblue' => '4169e1', 'dodgerblue' => '1e90ff', 'darkblue' => '00008b', 'darkgreen' => '006400', 'darkred' => '8b0000',
        'darkgray' => 'a9a9a9', 'darkgrey' => 'a9a9a9', 'lightgray' => 'd3d3d3', 'lightgrey' => 'd3d3d3', 'lightblue' => 'add8e6', 'lightgreen' => '90ee90',
        'whitesmoke' => 'f5f5f5', 'gainsboro' => 'dcdcdc', 'dimgray' => '696969', 'slategray' => '708090', 'seagreen' => '2e8b57', 'forestgreen' => '228b22',
        'firebrick' => 'b22222', 'chocolate' => 'd2691e', 'orangered' => 'ff4500', 'darkorange' => 'ff8c00', 'turquoise' => '40e0d0', 'aliceblue' => 'f0f8ff',
        'honeydew' => 'f0fff0', 'mintcream' => 'f5fffa', 'ghostwhite' => 'f8f8ff', 'linen' => 'faf0e6', 'snow' => 'fffafa', 'rebeccapurple' => '663399',
    ];
    private static array $cache = [];

    public static function parse(?string $v): ?array
    {
        if ($v === null) return null;
        $v = strtolower(trim($v));
        if (array_key_exists($v, self::$cache)) return self::$cache[$v];
        return self::$cache[$v] = self::doParse($v);
    }

    private static function doParse(string $v): ?array
    {
        if ($v === '' || $v === 'transparent' || $v === 'none') return null;
        if (isset(self::NAMES[$v])) $v = '#' . self::NAMES[$v];
        if ($v[0] === '#') {
            $h = substr($v, 1);
            if (!ctype_xdigit($h)) return null;
            if (strlen($h) === 3 || strlen($h) === 4) $h = implode('', array_map(fn($c) => $c . $c, str_split($h)));
            if (strlen($h) !== 6 && strlen($h) !== 8) return null;
            $a = strlen($h) === 8 ? hexdec(substr($h, 6, 2)) / 255 : 1.0;
            return [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255, $a];
        }
        if (preg_match('/^(rgba?|hsla?)\(([^)]*)\)$/', $v, $m)) {
            $p = preg_split('/[\s,\/]+/', trim($m[2])) ?: [];
            if (count($p) < 3) return null;
            $a = isset($p[3]) ? (str_ends_with($p[3], '%') ? (float)$p[3] / 100 : (float)$p[3]) : 1.0;
            if ($m[1][0] === 'r') {
                $c = array_map(fn($x) => str_ends_with($x, '%') ? (float)$x / 100 : (float)$x / 255, array_slice($p, 0, 3));
                return [...array_map(fn($x) => max(0, min(1, $x)), $c), max(0, min(1, $a))];
            }
            $h = fmod((float)$p[0], 360) / 360; $s = (float)$p[1] / 100; $l = (float)$p[2] / 100;
            if ($s == 0) return [$l, $l, $l, $a];
            $q = $l < .5 ? $l * (1 + $s) : $l + $s - $l * $s; $pp = 2 * $l - $q;
            $f = function ($t) use ($pp, $q) { if ($t < 0) $t += 1; if ($t > 1) $t -= 1; if ($t < 1 / 6) return $pp + ($q - $pp) * 6 * $t; if ($t < .5) return $q; if ($t < 2 / 3) return $pp + ($q - $pp) * (2 / 3 - $t) * 6; return $pp; };
            return [$f($h + 1 / 3), $f($h), $f($h - 1 / 3), $a];
        }
        return null;
    }

    public static function pdf(array $c, bool $stroke = false): string
    {
        return sprintf('%.3F %.3F %.3F %s', $c[0], $c[1], $c[2], $stroke ? 'RG' : 'rg');
    }

    public static function mix(array $a, array $b, float $t): array
    {
        return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t, $a[3] + ($b[3] - $a[3]) * $t];
    }
}
