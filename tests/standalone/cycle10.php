<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/BaurPDF.php';
use BaurPDF\BaurPDF;
function verify(bool $ok, string $label): void { if (!$ok) {fwrite(STDERR, "FAIL: $label\n"); exit(1);} echo "PASS: $label\n"; }
$h = BaurPDF::tableHtml([['a'=>1,'b'=>2],['b'=>4,'a'=>3],['a'=>5],['a'=>6,'b'=>7,'c'=>8]]);
verify(substr_count($h,'<tr>') === 5, 'header and four rows');
verify(substr_count($h,'<td') === 8, 'rectangular cells');
verify(str_contains($h, '<td class="bp-num">3</td><td class="bp-num">4</td>'), 'associative key order');
verify(str_contains($h, '<td class="bp-num">5</td><td class="bp-num"></td>'), 'missing cell padded');
verify(!str_contains($h,'>8</td>'), 'extra cell omitted');
$p = BaurPDF::tableHtml([[1,2],[3],[4,5,6]]);
verify(substr_count($p,'<td') === 6, 'positional rows rectangular');
verify(str_contains($p,'<td class="bp-num">3</td><td class="bp-num"></td>'), 'positional missing cell');
verify(!str_contains($p,'>6</td>'), 'positional extra cell omitted');
