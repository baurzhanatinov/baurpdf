<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.16: потоковые таблицы. */
final class V216FeaturesTest extends TestCase
{
    private static function rows(int $n): \Generator { for ($i = 1; $i <= $n; $i++) yield [$i, "Item $i", 'x<y']; }

    public function testRowsAreContinuousAndHeaderRepeats(): void
    {
        $pdf = BaurPDF::html('<h1>R</h1>')->deterministic()->footer('{PAGENO}/{nbpg}')
            ->streamTable(self::rows(1200), ['No', 'Name', 'Esc'], null, 100);
        $doc = $pdf->render();
        $n = $doc->pageCount();
        $this->assertGreaterThan(10, $n);
        $this->assertSame([], $pdf->warnings());
        $t = $this->text($doc->content());
        preg_match_all('/\bItem (\d+)\b/', $t, $m);
        $this->assertSame(range(1, 1200), array_map('intval', $m[1]));
        $this->assertSame($n, preg_match_all('/\bName\b/', $t));       // шапка на каждой странице
        $this->assertStringContainsString("$n/$n", $t);          // {nbpg} известен в конце
        $this->assertStringContainsString('x<y', $t);            // ячейки экранируются
    }

    public function testExistingTableAndCustomRowHtml(): void
    {
        $html = '<table data-bp-stream style="width:100%"><thead><tr><th>A</th></tr></thead><tbody><tr><td>static</td></tr></tbody></table><p>after</p>';
        $pdf = BaurPDF::html($html)->deterministic()->streamTable(['a', 'b'], [], fn($r) => "<tr><td>row-$r</td></tr>");
        $doc = $pdf->render();
        $this->assertSame(1, $doc->pageCount());
        $this->assertSame([], $pdf->warnings()); // 2.30: содержимое после таблицы выводится
        $t = $this->text($doc->content());
        $this->assertMatchesRegularExpression('/static.*row-a.*row-b.*after/s', $t);
    }

    public function testMemoryStaysFlat(): void
    {
        if (!function_exists('memory_reset_peak_usage')) $this->markTestSkipped('memory_reset_peak_usage() появилась в PHP 8.2');
        $peak = function (int $n): float {
            gc_collect_cycles(); memory_reset_peak_usage(); $base = memory_get_usage();
            BaurPDF::html('<p>x</p>')->deterministic()->streamTable(self::rows($n), ['No', 'Name', 'Esc'])->render();
            return (memory_get_peak_usage() - $base) / 1048576;
        };
        $a = $peak(1000); $b = $peak(8000);
        $this->assertLessThan($a * 3 + 8, $b, "1000 rows: {$a} MB, 8000 rows: {$b} MB");
    }
}
