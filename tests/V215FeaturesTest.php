<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Text\Bidi;

/** v2.15: Arabic shaping + RTL. */
final class V215FeaturesTest extends TestCase
{
    public function testShapingForms(): void
    {
        $all = fn(int $cp): bool => true;
        // ب ب ب → initial, medial, final
        $this->assertSame("\u{FE91}\u{FE92}\u{FE90}", Bidi::shape("\u{0628}\u{0628}\u{0628}", $all));
        // لا → lam-alef ligature (isolated)
        $this->assertSame("\u{FEFB}", Bidi::shape("\u{0644}\u{0627}", $all));
        // alef does not join to the left: ا ب → isolated alef + isolated beh
        $this->assertSame("\u{FE8D}\u{FE8F}", Bidi::shape("\u{0627}\u{0628}", $all));
        // no glyph → base letter kept
        $this->assertSame("\u{0628}", Bidi::shape("\u{0628}", fn(int $cp): bool => false));
    }

    public function testVisualOrder(): void
    {
        $this->assertSame("\u{0628}\u{0627}", Bidi::visualWord("\u{0627}\u{0628}", true));
        $this->assertSame("PDF", Bidi::visualWord("PDF", true));
        $this->assertSame('(' . "\u{0628}" . ')', Bidi::visualWord('(' . "\u{0628}" . ')', true));
        $this->assertSame('.1', Bidi::rtlEdges('1.'));
        $w = fn(string $t, bool $r = false) => ['t' => 'w', 'text' => $t] + ($r ? ['rtl' => true] : []);
        $s = ['t' => 's'];
        // RTL paragraph: A B "x y" → visual: "x y" B A (LTR run keeps its order)
        $out = Bidi::reorderLine([$w('A', true), $s, $w('B', true), $s, $w('x'), $s, $w('y')], true);
        $this->assertSame(['x', '', 'y', '', 'B', '', 'A'], array_map(fn($i) => $i['text'] ?? '', $out));
        // LTR paragraph with two Arabic words: order of the Arabic run reversed only
        $out = Bidi::reorderLine([$w('a'), $s, $w('B', true), $s, $w('C', true), $s, $w('d')], false);
        $this->assertSame(['a', '', 'C', '', 'B', '', 'd'], array_map(fn($i) => $i['text'] ?? '', $out));
    }

    public function testRtlParagraphRightAlignedAndCopyable(): void
    {
        $pdf = BaurPDF::html('<p dir="rtl">مرحبا بالعالم</p><p>left</p>')->theme(false)->toString();
        $txt = $this->text($pdf, null, '');
        $bb = $this->text($pdf, null, '-bbox');
        $this->assertStringContainsString('مرحبا', $txt);
        $this->assertStringContainsString('بالعالم', $txt);
        preg_match_all('/xMin="([\d.]+)"/', $bb, $m);
        $this->assertGreaterThan(300.0, (float)$m[1][0]); // RTL line starts near the right edge
    }

    public function testDirAutoAndCssDirection(): void
    {
        $pdf = BaurPDF::html('<div style="direction:rtl"><p>abc</p></div><p dir="auto">שלום</p>')->toString();
        $this->assertValidPdf($pdf);
    }
}
