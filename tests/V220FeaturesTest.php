<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Font\TrueTypeFont;

/** v2.20: TrueType Collections (.ttc) и понятные ошибки для CFF/OTF. */
final class V220FeaturesTest extends TestCase
{
    private static string $ttc;

    /** Собрать TTC из двух TTF: заголовок ttcf + шрифты целиком, смещения таблиц сдвигаются. */
    public static function setUpBeforeClass(): void
    {
        $fonts = [dirname(__DIR__) . '/resources/fonts/PTSans-Regular.ttf', dirname(__DIR__) . '/resources/fonts/DejaVuSans-Bold.ttf'];
        $hdr = 12 + 4 * count($fonts); $body = ''; $offs = [];
        foreach ($fonts as $f) {
            $d = (string)file_get_contents($f);
            $base = $hdr + strlen($body); $offs[] = $base;
            $n = (int)(unpack('n', $d, 4) ?: [1 => 0])[1];
            for ($i = 0; $i < $n; $i++) { $o = 12 + $i * 16 + 8; $d = substr_replace($d, pack('N', (int)(unpack('N', $d, $o) ?: [1 => 0])[1] + $base), $o, 4); }
            $body .= $d . str_repeat("\0", (4 - strlen($d) % 4) % 4);
        }
        self::$ttc = sys_get_temp_dir() . '/bp220-' . getmypid() . '.ttc';
        file_put_contents(self::$ttc, 'ttcf' . pack('nnN', 1, 0, count($fonts)) . pack('N*', ...$offs) . $body);
    }

    public static function tearDownAfterClass(): void { @unlink(self::$ttc); }

    public function testCollectionFacesLoadByIndex(): void
    {
        $this->assertNull(TrueTypeFont::probe(self::$ttc));
        $this->assertNull(TrueTypeFont::probe(self::$ttc . '#1'));
        $this->assertStringContainsString('out of range', (string)TrueTypeFont::probe(self::$ttc . '#5'));
        $a = new TrueTypeFont(self::$ttc); $b = new TrueTypeFont(self::$ttc . '#1');
        $this->assertStringContainsString('PT', $a->name);
        $this->assertStringContainsString('DejaVu', $b->name);
        $this->assertTrue($b->bold);
        $pdf = BaurPDF::html('<p style="font-family:Coll">Привет TTC</p><p style="font-family:Coll;font-weight:bold">Жирный</p>')
            ->font('Coll', self::$ttc, self::$ttc . '#1')->deterministic();
        $s = $pdf->toString();
        $this->assertValidPdf($s);
        $this->assertSame([], $pdf->warnings());
        $this->assertStringContainsString('Привет TTC', $this->text($s));
        $f = self::tmp($s); $list = (string)shell_exec('pdffonts ' . escapeshellarg($f) . ' 2>/dev/null'); @unlink($f);
        if ($list !== '') { $this->assertStringContainsString('PT', $list); $this->assertStringContainsString('DejaVu', $list); }
    }

    public function testBrokenOpenTypeGivesClearWarning(): void
    {
        $otf = sys_get_temp_dir() . '/bp220-' . getmypid() . '.otf';
        file_put_contents($otf, 'OTTO' . str_repeat("\0", 64));
        $pdf = BaurPDF::html('<p style="font-family:X">a</p>')->font('X', $otf);
        $this->assertValidPdf($pdf->toString());
        $w = implode("\n", $pdf->warnings());
        @unlink($otf);
        $this->assertStringContainsString('need glyf or CFF outlines', $w); // 2.27: CFF поддерживается, битый файл — предупреждение
    }
}
