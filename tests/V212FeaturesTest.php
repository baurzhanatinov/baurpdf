<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.12: fixed-layout tables of empty columns fill the width once; td height is content-box. */
final class V212FeaturesTest extends TestCase
{
    public function testFixedLayoutEmptyColumnsFitWidth(): void
    {
        $pdf = BaurPDF::html('<style>@page{size:A4;margin:0}body{margin:0}</style><table style="border-collapse:collapse;width:100%;table-layout:fixed"><tr><td>a</td><td>b</td><td>c</td><td>d</td><td>e</td><td>zz</td></tr></table>')->toString();
        $this->assertStringContainsString('zz', $this->text($pdf));
    }

    public function testCellHeightIsContentBox(): void
    {
        $pdf = BaurPDF::html('<table style="border-collapse:separate;border-spacing:0"><tr><td style="height:20pt;padding:5pt;border:0;background:#f00">x</td></tr></table>')->compress(false)->toString();
        $this->assertMatchesRegularExpression('/[\d.]+ [\d.]+ [\d.]+ 30\.00 re/', $pdf);
    }
}
