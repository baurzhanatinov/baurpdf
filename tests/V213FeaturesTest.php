<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.13: UA origin below author rules; list type attribute. */
final class V213FeaturesTest extends TestCase
{
    private function pdfText(string $html): string
    {
        return $this->text(BaurPDF::html($html)->toString(), null, '');
    }

    public function testOlTypeAttribute(): void
    {
        $t = $this->pdfText('<ol><li>x<ol type="a"><li>y</li><li>z</li></ol></li></ol><ol type="I"><li>q</li></ol>');
        $this->assertStringContainsString('b. z', $t);
        $this->assertStringContainsString('I. q', $t);
    }

    public function testAuthorTypeSelectorBeatsMoreSpecificUaRule(): void
    {
        // UA: "ol ol {margin-top:0}" (specificity 2) must lose to author "ol {margin-top:40pt}" (specificity 1)
        $pdf = BaurPDF::html('<style>ol{margin:40pt 0 0;padding:0;list-style:none}body{margin:0}</style><ol><li>a<ol><li>NESTED</li></ol></li></ol>')->compress(false)->toString();
        $xml = $this->text($pdf, null, '-bbox');
        preg_match('/yMin="([\d.]+)"[^>]*>a</', $xml, $a); preg_match('/yMin="([\d.]+)"[^>]*>NESTED</', $xml, $b);
        $this->assertGreaterThan(45.0, (float)$b[1] - (float)$a[1]);
    }

    public function testPresentationalHintLosesToAuthorCss(): void
    {
        $t = $this->pdfText('<style>ol{list-style-type:upper-roman}</style><ol type="a"><li>k</li><li>m</li></ol>');
        $this->assertStringContainsString('II. m', $t);
    }
}
