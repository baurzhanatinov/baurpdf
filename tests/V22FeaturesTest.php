<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;
use BaurPDF\Css\Selector;
use BaurPDF\Css\Transform;

/** v2.2: transforms, ::before/::after + content + counters, column-span, grid, form baseline, selector prefilter. */
final class V22FeaturesTest extends TestCase
{
    private const T = 'Казахстан — государство в Центральной Азии, крупнейшее по площади. ';

    /** @return array<string, array{0:float,1:float}> слово → [xMin, yMin] (pdftotext -bbox), первое вхождение */
    private function boxes(string $pdf): array
    {
        $x = $this->text($pdf, null, '-bbox');
        preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)"[^>]*>([^<]+)</', $x, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $w) $out[html_entity_decode($w[3])] ??= [(float)$w[1], (float)$w[2]];
        return $out;
    }

    public function testTransformParseAndOutput(): void
    {
        $m = Transform::parse('rotate(90deg)', 100, 100, 12);
        $this->assertNotNull($m);
        $this->assertEqualsWithDelta(0.0, $m[0], 1e-9);
        $this->assertEqualsWithDelta(1.0, $m[1], 1e-9);
        $this->assertNull(Transform::parse('none', 100, 100, 12));
        $this->assertNull(Transform::parse('rotate(0deg)', 100, 100, 12));
        $this->assertNull(Transform::parse('scale(1e400)', 100, 100, 12), 'non-finite matrices are rejected');
        $pdf = BaurPDF::html('<div style="transform:rotate(30deg);width:100pt;background:#f00">ПОВОРОТ</div>')->compress(false)->toString();
        $this->assertValidPdf($pdf);
        $this->assertSame(1, preg_match('/0\.866\d* -?0\.5\d* -?0\.5\d* 0\.866\d* [-\d.]+ [-\d.]+ cm/', $pdf), 'rotation matrix emitted');
        $this->assertStringContainsString('ПОВОРОТ', (string)preg_replace('/\s+/u', '', $this->text($pdf)));
        // garbage transforms never crash
        $this->assertValidPdf(BaurPDF::html('<div style="transform:rotate(abc) scale() matrix(1,2)">x</div>')->toString());
    }

    public function testPseudoElementsAndCounters(): void
    {
        $css = 'body{counter-reset:h} h2{counter-increment:h} h2::before{content:counter(h) ". "} h3::before{content:counter(h) "." counter(s, lower-alpha) " "}'
            . ' h2{counter-reset:s} h3{counter-increment:s} a::after{content:" (" attr(href) ")"} q::before{content:open-quote} q::after{content:close-quote}'
            . ' .x::before{content:none}';
        $html = "<style>$css</style><h2>Введение</h2><h3>Цели</h3><h3>Задачи</h3><h2>Метод</h2><h3>Опрос</h3><p><a href='https://e.kz'>сайт</a> <q>цитата</q></p><p class=x>Без</p>";
        $t = preg_replace('/\s+/u', ' ', $this->text(BaurPDF::html($html)->toString()));
        $this->assertStringContainsString('1. Введение', $t);
        $this->assertStringContainsString('1.a Цели', $t);
        $this->assertStringContainsString('1.b Задачи', $t);
        $this->assertStringContainsString('2.a Опрос', $t);
        $this->assertStringContainsString('(https://e.kz)', $t);
        $this->assertStringContainsString('«цитата»', $t);
    }

    public function testNestedListCounters(): void
    {
        $css = 'ol{counter-reset:it;list-style:none} li{counter-increment:it} li::before{content:counters(it, ".") ") "}';
        $t = $this->text(BaurPDF::html("<style>$css</style><ol><li>А<ol><li>Б</li><li>В</li></ol></li><li>Г</li></ol>")->toString());
        $this->assertStringContainsString('1.2) В', $t);
        $this->assertStringContainsString('2) Г', $t);
    }

    public function testColumnSpanAll(): void
    {
        $p = '<p>' . str_repeat(self::T, 5) . '</p>';
        $pdf = BaurPDF::html("<div style='columns:2'>$p$p<h2 style='column-span:all'>ШИРОКИЙ</h2>$p<p>ХВОСТ</p></div>")->toString();
        $b = $this->boxes($pdf);
        $this->assertArrayHasKey('ШИРОКИЙ', $b);
        $this->assertLessThan(80, $b['ШИРОКИЙ'][0], 'spanning heading starts at the left edge');
        $this->assertGreaterThan($b['ШИРОКИЙ'][1], $b['ХВОСТ'][1], 'columns after the spanner continue below it');
    }

    public function testGridAreasSpansAndAutoFill(): void
    {
        $html = '<div style="display:grid;grid-template-areas:\'h h\' \'s m\';grid-template-columns:100pt 1fr;gap:4pt">'
            . '<div style="grid-area:m">ГЛАВНОЕ</div><div style="grid-area:h">ШАПКА</div><div style="grid-area:s">БОК</div></div>'
            . '<div style="display:grid;grid-template-columns:repeat(3,1fr)"><div style="grid-row:span 2">ДЛИННЫЙ</div><div>Б1</div><div>В1</div><div>Б2</div><div>В2</div></div>'
            . '<div style="display:grid;grid-template-columns:repeat(4,1fr);grid-auto-flow:row dense"><div style="grid-column:span 3">ТРИ</div><div style="grid-column:span 2">ДВА</div><div>ОДИН</div></div>';
        $b = $this->boxes(BaurPDF::html($html)->toString());
        $this->assertLessThan($b['БОК'][1], $b['ШАПКА'][1]);
        $this->assertEqualsWithDelta($b['БОК'][1], $b['ГЛАВНОЕ'][1], 1.0);
        $this->assertGreaterThan($b['БОК'][0] + 90, $b['ГЛАВНОЕ'][0]);
        // row-span: Б2 under Б1, in the second column
        $this->assertEqualsWithDelta($b['Б1'][0], $b['Б2'][0], 1.0);
        $this->assertGreaterThan($b['Б1'][1], $b['Б2'][1]);
        // dense: ОДИН backfills row 1
        $this->assertEqualsWithDelta($b['ТРИ'][1], $b['ОДИН'][1], 1.0);
        // malformed grids never crash
        $this->assertValidPdf(BaurPDF::html('<div style="display:grid;grid-template-columns:repeat(abc);grid-row:-9999/span 99999;grid-area:\'\'"><div style="grid-column:0/0">x</div></div>')->toString());
    }

    public function testFormControlsShareTextBaseline(): void
    {
        $b = $this->boxes(BaurPDF::html('<p>Имя: <input name="a" value="Бауржан" style="padding:4pt"> ок</p>')->toString());
        $this->assertEqualsWithDelta($b['Имя:'][1], $b['Бауржан'][1], 1.5, 'field text sits on the line baseline');
    }

    public function testSelectorAncestorPrefilter(): void
    {
        $s = new Selector('.a > ul li.b span');
        $this->assertSame(['li', '.b', 'ul', '.a'], $s->need);
        $d = new \DOMDocument(); $d->loadHTML('<div class="a"><ul><li class="b"><span id="t">x</span></li></ul></div>');
        $t = $d->getElementById('t'); $this->assertNotNull($t);
        $this->assertTrue($s->matches($t));
    }

    public function testDashboardFitsOnePage(): void
    {
        $f = __DIR__ . '/../examples/output/dashboard.pdf';
        if (!is_file($f)) $this->markTestSkipped('run examples/generate.php');
        $this->assertSame(1, $this->pages((string)file_get_contents($f)));
    }
}
