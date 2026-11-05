<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/Exception/BaurPdfException.php';
require dirname(__DIR__, 2) . '/src/Options.php';
require dirname(__DIR__, 2) . '/src/BaurPDF.php';
use BaurPDF\BaurPDF;
use BaurPDF\Exception\BaurPdfException;
$checks = 0;
try { BaurPDF::fromFile('/nonexistent-baurpdf-test.html'); exit(1); }
catch (BaurPdfException $e) { $checks++; }
$file = tempnam(sys_get_temp_dir(), 'bp13_');
file_put_contents($file, '');
$m = BaurPDF::fromFile($file);
if ($m instanceof BaurPDF) $checks++; else exit(2);
file_put_contents($file, '<p>ok</p>');
if (BaurPDF::fromFile($file) instanceof BaurPDF) $checks++; else exit(3);
unlink($file);
echo "PASS $checks fromFile checks\n";
