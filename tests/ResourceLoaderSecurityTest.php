<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

use BaurPDF\Layout\ResourceLoader;
use PHPUnit\Framework\TestCase;

final class ResourceLoaderSecurityTest extends TestCase
{
    public function testDataUriEnforcesDecodedByteLimit(): void
    {
        $loader = new ResourceLoader(maxBytes: 4);
        self::assertSame('abcd', $loader->load('data:text/plain;base64,YWJjZA=='));
        self::assertNull($loader->load('data:text/plain;base64,YWJjZGU='));
        self::assertContains('Data URI too large', $loader->warnings);
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        $loader = new ResourceLoader(baseDir: sys_get_temp_dir());
        self::assertNull($loader->load('php://filter/resource=/etc/passwd'));
        self::assertContains('Unsupported resource scheme', $loader->warnings);
    }

    public function testLocalFileLimitEvenWhenFileSizeChanges(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'baurpdf-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, '12345');
            $loader = new ResourceLoader(baseDir: dirname($file), maxBytes: 4);
            self::assertNull($loader->load($file));
            file_put_contents($file, '1234');
            self::assertSame('1234', (new ResourceLoader(baseDir: dirname($file), maxBytes: 4))->load($file));
        } finally { unlink($file); }
    }
    public function testRejectsRemoteFileAuthority(): void
    {
        $loader = new ResourceLoader(baseDir: sys_get_temp_dir());
        self::assertNull($loader->load('file://evil.example/etc/passwd'));
        self::assertContains('Remote file authority blocked', $loader->warnings);
    }

    public function testDataUriPercentEncodingAndInvalidBase64(): void
    {
        $loader = new ResourceLoader(maxBytes: 4);
        self::assertSame('abcd', $loader->load('data:text/plain,%61%62%63%64'));
        self::assertNull($loader->load('data:text/plain,%61%62%63%64%65'));
        self::assertNull($loader->load('data:text/plain;base64,@@@@'));
    }

    public function testCaseInsensitiveDataScheme(): void
    {
        $loader = new ResourceLoader(maxBytes: 5);
        self::assertSame('hello', $loader->load('DATA:text/plain;base64,aGVsbG8='));
    }
    public function testQuestionMarkInLocalFilenameIsNotDiscarded(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') self::markTestSkipped('NTFS не допускает "?" в имени файла');
        $dir = sys_get_temp_dir();
        $file = $dir . '/baurpdf-question-' . bin2hex(random_bytes(6)) . '?part';
        file_put_contents($file, 'valid');
        try {
            self::assertSame('valid', (new ResourceLoader(baseDir: $dir))->load($file));
        } finally { unlink($file); }
    }

    public function testCacheDoesNotGrowWithoutBound(): void
    {
        $loader = new ResourceLoader(maxBytes: 64);
        for ($i = 0; $i < 1000; ++$i) {
            self::assertSame('x', $loader->load('data:text/plain,x'));
            $loader->load('data:text/plain,' . str_repeat('a', 30) . $i);
        }
        $property = new \ReflectionProperty(ResourceLoader::class, 'cache');
        self::assertLessThanOrEqual(128, count($property->getValue($loader)));
    }

    public function testLocalResourceIsNotStaleAfterModification(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'baurpdf-cache-');
        self::assertNotFalse($file);
        try {
            file_put_contents($file, 'before');
            $loader = new ResourceLoader(baseDir: dirname($file));
            self::assertSame('before', $loader->load($file));
            file_put_contents($file, 'after');
            self::assertSame('after', $loader->load($file));
        } finally { unlink($file); }
    }

    public function testRejectsMalformedBase64Padding(): void
    {
        $loader = new ResourceLoader(maxBytes: 16);
        self::assertNull($loader->load('data:text/plain;base64,YQ==='));
        self::assertContains('Invalid base64 data URI', $loader->warnings);
    }

    public function testRejectsControlCharacterInIdentifier(): void
    {
        $loader = new ResourceLoader(baseDir: sys_get_temp_dir());
        self::assertNull($loader->load("file:///tmp/a\nb"));
        self::assertContains('Control character in resource identifier', $loader->warnings);
    }

    public function testRejectsCredentialsInRemoteUrl(): void
    {
        $loader = new ResourceLoader(allowRemote: true);
        self::assertNull($loader->load('https://user:pass@example.com/pic.png'));
        self::assertContains('Invalid remote resource URL', $loader->warnings);
    }

    public function testNulByteInPathIsRejected(): void
    {
        $loader = new ResourceLoader(baseDir: sys_get_temp_dir());
        self::assertNull($loader->load('file:///tmp/test%00.txt'));
        self::assertContains('NUL byte in file path', $loader->warnings);
    }
}
