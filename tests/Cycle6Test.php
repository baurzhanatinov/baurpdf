<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Pdf\Image;
use BaurPDF\Tests\Support\PdfText;

/** Цикл 6: кернинг, перенос длинных слов в ячейках, EXIF-ориентация, предупреждения, извлечение текста без poppler. */
final class Cycle6Test extends TestCase
{
    private static function ops(BaurPDF $pdf): string
    {
        $s = $pdf->compress(false)->deterministic()->toString();
        preg_match_all('/stream\r?\n(.*?)endstream/s', $s, $m);
        foreach ($m[1] as $st) if (str_contains($st, 'BT') || str_contains($st, ' Do')) return $st;
        return '';
    }

    /** JPEG с APP1 Exif, где один тег — Orientation. */
    public static function jpegWithOrientation(int $w, int $h, int $orient): string
    {
        $g = imagecreatetruecolor($w, $h); imagefill($g, 0, 0, imagecolorallocate($g, 200, 220, 240));
        ob_start(); imagejpeg($g, null, 80); $jpeg = (string)ob_get_clean(); imagedestroy($g);
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orient) . "\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    public function testKerningTightensPairsAndEmitsTJ(): void
    {
        $html = '<p style="font-family:\'PT Sans\';font-size:24pt;margin:0">AVATAR ТАВРИЯ</p>';
        $on = self::ops(BaurPDF::html($html));
        $off = self::ops(BaurPDF::html($html)->kerning(false));
        $this->assertMatchesRegularExpression('/\[<[0-9A-F]+> \d+(\.\d)? <[0-9A-F]+>.*\] TJ/', $on, 'пары с кернингом идут массивом TJ');
        $this->assertStringNotContainsString('] TJ', $off);
        // ширина слова с кернингом меньше: второе слово начинается левее
        preg_match_all('/([\d.-]+) [\d.-]+ Td/', $on, $a); preg_match_all('/([\d.-]+) [\d.-]+ Td/', $off, $b);
        $this->assertNotEmpty($a[1]); $this->assertNotEmpty($b[1]);
        $this->assertLessThan((float)end($b[1]), (float)end($a[1]), 'сдвиг до второго слова короче на величину кернинга AVATAR');
        $css = self::ops(BaurPDF::html('<p style="font-family:\'PT Sans\';font-size:24pt;font-kerning:none">AVATAR</p>'));
        $this->assertStringNotContainsString('] TJ', $css, 'font-kerning: none выключает');
    }

    public function testLongWordsWrapInsideCellsInsteadOfShrinkingFont(): void
    {
        $html = '<table style="width:100%"><tr><td style="width:30%">https://kindwatch.sabidamu.kz/library/guide/view?id=12345&amp;lang=kk&amp;very_long_parameter_name=value</td><td>Пожалуйстаоченьдлинноесловобезпробеловкотороенепомещается</td><td>ок</td></tr></table>';
        $pdf = BaurPDF::html($html)->deterministic();
        $doc = $pdf->render();
        $w = $pdf->warnings();
        $this->assertContains('Wide table: long words wrapped inside cells', $w);
        $this->assertNotContains('Wide table: font reduced to 65%', $w);
        $this->assertStringNotContainsString('font reduced', implode(' ', $w));
        $this->assertSame(1, $doc->pageCount());
        // слово разбито на несколько строк одного размера шрифта
        $t = new PdfText($doc->content());
        $this->assertGreaterThan(2, count($t->pages[0]['words']));
        $this->assertStringContainsString('ок', $t->text());
    }

    public function testOverflowWrapAnywhereShrinksMinContent(): void
    {
        $narrow = BaurPDF::html('<div style="width:30mm;overflow-wrap:anywhere;border:1pt solid #000">Пожалуйстаоченьдлинноесловобезпробелов</div>')->deterministic()->render();
        $t = new PdfText($narrow->content());
        $this->assertGreaterThan(1, count($t->pages[0]['words']), 'слово разбито по символам');
    }

    public function testExifOrientationRotatesByMatrixAndSwapsSize(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD missing');
        $j6 = self::jpegWithOrientation(300, 200, 6);
        $this->assertSame(6, Image::orientation($j6));
        $this->assertSame([200, 300], Image::size($j6), 'размер на экране — с обменом сторон');
        $ops = self::ops(BaurPDF::html('<img src="data:image/jpeg;base64,' . base64_encode($j6) . '" style="width:40mm">'));
        // 40mm = 113.39pt ширины, высота 170.08pt; матрица поворота: 0 -h w 0 x y
        $this->assertMatchesRegularExpression('/0 -170\.0\d 113\.3\d 0 [\d.]+ [\d.]+ cm\n\/Im1 Do/', $ops);
        $j8 = self::jpegWithOrientation(300, 200, 8);
        $this->assertMatchesRegularExpression('/0 170\.0\d -113\.3\d 0 [\d.]+ [\d.]+ cm/', self::ops(BaurPDF::html('<img src="data:image/jpeg;base64,' . base64_encode($j8) . '" style="width:40mm">')));
        $j3 = self::jpegWithOrientation(300, 200, 3);
        $this->assertMatchesRegularExpression('/-113\.3\d 0 0 -75\.5\d [\d.]+ [\d.]+ cm/', self::ops(BaurPDF::html('<img src="data:image/jpeg;base64,' . base64_encode($j3) . '" style="width:40mm">')));
        // imageDpi: пиксели поворачиваются в GD, тег исчезает, матрица обычная
        $small = BaurPDF::html('<img src="data:image/jpeg;base64,' . base64_encode(self::jpegWithOrientation(1600, 1200, 6)) . '" style="width:20mm">')->imageDpi(72);
        $o = self::ops($small);
        $this->assertMatchesRegularExpression('/\n56\.6\d 0 0 75\.5\d [\d.]+ [\d.]+ cm/', $o);
        $this->assertMatchesRegularExpression('/\/Width 57 \/Height 76/', self::inflate($small->toString()));
    }

    public function testMissingGlyphWarnsOnce(): void
    {
        $pdf = BaurPDF::html('<p>документ 📄 и ещё раз 📄</p>')->deterministic();
        $pdf->render();
        $w = array_filter($pdf->warnings(), fn($s) => str_contains($s, 'U+1F4C4'));
        $this->assertCount(1, $w);
        $ok = BaurPDF::html('<p>обычный текст ☑ ★ ✓ → №</p>')->deterministic();
        $ok->render();
        $this->assertSame([], $ok->warnings(), 'символы из DejaVu предупреждений не дают');
    }

    public function testAvoidBlockTallerThanPageWarns(): void
    {
        $p = str_repeat('<p>' . str_repeat('Строка текста для проверки разрыва страницы. ', 12) . '</p>', 30);
        $pdf = BaurPDF::html('<div style="break-inside:avoid">' . $p . '</div>')->deterministic();
        $pdf->render();
        $this->assertNotEmpty(array_filter($pdf->warnings(), fn($s) => str_starts_with($s, 'break-inside: avoid ignored')));
    }

    public function testPdfTextExtractorReadsWordsLinesAndPages(): void
    {
        $pdf = BaurPDF::html('<h1>Заголовок</h1><p>Первая строка текста.</p><p>Қазақша мәтін — екінші жол.</p><pagebreak></pagebreak><p>Вторая страница</p>')
            ->footer('{page}/{pages}')->deterministic()->toString();
        $t = new PdfText($pdf);
        $this->assertCount(2, $t->pages);
        $txt = $t->text();
        $this->assertStringContainsString("Заголовок\nПервая строка текста.\nҚазақша мәтін — екінші жол.", $txt);
        $this->assertStringContainsString("1/2\n", $txt);
        $this->assertStringContainsString("Вторая страница", $t->text(2, 2));
        $this->assertStringNotContainsString('Заголовок', $t->text(2, 2));
        $bb = $t->bbox();
        $this->assertMatchesRegularExpression('/<word xMin="[\d.]+" yMin="[\d.]+" xMax="[\d.]+" yMax="[\d.]+">Заголовок<\/word>/', $bb);
        // ширины слов и строк реальные, а не нулевые (регрессия: размер шрифта захватывался замыканием по значению)
        preg_match('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="([\d.]+)">Заголовок/', $bb, $wb);
        $this->assertGreaterThan((float)$wb[1] + 40, (float)$wb[3], 'xMax заметно правее xMin');
        $this->assertGreaterThan((float)$wb[2] + 10, (float)$wb[4], 'высота слова ≈ кегль h1');
        preg_match('/yMin="([\d.]+)"[^>]*>Заголовок/', $bb, $a); preg_match('/yMin="([\d.]+)"[^>]*>Первая/', $bb, $b);
        $this->assertGreaterThan((float)$a[1], (float)$b[1], 'координаты сверху вниз');
        $this->assertStringContainsString('<line xMin=', $t->bbox(true));
        // кернинг (TJ) не ломает извлечение
        $k = new PdfText(BaurPDF::html('<p style="font-size:20pt">AVATAR WAVE</p>')->deterministic()->toString());
        $this->assertSame("AVATAR WAVE\n\f", $k->text());
    }
}
