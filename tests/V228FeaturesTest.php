<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Font\TrueTypeFont;

/** v2.28: арабские формы через GSUB для шрифтов без Presentation Forms. */
final class V228FeaturesTest extends TestCase
{
    private const HARMATTAN = '/usr/share/fonts/truetype/sand-box/google/Harmattan/Harmattan-Regular.ttf';

    public function testGsubFormsSynthesized(): void
    {
        if (!is_file(self::HARMATTAN)) $this->markTestSkipped('Harmattan not installed');
        $f = new TrueTypeFont(self::HARMATTAN);
        $base = $f->glyph(0x0644);
        $this->assertGreaterThan(0, $base);
        foreach ([0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0] as $pf) $this->assertGreaterThan(0, $f->glyph($pf), sprintf('U+%04X', $pf));
        $this->assertNotSame($base, $f->glyph(0xFEDF), 'init form differs from base');
        $this->assertGreaterThan(0, $f->glyph(0xFEFB), 'lam-alef ligature via rlig');
    }

    public function testShapedTextExtractsAsBaseLetters(): void
    {
        if (!is_file(self::HARMATTAN)) $this->markTestSkipped('Harmattan not installed');
        $pdf = BaurPDF::html('<p dir="rtl" style="font-family:H">مرحبا بالعالم</p>')->font('H', self::HARMATTAN);
        $s = $pdf->toString();
        $this->assertSame([], $pdf->warnings());
        $this->assertValidPdf($s);
        $t = $this->text($s, null, '');
        if ($t !== '') { $this->assertStringContainsString('مرحبا', $t); }
    }

    public function testFontWithPresentationFormsUnchanged(): void
    {
        $f = new TrueTypeFont(dirname(__DIR__) . '/resources/fonts/DejaVuSans.ttf');
        $this->assertGreaterThan(0, $f->glyph(0xFE8E));
    }
}
