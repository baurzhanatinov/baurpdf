<?php
declare(strict_types=1);
namespace BaurPDF\Text;

/**
 * Словарные переносы по алгоритму Франклина Лианга (паттерны TeX hyph-utf8): ru, en, kk.
 * Язык слова определяется по атрибуту lang или по алфавиту (кириллица → ru, с казахскими буквами → kk, латиница → en).
 */
final class Hyphenator
{
    /** @var array<string, array<string, list<int>>> */
    private static array $patterns = [];
    /** @var array<string, array<string, string>> */
    private static array $exceptions = [];
    /** @var array<string, list<int>> */
    private static array $cache = [];
    private const MIN = ['ru' => [2, 2], 'en' => [2, 3], 'kk' => [2, 2]];

    public static function langFor(string $word, ?string $lang = null): ?string
    {
        if ($lang !== null && $lang !== '') {
            $l = strtolower(substr($lang, 0, 2));
            if (isset(self::MIN[$l])) return $l;
        }
        if (preg_match('/[әғқңөұүһі]/iu', $word)) return 'kk';
        if (preg_match('/\p{Cyrillic}/u', $word)) return 'ru';
        if (preg_match('/^[a-z]+$/i', $word)) return 'en';
        return null;
    }

    /**
     * Позиции (в символах) допустимых переносов внутри слова.
     * @return list<int>
     */
    public static function points(string $word, ?string $lang = null): array
    {
        $len = mb_strlen($word);
        if ($len < 5 || !preg_match('/^\p{L}+$/u', $word)) return [];
        $l = self::langFor($word, $lang);
        if ($l === null) return [];
        $key = $l . ':' . $word;
        if (isset(self::$cache[$key])) return self::$cache[$key];
        if (count(self::$cache) > 20000) self::$cache = [];
        $pats = self::load($l);
        $low = mb_strtolower($word);
        if (isset(self::$exceptions[$l][$low])) {
            $res = []; $pos = 0;
            foreach (explode('-', self::$exceptions[$l][$low]) as $i => $part) { if ($i > 0) $res[] = $pos; $pos += mb_strlen($part); }
            return self::$cache[$key] = $res;
        }
        $chars = mb_str_split('.' . $low . '.');
        $n = count($chars);
        $vals = array_fill(0, $n + 1, 0);
        for ($i = 0; $i < $n; $i++) {
            $s = '';
            for ($j = $i; $j < $n && $j - $i < 10; $j++) {
                $s .= $chars[$j];
                if (isset($pats[$s])) foreach ($pats[$s] as $k => $v) if ($v > $vals[$i + $k]) $vals[$i + $k] = $v;
            }
        }
        [$minL, $minR] = self::MIN[$l];
        $res = [];
        for ($p = $minL; $p <= $len - $minR; $p++) if ($vals[$p + 1] % 2 === 1) $res[] = $p;
        return self::$cache[$key] = $res;
    }

    /** Слово с мягкими переносами (для отладки/тестов): «пере-нос». */
    public static function hyphenate(string $word, ?string $lang = null, string $mark = '-'): string
    {
        $chars = mb_str_split($word); $out = '';
        $pts = array_flip(self::points($word, $lang));
        foreach ($chars as $i => $c) $out .= (isset($pts[$i]) ? $mark : '') . $c;
        return $out;
    }

    /** @return array<string, list<int>> */
    private static function load(string $l): array
    {
        if (isset(self::$patterns[$l])) return self::$patterns[$l];
        $dir = dirname(__DIR__, 2) . '/resources/hyphenation/';
        $map = [];
        $raw = @file_get_contents($dir . $l . '.pat.txt');
        foreach (preg_split('/\s+/u', (string)$raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            $letters = ''; $vals = []; $pos = 0;
            foreach (mb_str_split($p) as $ch) {
                if ($ch >= '0' && $ch <= '9') $vals[$pos] = (int)$ch;
                else { $letters .= $ch; $pos++; }
            }
            $map[$letters] = $vals;
        }
        self::$exceptions[$l] = [];
        if (is_file($dir . $l . '.hyp.txt')) foreach (preg_split('/\s+/u', (string)file_get_contents($dir . $l . '.hyp.txt'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) self::$exceptions[$l][str_replace('-', '', $w)] = $w;
        return self::$patterns[$l] = $map;
    }
}
