<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/Exception/BaurPdfException.php';
require dirname(__DIR__, 2) . '/src/Document.php';
use BaurPDF\Document;
use BaurPDF\Exception\BaurPdfException;
$dir = sys_get_temp_dir() . '/baurpdf-cycle11-' . bin2hex(random_bytes(4));
$doc = new Document("%PDF-1.4\ncycle11");
$path = $dir . '/sub/test.pdf';
if ($doc->save($path) !== $path || file_get_contents($path) !== $doc->content()) exit(1);
echo "PASS save nested directory\n";
if ($doc->save($path) !== $path || file_get_contents($path) !== $doc->content()) exit(1);
echo "PASS atomic replace\n";
if (glob(dirname($path).'/.baurpdf-*')) exit(1);
echo "PASS no temporary files left\n";
try { $doc->save($dir); exit(1); } catch (BaurPdfException $e) { echo "PASS failed rename throws\n"; }
if (glob($dir.'/.baurpdf-*')) exit(1);
echo "PASS failure cleanup\n";
unlink($path); rmdir(dirname($path)); rmdir($dir);
