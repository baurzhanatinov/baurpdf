<?php
declare(strict_types=1);
namespace BaurPDF;

use BaurPDF\Exception\BaurPdfException;

/** Результат рендера: байты PDF/PNG + удобные методы вывода. */
final class Document
{
    public function __construct(private string $bytes, private string $mime = 'application/pdf', private ?int $pages = null) {}

    public function content(): string { return $this->bytes; }
    public function __toString(): string { return $this->bytes; }
    public function mime(): string { return $this->mime; }
    public function size(): int { return strlen($this->bytes); }
    public function base64(): string { return base64_encode($this->bytes); }
    public function dataUri(): string { return 'data:' . $this->mime . ';base64,' . $this->base64(); }

    public function pageCount(): int
    {
        if ($this->mime !== 'application/pdf') return 1;
        if ($this->pages !== null) return $this->pages;
        // Page-tree dictionaries may sit in compressed object streams (/ObjStm): inflate them too.
        // The root /Pages has the largest /Count; /PageLabels and /Pages never count as pages.
        $texts = [$this->bytes];
        if (preg_match_all('~/Type\s*/ObjStm\b[^>]*>>\s*stream\r?\n~', $this->bytes, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as [$hdr, $off]) {
                $start = $off + strlen($hdr); $end = strpos($this->bytes, 'endstream', $start);
                if ($end === false) continue;
                $raw = @gzuncompress(substr($this->bytes, $start, $end - $start));
                if (is_string($raw)) $texts[] = $raw;
            }
        }
        $count = null; $pageObjs = 0;
        foreach ($texts as $t) {
            if (preg_match_all('~<<((?:(?!<<|>>).)*?/Type\s*/Pages\b(?:(?!<<|>>).)*)>>~s', $t, $dm)) {
                foreach ($dm[1] as $d) if (preg_match('~/Count\s+(\d+)~', $d, $c)) $count = max($count ?? 0, (int)$c[1]);
            }
            $pageObjs += (int)preg_match_all('~/Type\s*/Page(?![A-Za-z])~', $t);
        }
        return $this->pages = $count ?? $pageObjs;
    }

    public function save(string $path): string
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new BaurPdfException("Cannot create dir $dir");
        }
        // A unique file in the destination directory prevents concurrent saves
        // from clobbering each other's temporary files.
        $tmp = @tempnam($dir, '.baurpdf-');
        if ($tmp === false) throw new BaurPdfException("Cannot create temporary file in $dir");
        try {
            $written = @file_put_contents($tmp, $this->bytes, LOCK_EX);
            @chmod($tmp, 0666 & ~umask()); // tempnam() creates 0600; keep the usual umask-based mode
            if ($written !== strlen($this->bytes) || !@rename($tmp, $path)) {
                throw new BaurPdfException("Cannot write $path");
            }
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
        return $path;
    }

    /** Показать в браузере (inline). Для чистого PHP; в Yii2 используйте Yii::$app->pdf->render(...)->download() или PdfResponse::send(). */
    public function stream(string $filename = 'document.pdf'): void { $this->send($filename, 'inline'); }
    public function download(string $filename = 'document.pdf'): void { $this->send($filename, 'attachment'); }

    private function send(string $filename, string $disposition): void
    {
        if (headers_sent($f, $l)) throw new BaurPdfException("Cannot send PDF: output already started in $f:$l. Remove any echo/whitespace before this call, or use ->toString() / ->save().");
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . $this->mime);
        header('Content-Length: ' . strlen($this->bytes));
        header(self::disposition($disposition, $filename));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $this->bytes;
    }

    public static function disposition(string $type, string $filename): string
    {
        $type = strtolower(trim($type));
        if (!in_array($type, ['inline', 'attachment'], true)) {
            throw new BaurPdfException('Unsupported content disposition');
        }
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '_', $filename) ?? 'document.pdf';
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $filename) ?? 'document.pdf';
        return sprintf('Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s', $type, $ascii, rawurlencode($filename));
    }
}
