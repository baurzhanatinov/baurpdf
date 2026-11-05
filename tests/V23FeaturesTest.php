<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Css\CssParser;

/** v2.3: Chromium-parity fixes found by the side-by-side scorecard. */
final class V23FeaturesTest extends TestCase
{
    /** @return list<string> "x y w h re" rectangles of an uncompressed PDF */
    private static function rects(string $pdf): array
    {
        preg_match_all('/(-?[\d.]+) (-?[\d.]+) ([\d.]+) ([\d.]+) re\b/', $pdf, $m, PREG_SET_ORDER);
        return array_map(fn($r) => sprintf('%.1f %.1f %.1f %.1f', $r[1], $r[2], $r[3], $r[4]), $m);
    }

    public function testNestedParenthesesDoNotSwallowNextDeclaration(): void
    {
        $d = CssParser::declarations('width:calc(var(--w) * 2 - 20pt);height:30pt;content:"a;b"');
        $this->assertSame('calc(var(--w) * 2 - 20pt)', $d['width'][0]);
        $this->assertSame('30pt', $d['height'][0]);
        $this->assertSame('"a;b"', $d['content'][0]);
        $pdf = BaurPDF::html('<style>:root{--w:100pt}div{background:#f00;width:calc(var(--w) * 2 - 20pt);height:30pt}</style><div></div>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertNotEmpty(array_filter(self::rects($pdf), fn($r) => str_ends_with($r, '180.0 30.0')));
    }

    public function testPositionRelativeOffsetsWithoutAffectingFlow(): void
    {
        $base = BaurPDF::html('<div style="background:#000;height:20pt;width:60pt"></div>')->compress(false)->toString();
        $rel = BaurPDF::html('<div style="position:relative;left:30pt;top:10pt;background:#000;height:20pt;width:60pt"></div>')->compress(false)->toString();
        $this->assertValidPdf($rel);
        $this->assertValidPdf($base);
        $this->assertSame(1, preg_match('/1(\.0+)? 0(\.0+)? 0(\.0+)? 1(\.0+)? 30(\.0+)? -10(\.0+)? cm/', $rel), 'translation emitted');
    }

    public function testOverflowHiddenClipsToDefiniteHeight(): void
    {
        $t = str_repeat('Съешь же ещё этих мягких французских булок. ', 6);
        $pdf = BaurPDF::html("<div style=\"width:100pt;height:30pt;overflow:hidden;background:#eee\">$t</div><p>ПОСЛЕ</p>")->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString(' re W n', $pdf);
        $this->assertNotEmpty(array_filter(self::rects($pdf), fn($r) => str_ends_with($r, '100.0 30.0')), 'background keeps the specified height');
        $this->assertStringContainsString('ПОСЛЕ', $this->text($pdf));
    }

    public function testSingleLineFlexAlignsToContainerHeight(): void
    {
        $pdf = BaurPDF::html('<div style="display:flex;align-items:center;height:80pt"><div style="width:40pt;height:20pt;background:#000"></div></div>')->compress(false)->toString();
        $r = array_values(array_filter(self::rects($pdf), fn($x) => str_ends_with($x, '40.0 20.0')));
        $this->assertCount(1, $r);
        $top = (float)explode(' ', $r[0])[1] + 20;
        $this->assertEqualsWithDelta(841.89 - 42.52 - 30, $top, 1.0, 'centred: 30pt from the container top');
    }

    public function testTabsExpandToStops(): void
    {
        $tab = $this->xOf(BaurPDF::html("<pre>a\tb</pre>")->toString(), 'b');
        $four = $this->xOf(BaurPDF::html("<pre>a    b</pre>")->toString(), 'b');
        $seven = $this->xOf(BaurPDF::html("<pre>a       b</pre>")->toString(), 'b');
        $this->assertGreaterThan($four + 1, $tab, 'tab is wider than the old 4-space expansion');
        $this->assertEqualsWithDelta($seven, $tab, 0.5, 'tab stop at column 8');
    }

    public function testWideCheckboxDrawnSquare(): void
    {
        $pdf = BaurPDF::html('<style>input{width:150pt}</style><p><input type=checkbox checked> да</p>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        foreach (self::rects($pdf) as $r) { [, , $w] = explode(' ', $r); $this->assertLessThan(40, (float)$w, 'no 150pt-wide checkbox'); }
    }

    private function xOf(string $pdf, string $word): float
    {
        $x = $this->text($pdf, null, '-bbox');
        $this->assertSame(1, preg_match('/<word xMin="([\d.]+)"[^>]*>' . preg_quote($word, '/') . '</', $x, $m));
        return (float)$m[1];
    }
}
