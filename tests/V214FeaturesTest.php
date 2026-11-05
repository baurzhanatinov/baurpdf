<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.14: font fallback chains. */
final class V214FeaturesTest extends TestCase
{
    private const KO = '/usr/share/fonts/truetype/sand-box/google/Nanum Gothic/NanumGothic-Regular.ttf';

    public function testExplicitFallbackFontCoversMissingGlyphs(): void
    {
        if (!is_file(self::KO)) $this->markTestSkipped('Nanum Gothic not installed');
        $pdf = BaurPDF::html('<p>보고서 report</p>')->fallbackFont(self::KO)->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString('NanumGothic', $pdf);
        $without = BaurPDF::html('<p>보고서 report</p>')->compress(false)->toString();
        $this->assertStringNotContainsString('NanumGothic', $without);
    }

    public function testFontFamilyListActsAsFallbackChain(): void
    {
        if (!is_file(self::KO)) $this->markTestSkipped('Nanum Gothic not installed');
        $pdf = BaurPDF::html('<p style="font-family:\'PT Sans\', Ko">Привет 보고서</p>')->font('Ko', self::KO)->compress(false)->toString();
        $this->assertStringContainsString('PTSans', $pdf);
        $this->assertStringContainsString('NanumGothic', $pdf);
    }

    public function testUnusableFallbackWarnsAndDoesNotCrash(): void
    {
        $b = BaurPDF::html('<p>漢字</p>')->fallbackFont('/nonexistent/x.ttf');
        $this->assertValidPdf($b->toString());
        $this->assertNotEmpty(array_filter($b->warnings(), fn($w) => str_contains($w, 'Fallback font')));
    }
}
