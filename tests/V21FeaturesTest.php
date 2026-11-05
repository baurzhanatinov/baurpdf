<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.1: колонки через страницы, лимиты переносов, SMask-тени, Tagged PDF / PDF/UA, AcroForm, SVG, шаблоны, радиальные градиенты. */
final class V21FeaturesTest extends TestCase
{
    private const T = 'Казахстан — государство в Центральной Азии, крупнейшее по площади среди стран без выхода к Мировому океану. ';

    private static function qdf(string $pdf): string
    {
        if (!self::hasTool('qpdf')) return self::inflate($pdf); // без qpdf: словари из раскрытых объектных потоков
        $f = self::tmp($pdf); $o = $f . '.qdf';
        exec('qpdf --qdf --object-streams=disable ' . escapeshellarg($f) . ' ' . escapeshellarg($o));
        $s = (string)@file_get_contents($o); @unlink($f); @unlink($o);
        return $s;
    }

    private function vera(string $pdf, string $flavour): void
    {
        $bin = getenv('VERAPDF') ?: '';
        if ($bin === '' || !is_file($bin)) return; // опционально: VERAPDF=/path/verapdf
        $f = self::tmp($pdf);
        $out = (string)shell_exec(escapeshellarg($bin) . ' --flavour ' . $flavour . ' --format text ' . escapeshellarg($f) . ' 2>&1');
        @unlink($f);
        $this->assertStringStartsWith('PASS', trim($out), $out);
    }

    public function testColumnsContinueAcrossPages(): void
    {
        $pdf = BaurPDF::html('<div style="columns:3;column-gap:12pt">' . str_repeat('<p>' . str_repeat(self::T, 3) . '</p>', 40) . '</div><p>КОНЕЦ</p>')->toString();
        $this->assertValidPdf($pdf);
        $n = $this->pages($pdf);
        $this->assertGreaterThan(1, $n);
        $txt = $this->text($pdf);
        $this->assertStringContainsString('КОНЕЦ', $txt);
        $this->assertLessThan(60, $n, 'колонки не должны давать страницу на абзац');
    }

    public function testHyphenateLimitCharsInNarrowCells(): void
    {
        $base = '<table style="width:60pt"><tr><td style="hyphens:auto" lang="ru">%s</td></tr></table>';
        $txt = $this->text(BaurPDF::html(sprintf($base, 'электрификация промышленности'))->toString());
        $this->assertStringNotContainsString("э-\n", $txt, 'перенос не должен оставлять 1 букву');
        $limited = $this->text(BaurPDF::html(sprintf('<p style="width:90pt;hyphens:auto;hyphenate-limit-chars:30" lang="ru">%s</p>', str_repeat('электрификация промышленности ', 4)))->toString());
        $this->assertStringNotContainsString('-', $limited);
    }

    public function testBlurredShadowUsesSoftMask(): void
    {
        $pdf = BaurPDF::html('<div style="box-shadow:0 4pt 12pt rgba(0,0,0,.3);border-radius:8pt;padding:20pt">x</div>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString('/SMask', self::qdf($pdf));
    }

    public function testTaggedPdfStructure(): void
    {
        $pdf = BaurPDF::html('<html lang="ru"><head><title>Т</title></head><body><h1>Заголовок</h1><p>Текст <a href="https://example.com">ссылка</a></p><img alt="Логотип" src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="><table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table><ul><li>п</li></ul></body></html>')->pdfua()->toString();
        $this->assertValidPdf($pdf);
        $q = self::qdf($pdf);
        foreach (['/StructTreeRoot', '/MarkInfo', '/S /H1', '/S /P', '/S /Link', '/S /Figure', '/S /Table', '/S /TH', '/S /TD', '/S /L', '/S /LI', '/S /LBody', '/MCID', '/ParentTree', 'pdfuaid', '/OBJR'] as $k) $this->assertStringContainsString($k, $q, $k);
        $this->vera($pdf, 'ua1');
    }

    public function testAcroFormFields(): void
    {
        $pdf = BaurPDF::html('<form><input name="fio" value="Бауржан"><input type="password" name="pw"><input type="checkbox" name="ok" checked><input type="radio" name="r" value="a" checked><input type="radio" name="r" value="b"><select name="s"><option>Один</option><option selected>Два</option></select><textarea name="t">Строка</textarea><input type="hidden" name="h" value="x"><button>Ок</button></form>')->toString();
        $this->assertValidPdf($pdf);
        $q = self::qdf($pdf);
        $this->assertStringContainsString('/AcroForm', $q);
        foreach (['/FT /Tx', '/FT /Btn', '/FT /Ch', '/Ff 49152', '/Ff 4096', '/Ff 8192', '/AS /Yes', '/Opt'] as $k) $this->assertStringContainsString($k, $q, $k);
        $this->assertSame(1, substr_count($q, '/Ff 49152'), 'одна группа радиокнопок');
        $this->assertStringNotContainsString('(h)', $q, 'hidden не создаёт поле');
        $this->assertStringContainsString('Ок', $this->text($pdf), 'кнопка рисуется');
        $this->vera(BaurPDF::html('<html lang="ru"><head><title>Ф</title></head><body><input name="a" value="x"></body></html>')->pdfua()->pdfa()->toString(), 'ua1');
    }

    public function testDuplicateFieldNamesAreUnique(): void
    {
        $q = self::qdf(BaurPDF::html('<input name="a"><input name="a">')->toString());
        $this->assertStringContainsString('(a.2)', $q);
    }

    public function testSvgUseSymbolAndStyle(): void
    {
        $pdf = BaurPDF::html('<svg width="100" height="50"><style>.b{fill:#0000ff}</style><defs><symbol id="s" viewBox="0 0 10 10"><rect width="10" height="10"/></symbol></defs><use href="#s" width="20" height="20" class="b"/><use href="https://evil.example/x.svg#a"/></svg>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString('0.000 0.000 1.000 rg', self::qdf($pdf));
    }

    public function testRadialGradient(): void
    {
        $pdf = BaurPDF::html('<div style="height:50pt;background:radial-gradient(circle at 30% 40%, #fff, #f00)"></div>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertGreaterThan(20, substr_count(self::qdf($pdf), ' c f'));
    }

    public function testNewTemplates(): void
    {
        foreach (['contract' => ['parties' => [['role' => 'Исполнитель', 'name' => 'ТОО А'], ['role' => 'Заказчик', 'name' => 'ТОО Б']], 'sections' => [['title' => 'Предмет', 'items' => ['Пункт']]]],
                  'certificate' => ['name' => 'Иван Иванов', 'signers' => [['name' => 'А', 'role' => 'Б']]],
                  'dashboard' => ['kpis' => [['label' => 'K', 'value' => '1', 'delta' => '-1%']], 'table' => ['headers' => ['a'], 'rows' => [['b']]]]] as $name => $data) {
            $pdf = BaurPDF::template($name, $data)->toString();
            $this->assertValidPdf($pdf);
            $this->assertSame(1, $this->pages($pdf), $name);
        }
    }

    public function testLargeTableSpeedAndMemory(): void
    {
        $rows = ''; for ($i = 0; $i < 1000; $i++) $rows .= "<tr><td>$i</td><td>Позиция $i</td><td>" . ($i * 37) . '</td></tr>';
        gc_collect_cycles(); if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage(); $m0 = memory_get_usage(); $t = microtime(true);
        $pdf = BaurPDF::html("<table><thead><tr><th>#</th><th>Название</th><th>Сумма</th></tr></thead><tbody>$rows</tbody></table>")->toString();
        $dt = microtime(true) - $t;
        $this->assertValidPdf($pdf);
        $this->assertLessThan(5.0, $dt, 'с запасом на медленный CI');
        // без memory_reset_peak_usage() (PHP < 8.2) пик — от всего процесса, замер бессмыслен
        if (function_exists('memory_reset_peak_usage')) $this->assertLessThan(96 * 1024 * 1024, memory_get_peak_usage() - $m0 + 64 * 1024 * 1024);
    }
}
