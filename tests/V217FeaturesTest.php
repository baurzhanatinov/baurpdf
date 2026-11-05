<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Pdf\Image;

/** v2.17: оптимизация картинок. */
final class V217FeaturesTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/bp217-' . getmypid();
        @mkdir(self::$dir);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir(self::$dir);
    }

    public function testPalettePngIsPassedThroughAsIndexed(): void
    {
        if (!function_exists('imagecreate')) $this->markTestSkipped('GD missing');
        $g = imagecreate(64, 32); imagecolorallocate($g, 255, 255, 255); $b = imagecolorallocate($g, 200, 0, 0);
        imagefilledrectangle($g, 0, 0, 31, 31, $b);
        ob_start(); imagepng($g); $png = (string)ob_get_clean();
        $im = Image::parse($png);
        $this->assertNotNull($im);
        $this->assertStringStartsWith('[/Indexed /DeviceRGB 1 <ffffffc80000>]', $im['cs']);
        $this->assertSame(1, $im['bpc']);
        $this->assertTrue($im['raw']);
        $pdf = BaurPDF::html('<img src="data:image/png;base64,' . base64_encode($png) . '" style="width:4cm">')->deterministic()->toString();
        $this->assertValidPdf($pdf);
        $this->assertStringContainsString('/ColorSpace [/Indexed /DeviceRGB 1', $pdf);
    }

    public function testImageDpiDownscalesLargePhotos(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD missing');
        $im = imagecreatetruecolor(1600, 1200);
        for ($y = 0; $y < 1200; $y += 8) for ($x = 0; $x < 1600; $x += 8) imagefilledrectangle($im, $x, $y, $x + 7, $y + 7, (int)imagecolorallocate($im, ($x * 5) % 256, ($y * 3) % 256, ($x + $y) % 256));
        imagejpeg($im, self::$dir . '/p.jpg', 92);
        $html = '<img src="p.jpg" style="width:5cm"><img src="p.jpg" style="width:5cm">';
        $full = BaurPDF::html($html)->baseDir(self::$dir)->deterministic()->toString();
        $small = BaurPDF::html($html)->baseDir(self::$dir)->deterministic()->imageDpi(150)->toString();
        $this->assertValidPdf($small);
        $this->assertLessThan(strlen($full) / 4, strlen($small));
        $this->assertSame(1, substr_count($small, '/Subtype /Image')); // одна картинка — один объект
        $this->assertMatchesRegularExpression('#/Width 296 /Height 222#', $small); // 5 см при 150 dpi ≈ 296 px
        // крупнее не делаем, повторный вызов детерминирован
        $this->assertSame($small, BaurPDF::html($html)->baseDir(self::$dir)->deterministic()->imageDpi(150)->toString());
        $this->assertSame(strlen($full), strlen(BaurPDF::html($html)->baseDir(self::$dir)->deterministic()->imageDpi(2400)->toString()));
    }

    public function testDownscaleNeverCrashes(): void
    {
        $this->assertNull(Image::downscale('garbage', 10, 10));
        $pdf = BaurPDF::html('<img src="data:image/png;base64,' . base64_encode("\x89PNGbroken") . '" style="width:3cm">')->imageDpi(72)->toString();
        $this->assertValidPdf($pdf);
    }
}
