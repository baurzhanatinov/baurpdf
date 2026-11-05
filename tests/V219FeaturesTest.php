<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.19: RTL-таблицы и списки. */
final class V219FeaturesTest extends TestCase
{
    /** @return array<string,array{0:float,1:float}> слово => [xMin, xMax] */
    private function words(string $pdf): array
    {
        $x = $this->text($pdf, null, '-bbox');
        preg_match_all('/<word xMin="([\d.]+)" yMin="[\d.]+" xMax="([\d.]+)"[^>]*>([^<]*)</', $x, $m, PREG_SET_ORDER);
        $r = []; foreach ($m as $w) $r[$w[3]] = [(float)$w[1], (float)$w[2]];
        return $r;
    }

    public function testRtlTableColumnsAreMirrored(): void
    {
        $w = $this->words(BaurPDF::html('<table dir="rtl" border="1" style="width:300pt"><tr><td>AAA</td><td colspan="2">BBB</td></tr><tr><td>C1</td><td>C2</td><td>C3</td></tr></table>'
            . '<table border="1" style="width:300pt"><tr><td>LLL</td><td>RRR</td></tr></table>')->toString());
        $this->assertGreaterThan($w['C2'][0], $w['C1'][0]);
        $this->assertGreaterThan($w['C3'][0], $w['C2'][0]);
        $this->assertGreaterThan($w['BBB'][0], $w['AAA'][0]); // colspan тоже зеркалится
        $this->assertLessThan($w['RRR'][0], $w['LLL'][0]);    // LTR-таблица не тронута
    }

    public function testRtlListMarkersOnTheRight(): void
    {
        $w = $this->words(BaurPDF::html('<div dir="rtl"><ol><li>alpha</li></ol></div><ol><li>beta</li></ol>')->toString());
        $this->assertArrayHasKey('.1', $w);
        $this->assertGreaterThan($w['alpha'][1], $w['.1'][0]);   // маркер правее текста
        $this->assertLessThan($w['beta'][0], $w['1.'][0]);       // LTR: левее
        $this->assertGreaterThan(20, 595.28 - 42.52 - $w['alpha'][1] + 0.0); // отступ списка справа
    }
}
