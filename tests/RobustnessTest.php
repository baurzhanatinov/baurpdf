<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\BaurPDF;

/** Фаззинг: случайный/битый HTML и CSS никогда не роняют движок и не дают битый PDF. */
final class RobustnessTest extends TestCase
{
    public function testFuzz(): void
    {
        mt_srand(1337);
        $tags = ['div', 'p', 'span', 'table', 'tr', 'td', 'th', 'thead', 'ul', 'li', 'h1', 'h2', 'b', 'i', 'img', 'svg', 'pre', 'br', 'a', 'section', 'chart', 'blockquote'];
        $css = ['display:flex', 'display:grid;grid-template-columns:1fr 2fr', 'position:absolute;top:-50px', 'width:9999px', 'font-size:0', 'font-size:400pt', 'padding:-5px', 'margin:auto', 'page-break-before:always', 'float:left', 'transform:rotate(45deg)', 'border:3px dashed red', 'color:var(--nope)', 'width:calc(100% - 20px)', 'columns:3', 'height:0;overflow:hidden', 'white-space:pre', 'word-break:break-all'];
        $junk = ['<', '>', '&', '&#xFFFF;', "\0", "\xff\xfe", '<!--', ']]>', '"', 'Ёжик', '𝔘𝔫𝔦', '﷽', str_repeat('Ж', 300)];
        for ($n = 0; $n < 40; $n++) {
            $h = '';
            for ($k = 0, $L = mt_rand(5, 120); $k < $L; $k++) {
                $r = mt_rand(0, 9);
                if ($r < 4) { $t = $tags[array_rand($tags)]; $h .= "<$t style=\"" . $css[array_rand($css)] . '"' . ($t === 'td' && mt_rand(0, 1) ? ' colspan="' . mt_rand(0, 50) . '" rowspan="' . mt_rand(-1, 9) . '"' : '') . ($t === 'img' ? ' src="' . ['/etc/passwd', 'http://127.0.0.1/x.png', 'data:image/png;base64,AAAA', 'nope.jpg'][mt_rand(0, 3)] . '"' : '') . '>'; }
                elseif ($r < 6) $h .= '</' . $tags[array_rand($tags)] . '>';
                elseif ($r < 7) $h .= $junk[array_rand($junk)];
                else $h .= 'слово word ' . mt_rand();
            }
            if ($n % 5 === 0) $h = '<style>' . implode(';', array_rand(array_flip($css), 5)) . '} * {' . $css[array_rand($css)] . '</style>' . $h;
            $pdf = BaurPDF::html($h)->maxPages(50)->timeLimit(20)->toString();
            $this->assertValidPdf($pdf);
        }
    }

    public function testBinaryGarbage(): void
    {
        mt_srand(7);
        for ($i = 0; $i < 10; $i++) {
            $g = ''; for ($k = 0; $k < 4000; $k++) $g .= chr(mt_rand(0, 255));
            $this->assertValidPdf(BaurPDF::html($g)->toString());
        }
    }

    public function testSecureByDefault(): void
    {
        $b = BaurPDF::html('<img src="/etc/passwd"><img src="http://127.0.0.1:1/x.png"><script>alert(1)</script><p>ok</p>');
        $pdf = $b->toString();
        $this->assertValidPdf($pdf);
        $t = $this->text($pdf);
        $this->assertStringNotContainsString('alert', $t);
        $this->assertStringNotContainsString('root:', $t);
        $this->assertNotEmpty($b->warnings(), 'заблокированные ресурсы попадают в warnings()');
    }
}
