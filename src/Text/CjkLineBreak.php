<?php
declare(strict_types=1);
namespace BaurPDF\Text;

/**
 * CJK line break opportunities (a deliberately documented subset of Unicode UAX #14).
 *
 * Splits at ideographic script boundaries without inserting text glyphs.
 * No break immediately after an opening bracket or before a closing mark.
 * Non-CJK Latin/digit runs stay intact; grapheme clusters remain indivisible.
 * This is NOT a complete UAX #14 implementation and does not do font fallback.
 */
final class CjkLineBreak
{
    private const CJK = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\x{3000}-\x{303F}\x{FF00}-\x{FFEF}]/u';
    private const OPEN = '（(［[｛{〈《「『【〔〖〘〚“‘«';
    private const CLOSE = '）)］]｝}〉》」』】〕〗〙〛”’»、。，．！？：；!?,.:;％‰';

    public static function contains(string $text): bool
    {
        return preg_match(self::CJK, $text) === 1;
    }

    /** @return list<string> */
    public static function segments(string $text): array
    {
        if ($text === '') return [];
        if (!self::contains($text)) return [$text];
        $ok = preg_match_all('/\X/u', $text, $matches);
        if ($ok === false || $ok === 0) return [$text];
        $chars = $matches[0];
        $n = count($chars);
        if ($n < 2) return [$text];
        $out = [];
        $buf = $chars[0];
        for ($i = 1; $i < $n; ++$i) {
            $left = $chars[$i - 1];
            $right = $chars[$i];
            // A boundary is allowed only if at least one side is CJK;
            // CJK brackets and sentence punctuation must remain attached.
            $opener = str_contains(self::OPEN, $left);
            $closer = str_contains(self::CLOSE, $right);
            $break = !$opener && !$closer && (self::contains($left) || self::contains($right));
            if ($break) { $out[] = $buf; $buf = ''; }
            $buf .= $right;
        }
        $out[] = $buf;
        return $out;
    }
}
