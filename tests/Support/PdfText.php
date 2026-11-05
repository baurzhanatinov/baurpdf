<?php
declare(strict_types=1);
namespace BaurPDF\Tests\Support;

/**
 * Извлечение текста из PDF, которые пишет BaurPDF (Identity-H + ToUnicode, объектные потоки, Flate), без poppler.
 * Эмулирует вывод `pdftotext -layout`, `-bbox` и `-bbox-layout` ровно настолько, насколько это нужно тестам.
 */
final class PdfText
{
    /** @var array<int, string> номер объекта → словарь/тело */
    private array $objs = [];
    /** @var array<int, string> номер объекта → раскрытые данные потока */
    private array $streams = [];
    /** @var list<array{w: float, h: float, words: list<array{0: float, 1: float, 2: float, 3: float, 4: string}>}> */
    public array $pages = [];
    /** @var array<int, array{map: array<int, string>, w: array<int, float>}> */
    private array $fontCache = [];

    public function __construct(string $pdf)
    {
        $this->parseObjects($pdf);
        $this->parsePages();
    }

    public static function isEncrypted(string $pdf): bool { return str_contains($pdf, '/Encrypt'); }

    /** Текст как у `pdftotext -layout`: слова строки через пробел, страницы через \f. */
    public function text(int $first = 1, int $last = PHP_INT_MAX): string
    {
        $out = [];
        foreach ($this->pages as $i => $p) {
            if ($i + 1 < $first || $i + 1 > $last) continue;
            $out[] = implode("\n", array_map(fn($l) => implode(' ', array_column($l, 4)), $this->lines($p['words']))) . "\n";
        }
        return implode("\f", $out) . ($out ? "\f" : '');
    }

    /** XML как у `pdftotext -bbox` (или `-bbox-layout`, если $layout): страницы, строки, слова с координатами от верхнего левого угла. */
    public function bbox(bool $layout = false, int $first = 1, int $last = PHP_INT_MAX): string
    {
        $x = "<?xml version=\"1.0\"?>\n<html><body><doc>\n";
        foreach ($this->pages as $i => $p) {
            if ($i + 1 < $first || $i + 1 > $last) continue;
            $x .= sprintf("<page width=\"%.6F\" height=\"%.6F\">\n", $p['w'], $p['h']);
            foreach ($this->lines($p['words']) as $line) {
                if ($layout) {
                    $x0 = min(array_column($line, 0)); $y0 = min(array_column($line, 1)); $x1 = max(array_column($line, 2)); $y1 = max(array_column($line, 3));
                    $x .= sprintf("<line xMin=\"%.6F\" yMin=\"%.6F\" xMax=\"%.6F\" yMax=\"%.6F\">\n", $x0, $y0, $x1, $y1);
                }
                foreach ($line as $w) $x .= sprintf("<word xMin=\"%.6F\" yMin=\"%.6F\" xMax=\"%.6F\" yMax=\"%.6F\">%s</word>\n", $w[0], $w[1], $w[2], $w[3], htmlspecialchars($w[4], ENT_XML1 | ENT_QUOTES, 'UTF-8'));
                if ($layout) $x .= "</line>\n";
            }
            $x .= "</page>\n";
        }
        return $x . "</doc></body></html>\n";
    }

    /** Строки: слова с близкой базовой линией, сверху вниз, слева направо. @return list<list<array>> */
    private function lines(array $words): array
    {
        usort($words, fn($a, $b) => [round($a[1] * 2), $a[0]] <=> [round($b[1] * 2), $b[0]]);
        $lines = []; $cur = []; $y = null;
        foreach ($words as $w) {
            if ($y !== null && abs($w[1] - $y) > 1.0) { $lines[] = $cur; $cur = []; }
            $cur[] = $w; $y = $w[1];
        }
        if ($cur) $lines[] = $cur;
        foreach ($lines as &$l) usort($l, fn($a, $b) => $a[0] <=> $b[0]);
        return $lines;
    }

    private function parseObjects(string $pdf): void
    {
        $off = 0;
        while (preg_match('/(?<![\d])(\d+) 0 obj\s*/', $pdf, $m, PREG_OFFSET_CAPTURE, $off)) {
            $num = (int)$m[1][0]; $start = $m[0][1] + strlen($m[0][0]);
            $eo = strpos($pdf, 'endobj', $start);
            if ($eo === false) break;
            $sp = strpos($pdf, "\nstream\n", $start);
            if ($sp !== false && $sp < $eo) {
                $dict = substr($pdf, $start, $sp - $start);
                preg_match('/\/Length (\d+)/', $dict, $lm);
                $len = (int)($lm[1] ?? 0);
                $data = substr($pdf, $sp + 8, $len);
                if (str_contains($dict, '/FlateDecode')) { $u = @gzuncompress($data); if ($u !== false) $data = $u; }
                $this->objs[$num] = $dict; $this->streams[$num] = $data;
                $eo = strpos($pdf, 'endobj', $sp + 8 + $len) ?: $eo;
            } else $this->objs[$num] = substr($pdf, $start, $eo - $start);
            $off = $eo + 6;
        }
        foreach ($this->objs as $n => $d) { // объектные потоки: заголовок «номер смещение …», затем тела
            if (!str_contains($d, '/ObjStm') || !preg_match('/\/N (\d+)/', $d, $nm) || !preg_match('/\/First (\d+)/', $d, $fm)) continue;
            $s = $this->streams[$n] ?? ''; $first = (int)$fm[1];
            $hdr = preg_split('/\s+/', trim(substr($s, 0, $first))) ?: [];
            for ($i = 0; $i < (int)$nm[1]; $i++) {
                if (!isset($hdr[$i * 2 + 1])) break;
                $on = (int)$hdr[$i * 2]; $oo = (int)$hdr[$i * 2 + 1];
                $end = isset($hdr[$i * 2 + 3]) ? (int)$hdr[$i * 2 + 3] : strlen($s) - $first;
                $this->objs[$on] = substr($s, $first + $oo, $end - $oo);
            }
        }
    }

    private function parsePages(): void
    {
        $root = null;
        foreach ($this->objs as $n => $d) if (preg_match('/\/Type\s*\/Pages\b/', $d) && !str_contains($d, '/Parent')) { $root = $n; break; }
        foreach ($root !== null ? $this->kids($root) : [] as $pn) {
            $d = $this->objs[$pn] ?? '';
            preg_match('/\/MediaBox\s*\[\s*[\d.-]+\s+[\d.-]+\s+([\d.]+)\s+([\d.]+)/', $d, $mb);
            $W = (float)($mb[1] ?? 595); $H = (float)($mb[2] ?? 842);
            $fonts = [];
            if (preg_match('/\/Resources (\d+) 0 R/', $d, $rm) && preg_match_all('/\/(F\d+) (\d+) 0 R/', $this->objs[(int)$rm[1]] ?? '', $fm, PREG_SET_ORDER)) {
                foreach ($fm as $f) $fonts[$f[1]] = $this->font((int)$f[2]);
            }
            $content = preg_match('/\/Contents (\d+) 0 R/', $d, $cm) ? ($this->streams[(int)$cm[1]] ?? '') : '';
            $words = $this->words($content, $fonts, $H);
            // поля форм: текст лежит в appearance-потоке виджета, сдвинутом в его /Rect (как делает pdftotext)
            if (preg_match('/\/Annots\s*\[([^\]]*)\]/', $d, $am)) {
                foreach (preg_split('/\s+/', trim($am[1])) ?: [] as $i => $t) {
                    if ($i % 3 !== 0 || !is_numeric($t)) continue;
                    $ad = $this->objs[(int)$t] ?? '';
                    if (!preg_match('/\/AP\s*<<\s*\/N (\d+) 0 R/', $ad, $ap) || !preg_match('/\/Rect\s*\[\s*([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)\s*\]/', $ad, $rc)) continue;
                    $sd = $this->objs[(int)$ap[1]] ?? ''; $afonts = [];
                    if (preg_match_all('/\/(F\d+) (\d+) 0 R/', $sd, $fm2, PREG_SET_ORDER)) foreach ($fm2 as $f) $afonts[$f[1]] = $this->font((int)$f[2]);
                    foreach ($this->words($this->streams[(int)$ap[1]] ?? '', $afonts, $H, [1, 0, 0, 1, (float)$rc[1], (float)$rc[2]]) as $w) $words[] = $w;
                }
            }
            $this->pages[] = ['w' => $W, 'h' => $H, 'words' => $words];
        }
    }

    /** @return list<int> */
    private function kids(int $n): array
    {
        preg_match('/\/Kids\s*\[([^\]]*)\]/', $this->objs[$n] ?? '', $m);
        $out = [];
        foreach (preg_split('/\s+/', trim($m[1] ?? '')) ?: [] as $i => $t) {
            if ($i % 3 !== 0 || !is_numeric($t)) continue;
            $k = (int)$t;
            $out = array_merge($out, str_contains($this->objs[$k] ?? '', '/Kids') ? $this->kids($k) : [$k]);
        }
        return $out;
    }

    /** @return array{map: array<int, string>, w: array<int, float>} */
    private function font(int $n): array
    {
        if (isset($this->fontCache[$n])) return $this->fontCache[$n];
        $d = $this->objs[$n] ?? ''; $map = []; $widths = [];
        if (preg_match('/\/ToUnicode (\d+) 0 R/', $d, $tm)) {
            $cmap = $this->streams[(int)$tm[1]] ?? '';
            if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $secs)) foreach ($secs[1] as $sec) {
                preg_match_all('/<([0-9A-Fa-f]{4})>\s*<([0-9A-Fa-f]+)>/', $sec, $bf, PREG_SET_ORDER);
                foreach ($bf as $b) $map[hexdec($b[1])] = (string)mb_convert_encoding((string)hex2bin($b[2]), 'UTF-8', 'UTF-16BE');
            }
            if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $secs)) foreach ($secs[1] as $sec) {
                preg_match_all('/<([0-9A-Fa-f]{4})>\s*<([0-9A-Fa-f]{4})>\s*<([0-9A-Fa-f]+)>/', $sec, $br, PREG_SET_ORDER);
                foreach ($br as $b) { $base = hexdec($b[3]); for ($c = hexdec($b[1]); $c <= hexdec($b[2]); $c++) $map[$c] = mb_chr((int)($base + $c - hexdec($b[1]))); }
            }
        }
        if (preg_match('/\/DescendantFonts \[(\d+) 0 R\]/', $d, $dm) && preg_match('/\/W \[(.*?)\]\s*\/CIDToGIDMap/s', $this->objs[(int)$dm[1]] ?? '', $wm)) {
            preg_match_all('/(\d+) \[([\d.]+)\]/', $wm[1], $ws, PREG_SET_ORDER);
            foreach ($ws as $w) $widths[(int)$w[1]] = (float)$w[2];
        }
        return $this->fontCache[$n] = ['map' => $map, 'w' => $widths];
    }

    /** @return list<array{0: float, 1: float, 2: float, 3: float, 4: string}> [xMin, yMin, xMax, yMax, text] */
    private function words(string $c, array $fonts, float $H, array $base = [1, 0, 0, 1, 0, 0]): array
    {
        preg_match_all('/<[0-9A-Fa-f]*>|\[|\]|\/[^\s\[\]<>\/()]+|-?\d*\.?\d+|[A-Za-z\'"*]+/', $c, $m);
        $words = []; $stack = []; $ctm = $base; $tm = $tlm = [1, 0, 0, 1, 0, 0]; $font = null; $size = 0.0; $tc = 0.0; $tl = 0.0;
        $ops = []; $arr = null;
        // всё состояние — по ссылке: Tf/Tc/cm меняют его уже после объявления замыкания
        $show = function (array $items) use (&$words, &$tm, &$ctm, &$font, &$size, &$tc, $H): void {
            $text = ''; [$x0, $y0] = self::apply($tm, $ctm, 0.0, 0.0); $adv = 0.0;
            foreach ($items as $it) {
                if (is_float($it)) { $adv -= $it / 1000 * $size; continue; }
                foreach (str_split($it, 4) as $hex) {
                    if (strlen($hex) < 4) continue;
                    $gid = (int)hexdec($hex);
                    $text .= $font['map'][$gid] ?? '';
                    $adv += ($font['w'][$gid] ?? 500) / 1000 * $size + $tc;
                }
            }
            [$x1] = self::apply($tm, $ctm, $adv, 0.0);
            $tm = self::mul([1, 0, 0, 1, $adv, 0], $tm);
            if ($text === '') return;
            if (preg_match('/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text)) $text = implode('', array_reverse(mb_str_split($text))); // визуальный → логический порядок
            $sc = sqrt(abs($ctm[0] * $ctm[3] - $ctm[1] * $ctm[2])) ?: 1.0;
            $words[] = [min($x0, $x1), $H - $y0 - $size * 0.8 * $sc, max($x0, $x1), $H - $y0 + $size * 0.2 * $sc, $text];
        };
        foreach ($m[0] as $t) {
            if ($t === '[') { $arr = []; continue; }
            if ($t === ']') { $ops[] = $arr; $arr = null; continue; }
            if ($arr !== null) { $arr[] = $t[0] === '<' ? trim($t, '<>') : (float)$t; continue; }
            if ($t[0] === '<' || $t[0] === '/' || preg_match('/^-?\d*\.?\d+$/', $t)) { $ops[] = $t; continue; }
            $num = fn(int $back) => (float)($ops[count($ops) - $back] ?? 0);
            switch ($t) {
                case 'q': $stack[] = $ctm; break;
                case 'Q': $ctm = array_pop($stack) ?? $base; break;
                case 'cm': $ctm = self::mul([$num(6), $num(5), $num(4), $num(3), $num(2), $num(1)], $ctm); break;
                case 'BT': $tm = $tlm = [1, 0, 0, 1, 0, 0]; break;
                case 'Tf': $font = $fonts[ltrim((string)($ops[count($ops) - 2] ?? ''), '/')] ?? ['map' => [], 'w' => []]; $size = $num(1); break;
                case 'Tc': $tc = $num(1); break;
                case 'TL': $tl = $num(1); break;
                case 'Tm': $tm = $tlm = [$num(6), $num(5), $num(4), $num(3), $num(2), $num(1)]; break;
                case 'Td': $tlm = self::mul([1, 0, 0, 1, $num(2), $num(1)], $tlm); $tm = $tlm; break;
                case 'TD': $tl = -$num(1); $tlm = self::mul([1, 0, 0, 1, $num(2), $num(1)], $tlm); $tm = $tlm; break;
                case 'T*': $tlm = self::mul([1, 0, 0, 1, 0, -$tl], $tlm); $tm = $tlm; break;
                case 'Tj': $show([trim((string)end($ops), '<>')]); break;
                case 'TJ': $last = end($ops); if (is_array($last)) $show($last); break;
            }
            $ops = [];
        }
        return $words;
    }

    /** @return array{0: float, 1: float} точка (x, y) текстового пространства в координатах страницы */
    private static function apply(array $tm, array $ctm, float $x, float $y): array
    {
        $m = self::mul($tm, $ctm);
        return [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5]];
    }

    /** Произведение матриц PDF: $a × $b (сначала $a). */
    private static function mul(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2], $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2], $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4], $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }
}
