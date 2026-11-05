<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.11: form controls as block / flex items are drawn as controls. */
final class V211FeaturesTest extends TestCase
{
    public function testInputAsFlexItemKeepsHeightAndValue(): void
    {
        $pdf = BaurPDF::html('<div style="display:flex"><label style="width:100pt">A</label><input style="flex:1" name="q" value="hello"></div>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString('/FT /Tx', $pdf);
        $this->assertStringContainsString('(hello)', $pdf);
        $this->assertMatchesRegularExpression('/\/Rect \[\s*[\d.]+ ([\d.]+) [\d.]+ ([\d.]+)\s*\]/', $pdf);
        preg_match('/\/FT \/Tx.*?\/Rect \[\s*([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+)/s', $pdf, $r);
        if (!$r) preg_match('/\/Rect \[\s*([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+)/', $pdf, $r);
        $this->assertGreaterThan(8.0, (float)$r[4] - (float)$r[2]);
    }

    public function testDisplayBlockInput(): void
    {
        $pdf = BaurPDF::html('<input style="display:block;width:200pt" name="b" value="v">')->compress(false)->toString();
        $this->assertStringContainsString('/FT /Tx', $pdf);
    }
}
