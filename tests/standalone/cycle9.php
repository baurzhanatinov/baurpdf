<?php
require dirname(__DIR__, 2) . '/src/BaurPDF.php';
use BaurPDF\BaurPDF;
function verify(bool $ok, string $label): void { if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } echo "PASS: $label\n"; }
$html = BaurPDF::tableHtml([['name' => '&lt;img src=x&gt;', 'n' => 1000000], ['n' => 2, 'name' => 'other']]);
verify(str_contains($html, '&amp;lt;img'), 'encoded entities cannot be smuggled');
verify(str_contains($html, '1' . "\u{00A0}" . '000' . "\u{00A0}" . '000'), 'thousands use NBSP');
verify(strpos($html, 'other') < strpos($html, '>2</td>'), 'associative column order stable');
verify(str_contains(BaurPDF::tableHtml([['x' => "\xC3\x28"]]), "\xEF\xBF\xBD"), 'invalid UTF-8 replaced');
verify(!str_contains(BaurPDF::tableHtml([['x' => '<script>']]), '<script>'), 'HTML escaped');
