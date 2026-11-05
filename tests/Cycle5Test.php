<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Layout\ResourceLoader;

/** Цикл 5: регрессии, найденные на реальной инструкции (Windows, PHP 8.1, без poppler). */
final class Cycle5Test extends TestCase
{
    private static function png(int $w = 60, int $h = 30): string
    {
        $g = imagecreatetruecolor($w, $h);
        imagefill($g, 0, 0, imagecolorallocate($g, 220, 230, 240));
        ob_start(); imagepng($g);
        return 'data:image/png;base64,' . base64_encode((string)ob_get_clean());
    }

    /** Операции первой страницы без сжатия: удобно проверять, что именно нарисовано. */
    private static function ops(string $html): string
    {
        $pdf = BaurPDF::html($html)->compress(false)->deterministic()->toString();
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $m);
        foreach ($m[1] as $s) if (str_contains($s, ' Do') || str_contains($s, 'BT')) return $s;
        return '';
    }

    public function testInlineImageDrawsBorderPaddingAndBackground(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD missing');
        $ops = self::ops('<style>.i{border:3pt solid #e11d48;padding:2pt;background:#ff0}</style><div>текст <img class="i" src="' . self::png() . '" width="60"> текст</div>');
        $this->assertStringContainsString('/Im1 Do', $ops);
        $this->assertStringContainsString('0.882 0.114 0.282 RG 3.00 w', $ops, 'рамка строчной картинки');
        $this->assertStringContainsString('1.000 1.000 0.000 rg', $ops, 'фон строчной картинки');
        preg_match('/([\d.]+) 0 0 ([\d.]+) ([\d.]+) ([\d.]+) cm\n\/Im1 Do/', $ops, $im);
        preg_match('/([\d.]+) ([\d.]+) ([\d.]+) ([\d.]+) re\n/', $ops, $re);
        $this->assertNotEmpty($im); $this->assertNotEmpty($re);
        // width="60" — это 60px = 45pt; первый re — фон на весь border-box
        $this->assertEqualsWithDelta((float)$re[1] + 3 + 2, (float)$im[3], 0.05, 'картинка внутри рамки и отступа');
        $this->assertEqualsWithDelta(45 + 2 * 2 + 2 * 3, (float)$re[3], 0.05, 'ширина бокса = картинка + padding + border');
        $this->assertStringContainsString('W n', $ops === '' ? 'W n' : self::ops('<img src="' . self::png() . '" width="60" style="border:2pt solid #000;border-radius:6pt">'), 'клип по border-radius');
    }

    public function testThemeClassesDoNotLeakIntoUserCss(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD missing');
        // .row в теме раньше был display:flex: пользовательский .row превращал строчную картинку в блочную
        $html = '<style>.row{margin:6pt 0}</style><div class="row">текст <img src="' . self::png() . '" width="60"> текст</div>';
        $flex = self::ops($html);
        $plain = self::ops(str_replace(' class="row"', '', $html));
        preg_match('/cm\n\/Im1 Do/', $flex, $a, PREG_OFFSET_CAPTURE);
        preg_match('/([\d.]+) ([\d.]+) Td/', $flex, $t);
        $this->assertSame(substr_count($plain, 'BT'), substr_count($flex, 'BT'), 'число текстовых блоков одинаково: картинка осталась в строке');
        $this->assertStringContainsString('/Im1 Do', $flex);
    }

    public function testUserRuleBeatsThemeRegardlessOfSpecificity(): void
    {
        // тема: th { background: #F1F5F9 } (специфичность 1); автор: th { background: #ff0000 } — тоже 1, но автор позже
        // и тема: table.bp-striped tbody tr:nth-child(even) td (специфичность 23) против авторского td { background: #00ff00 }
        $ops = self::ops('<style>th{background:#ff0000} td{background:#00ff00}</style><table class="bp-striped"><tr><th>a</th></tr><tr><td>b</td></tr><tr><td>c</td></tr></table>');
        $this->assertStringContainsString('1.000 0.000 0.000 rg', $ops);
        $this->assertStringContainsString('0.000 1.000 0.000 rg', $ops);
        $this->assertStringNotContainsString('0.973 0.980 0.988 rg', $ops, 'зебра темы не перебивает авторский td');
    }

    public function testResourceLoaderUnderstandsWindowsPathsAndUppercaseDataScheme(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bp5-' . getmypid();
        @mkdir($dir);
        $file = $dir . DIRECTORY_SEPARATOR . 'a.txt';
        file_put_contents($file, 'ok');
        try {
            $l = new ResourceLoader(baseDir: $dir);
            $this->assertSame('ok', $l->load($file), 'абсолютный путь ОС');
            $this->assertSame('ok', $l->load('a.txt'), 'относительно baseDir');
            $this->assertSame('ok', $l->load('file://' . (str_starts_with($file, '/') ? '' : '/') . str_replace('\\', '/', $file)), 'file:///C:/…');
            $this->assertSame('hello', $l->load('DATA:text/plain;base64,aGVsbG8='));
            // UNC/сетевые пути отклоняются до realpath(): на Windows иначе утечка NTLM на чужой хост
            $this->assertNull($l->load('\\\\evil\\share\\a.png'));
            $this->assertNull($l->load('//evil/share/a.png'));
            $this->assertNull($l->load('file:////evil/share/a.png'));
            $this->assertNull((new ResourceLoader(baseDir: $dir . DIRECTORY_SEPARATOR . 'other'))->load($file), 'за пределами allowlist');
        } finally { @unlink($file); @rmdir($dir); }
    }

    public function testCyrillicAndKazakhLettersHaveGlyphs(): void
    {
        $pdf = BaurPDF::html('<p style="font-family:\'PT Sans\'">Әлем ғылым қазақ ңөұүһі. Привет, мир!</p><p style="font-family:DejaVu Sans">Әлем ғылым қазақ ңөұүһі</p>')->deterministic()->compress(false);
        $doc = $pdf->render();
        $this->assertSame([], $pdf->warnings());
        $this->assertStringContainsString('/ToUnicode', $doc->content());
        // все буквы попали в ToUnicode (значит, у шрифта есть глифы, а не .notdef)
        foreach (['04D8', '0493', '049B', '04A3', '04E9', '04B1', '04AF', '04BB', '0456'] as $cp) {
            $this->assertMatchesRegularExpression('/<' . $cp . '>/i', $doc->content(), "U+$cp в ToUnicode");
        }
    }

    public function testDeterministicOutputIsByteIdentical(): void
    {
        $html = '<h1>Отчёт</h1><p>' . str_repeat('текст ', 300) . '</p>';
        $a = BaurPDF::html($html)->deterministic()->toString();
        $b = BaurPDF::html($html)->deterministic()->toString();
        $this->assertSame(md5($a), md5($b));
    }

    public function testAvoidBlockTallerThanPageBreaksNormally(): void
    {
        // раньше блок с break-inside:avoid выше страницы раскладывался по одной строке на страницу
        $p = str_repeat('<p>' . str_repeat('Строка текста для проверки разрыва страницы. ', 12) . '</p>', 30);
        $plain = BaurPDF::html('<style>p{margin:0 0 6pt}</style><div>' . $p . '</div><p>после</p>')->deterministic()->render()->pageCount();
        $avoid = BaurPDF::html('<style>p{margin:0 0 6pt}</style><div style="break-inside:avoid">' . $p . '</div><p>после</p>')->deterministic()->render()->pageCount();
        $this->assertGreaterThanOrEqual(2, $plain);
        $this->assertLessThanOrEqual($plain + 1, $avoid, 'avoid-блок выше страницы рвётся как обычный текст');
    }

    public function testSoftHyphenInvisibleButBreaks(): void
    {
        $ops = self::ops('<p style="width:40mm">Мяг&shy;кий&shy;пере&shy;нос&shy;рабо&shy;тает в узкой колонке</p>');
        $this->assertStringNotContainsString('00AD', strtoupper($ops), 'U+00AD не печатается');
        $narrow = BaurPDF::html('<p style="width:30mm">Сверх&shy;длинное&shy;слово&shy;которое&shy;не&shy;влезает</p>')->deterministic()->compress(false)->toString();
        $this->assertGreaterThan(1, preg_match_all('/\bTd\b|\bTm\b/', $narrow), 'слово разбито по мягким переносам на несколько строк');
    }

    public function testMaxHeightWithOverflowHiddenClips(): void
    {
        $tall = BaurPDF::html('<div style="max-height:10mm;overflow:hidden;background:#fdd">' . str_repeat('<p>строка</p>', 30) . '</div><p id="after">после</p>')->deterministic()->render();
        $this->assertSame(1, $tall->pageCount());
        $ops = self::ops('<div style="max-height:10mm;overflow:hidden">' . str_repeat('<p>строка</p>', 30) . '</div>');
        $this->assertMatchesRegularExpression('/re W n/', $ops, 'содержимое обрезано клипом');
    }

    public function testMaxPagesStopsLayoutEarly(): void
    {
        $rows = '';
        for ($i = 0; $i < 4000; $i++) $rows .= "<tr><td>$i</td><td>строка $i</td></tr>";
        $t = microtime(true);
        $pdf = BaurPDF::html('<table>' . $rows . '</table>')->maxPages(2)->deterministic();
        $doc = $pdf->render();
        $this->assertSame(2, $doc->pageCount());
        $this->assertContains('maxPages reached: table truncated', $pdf->warnings());
        $this->assertLessThan(3.0, microtime(true) - $t, 'раскладка остановлена, а не выполнена целиком');
    }

    public function testOpaqueWebpHasNoSoftMaskAndStaysSmall(): void
    {
        if (!function_exists('imagewebp')) $this->markTestSkipped('GD без WebP');
        $g = imagecreatetruecolor(400, 300); imagefill($g, 0, 0, imagecolorallocate($g, 200, 220, 240));
        ob_start(); imagewebp($g, null, 80); $webp = (string)ob_get_clean(); imagedestroy($g);
        $pdf = BaurPDF::html('<img src="data:image/webp;base64,' . base64_encode($webp) . '" style="width:60mm">')->deterministic()->toString();
        $this->assertValidPdf($pdf);
        $all = self::inflate($pdf);
        $this->assertStringContainsString('/Subtype /Image', $all);
        $this->assertStringNotContainsString('/SMask', $all, 'непрозрачный WebP без альфа-маски');
        $this->assertLessThan(60000, strlen($pdf), 'IDAT уходит сжатым, а не сырыми пикселями');
        // c альфой — маска есть
        $a = imagecreatetruecolor(100, 100); imagealphablending($a, false); imagesavealpha($a, true);
        imagefill($a, 0, 0, imagecolorallocatealpha($a, 255, 0, 0, 80));
        ob_start(); imagewebp($a, null, 80); $webpA = (string)ob_get_clean(); imagedestroy($a);
        $pdfA = BaurPDF::html('<img src="data:image/webp;base64,' . base64_encode($webpA) . '" style="width:30mm">')->deterministic()->toString();
        $this->assertStringContainsString('/SMask', self::inflate($pdfA));
    }

    public function testPageMarginsFromApiWinOverPageRule(): void
    {
        // @page в CSS задаёт поля, ->margins() их перекрывает; документ одинаково раскладывается, без взрыва страниц
        $html = '<style>@page{size:A4;margin:40mm}</style>' . str_repeat('<p>абзац</p>', 120);
        $css = BaurPDF::html($html)->deterministic()->render();
        $api = BaurPDF::html($html)->margins(10)->deterministic()->render();
        $this->assertGreaterThan($api->pageCount(), $css->pageCount());
        $this->assertLessThan(20, $css->pageCount());
    }
}
