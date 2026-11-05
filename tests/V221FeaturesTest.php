<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Font\TrueTypeFont;

/** v2.21: кернинг (GPOS PairPos / kern). */
final class V221FeaturesTest extends TestCase
{
    public function testFontKernPairs(): void
    {
        $f = new TrueTypeFont(dirname(__DIR__) . '/resources/fonts/PTSans-Regular.ttf');
        $this->assertTrue($f->hasKerning());
        $this->assertLessThan(0, $f->kernCp(ord('T'), ord('o')));
        $this->assertLessThan(0, $f->kernCp(ord('A'), ord('V')));
        $this->assertSame(0.0, $f->kernCp(ord('o'), ord('o')));
    }

    public function testKernedTextIsNarrowerAndOptOut(): void
    {
        $pdf = BaurPDF::html('<p style="font:30pt \'PT Sans\'">AVAVAVToTo</p><p style="font:30pt \'PT Sans\';font-kerning:none">AVAVAVToTo</p>')->toString();
        $this->assertStringContainsString(' TJ', $this->raw($pdf));
        $x = $this->text($pdf, null, '-bbox');
        preg_match_all('/xMin="([\d.]+)" yMin="[\d.]+" xMax="([\d.]+)"/', $x, $m);
        $this->assertCount(2, $m[1]);
        $this->assertLessThan(((float)$m[2][1] - (float)$m[1][1]) - 10, (float)$m[2][0] - (float)$m[1][0]);
        $this->assertStringContainsString('AVAVAVToTo', $this->text($pdf));
    }

    private function raw(string $pdf): string
    {
        if (!self::hasTool('qpdf')) return self::inflate($pdf); // без qpdf — раскрытые потоки на чистом PHP
        $f = self::tmp($pdf); $o = $f . '.q';
        shell_exec('qpdf --qdf --object-streams=disable ' . escapeshellarg($f) . ' ' . escapeshellarg($o) . ' 2>/dev/null');
        $r = (string)@file_get_contents($o); @unlink($f); @unlink($o);
        return $r;
    }
}
