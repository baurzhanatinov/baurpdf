<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Pdf\AES;
use BaurPDF\Text\Hyphenator;

/** Возможности v2: переносы, Knuth-Plass, tfoot, альбомные таблицы, AES-256, PDF/A-2b, float, колонки, тени. */
final class V2FeaturesTest extends TestCase
{
    private const TEXT = 'Казахстан — государство в Центральной Азии, крупнейшее по площади среди стран без выхода к Мировому океану. ';

    public function testHyphenationRuEnKz(): void
    {
        $this->assertSame('го-су-дар-ство', Hyphenator::hyphenate('государство'));
        $this->assertSame('hy-phen-ation', Hyphenator::hyphenate('hyphenation'));
        $this->assertStringContainsString('-', Hyphenator::hyphenate('қазақстандықтар'));
        $this->assertSame('кот', Hyphenator::hyphenate('кот'), 'короткие слова не переносятся');
    }

    public function testJustifiedTextUsesHyphensAndKeepsAllWords(): void
    {
        $txt = str_repeat(self::TEXT, 12);
        $pdf = BaurPDF::html('<p style="text-align:justify;hyphens:auto;width:60mm" lang="ru">' . $txt . '</p>')->toString();
        $this->assertValidPdf($pdf);
        $t = $this->text($pdf);
        $this->assertSame(12, substr_count(preg_replace('/-\s*\n\s*/u', '', $t), 'Мировому'));
    }

    public function testTfootRepeatsOnEveryPage(): void
    {
        $rows = '';
        for ($i = 1; $i <= 150; $i++) $rows .= "<tr><td>$i</td><td>Позиция $i</td></tr>";
        $pdf = BaurPDF::html("<table><thead><tr><th>#</th><th>Название</th></tr></thead><tbody>$rows</tbody><tfoot><tr><td colspan=2>ПОДВАЛ</td></tr></tfoot></table>")->toString();
        $this->assertValidPdf($pdf);
        $n = $this->pages($pdf);
        $this->assertGreaterThan(2, $n);
        $this->assertSame($n, substr_count($this->text($pdf), 'ПОДВАЛ'));
        $off = BaurPDF::html("<table data-repeat-footer='false'><tbody>$rows</tbody><tfoot><tr><td colspan=2>ПОДВАЛ</td></tr></tfoot></table>")->toString();
        $this->assertSame(1, substr_count($this->text($off), 'ПОДВАЛ'));
    }

    public function testWideTableGoesLandscape(): void
    {
        $h = ''; $r = '';
        for ($c = 0; $c < 16; $c++) { $h .= "<th>Колонка$c</th>"; $r .= "<td>Знач$c</td>"; }
        $pdf = BaurPDF::html("<p>До</p><table><thead><tr>$h</tr></thead><tbody>" . str_repeat("<tr>$r</tr>", 5) . "</tbody></table><p>После</p>")->toString();
        $this->assertValidPdf($pdf);
        $sizes = $this->sizes($pdf);
        $this->assertContains('841x595', $sizes, 'альбомная страница A4');
        $this->assertContains('595x841', $sizes, 'остальные страницы книжные');
        $t = $this->text($pdf);
        for ($c = 0; $c < 16; $c++) $this->assertStringContainsString("Знач$c", $t);
        $off = BaurPDF::html("<table><tr>$r</tr></table>")->autoLandscape(false)->toString();
        $this->assertNotContains('841x595', $this->sizes($off));
    }

    public function testAes256(): void
    {
        $pdf = BaurPDF::html('<p>Секретно AES</p>')->password('user', 'owner')->toString();
        $this->assertStringContainsString('/V 5', $pdf);
        $this->assertStringContainsString('/AESV3', $pdf);
        $this->assertValidPdf($pdf, 'user');
        $this->assertValidPdf($pdf, 'owner');
        $this->assertStringContainsString('Секретно AES', $this->text($pdf, 'user'));
        $rc4 = BaurPDF::html('<p>x</p>')->password('u')->encryption('rc4')->toString();
        $this->assertStringContainsString('/V 2', $rc4);
        $this->assertValidPdf($rc4, 'u');
    }

    public function testAesFips197Vectors(): void
    {
        $save = AES::$pure; AES::$pure = true;
        try {
            $pt = hex2bin('00112233445566778899aabbccddeeff');
            $this->assertSame('69c4e0d86a7b0430d8cdb78070b4c55a', bin2hex(AES::ecbBlock(hex2bin('000102030405060708090a0b0c0d0e0f'), $pt)));
            $this->assertSame('8ea2b7ca516745bfeafc49904b496089', bin2hex(AES::ecbBlock(hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f'), $pt)));
        } finally { AES::$pure = $save; }
    }

    public function testPdfA2b(): void
    {
        $pdf = BaurPDF::html('<html lang="ru"><head><title>Архив</title></head><body><p>Документ <a href="https://example.com">ссылка</a></p></body></html>')->pdfa()->deterministic()->toString();
        $this->assertValidPdf($pdf);
        $x = $this->expand($pdf);
        $this->assertStringContainsString('/OutputIntents', $x);
        $this->assertStringContainsString('/GTS_PDFA1', $x);
        $this->assertStringContainsString('<pdfaid:part>2</pdfaid:part>', $pdf);
        $this->assertStringContainsString('<pdfaid:conformance>B</pdfaid:conformance>', $pdf);
        $this->assertStringNotContainsString('/Encrypt', BaurPDF::html('<p>x</p>')->pdfa()->password('x')->toString(), 'PDF/A запрещает шифрование');
    }

    public function testFloatWrapsText(): void
    {
        $pdf = BaurPDF::html('<div style="float:right;width:50mm;background:#eef">ВРЕЗКА</div><p>' . str_repeat(self::TEXT, 4) . '</p><p style="clear:both">ПОСЛЕ</p>')->toString();
        $this->assertValidPdf($pdf);
        $t = $this->text($pdf);
        $this->assertMatchesRegularExpression('/\S.*ВРЕЗКА/u', $t, 'текст стоит на одной строке с врезкой');
        $this->assertStringContainsString('ПОСЛЕ', $t);
    }

    public function testColumnsKeepAllText(): void
    {
        $txt = '';
        for ($i = 1; $i <= 60; $i++) $txt .= "слово$i ";
        $pdf = BaurPDF::html('<div style="columns:3;column-rule:1px solid #ccc"><p>' . $txt . '</p></div>')->toString();
        $this->assertValidPdf($pdf);
        $t = $this->text($pdf);
        for ($i = 1; $i <= 60; $i++) $this->assertStringContainsString("слово$i", $t);
        $this->assertSame(1, $this->pages($pdf));
    }

    public function testBoxShadowRendersAndIsDeterministic(): void
    {
        $html = '<div style="box-shadow:0 4px 12px rgba(0,0,0,.35), 0 0 0 2px #36c;padding:10px">Тень</div>';
        $a = BaurPDF::html($html)->deterministic()->toString();
        $this->assertValidPdf($a);
        $this->assertSame($a, BaurPDF::html($html)->deterministic()->toString());
        $this->assertGreaterThan(strlen(BaurPDF::html('<div style="padding:10px">Тень</div>')->deterministic()->toString()), strlen($a));
    }

    public function testMemoryOnLongText(): void
    {
        $html = str_repeat('<p style="text-align:justify">' . str_repeat(self::TEXT, 8) . '</p>', 60);
        if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage(); // иначе пик — от предыдущих тестов процесса
        $before = memory_get_usage();
        $pdf = BaurPDF::html($html)->toString();
        $this->assertValidPdf($pdf);
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $before + 1);
    }

    /** Распаковать объектные потоки через qpdf, чтобы проверить словари. */
    private function expand(string $pdf): string
    {
        return self::hasTool('qpdf') ? (string)self::tool('qpdf', '--qdf --object-streams=disable', $pdf, '-') : self::inflate($pdf);
    }

    /** @return list<string> размеры страниц «ШxВ» (pt, округлённо) */
    private function sizes(string $pdf): array
    {
        return $this->pageSizes($pdf);
    }
}
