<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.18: схлопывание нижнего отступа последнего ребёнка с родителем. */
final class V218FeaturesTest extends TestCase
{
    /** @return list<float> yMin строк первой страницы */
    private function lineTops(string $pdf): array
    {
        $x = $this->text($pdf, null, '-bbox-layout -f 1 -l 1');
        preg_match_all('/<line xMin="[\d.]+" yMin="([\d.]+)"/', $x, $m);
        return array_map('floatval', $m[1]);
    }

    public function testLastChildBottomMarginCollapsesThroughParent(): void
    {
        $css = '<style>body{margin:0;font:10pt sans-serif}p{margin:0}</style>';
        $gap = function (string $mid) use ($css): float {
            $t = $this->lineTops(BaurPDF::html($css . '<p>A</p>' . $mid . '<p>C</p>')->deterministic()->toString());
            return $t[2] - $t[1];
        };
        $base = $gap('<div style="margin-bottom:10pt"><p>B</p></div>');
        $this->assertEqualsWithDelta($base + 10, $gap('<div style="margin-bottom:10pt"><p style="margin-bottom:20pt">B</p></div>'), 0.6); // max(20,10), не 30
        $this->assertEqualsWithDelta($base + 20, $gap('<div style="margin-bottom:10pt;padding-bottom:0.01pt"><p style="margin-bottom:20pt">B</p></div>'), 0.6); // padding блокирует
        $this->assertEqualsWithDelta($base, $gap('<ol style="margin:0 0 10pt"><li style="margin-bottom:2pt">B</li></ol>'), 0.6);
    }

    public function testImageOnlyLineKeepsStrutDescent(): void
    {
        $css = '<style>body{margin:0;font:10pt sans-serif}</style>';
        $top = fn(string $html): float => $this->lineTops(BaurPDF::html($css . $html . '<p style="margin:0">X</p>')->deterministic()->toString())[0];
        $d = $top('<div><svg width="40" height="40"><rect width="40" height="40"/></svg></div>') - $top('<div style="height:30pt"></div>');
        $this->assertGreaterThan(1.5, $d);  // 30pt картинка + глубина strut (~2.4pt), как в Chromium
        $this->assertLessThan(4.0, $d);
    }
}
