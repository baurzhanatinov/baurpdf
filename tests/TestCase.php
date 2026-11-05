<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected static function tmp(string $pdf): string
    { $f = tempnam(sys_get_temp_dir(), 'bpt') . '.pdf'; file_put_contents($f, $pdf); return $f; }

    /** Утилита в PATH: `command -v` на Unix, `where` на Windows (там `command` — не команда). */
    protected static function hasTool(string $name): bool
    {
        static $cache = [];
        if (!isset($cache[$name])) {
            $cmd = PHP_OS_FAMILY === 'Windows' ? 'where ' . escapeshellarg($name) . ' 2>NUL' : 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
            $cache[$name] = trim((string)shell_exec($cmd)) !== '';
        }
        return $cache[$name];
    }

    protected function requireTool(string $name): void
    { if (!self::hasTool($name)) $this->markTestSkipped("$name не установлен"); }

    /** Вывод утилиты над PDF-строкой: `$name $args <файл> $after`; null — утилиты нет. */
    protected static function tool(string $name, string $args, string $pdf, string $after = ''): ?string
    {
        if (!self::hasTool($name)) return null;
        $f = self::tmp($pdf);
        $null = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';
        $out = (string)shell_exec(trim("$name $args " . escapeshellarg($f) . " $after") . " $null");
        @unlink($f);
        return $out;
    }

    /** Проверка qpdf --check (если qpdf установлен) + базовая структура. */
    protected function assertValidPdf(string $pdf, ?string $password = null): void
    {
        $this->assertStringStartsWith('%PDF-1.', $pdf);
        $this->assertStringContainsString('%%EOF', substr($pdf, -64));
        if (!self::hasTool('qpdf')) return;
        $f = self::tmp($pdf);
        $pw = $password !== null ? '--password=' . escapeshellarg($password) . ' ' : '';
        exec('qpdf ' . $pw . '--check ' . escapeshellarg($f) . ' 2>&1', $out, $code);
        @unlink($f);
        $this->assertSame(0, $code, "qpdf --check:\n" . implode("\n", $out));
        $this->assertStringContainsString('No syntax or stream encoding errors', implode("\n", $out));
    }

    /**
     * Текст PDF: через pdftotext, если он есть, иначе собственным извлечением (tests/Support/PdfText).
     * Поддерживаются аргументы `-layout`, `-bbox`, `-bbox-layout`, `-f N`, `-l N`; зашифрованный PDF без pdftotext — skip.
     */
    protected function text(string $pdf, ?string $password = null, string $args = '-layout'): string
    {
        if (self::hasTool('pdftotext')) {
            $pw = $password !== null ? '-upw ' . escapeshellarg($password) . ' ' : '';
            return (string)self::tool('pdftotext', $args . ' ' . $pw, $pdf, '-');
        }
        if (Support\PdfText::isEncrypted($pdf)) $this->markTestSkipped('нужен pdftotext для зашифрованного PDF');
        $first = preg_match('/-f (\d+)/', $args, $m) ? (int)$m[1] : 1;
        $last = preg_match('/-l (\d+)/', $args, $m) ? (int)$m[1] : PHP_INT_MAX;
        $t = new Support\PdfText($pdf);
        if (str_contains($args, '-bbox-layout')) return $t->bbox(true, $first, $last);
        if (str_contains($args, '-bbox')) return $t->bbox(false, $first, $last);
        return $t->text($first, $last);
    }

    /** Число страниц: pdfinfo, а без него — подсчёт объектов /Type /Page в раскрытых потоках (чистый PHP). */
    protected function pages(string $pdf): int
    {
        if (self::hasTool('pdfinfo')) {
            preg_match('/Pages:\s+(\d+)/', (string)self::tool('pdfinfo', '', $pdf), $m);
            return (int)($m[1] ?? 0);
        }
        if (str_contains($pdf, '/Encrypt')) $this->markTestSkipped('нужен pdfinfo для зашифрованного PDF');
        return preg_match_all('/\/Type\s*\/Page(?![A-Za-z])/', self::inflate($pdf));
    }

    /** Размеры страниц «ШxВ» в pt: pdfinfo или /MediaBox из раскрытых потоков. */
    protected function pageSizes(string $pdf): array
    {
        if (self::hasTool('pdfinfo')) {
            preg_match_all('/size:\s+([\d.]+) x ([\d.]+)/', (string)self::tool('pdfinfo', '-f 1 -l 999', $pdf), $m, PREG_SET_ORDER);
            return array_map(fn($r) => (int)$r[1] . 'x' . (int)$r[2], $m);
        }
        $sizes = [];
        foreach (preg_split('/\/Type\s*\/Page(?![A-Za-z])/', self::inflate($pdf)) as $i => $chunk) {
            if ($i === 0) continue;
            if (preg_match('/\/MediaBox\s*\[\s*[\d.-]+\s+[\d.-]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $chunk, $mb)) $sizes[] = (int)$mb[1] . 'x' . (int)$mb[2];
            else $sizes[] = '?';
        }
        return $sizes;
    }

    /** Сырой PDF + содержимое всех Flate-потоков (объектные потоки включительно). */
    protected static function inflate(string $pdf): string
    {
        // без регулярок: на многомегабайтных PDF ленивый `(.*?)` упирается в pcre.backtrack_limit и молча отдаёт пустоту
        $all = $pdf; $p = 0;
        while (($s = strpos($pdf, "\nstream\n", $p)) !== false) {
            $e = strpos($pdf, "\nendstream", $s + 8);
            if ($e === false) break;
            $d = @gzuncompress(substr($pdf, $s + 8, $e - $s - 8));
            if ($d !== false) $all .= "\n" . $d;
            $p = $e + 10;
        }
        return $all;
    }
}
