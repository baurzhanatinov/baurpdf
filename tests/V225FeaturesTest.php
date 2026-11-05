<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.25: opt-in fallback-шрифты из каталогов (fontDir). */
final class V225FeaturesTest extends TestCase
{
    private const NANUM = '/usr/share/fonts/truetype/sand-box/google/Nanum Gothic/NanumGothic-Regular.ttf';

    private function fonts(string $pdf): string
    {
        if (!trim((string)shell_exec('command -v pdffonts'))) $this->markTestSkipped('pdffonts missing');
        $f = self::tmp($pdf); $r = (string)shell_exec('pdffonts ' . escapeshellarg($f) . ' 2>/dev/null'); @unlink($f);
        return $r;
    }

    public function testMissingGlyphFoundInFontDir(): void
    {
        if (!is_file(self::NANUM)) $this->markTestSkipped('Nanum Gothic not installed');
        $dir = sys_get_temp_dir() . '/bp225a-' . getmypid(); @mkdir($dir);
        copy(self::NANUM, "$dir/NanumGothic-Regular.ttf");
        try {
            $doc = BaurPDF::html('<p>한국어 텍스트</p>')->fontDir($dir);
            $this->assertStringContainsString('NanumGothic', $this->fonts($doc->toString()));
            $this->assertSame([], $doc->warnings());
            $this->assertStringNotContainsString('NanumGothic', $this->fonts(BaurPDF::html('<p>한국어 텍스트</p>')->toString())); // без fontDir — не ищем
        } finally { @unlink("$dir/NanumGothic-Regular.ttf"); @rmdir($dir); }
    }

    public function testSymlinksOutsideDirAreIgnored(): void
    {
        if (!is_file(self::NANUM)) $this->markTestSkipped('Nanum Gothic not installed');
        $dir = sys_get_temp_dir() . '/bp225b-' . getmypid(); @mkdir($dir);
        @symlink(self::NANUM, "$dir/link.ttf");
        try {
            $this->assertStringNotContainsString('NanumGothic', $this->fonts(BaurPDF::html('<p>한국어</p>')->fontDir($dir)->toString()));
        } finally { @unlink("$dir/link.ttf"); @rmdir($dir); }
    }

    public function testMissingDirWarnsAndRenders(): void
    {
        $doc = BaurPDF::html('<p>x</p>')->fontDir('/nonexistent/fonts');
        $this->assertValidPdf($doc->toString());
        $this->assertStringContainsString('Font directory not found', implode("\n", $doc->warnings()));
    }
}
