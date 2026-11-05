<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.23: legend на рамке fieldset. */
final class V223FeaturesTest extends TestCase
{
    /** @return array<string,array{0:float,1:float}> слово => [yMin, yMax] */
    private function rows(string $pdf): array
    {
        $x = $this->text($pdf, null, '-bbox');
        preg_match_all('/<word xMin="[\d.]+" yMin="([\d.]+)" xMax="[\d.]+" yMax="([\d.]+)"[^>]*>([^<]*)</', $x, $m, PREG_SET_ORDER);
        $r = []; foreach ($m as $w) $r[$w[3]] = [(float)$w[1], (float)$w[2]];
        return $r;
    }

    public function testLegendSitsOnTheBorder(): void
    {
        $doc = BaurPDF::html('<fieldset style="border:4pt solid #000;padding:10pt;margin:0"><legend>Title</legend><p style="margin:0">Body</p></fieldset>');
        $pdf = $doc->toString();
        $r = $this->rows($pdf);
        // legend выше начала содержимого: до Body только высота legend + padding
        $this->assertLessThan($r['Body'][0], $r['Title'][1]);
        $this->assertLessThan(16, $r['Body'][0] - $r['Title'][1]);
        $this->assertSame([], $doc->warnings());
    }

    public function testFieldsetWithoutLegendUnchanged(): void
    {
        $r = $this->rows(BaurPDF::html('<fieldset style="border:4pt solid #000;padding:10pt;margin:0"><p style="margin:0">Body</p><legend>Late</legend></fieldset>')->toString());
        $this->assertGreaterThan($r['Body'][1], $r['Late'][0]); // legend не первым ребёнком — обычный блок
    }
}
