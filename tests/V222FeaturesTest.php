<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.22: hyphens по умолчанию manual (как в CSS и Chromium), в том числе при выключке по ширине. */
final class V222FeaturesTest extends TestCase
{
    private function para(string $style): string
    {
        $t = str_repeat('эффективность региональный показатель ответственность ', 12);
        return $this->text(BaurPDF::html("<p lang=\"ru\" style=\"width:180pt;text-align:justify;$style\">$t</p>")->toString());
    }

    public function testJustifiedTextIsNotHyphenatedByDefault(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\p{L}-\s*$/mu', $this->para(''));
    }

    public function testHyphensAutoStillHyphenates(): void
    {
        $this->assertMatchesRegularExpression('/\p{L}-\s*$/mu', $this->para('hyphens:auto'));
    }
}
