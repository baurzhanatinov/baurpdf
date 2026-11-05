<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.4: line metrics, margin collapsing, collapsed-border cell boxes. */
final class V24FeaturesTest extends TestCase
{
    /** @return list<array{float,float,float,float}> */
    private static function rects(string $pdf): array
    {
        preg_match_all('/(-?[\d.]+) (-?[\d.]+) ([\d.]+) ([\d.]+) re\b/', $pdf, $m, PREG_SET_ORDER);
        return array_map(fn($r) => [(float)$r[1], (float)$r[2], (float)$r[3], (float)$r[4]], $m);
    }

    public function testFirstChildMarginCollapsesWithParent(): void
    {
        $a = BaurPDF::html('<div style="margin-top:20pt"><p style="margin:20pt 0">x</p></div><div style="background:#f00;height:10pt"></div>')->compress(false)->toString();
        $b = BaurPDF::html('<div style="margin-top:20pt"><p style="margin:0 0 20pt">x</p></div><div style="background:#f00;height:10pt"></div>')->compress(false)->toString();
        $this->assertValidPdf($a);
        $ya = array_values(array_filter(self::rects($a), fn($r) => abs($r[3] - 10) < .01));
        $yb = array_values(array_filter(self::rects($b), fn($r) => abs($r[3] - 10) < .01));
        $this->assertEqualsWithDelta($yb[0][1], $ya[0][1], 0.05);
    }

    public function testCollapsedBordersShareHalfWidthPerCell(): void
    {
        $css = '<style>table{border-collapse:collapse}td{border:2pt solid #000;padding:0;height:0;line-height:10pt;font-size:8pt}</style>';
        $pdf = BaurPDF::html($css . '<table><tr><td>a</td></tr><tr><td>b</td></tr></table>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        preg_match_all('/1 0 0 1 [\d.]+ ([\d.]+) Tm/', $pdf, $m);
        // content 10pt + half of top + half of bottom border = 12pt row pitch
        $this->assertEqualsWithDelta(12.0, (float)$m[1][0] - (float)$m[1][1], 0.05);
    }

    public function testLineHeightNormalComesFromFontMetrics(): void
    {
        $pdf = BaurPDF::html('<p style="font-size:10pt;margin:0;line-height:normal">a<br>b<br>c</p>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        preg_match('/0 -([\d.]+) Td/', $pdf, $m);
        $pitch = (float)($m[1] ?? 0);
        $this->assertGreaterThan(10.5, $pitch);
        $this->assertLessThan(14.0, $pitch);
        // ascent/descent/lineGap snap to whole CSS pixels (0.75pt), as in Chromium
        $this->assertEqualsWithDelta(0.0, fmod($pitch / 0.75 + 0.5, 1.0) - 0.5, 0.01);
    }
    public function testCollapsedBorderCountsHalfInColumnWidth(): void
    {
        $xs = function (string $b): array {
            $pdf = BaurPDF::html('<table style="border-collapse:collapse;width:auto"><tr><td style="border:' . $b . ';padding:0">a</td><td style="border:' . $b . ';padding:0">b</td></tr></table>')->compress(false)->toString();
            preg_match('/1 0 0 1 ([\d.]+) [\d.]+ Tm/', $pdf, $m1);
            preg_match('/([\d.]+) 0 Td/', $pdf, $m2);
            return [(float)($m1[1] ?? 0), (float)($m1[1] ?? 0) + (float)($m2[1] ?? -999)];
        };
        $a = $xs('0'); $b = $xs('4pt solid #000');
        $this->assertCount(2, $b);
        // each cell owns half of the 4pt shared border on each side: +2 +2 between the two texts
        $this->assertEqualsWithDelta(4.0, ($b[1] - $b[0]) - ($a[1] - $a[0]), 0.05);
    }

    public function testCollapsedSharedBorderSplitsBetweenRows(): void
    {
        $css = '<style>table{border-collapse:collapse}td{padding:0;line-height:10pt;font-size:8pt;border:0}tr.a td{border-bottom:4pt solid #000}</style>';
        $pdf = BaurPDF::html($css . '<table><tr class="a"><td>a</td></tr><tr><td>b</td></tr><tr><td>c</td></tr></table>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        preg_match_all('/1 0 0 1 [\d.]+ ([\d.]+) Tm/', $pdf, $m);
        $y = array_map('floatval', $m[1]);
        // row 1 owns the bottom half (2pt), row 2 the top half of the same border
        $this->assertEqualsWithDelta(14.0, $y[0] - $y[1], 0.05);
    }
}
