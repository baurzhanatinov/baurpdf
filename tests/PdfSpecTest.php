<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

final class PdfSpecTest extends TestCase
{
    public function testObjectStreamsAndToUnicode(): void
    {
        $pdf = BaurPDF::html('<p>Қазақ тілі — Ғ Ү Ұ Ә Ө</p>')->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString('/Type /XRef', $pdf);
        $all = self::inflate($pdf);
        $this->assertStringContainsString('/Identity-H', $all);
        $this->assertMatchesRegularExpression('/\/BaseFont\s*\/[A-Z]{6}\+/', $all, 'сабсет шрифта');
        $this->assertStringContainsString('/ToUnicode', $all);
        if (self::hasTool('pdffonts')) $this->assertMatchesRegularExpression('/[A-Z]{6}\+\S+\s+CID TrueType\s+Identity-H\s+yes\s+yes\s+yes/', (string)self::tool('pdffonts', '', $pdf));
        $this->assertStringContainsString('Қазақ тілі', $this->text($pdf));
    }

    public function testDeterministic(): void
    {
        $h = '<h1>Детерминизм</h1>' . str_repeat('<p>текст</p>', 50);
        $a = BaurPDF::html($h)->deterministic()->toString();
        $b = BaurPDF::html($h)->deterministic()->toString();
        $this->assertSame(md5($a), md5($b));
    }

    public function testEncryption(): void
    {
        $pdf = BaurPDF::html('<p>Секретно</p>')->password('x')->toString();
        $this->assertStringContainsString('/Encrypt', $pdf);
        $this->assertValidPdf($pdf, 'x');
        $this->assertStringContainsString('Секретно', $this->text($pdf, 'x'));
        $this->assertStringNotContainsString('Секретно', $this->text($pdf, 'wrong'));
    }

    public function testTocAndOutline(): void
    {
        $h = '';
        for ($i = 1; $i <= 4; $i++) $h .= "<h1>Глава $i</h1>" . str_repeat('<p>Текст главы.</p>', 60);
        $pdf = BaurPDF::html($h)->toc()->toString();
        $this->assertValidPdf($pdf);
        if (self::hasTool('qpdf')) $this->assertStringContainsString('"/Outlines"', (string)self::tool('qpdf', '--json', $pdf));
        else $this->assertStringContainsString('/Outlines', self::inflate($pdf));
        $this->assertMatchesRegularExpression('/Глава 4\s*\.*\s*\d+/u', $this->text($pdf));
    }

    public function testUncompressedClassicXref(): void
    {
        $pdf = BaurPDF::html('<p>x</p>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString("\nxref\n", $pdf);
    }
}
