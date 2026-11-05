<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\Pdf\Writer;

/** v2.34: вертикальные сдвиги диакритик GPOS через Ts. */
final class V234FeaturesTest extends TestCase
{
    public function testRiseMarkersBecomeTs(): void
    {
        $enc = "[<0001> 300 \x01R-200 <0002> -300 \x01R0 <0003>]";
        $this->assertSame('[<0001> 300] TJ -2 Ts [<0002> -300] TJ 0 Ts [<0003>] TJ', Writer::show($enc, 10.0));
    }

    public function testPlainTextUnchanged(): void
    {
        $this->assertSame('<0001> Tj', Writer::show('<0001>', 12.0));
        $this->assertSame('[<0001> -40 <0002>] TJ', Writer::show('[<0001> -40 <0002>]'));
    }
}
