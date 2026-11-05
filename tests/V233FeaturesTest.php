<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Layout\LineBreaker;

/** v2.33: допуск умещения строки (как у Chromium). */
final class V233FeaturesTest extends TestCase
{
    public function testFitSlackIsSmallAndPositive(): void
    {
        $this->assertGreaterThan(0.0, LineBreaker::FIT_SLACK);
        $this->assertLessThanOrEqual(1.0, LineBreaker::FIT_SLACK);
    }

    public function testNarrowBoxStillWrapsLongText(): void
    {
        $s = BaurPDF::html('<div style="width:60pt">слово слово слово слово слово слово</div>')->toString();
        $this->assertValidPdf($s);
        $t = $this->text($s);
        $this->assertGreaterThan(2, count(array_filter(explode("\n", trim($t)))));
    }
}
