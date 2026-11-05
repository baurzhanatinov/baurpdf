<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.31: компактный CID-сабсет CFF (только используемые глифы) и индекс блоков fontDir. */
final class V231FeaturesTest extends TestCase
{
    private const CJK = '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc';

    public function testCompactCidSubsetKeepsTextAndIsSmall(): void
    {
        if (!is_file(self::CJK)) $this->markTestSkipped('Noto CJK not installed');
        $pdf = BaurPDF::html('<p>你好世界 日本語 한국어</p>')->fallbackFont(self::CJK . '#2');
        $s = $pdf->toString();
        $this->assertSame([], $pdf->warnings());
        $this->assertValidPdf($s);
        $t = $this->text($s, null, '');
        $this->assertStringContainsString('你好世界 日本語 한국어', $t);
        $this->assertLessThan(150_000, strlen($s), 'only used glyphs are kept (was ~235 KB of empty charstrings)');
    }

    public function testFontDirSkipsFormatCharsAndRepeatsFast(): void
    {
        if (!is_dir('/usr/share/fonts/opentype/noto')) $this->markTestSkipped('no system fonts');
        $html = "<p>\u{202B}مرحبا\u{202C} 你好</p>";
        $s = BaurPDF::html($html)->fontDir('/usr/share/fonts')->toString();
        $this->assertValidPdf($s);
        $t = microtime(true);
        $s2 = BaurPDF::html($html)->fontDir('/usr/share/fonts')->toString();
        $this->assertSame(strlen($s), strlen($s2));
        $this->assertLessThan(2.0, microtime(true) - $t);
    }
}
