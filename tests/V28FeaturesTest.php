<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Layout\Paginator;

/** v2.8: widows/orphans at page and column breaks. */
final class V28FeaturesTest extends TestCase
{
    public function testLineBreakAtHonoursWidowsAndOrphans(): void
    {
        $atoms = [];
        for ($k = 0; $k < 5; $k++) $atoms[] = ['y' => $k * 10.0, 'h' => 10.0, 'ops' => [], 'pl' => [$k, 5, 2, 2]];
        $this->assertSame(3, Paginator::lineBreakAt($atoms, 4)); // a lone last line pulls one more line along (widows 2)
        $this->assertSame(0, Paginator::lineBreakAt($atoms, 1)); // a lone first line moves with the paragraph (orphans 2)
        $this->assertSame(2, Paginator::lineBreakAt($atoms, 2)); // already fine
        $atoms[4]['pl'] = [4, 5, 1, 1];
        $this->assertSame(4, Paginator::lineBreakAt($atoms, 4)); // widows:1 allows it
    }

    public function testPageBreakLeavesNoWidow(): void
    {
        // fill the page so that exactly one line of the last paragraph would spill onto page 2
        $html = '<style>@page{size:A6;margin:10mm}body{font-size:10pt;line-height:14pt}p{margin:0}</style>'
            . '<div style="height:' . (148 * 72 / 25.4 - 20 * 72 / 25.4 - 3 * 14) . 'pt"></div><p>one<br>two<br>three<br>four</p>';
        $pdf = BaurPDF::html($html)->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $p2 = $this->text($pdf, null, '-f 2 -l 2');
        // without widows the page-2 part would be just "four"; with widows:2 it is "three four"
        $this->assertStringContainsString('three', $p2);
        $this->assertStringContainsString('four', $p2);
        $this->assertStringNotContainsString('two', $p2);
    }

    public function testOrphansAndWidowsAreInherited(): void
    {
        $st = \BaurPDF\Css\StyleResolver::INHERITED;
        $this->assertArrayHasKey('widows', $st);
        $this->assertArrayHasKey('orphans', $st);
    }
}
