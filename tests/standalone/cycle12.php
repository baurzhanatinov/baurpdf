<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/Document.php';
use BaurPDF\Document;
$cases = [
    ['%PDF-1.7\n1 0 obj << /Type /Pages /Count 2 >> endobj\n2 0 obj << /Type /Page /Parent 1 0 R >> endobj\n3 0 obj << /Type /Page /Parent 1 0 R >> endobj', 2],
    ['%PDF-1.7\n1 0 obj << /Type /Pages /Count 4 >> endobj', 4],
    ['%PDF-1.7\n1 0 obj << /Type /PageLabels >> endobj', 0],
    ['%PDF-1.7\n1 0 obj << /Type /Page /Parent 2 0 R >> endobj', 1],
];
foreach ($cases as $i => [$pdf, $expected]) {
    $actual = (new Document($pdf))->pageCount();
    if ($actual !== $expected) { fwrite(STDERR, "FAIL $i expected=$expected actual=$actual\n"); exit(1); }
}
if ((new Document('image', 'image/png'))->pageCount() !== 1) exit(1);
if ((new Document('bytes', 'application/pdf', 7))->pageCount() !== 7) exit(1);
echo "PASS 6 page-count assertions\n";
