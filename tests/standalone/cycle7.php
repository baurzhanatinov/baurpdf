<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/BaurPDF.php';
use BaurPDF\BaurPDF;
$cases = [
    [[null], '<td></td>'],
    [['<script>alert(1)</script>'], '&lt;script&gt;alert(1)&lt;/script&gt;'],
    [["\xFF"], "\xEF\xBF\xBD"],
    [[[1, 2]], '<td></td>'],
    [[true], '<td>1</td>'],
    [['" onmouseover="evil'], '&quot; onmouseover=&quot;evil'],
];
foreach ($cases as $i => [$row, $expected]) {
    $html = BaurPDF::tableHtml([$row]);
    if (!str_contains($html, $expected)) { fwrite(STDERR, "FAIL $i\n"); exit(1); }
}
echo 'PASS ' . count($cases) . " tableHtml assertions\n";
