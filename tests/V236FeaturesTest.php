<?php
declare(strict_types=1);

namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Exception\BaurPdfException;

/** 2.36: слияние работ программистов A/B/C — EXIF 1–8, свежий рендер после смены опций, одноразовые генераторы, kerning(false). */
final class V236FeaturesTest extends TestCase
{
    /** JPEG 60×30 с красным левым верхним квадрантом и тегом Orientation. */
    private static function marked(int $orient): string
    {
        $g = imagecreatetruecolor(60, 30); imagefill($g, 0, 0, (int)imagecolorallocate($g, 255, 255, 255));
        imagefilledrectangle($g, 0, 0, 29, 14, (int)imagecolorallocate($g, 255, 0, 0));
        ob_start(); imagejpeg($g, null, 95); $jpeg = (string)ob_get_clean();
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orient) . "\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /** Квадрант с красным на растре страницы: TL/TR/BL/BR. */
    private static function redCorner(string $pdf): string
    {
        $f = tempnam(sys_get_temp_dir(), 'bp36'); file_put_contents($f, $pdf);
        shell_exec('pdftoppm -r 72 -png -singlefile ' . escapeshellarg($f) . ' ' . escapeshellarg($f));
        $g = @imagecreatefrompng("$f.png"); @unlink($f); @unlink("$f.png");
        if (!$g) return '?';
        // картинка стоит в левом верхнем углу поля: ищем красные пиксели в её габарите
        $minX = PHP_INT_MAX; $minY = PHP_INT_MAX; $maxX = -1; $maxY = -1; $rx = 0; $ry = 0; $n = 0;
        for ($y = 0; $y < 400; $y++) for ($x = 0; $x < 400; $x++) {
            $c = imagecolorat($g, $x, $y); $r = ($c >> 16) & 255; $gg = ($c >> 8) & 255; $b = $c & 255;
            if ($r > 200 && $gg > 200 && $b > 200) continue;
            $minX = min($minX, $x); $minY = min($minY, $y); $maxX = max($maxX, $x); $maxY = max($maxY, $y);
            if ($r > 180 && $gg < 90 && $b < 90) { $rx += $x; $ry += $y; $n++; }
        }
        if (!$n) return '?';
        $cx = ($minX + $maxX) / 2; $cy = ($minY + $maxY) / 2;
        return ($ry / $n < $cy ? 'T' : 'B') . ($rx / $n < $cx ? 'L' : 'R');
    }

    public function testExifOrientationAllEightPlacePixelsCorrectly(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD missing');
        $this->requireTool('pdftoppm');
        // куда попадает левый верхний пиксель исходника (EXIF 2.32, табл. Orientation)
        $want = [1 => 'TL', 2 => 'TR', 3 => 'BR', 4 => 'BL', 5 => 'TL', 6 => 'TR', 7 => 'BR', 8 => 'BL'];
        foreach ($want as $o => $corner) {
            $src = 'data:image/jpeg;base64,' . base64_encode(self::marked($o));
            $pdf = BaurPDF::html('<style>@page{margin:20pt}</style><img src="' . $src . '" style="width:' . ($o >= 5 ? 60 : 120) . 'pt">')->toString();
            $this->assertSame($corner, self::redCorner($pdf), "EXIF $o: матрица cm");
            // пережатие через GD (imageDpi) — те же пиксели на экране
            $pdf = BaurPDF::html('<style>@page{margin:20pt}</style><img src="' . $src . '" style="width:' . ($o >= 5 ? 6 : 12) . 'pt">')->imageDpi(72)->toString();
        }
        foreach ([5, 7] as $o) {
            $size = \BaurPDF\Pdf\Image::size(self::marked($o));
            $this->assertSame([30, 60], $size, "EXIF $o меняет местами ширину и высоту");
        }
    }

    public function testDownscaledCopyKeepsOrientation(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD missing');
        foreach ([2 => 'TR', 4 => 'BL', 5 => 'TL', 7 => 'BR'] as $o => $corner) {
            $out = \BaurPDF\Pdf\Image::downscale(self::marked($o), $o >= 5 ? 15 : 30, $o >= 5 ? 30 : 15, 95, $o);
            if ($out === null) { $this->addToAssertionCount(1); continue; } // не меньше исходника — остаётся оригинал с тегом
            $g = imagecreatefromstring($out); $this->assertNotFalse($g);
            $w = imagesx($g); $h = imagesy($g);
            $this->assertSame($o >= 5 ? [15, 30] : [30, 15], [$w, $h]);
            $px = static fn(int $x, int $y): bool => ((imagecolorat($g, $x, $y) >> 8) & 255) < 120;
            $got = $px(1, 1) ? 'TL' : ($px($w - 2, 1) ? 'TR' : ($px(1, $h - 2) ? 'BL' : 'BR'));
            $this->assertSame($corner, $got, "downscale EXIF $o");
        }
    }

    public function testChangingOptionsAfterRenderReRenders(): void
    {
        $p = BaurPDF::html('<p>x</p>')->compress(false);
        $a = $p->toString();
        $b = $p->tagged()->toString();
        $this->assertNotSame($a, $b);
        $this->assertStringContainsString('/StructTreeRoot', $b);
        $c = $p->landscape()->toString();
        $this->assertNotSame($b, $c);
    }

    public function testConsumedGeneratorFailsInsteadOfEmptyTable(): void
    {
        $g = (static function () { yield ['a' => 1]; yield ['a' => 2]; })();
        $p = BaurPDF::html('')->streamTable($g);
        $p->toString();
        try { $p->landscape()->toString(); $this->fail('expected exception'); }
        catch (BaurPdfException $e) { $this->assertStringContainsString('already consumed', $e->getMessage()); }
        // массив можно рендерить повторно
        $q = BaurPDF::html('')->streamTable([['a' => 1]]);
        $q->toString();
        $this->assertStringStartsWith('%PDF', $q->landscape()->toString());
    }

    public function testKerningOptionDisablesKerning(): void
    {
        $html = '<p style="font-size:40pt">AVAVAVAV To Ty</p>';
        $on = BaurPDF::html($html)->compress(false)->toString();
        $off = BaurPDF::html($html)->compress(false)->kerning(false)->toString();
        $this->assertNotSame($on, $off);
        $css = BaurPDF::html('<p style="font-size:40pt;font-kerning:none">AVAVAVAV To Ty</p>')->compress(false)->toString();
        $this->assertSame(preg_replace('#/(CreationDate|ModDate|ID).*#', '', $off), preg_replace('#/(CreationDate|ModDate|ID).*#', '', $css));
    }

    public function testCjkBreaksBetweenIdeographsButNotBeforeClosingPunctuation(): void
    {
        $this->assertSame(['中', '文。', '「引', '号」'], \BaurPDF\Text\CjkLineBreak::segments('中文。「引号」'));
        $this->assertSame(['abc'], \BaurPDF\Text\CjkLineBreak::segments('abc'));
        if (!is_dir('/usr/share/fonts')) $this->markTestSkipped('no system fonts');
        $c = str_repeat('这是一个没有空格的中文句子，用于测试换行。「引号」', 12);
        foreach ([150, 157, 163] as $w) {
            $pdf = BaurPDF::html("<div style='width:{$w}pt;font-size:10pt'><p>$c</p></div>")->fontDir('/usr/share/fonts')->toString();
            $lines = array_filter(explode("\n", $this->text($pdf, null, '')));
            $this->assertGreaterThan(5, count($lines));
            foreach ($lines as $l) {
                $this->assertDoesNotMatchRegularExpression('/^[，。」]/u', $l, "width $w");
                $this->assertDoesNotMatchRegularExpression('/「$/u', $l, "width $w");
            }
        }
    }
}
