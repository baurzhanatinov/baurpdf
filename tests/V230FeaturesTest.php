<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.30: содержимое после потоковой таблицы. */
final class V230FeaturesTest extends TestCase
{
    public function testContentAfterTableAcrossPages(): void
    {
        $rows = (function () { for ($i = 1; $i <= 300; $i++) yield ["r$i", 'x']; })();
        $html = '<h1>Report</h1><div class="w"><table data-bp-stream style="width:100%"><thead><tr><th>A</th><th>B</th></tr></thead></table><p>Totals: 300</p></div><p>Signature line</p>';
        $pdf = BaurPDF::html($html)->deterministic()->streamTable($rows, [], null, 50);
        $doc = $pdf->render();
        $this->assertSame([], $pdf->warnings());
        $this->assertGreaterThan(5, $doc->pageCount());
        $t = $this->text($doc->content());
        $this->assertMatchesRegularExpression('/Report.*r1\b.*r300.*Totals: 300.*Signature line/s', $t);
        $this->assertSame(1, substr_count($t, 'Totals: 300'));
    }

    public function testTrailingContentStartsBelowLastRow(): void
    {
        $pdf = BaurPDF::html('<table data-bp-stream><thead><tr><th>H</th></tr></thead></table><p>END</p>')->streamTable([['only']]);
        $x = $this->text($pdf->toString(), null, '-bbox');
        preg_match('/yMin="([\d.]+)"[^>]*>only</', $x, $a); preg_match('/yMin="([\d.]+)"[^>]*>END</', $x, $b);
        $this->assertNotEmpty($a); $this->assertNotEmpty($b);
        $this->assertGreaterThan((float)$a[1] + 5, (float)$b[1]);
    }
}
