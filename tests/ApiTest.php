<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Exception\BaurPdfException;

final class ApiTest extends TestCase
{
    public function testSimplest(): void
    {
        $pdf = BaurPDF::html('<h1>Привет, мир</h1><p>Hello</p>')->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString('Привет, мир', $this->text($pdf));
    }

    public function testFluentOptions(): void
    {
        $pdf = BaurPDF::html(str_repeat('<p>Абзац текста для нескольких страниц.</p>', 200))
            ->landscape()->margins('15mm')->footer('{page}/{pages}')->watermark('DRAFT')->title('Тест')->toString();
        $this->assertValidPdf($pdf);
        $n = $this->pages($pdf);
        $this->assertGreaterThan(1, $n);
        $this->assertSame('841x595', $this->pageSizes($pdf)[0]);
        if (self::hasTool('pdfinfo')) $this->assertStringContainsString('Тест', (string)self::tool('pdfinfo', '', $pdf));
        else $this->assertStringContainsString('/Title', self::inflate($pdf));
        $this->assertStringContainsString("1/$n", $this->text($pdf));
    }

    public function testSaveAndFile(): void
    {
        $f = sys_get_temp_dir() . '/bp-save-' . getmypid() . '.pdf';
        BaurPDF::html('<p>x</p>')->save($f);
        $this->assertFileExists($f);
        $this->assertValidPdf((string)file_get_contents($f));
        unlink($f);
    }

    public function testTableBuilder(): void
    {
        $pdf = BaurPDF::table([['Город' => 'Алматы', 'Сумма' => 1500], ['Город' => 'Астана', 'Сумма' => 900]], title: 'Продажи')->toString();
        $t = $this->text($pdf);
        foreach (['Продажи', 'Город', 'Алматы', 'Астана', '1 500'] as $s) $this->assertStringContainsString($s, $t);
    }

    public function testChartBuilder(): void
    {
        foreach (['bar', 'line', 'pie', 'donut', 'area', 'hbar', 'stacked', 'gauge'] as $type) {
            $data = $type === 'gauge' ? ['value' => 72] : ['labels' => ['Q1', 'Q2', 'Q3'], 'series' => ['Выручка' => [10, 20, 15]]];
            $pdf = BaurPDF::chart($type, $data, "График $type")->toString();
            $this->assertValidPdf($pdf);
            $this->assertStringContainsString("График $type", $this->text($pdf));
        }
    }

    public function testSlides(): void
    {
        $pdf = BaurPDF::slides([['title' => 'Первый'], ['title' => 'Второй', 'bullets' => ['а', 'б']], ['title' => 'Третий']])->toString();
        $this->assertValidPdf($pdf);
        $this->assertSame(3, $this->pages($pdf));
    }

    public function testTemplates(): void
    {
        $pdf = BaurPDF::template('invoice', ['number' => 'A-1', 'items' => [['name' => 'Услуга', 'qty' => 2, 'price' => 100]], 'qr' => 'test'])->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString('A-1', $this->text($pdf));
    }

    public function testMergeAndBatch(): void
    {
        $pdf = BaurPDF::merge(['<p>Один</p>', '<p>Два</p>'])->toString();
        $this->assertSame(2, $this->pages($pdf));
        $dir = sys_get_temp_dir() . '/bp-batch-' . getmypid();
        @mkdir($dir);
        $r = BaurPDF::batch(["$dir/a.pdf" => '<p>a</p>', "$dir/b.pdf" => '<p>b</p>']);
        $this->assertCount(2, $r);
        foreach ($r as $p) $this->assertFileExists($p);
        array_map('unlink', glob("$dir/*") ?: []); rmdir($dir);
    }

    public function testHelpfulErrors(): void
    {
        try { BaurPDF::html('x')->format('A9'); $this->fail(); }
        catch (BaurPdfException $e) { $this->assertStringContainsString('A4', $e->getMessage()); }
        $this->expectException(BaurPdfException::class);
        BaurPDF::html('x')->margins('abc');
    }

    public function testUnknownOptionRejected(): void
    {
        $this->expectException(BaurPdfException::class);
        BaurPDF::html('x', ['formt' => 'A4']);
    }
}
