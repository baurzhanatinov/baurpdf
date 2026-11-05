<?php
declare(strict_types=1);
namespace BaurPDF\Text;

/**
 * Упрощённый двунаправленный текст (подмножество UAX #9) и контекстное формирование арабского письма
 * через Unicode Presentation Forms (FB50–FEFF): изолированная/конечная/начальная/срединная формы + лигатуры лам-алиф.
 * Порядок: логический → визуальный на уровне слова (shapeWord) и строки (reorderLine).
 */
final class Bidi
{
    /** буква => [isolated, final, initial, medial]; без initial/medial — соединяется только справа */
    private const FORMS = [
        0x0621 => [0xFE80], 0x0622 => [0xFE81, 0xFE82], 0x0623 => [0xFE83, 0xFE84], 0x0624 => [0xFE85, 0xFE86], 0x0625 => [0xFE87, 0xFE88],
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], 0x0627 => [0xFE8D, 0xFE8E], 0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], 0x0629 => [0xFE93, 0xFE94],
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98], 0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], 0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4], 0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], 0x062F => [0xFEA9, 0xFEAA], 0x0630 => [0xFEAB, 0xFEAC],
        0x0631 => [0xFEAD, 0xFEAE], 0x0632 => [0xFEAF, 0xFEB0], 0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], 0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC], 0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], 0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8], 0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], 0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4], 0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], 0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0], 0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], 0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC], 0x0648 => [0xFEED, 0xFEEE], 0x0649 => [0xFEEF, 0xFEF0], 0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
        0x067E => [0xFB56, 0xFB57, 0xFB58, 0xFB59], 0x0686 => [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D], 0x0698 => [0xFB8A, 0xFB8B],
        0x06A9 => [0xFB8E, 0xFB8F, 0xFB90, 0xFB91], 0x06AF => [0xFB92, 0xFB93, 0xFB94, 0xFB95], 0x06CC => [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF],
    ];
    /** лам + алиф-вариант => [isolated, final] */
    private const LAM_ALEF = [0x0622 => [0xFEF5, 0xFEF6], 0x0623 => [0xFEF7, 0xFEF8], 0x0625 => [0xFEF9, 0xFEFA], 0x0627 => [0xFEFB, 0xFEFC]];
    private const MIRROR = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '<' => '>', '>' => '<', '«' => '»', '»' => '«'];

    /** @var array<int, int|list<int>>|null форма представления => базовые символы (для ToUnicode) */
    private static ?array $base = null;

    public static function hasRtl(string $s): bool
    {
        return preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $s) === 1;
    }

    public static function hasLtr(string $s): bool
    {
        return preg_match('/[A-Za-z0-9\x{00C0}-\x{024F}\x{0370}-\x{058F}\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{3040}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $s) === 1;
    }

    private static function transparent(int $cp): bool
    {
        return ($cp >= 0x064B && $cp <= 0x065F) || $cp === 0x0670 || ($cp >= 0x06D6 && $cp <= 0x06ED);
    }

    private static function dual(int $cp): bool { return $cp === 0x0640 || (isset(self::FORMS[$cp]) && count(self::FORMS[$cp]) === 4); }
    private static function joins(int $cp): bool { return $cp === 0x0640 || (isset(self::FORMS[$cp]) && count(self::FORMS[$cp]) >= 2); }

    /**
     * Контекстные формы арабских букв (логический порядок сохраняется).
     * @param callable(int):bool $has есть ли глиф в шрифте — иначе остаётся базовая буква
     */
    public static function shape(string $s, callable $has): string
    {
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $s)) return $s;
        /** @var list<int> $cps */
        $cps = array_map(fn(string $c): int => (int)mb_ord($c), mb_str_split($s));
        $n = count($cps); $out = [];
        for ($i = 0; $i < $n; $i++) {
            $c = $cps[$i];
            if (!isset(self::FORMS[$c])) { $out[] = $c; continue; }
            $p = $i - 1; while ($p >= 0 && self::transparent($cps[$p])) $p--;
            $prev = $p >= 0 && self::dual($cps[$p]);
            $q = $i + 1; while ($q < $n && self::transparent($cps[$q])) $q++;
            if ($c === 0x0644 && $q < $n && isset(self::LAM_ALEF[$cps[$q]])) {
                $lig = self::LAM_ALEF[$cps[$q]][$prev ? 1 : 0];
                if ($has($lig)) { for ($k = $i + 1; $k < $q; $k++) $out[] = $cps[$k]; $out[] = $lig; $i = $q; continue; }
            }
            $next = self::dual($c) && $q < $n && self::joins($cps[$q]);
            $f = self::FORMS[$c];
            $form = $prev && $next ? ($f[3] ?? $f[0]) : ($prev ? ($f[1] ?? $f[0]) : ($next ? ($f[2] ?? $f[0]) : $f[0]));
            $out[] = $has($form) ? $form : $c;
        }
        return implode('', array_map(fn(int $c): string => (string)mb_chr($c), $out));
    }

    /** В RTL-абзаце нейтральная пунктуация на краях LTR-слова получает уровень абзаца (W/N-правила): «1.» → «.1». */
    public static function rtlEdges(string $s): string
    {
        if (!preg_match('/^([^\p{L}\p{N}]*)(.*?)([^\p{L}\p{N}]*)$/su', $s, $m) || ($m[1] === '' && $m[3] === '')) return $s;
        $mir = fn(string $t): string => implode('', array_map(fn($c) => self::MIRROR[$c] ?? $c, array_reverse(mb_str_split($t))));
        return $mir($m[3]) . $m[2] . $mir($m[1]);
    }

    /** Визуальный порядок символов слова: RTL-участки разворачиваются, латиница/цифры — нет. */
    public static function visualWord(string $s, bool $rtlBase): string
    {
        if (!self::hasRtl($s)) return $s;
        $runs = []; $cur = ''; $curL = null;
        foreach (mb_str_split($s) as $ch) {
            $l = self::hasLtr($ch) ? true : (self::hasRtl($ch) ? false : null);
            if ($l === null) $l = $curL ?? false; // нейтральный в начале слова — уровень RTL
            if ($curL !== null && $l !== $curL) { $runs[] = [$curL, $cur]; $cur = ''; }
            $curL = $l; $cur .= $ch;
        }
        if ($cur !== '') $runs[] = [$curL, $cur];
        // нейтральные символы на краях слова получают направление абзаца (R)
        $vis = [];
        foreach (array_reverse($runs) as [$ltr, $t]) $vis[] = $ltr ? $t : self::reverseRtl($t);
        return implode('', $vis);
    }

    private static function reverseRtl(string $t): string
    {
        $chs = array_reverse(mb_str_split($t));
        // диакритики должны следовать за базой после разворота
        $out = []; $marks = [];
        foreach ($chs as $ch) {
            if (self::transparent(mb_ord($ch))) { $marks[] = $ch; continue; }
            $out[] = self::MIRROR[$ch] ?? $ch;
            if ($marks) { foreach (array_reverse($marks) as $m) $out[] = $m; $marks = []; }
        }
        foreach ($marks as $m) $out[] = $m;
        return implode('', $out);
    }

    /**
     * Порядок элементов строки (UAX #9 L2): уровни R=1, L=2 в RTL-абзаце; L=0, R=1 в LTR.
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function reorderLine(array $items, bool $rtl): array
    {
        $n = count($items);
        if ($n < 2) return $items;
        $lv = [];
        foreach ($items as $i => $it) {
            $t = $it['t'] === 'w' && is_string($it['text'] ?? null) ? $it['text'] : '';
            if ($t !== '' && !empty($it['rtl'])) $lv[$i] = 1;
            elseif ($t !== '' && self::hasLtr($t)) $lv[$i] = $rtl ? 2 : 0;
            else $lv[$i] = null;
        }
        $base = $rtl ? 1 : 0;
        for ($i = 0; $i < $n; $i++) {
            if ($lv[$i] !== null) continue;
            $p = $i - 1; while ($p >= 0 && $lv[$p] === null) $p--;
            $q = $i + 1; while ($q < $n && $lv[$q] === null) $q++;
            $a = $p >= 0 ? $lv[$p] : $base; $b = $q < $n ? $lv[$q] : $base;
            $lv[$i] = $a === $b ? $a : $base;
        }
        $max = max($lv);
        if ($max === 0) return $items;
        $idx = range(0, $n - 1);
        for ($L = $max; $L >= 1; $L--) {
            for ($i = 0; $i < $n;) {
                if ($lv[$idx[$i]] < $L) { $i++; continue; }
                $j = $i; while ($j < $n && $lv[$idx[$j]] >= $L) $j++;
                $seg = array_reverse(array_slice($idx, $i, $j - $i));
                array_splice($idx, $i, $j - $i, $seg);
                $i = $j;
            }
        }
        $out = [];
        foreach ($idx as $k) $out[] = $items[$k];
        return $out;
    }

    /** База формы представления для ToUnicode (копирование текста). @return list<int>|null */
    /** @return array<int,list<int>> базовая буква => [isol, fina, init, medi] (Unicode Presentation Forms-B) */
    public static function forms(): array { return self::FORMS; }
    /** @return array<int,list<int>> алиф => [лам-алиф isol, fina] */
    public static function lamAlef(): array { return self::LAM_ALEF; }

    public static function baseOf(int $cp): ?array
    {
        if ($cp < 0xFB50 || $cp > 0xFEFF) return null;
        if (self::$base === null) {
            self::$base = [];
            foreach (self::FORMS as $b => $fs) foreach ($fs as $f) self::$base[$f] = [$b];
            foreach (self::LAM_ALEF as $a => $fs) foreach ($fs as $f) self::$base[$f] = [$a, 0x0644]; // визуальный порядок: просмотрщики разворачивают RTL-строку целиком
        }
        $r = self::$base[$cp] ?? null;
        return is_array($r) ? $r : null;
    }
}
