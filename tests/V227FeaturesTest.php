<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.27: OpenType/CFF (Noto CJK, .otf) — встраивание FontFile3/CIDFontType0C с сабсеттингом. */
final class V227FeaturesTest extends TestCase
{
    private const CJK = '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc';
    private const OTF = '/usr/share/fonts/truetype/sand-box/custom/Pretendard/Pretendard-Regular.otf';

    private function sh(string $cmd, string $pdf): string
    {
        $f = self::tmp($pdf); $r = (string)shell_exec($cmd . ' ' . escapeshellarg($f) . ' - 2>/dev/null'); @unlink($f);
        return $r;
    }

    public function testCidKeyedCffCollectionEmbeddedAndSubset(): void
    {
        if (!is_file(self::CJK)) $this->markTestSkipped('Noto CJK not installed');
        $pdf = BaurPDF::html('<p>中文字体测试 日本語 한국어 Latin</p>')->fallbackFont(self::CJK . '#2');
        $s = $pdf->toString();
        $this->assertSame([], $pdf->warnings());
        $this->assertValidPdf($s);
        $this->assertStringContainsString('中文字体测试 日本語 한국어', $this->sh('pdftotext', $s));
        $this->assertLessThan(600_000, strlen($s), 'subset, not the 19 MB collection');
        $f = self::tmp($s); $fonts = (string)shell_exec('pdffonts ' . escapeshellarg($f) . ' 2>/dev/null'); @unlink($f);
        if ($fonts !== '') $this->assertMatchesRegularExpression('/NotoSansCJKsc-Regular\s+CID Type 0C/', $fonts);
    }

    public function testNameOrCidOtfViaFontFace(): void
    {
        if (!is_file(self::OTF)) $this->markTestSkipped('Pretendard not installed');
        $pdf = BaurPDF::html('<style>@font-face{font-family:P;src:url(Pretendard-Regular.otf)}</style><p style="font-family:P">Hello 한국어</p>')->baseDir(dirname(self::OTF));
        $s = $pdf->toString();
        $this->assertSame([], $pdf->warnings());
        $this->assertStringContainsString('Hello 한국어', $this->sh('pdftotext', $s));
    }

    public function testFontDirPrefersNotoCjkAndSkipsDecorative(): void
    {
        if (!is_file(self::CJK)) $this->markTestSkipped('Noto CJK not installed');
        $dir = sys_get_temp_dir() . '/bp227-' . getmypid(); @mkdir($dir);
        $dela = glob('/usr/share/fonts/truetype/sand-box/google/*/DelaGothicOne-Regular.ttf') ?: [];
        try {
            symlink(self::CJK, "$dir/NotoSansCJK-Regular.ttc");
            if ($dela) copy($dela[0], "$dir/DelaGothicOne-Regular.ttf");
            $s = BaurPDF::html('<p>汉字</p>')->fontDir($dir)->toString();
            $f = self::tmp($s); $fonts = (string)shell_exec('pdffonts ' . escapeshellarg($f) . ' 2>/dev/null'); @unlink($f);
            // симлинк ведёт наружу — по allowlist игнорируется; остаётся только Dela (последней по рангу)
            $this->assertStringNotContainsString('NotoSansCJK', $fonts);
        } finally { @unlink("$dir/NotoSansCJK-Regular.ttc"); @unlink("$dir/DelaGothicOne-Regular.ttf"); @rmdir($dir); }
        $s = BaurPDF::html('<p>汉字</p>')->fontDir(dirname(self::CJK))->toString();
        $f = self::tmp($s); $fonts = (string)shell_exec('pdffonts ' . escapeshellarg($f) . ' 2>/dev/null'); @unlink($f);
        if ($fonts !== '') $this->assertStringContainsString('NotoSansCJKsc', $fonts);
    }

    public function testCorruptCffDoesNotCrash(): void
    {
        $sub = new \BaurPDF\Font\CffSubsetter("\x01\x00\x04\x04\xff\xff");
        try { $sub->subset([1 => true]); $this->fail('expected exception'); }
        catch (\BaurPDF\Exception\BaurPdfException) { $this->assertTrue(true); }
    }
}
