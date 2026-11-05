<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Font\TrueTypeFont;

/** v2.26: кэш кернинга на процесс, детерминизм между рендерами. */
final class V226FeaturesTest extends TestCase
{
    public function testKerningSharedAcrossInstances(): void
    {
        $p = dirname(__DIR__) . '/resources/fonts/PTSans-Regular.ttf';
        $a = new TrueTypeFont($p); $b = new TrueTypeFont($p);
        $this->assertTrue($a->hasKerning());
        $ka = $a->kernCp(ord('A'), ord('V'));
        $this->assertLessThan(0, $ka);
        $this->assertSame($ka, $b->kernCp(ord('A'), ord('V')));
        $this->assertSame($a->kern(5, 7), $b->kern(5, 7));
    }

    public function testRepeatedRenderIdentical(): void
    {
        $h = '<p>AVATAR Wave To. Yo</p><p style="font-kerning:none">AVATAR</p>';
        $x = BaurPDF::html($h)->deterministic()->toString();
        $this->assertSame($x, BaurPDF::html($h)->deterministic()->toString());
    }

    public function testKnuthPlassItemWithoutWidthNoWarning(): void
    {
        $doc = BaurPDF::html(file_get_contents(dirname(__DIR__) . '/tools/compare/docs/rw-letter.html') ?: '<p>x</p>');
        $warn = []; set_error_handler(function (int $n, string $m) use (&$warn): bool { $warn[] = $m; return true; });
        try { $doc->toString(); } finally { restore_error_handler(); }
        $this->assertSame([], $warn);
    }
}
