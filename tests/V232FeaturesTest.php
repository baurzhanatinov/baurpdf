<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Font\TrueTypeFont;

/** v2.32: GPOS mark-to-base (feature mark) для арабских огласовок. */
final class V232FeaturesTest extends TestCase
{
    private const NASKH = '/usr/share/fonts/truetype/sand-box/google/Noto Naskh Arabic/NotoNaskhArabic-VariableFont_wght.ttf';

    public function testMarkAnchorsParsedAndApplied(): void
    {
        if (!is_file(self::NASKH)) $this->markTestSkipped('Noto Naskh Arabic not installed');
        $f = new TrueTypeFont(self::NASKH);
        $beh = $f->glyph(0xFE91); $fatha = $f->glyph(0x064E);
        $this->assertTrue($f->isMarkGlyph($fatha));
        $hits = 0; foreach ([0x0628, 0x0633, 0x0645, $beh ? 0xFE91 : 0x0628] as $cp) if ($f->markAnchor($f->glyph($cp), $fatha) !== null) $hits++;
        $this->assertGreaterThan(0, $hits, 'mark-to-base anchors found for beh/seen/meem');
        $pdf = BaurPDF::html('<p>بَسْمِ ٱللَّهِ</p>')->fallbackFont(self::NASKH);
        $s = $pdf->toString();
        $this->assertValidPdf($s);
        $this->assertSame([], $pdf->warnings());
        $plain = BaurPDF::html('<p>بسم الله</p>')->fallbackFont(self::NASKH)->toString();
        $this->assertValidPdf($plain);
    }

    public function testNoMarksNoChange(): void
    {
        $a = BaurPDF::html('<p>Hello, мир</p>')->toString();
        $this->assertValidPdf($a);
        $this->assertStringNotContainsString('<> ', $a);
    }
}
