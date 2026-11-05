<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.10: percentage heights resolve against the containing block. */
final class V210FeaturesTest extends TestCase
{
    public function testPercentHeightUsesDefiniteParentHeight(): void
    {
        $pdf = BaurPDF::html('<div style="height:14pt"><div style="height:100%;background:#f00"></div></div>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertMatchesRegularExpression('/[\d.]+ [\d.]+ [\d.]+ 14\.00 re/', $pdf);
        $this->assertSame(1, substr_count($pdf, '/Type /Page '));
    }

    public function testPercentHeightWithAutoParentIsAuto(): void
    {
        $pdf = BaurPDF::html('<div><div style="height:100%;background:#f00">x</div></div><div><div style="height:100%">y</div></div>')->compress(false)->toString();
        $this->assertSame(1, substr_count($pdf, '/Type /Page '));
    }
}
