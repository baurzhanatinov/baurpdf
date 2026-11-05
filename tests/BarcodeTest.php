<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\Barcode\{QrCode, Code128};
use BaurPDF\BaurPDF;

final class BarcodeTest extends TestCase
{
    public function testQrSizes(): void
    {
        $this->assertCount(21, QrCode::matrix('hello', 'M'));          // версия 1
        $this->assertCount(177, QrCode::matrix(str_repeat('a', 2900), 'L')); // версия 40
    }

    public function testQrDecodes(): void
    {
        if (!extension_loaded('gd') || trim((string)shell_exec('python3 -c "import zxingcpp" 2>&1; echo $?')) !== '0') $this->markTestSkipped('нужен gd + python zxingcpp');
        foreach (['L', 'M', 'Q', 'H'] as $e) {
            $data = "Оплата счёта №1 / $e";
            $m = QrCode::matrix($data, $e); $n = count($m); $s = 6;
            $im = imagecreatetruecolor(($n + 8) * $s, ($n + 8) * $s); imagefill($im, 0, 0, 0xFFFFFF);
            foreach ($m as $y => $r) foreach ($r as $x => $on) if ($on) imagefilledrectangle($im, ($x + 4) * $s, ($y + 4) * $s, ($x + 5) * $s - 1, ($y + 5) * $s - 1, 0);
            $f = sys_get_temp_dir() . "/bpqr$e.png"; imagepng($im, $f);
            $out = trim((string)shell_exec('python3 -c ' . escapeshellarg("import zxingcpp,sys;from PIL import Image;print(zxingcpp.read_barcodes(Image.open('$f'))[0].text)")));
            unlink($f);
            $this->assertSame($data, $out);
        }
    }

    public function testCode128(): void
    {
        $w = Code128::widths('ABC-123');
        $this->assertSame(11 * (7 + 3) + 2, array_sum($w)); // start+7+check+stop
        $pdf = BaurPDF::html('<div>' . Code128::svg('ABC-123') . QrCode::svg('x') . '</div>')->toString();
        $this->assertValidPdf($pdf);
    }
}
