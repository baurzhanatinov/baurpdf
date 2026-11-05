<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

final class TableTest extends TestCase
{
    public function testTheadRepeatsAndNoRowsLost(): void
    {
        $rows = '';
        for ($i = 1; $i <= 300; $i++) $rows .= "<tr><td>R$i</td><td>Длинное описание строки номер $i https://example.com/very/long/path/segment/$i</td></tr>";
        $pdf = BaurPDF::html("<table><thead><tr><th>КЛЮЧ</th><th>Описание</th></tr></thead><tbody>$rows</tbody></table>")->toString();
        $this->assertValidPdf($pdf);
        $n = $this->pages($pdf);
        $this->assertGreaterThan(3, $n);
        $t = $this->text($pdf);
        $this->assertSame($n, substr_count($t, 'КЛЮЧ'), 'шапка на каждой странице');
        for ($i = 1; $i <= 300; $i++) $this->assertMatchesRegularExpression("/\\bR$i\\b/", $t);
    }

    public function testRowTallerThanPageIsSliced(): void
    {
        $long = '';
        for ($i = 1; $i <= 150; $i++) $long .= "строка$i ";
        $pdf = BaurPDF::html("<table><tr><td style='width:20mm'>$long</td><td>x</td></tr></table>")->toString();
        $this->assertValidPdf($pdf);
        $this->assertGreaterThan(1, $this->pages($pdf));
        $t = $this->text($pdf);
        for ($i = 1; $i <= 150; $i++) $this->assertStringContainsString("строка$i", $t);
    }

    public function testWideTableSplitRepeatsKeyColumn(): void
    {
        $head = '<th>Ключ</th>'; $row = '<td>K1</td>';
        for ($c = 1; $c <= 30; $c++) { $head .= "<th>Колонка$c</th>"; $row .= "<td>Значение$c</td>"; }
        $pdf = BaurPDF::html("<table data-overflow='split'><thead><tr>$head</tr></thead><tbody><tr>$row</tr></tbody></table>")->toString();
        $this->assertValidPdf($pdf);
        $this->assertGreaterThan(1, $this->pages($pdf));
        $t = $this->text($pdf);
        $this->assertGreaterThan(1, substr_count($t, 'K1'));
        for ($c = 1; $c <= 30; $c++) $this->assertStringContainsString("Значение$c", $t);
    }

    public function testRowspanColspan(): void
    {
        $pdf = BaurPDF::html('<table><tr><td rowspan="2">A</td><td colspan="2">B</td></tr><tr><td>C</td><td>D</td></tr></table>')->toString();
        $this->assertValidPdf($pdf);
        $t = $this->text($pdf);
        foreach (['A', 'B', 'C', 'D'] as $s) $this->assertStringContainsString($s, $t);
    }
}
