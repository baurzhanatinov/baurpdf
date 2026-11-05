<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

/** Все PDF в examples/output валидны по qpdf. */
final class ExamplesTest extends TestCase
{
    public function testExampleOutputs(): void
    {
        $files = glob(__DIR__ . '/../examples/output/*.pdf') ?: [];
        if (!$files) $this->markTestSkipped('сначала: php examples/generate.php');
        foreach ($files as $f) $this->assertValidPdf((string)file_get_contents($f));
    }
}
