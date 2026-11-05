<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Document;

/** v2.24: hardening contributed by Baur + review fixes. */
final class V224FeaturesTest extends TestCase
{
    public function testSaveKeepsUmaskModeAndNoTempLeft(): void
    {
        $dir = sys_get_temp_dir() . '/bp224-' . getmypid(); @mkdir($dir);
        $old = umask(0022);
        try { $p = BaurPDF::html('<p>x</p>')->save("$dir/a.pdf"); } finally { umask($old); }
        if (DIRECTORY_SEPARATOR !== '\\') $this->assertSame(0644, fileperms($p) & 0777); // на Windows нет umask: fileperms отдаёт 0666
        $this->assertSame(['a.pdf'], array_values(array_diff((array)scandir($dir), ['.', '..'])));
        @unlink($p); @rmdir($dir);
    }

    public function testPageCountFromBytesWithObjectStreams(): void
    {
        $bytes = BaurPDF::html(str_repeat('<p style="break-after:page">p</p>', 3))->toString();
        $this->assertSame(3, (new Document($bytes))->pageCount());
    }

    public function testDispositionNormalisesAndRejects(): void
    {
        $this->assertStringStartsWith('Content-Disposition: attachment;', Document::disposition('Attachment', "a\r\nb.pdf"));
        $this->assertStringNotContainsString("\n", Document::disposition('inline', "a\nb.pdf"));
        $this->expectException(\BaurPDF\Exception\BaurPdfException::class);
        Document::disposition("inline\r\nX-Evil: 1", 'a.pdf');
    }

    public function testTableHtmlKeepsColumnOrder(): void
    {
        $h = BaurPDF::tableHtml([['a' => 1, 'b' => 'x'], ['b' => 'y', 'a' => 2, 'c' => 'z']]);
        $this->assertMatchesRegularExpression('~<td class="bp-num">2</td><td>y</td></tr>~', $h);
        $this->assertStringNotContainsString('z', $h);
    }
}
