<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/BaurPDF.php';
use BaurPDF\BaurPDF;
function check(bool $ok, string $name): void {
    if (!$ok) { fwrite(STDERR, "FAIL $name\n"); exit(1); }
    echo "PASS $name\n";
}
$html = BaurPDF::tableHtml([
    ['name' => '<script>alert(1)</script>', 'amount' => 1],
    ['amount' => 2, 'name' => 'second'],
    ['name' => null, 'amount' => 3],
]);
check(str_contains($html, '&lt;script&gt;'), 'html escaping');
check(!str_contains($html, '<script>'), 'no raw script');
check(strpos($html, 'second') < strpos($html, '>2</td>'), 'stable associative column order');
check(substr_count($html, '<td') === 6, 'all cells present');
check(str_contains(BaurPDF::tableHtml([['x' => ['bad']]]), '<td></td>'), 'nested arrays safe');
check(str_contains(BaurPDF::tableHtml([['x' => "\xC3\x28"]]), "\xEF\xBF\xBD"), 'invalid UTF-8 replaced');
check(str_contains(BaurPDF::tableHtml([], [], '<unsafe>'), '&lt;unsafe&gt;'), 'title escaped');
check(str_contains(BaurPDF::tableHtml([['x' => 'A &amp; B']]), 'A &amp;amp; B'), 'existing entities escaped');
