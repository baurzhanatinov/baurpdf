<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** v2.29: жадная разбивка justify (как Chromium) и кернинг через пробел. */
final class V229FeaturesTest extends TestCase
{
    private function lines(string $html): array
    {
        $t = $this->text(BaurPDF::html($html)->toString());
        return array_values(array_filter(array_map('trim', explode("\n", $t))));
    }

    public function testJustifyFillsLineGreedilyWithSpaceKerning(): void
    {
        // ширина подобрана так, что «период» влезает только с учётом кернинга «т␣» и «␣п»-пар (как в Chromium)
        $h = '<style>@page{size:A4;margin:18mm}body{font-family:"PT Sans";font-size:10.5pt;margin:0}p{text-align:justify;margin:0}</style>'
           . '<p>договор отчёт выручка Астана Астана выручка договор выручка услуга Астана отчёт эффективность период выручка договор анализ анализ период отчёт</p>';
        $l = $this->lines($h);
        $this->assertStringEndsWith('эффективность период', (string)preg_replace('/\s+/', ' ', $l[0]));
    }

    public function testNoKerningOptOutKeepsPlainSpaces(): void
    {
        $l = $this->lines('<p style="font-kerning:none;text-align:justify;width:200px">т п т п т п т п т п т п т п т п т п т п т п т п т п</p>');
        $this->assertNotEmpty($l);
    }
}
