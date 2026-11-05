<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/Exception/BaurPdfException.php';
require dirname(__DIR__, 2) . '/src/Document.php';
use BaurPDF\Document;
$tests = [
    ['inline', 'report.pdf', 'Content-Disposition: inline;'],
    ['attachment', 'test.pdf', 'Content-Disposition: attachment;'],
    ['attachment', "evil\r\nX-Injected: yes.pdf", 'X-Injected%3A%20yes.pdf'],
    ['attachment', 'quote"file.pdf', 'quote_file.pdf'],
    ['attachment', "résumé.pdf", 'r%C3%A9sum%C3%A9.pdf'],
];
$passed = 0;
foreach ($tests as [$type, $filename, $expected]) {
    $actual = Document::disposition($type, $filename);
    if (!str_contains($actual, $expected) || str_contains($actual, "\r") || str_contains($actual, "\n")) {
        fwrite(STDERR, "FAIL: " . $filename . "\n"); exit(1);
    }
    $passed++;
}
try { Document::disposition("attachment\r\nX-Test: yes", "x.pdf"); fwrite(STDERR, "FAIL invalid type\n"); exit(1); }
catch (BaurPDF\Exception\BaurPdfException $e) { $passed++; }
echo "PASS $passed disposition assertions\n";
