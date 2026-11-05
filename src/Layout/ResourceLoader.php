<?php
declare(strict_types=1);
namespace BaurPDF\Layout;

/**
 * Безопасная загрузка ресурсов: data: URI, локальные файлы только из allowlist-каталогов,
 * http(s) — только если явно разрешено (allowRemote), с таймаутом и лимитом размера.
 */
final class ResourceLoader
{
    private array $cache = [];
    private int $cacheBytes = 0;
    private const MAX_CACHE_ENTRIES = 128;
    private const MAX_CACHE_BYTES = 8_388_608;
    public array $warnings = [];

    public function __construct(
        private ?string $baseDir = null,
        private array $allowedPaths = [],
        private bool $allowRemote = false,
        private int $maxBytes = 20_000_000,
        private float $timeout = 10,
    ) {
        $this->allowedPaths = array_values(array_filter(array_map(fn($p) => realpath($p) ?: null, array_merge($allowedPaths, $baseDir ? [$baseDir] : []))));
    }

    public function load(string $src): ?string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Refuse control characters before URL/path parsing.
        if (preg_match('/[\x00-\x1F\x7F]/', $src)) {
            $this->warnings[] = 'Control character in resource identifier';
            return null;
        }
        if ($src === '') return null;
        if ($this->maxBytes <= 0) { $this->warnings[] = 'Resource size limit must be positive'; return null; }
        // Never treat unsupported URI schemes as local filenames (one letter + «:» — это диск Windows, не схема).
        if (preg_match('/^[a-z][a-z0-9+.-]+:/i', $src) && !preg_match('#^(?:data:|https?://|file://)#i', $src)) {
            $this->warnings[] = 'Unsupported resource scheme';
            return null;
        }
        $cacheable = strlen($src) <= 4096;
        // Local files can change or be replaced by symlinks; never return stale cached bytes.
        $cacheable = $cacheable && strncasecmp($src, 'data:', 5) === 0;
        if ($cacheable && array_key_exists($src, $this->cache)) return $this->cache[$src];
        $data = null;
        try {
            if (strncasecmp($src, 'data:', 5) === 0) {
                if (preg_match('#^data:([^;,]*)(;charset=[^;,]*)?(;base64)?,(.*)$#is', $src, $m)) {
                    $payload = $m[4];
                    if (!empty($m[3])) {
                        // Reject oversized input before allocating the decoded binary blob.
                        // Whitespace and malformed padding must not be silently accepted.
                        $encoded = $payload;
                        if (!preg_match('#^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$#D', $encoded) ) {
                            $this->warnings[] = 'Invalid base64 data URI';
                            return null;
                        }
                        if (strlen($encoded) > 4 * (int) ceil($this->maxBytes / 3) + 4) {
                            $this->warnings[] = 'Data URI too large';
                            return null;
                        }
                        $data = base64_decode($encoded, true);
                        // Canonical form only (no stray padding bits); checked after the size limit, single decode.
                        if ($data !== false && base64_encode($data) !== $encoded) { $this->warnings[] = 'Invalid base64 data URI'; return null; }
                    } else {
                        // Percent-encoding can only shrink when decoded.
                        if (strlen($payload) > $this->maxBytes * 3) {
                            $this->warnings[] = 'Data URI too large';
                            return null;
                        }
                        if (preg_match('/%(?![0-9a-fA-F]{2})/', $payload)) {
                            $this->warnings[] = 'Malformed percent encoding in data URI';
                            return null;
                        }
                        $data = rawurldecode($payload);
                    }
                    if ($data === false) { $this->warnings[] = 'Invalid base64 data URI'; $data = null; }
                    if ($data !== null && strlen($data) > $this->maxBytes) { $this->warnings[] = 'Data URI too large'; $data = null; }
                }
            } elseif (preg_match('#^https?://#i', $src)) {
                if (!$this->allowRemote) { $this->warnings[] = "Remote resource blocked (allowRemote=false): $src"; }
                elseif (!filter_var($src, FILTER_VALIDATE_URL) || parse_url($src, PHP_URL_USER) !== null || parse_url($src, PHP_URL_PASS) !== null) {
                    $this->warnings[] = 'Invalid remote resource URL';
                }
                else {
                    $ctx = stream_context_create(['http' => ['timeout' => $this->timeout, 'follow_location' => 0, 'max_redirects' => 0, 'user_agent' => 'BaurPDF'], 'ssl' => ['verify_peer' => true]]);
                    // Read one extra byte so a truncated response is never mistaken for a valid image.
                    $d = @file_get_contents($src, false, $ctx, 0, $this->maxBytes + 1);
                    $data = $d === false ? null : $d;
                    if ($data !== null && strlen($data) > $this->maxBytes) { $this->warnings[] = 'Remote resource too large'; $data = null; }
                    $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null); // @phpstan-ignore nullCoalesce.variable
                    if (is_array($headers)) {
                        $status = null;
                        foreach ($headers as $header) {
                            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $statusMatch)) $status = (int)$statusMatch[1];
                        }
                        if ($status === null || $status < 200 || $status >= 300) {
                            $this->warnings[] = 'Remote resource returned non-success HTTP status';
                            $data = null;
                        }
                    }
                }
            } else {
                // file:// URLs must not specify a remote host (or a deceptive authority).
                if (preg_match('#^file://([^/]+)#i', $src, $authority) && strcasecmp($authority[1], 'localhost') !== 0) {
                    $this->warnings[] = 'Remote file authority blocked';
                    return null;
                }
                $path = (string) preg_replace('#^file://(?:localhost)?#i', '', $src);
                $path = rawurldecode($path);
                if (str_contains($path, chr(0))) { $this->warnings[] = 'NUL byte in file path'; return null; }
                // UNC (\\\\host\\share, //host/share): realpath() на Windows полез бы в сеть (утечка NTLM) ещё до проверки allowPath
                if (preg_match('#^(?:\\\\\\\\|//|\\\\/|/\\\\)#', $path)) { $this->warnings[] = 'UNC/network paths are not allowed'; return null; }
                // file:///C:/x → C:/x; абсолютным считаем и Windows-путь с буквой диска
                if (preg_match('#^/[A-Za-z]:[\\\\/]#', $path)) $path = substr($path, 1);
                if (!self::isAbsolute($path) && $this->baseDir) $path = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR . $path;
                $real = realpath($path);
                if ($real !== false) clearstatcache(true, $real); // файл могли перезаписать между вызовами
                if ($real === false || !is_file($real)) { $this->warnings[] = "File not found: $src"; }
                elseif (!$this->allowed($real)) { $this->warnings[] = "File outside allowed paths: $src"; }
                elseif (filesize($real) > $this->maxBytes) { $this->warnings[] = "File too large: $src"; }
                else {
                    $handle = @fopen($real, 'rb');
                    if ($handle !== false) {
                        try {
                            $bytes = stream_get_contents($handle, $this->maxBytes + 1);
                            if ($bytes !== false && strlen($bytes) <= $this->maxBytes) $data = $bytes;
                            else $this->warnings[] = 'File too large or unreadable';
                        } finally { fclose($handle); }
                    }
                }
            }
        } catch (\Throwable $e) { $this->warnings[] = 'Resource error: ' . $e->getMessage(); $data = null; }
        if ($cacheable && $data !== null && strlen($data) <= 262144) {
            if (count($this->cache) >= self::MAX_CACHE_ENTRIES || $this->cacheBytes + strlen($data) > self::MAX_CACHE_BYTES) {
                $this->cache = [];
                $this->cacheBytes = 0;
            }
            $this->cache[$src] = $data;
            $this->cacheBytes += strlen($data);
        }
        return $data;
    }

    private function allowed(string $real): bool
    {
        // realpath отдаёт разделитель ОС: на Windows — обратный слеш
        foreach ($this->allowedPaths as $p) if ($real === $p || str_starts_with($real, rtrim($p, '/\\') . DIRECTORY_SEPARATOR)) return true;
        return false;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
