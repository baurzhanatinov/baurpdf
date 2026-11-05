<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Font\TrueTypeFont;

/** v2.35: GPOS mark-to-mark (mkmk): шадда + огласовка. */
final class V235FeaturesTest extends TestCase
{
    private const NASKH = '/usr/share/fonts/truetype/sand-box/google/Noto Naskh Arabic/NotoNaskhArabic-VariableFont_wght.ttf';

    public function testShaddaFathaStack(): void
    {
        if (!is_file(self::NASKH)) $this->markTestSkipped('Noto Naskh Arabic not installed');
        $f = new TrueTypeFont(self::NASKH);
        $this->assertNotNull($f->markAnchor($f->glyph(0x0651), $f->glyph(0x064E), true), 'fatha attaches to shadda');
        $pdf = BaurPDF::html('<p>اللَّهِ</p>')->fallbackFont(self::NASKH);
        $this->assertValidPdf($pdf->toString());
        $this->assertSame([], $pdf->warnings());
    }
}
