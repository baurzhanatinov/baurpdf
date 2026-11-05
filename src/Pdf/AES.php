<?php
declare(strict_types=1);
namespace BaurPDF\Pdf;

/**
 * AES (FIPS-197), только шифрование — для PDF AESV3 (AES-256-CBC) и алгоритма 2.B (AES-128-CBC).
 * Чистый PHP на T-таблицах; если доступен ext-openssl, используется он (результат идентичен).
 */
final class AES
{
    /** Принудительно чистый PHP (тесты/отладка). */
    public static bool $pure = false;
    /** @var list<int> */
    private static array $sbox = [];
    /** @var array<int, list<int>> */
    private static array $T = [];

    /** CBC без дополнения (длина кратна 16). */
    public static function cbc(string $key, string $iv, string $data): string
    {
        if (strlen($data) % 16 !== 0) throw new \InvalidArgumentException('AES-CBC: data length must be a multiple of 16');
        if ($data === '') return '';
        if (!self::$pure && function_exists('openssl_encrypt')) {
            $alg = strlen($key) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';
            $r = openssl_encrypt($data, $alg, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if (is_string($r)) return $r;
        }
        $rk = self::expand($key);
        $out = ''; $prev = $iv; $n = strlen($data);
        for ($i = 0; $i < $n; $i += 16) { $prev = self::block($rk, substr($data, $i, 16) ^ $prev); $out .= $prev; }
        return $out;
    }

    /** CBC с PKCS#7 и случайным IV в начале (формат строк/потоков PDF AESV2/AESV3). */
    public static function encryptPdf(string $key, string $data, string $iv): string
    {
        $p = 16 - strlen($data) % 16;
        return $iv . self::cbc($key, $iv, $data . str_repeat(chr($p), $p));
    }

    public static function ecbBlock(string $key, string $block): string
    {
        return self::cbc($key, str_repeat("\0", 16), $block);
    }

    private static function init(): void
    {
        if (self::$sbox) return;
        $p = 1; $q = 1; $s = array_fill(0, 256, 0);
        do {
            $p = $p ^ (($p << 1) & 0xFF) ^ (($p & 0x80) ? 0x1B : 0);
            $q ^= ($q << 1) & 0xFF; $q ^= ($q << 2) & 0xFF; $q ^= ($q << 4) & 0xFF; if ($q & 0x80) $q ^= 0x09;
            $x = $q ^ (($q << 1) | ($q >> 7)) ^ (($q << 2) | ($q >> 6)) ^ (($q << 3) | ($q >> 5)) ^ (($q << 4) | ($q >> 4));
            $s[$p] = ($x ^ 0x63) & 0xFF;
        } while ($p !== 1);
        $s[0] = 0x63;
        self::$sbox = $s;
        $T0 = [];
        for ($i = 0; $i < 256; $i++) {
            $a = $s[$i]; $a2 = (($a << 1) ^ (($a & 0x80) ? 0x11B : 0)) & 0xFF; $a3 = $a2 ^ $a;
            $T0[$i] = ($a2 << 24) | ($a << 16) | ($a << 8) | $a3;
        }
        $rot = fn(int $v, int $r) => (($v >> $r) | ($v << (32 - $r))) & 0xFFFFFFFF;
        self::$T = [$T0, array_map(fn($v) => $rot($v, 8), $T0), array_map(fn($v) => $rot($v, 16), $T0), array_map(fn($v) => $rot($v, 24), $T0)];
    }

    /** @return list<int> */
    private static function expand(string $key): array
    {
        self::init();
        $s = self::$sbox; $nk = intdiv(strlen($key), 4); $nr = $nk + 6;
        $w = array_values(unpack('N*', $key) ?: []);
        $rc = 1;
        for ($i = $nk; $i < 4 * ($nr + 1); $i++) {
            $t = $w[$i - 1];
            if ($i % $nk === 0) {
                $t = (($t << 8) | ($t >> 24)) & 0xFFFFFFFF;
                $t = ($s[$t >> 24] << 24) | ($s[($t >> 16) & 0xFF] << 16) | ($s[($t >> 8) & 0xFF] << 8) | $s[$t & 0xFF];
                $t ^= $rc << 24; $rc = (($rc << 1) ^ (($rc & 0x80) ? 0x11B : 0)) & 0xFF;
            } elseif ($nk > 6 && $i % $nk === 4) {
                $t = ($s[$t >> 24] << 24) | ($s[($t >> 16) & 0xFF] << 16) | ($s[($t >> 8) & 0xFF] << 8) | $s[$t & 0xFF];
            }
            $w[$i] = $w[$i - $nk] ^ $t;
        }
        return $w;
    }

    /** @param list<int> $w */
    private static function block(array $w, string $in): string
    {
        [$T0, $T1, $T2, $T3] = self::$T; $s = self::$sbox;
        $v = unpack('N4', $in) ?: [1 => 0, 0, 0, 0];
        $a = $v[1] ^ $w[0]; $b = $v[2] ^ $w[1]; $c = $v[3] ^ $w[2]; $d = $v[4] ^ $w[3];
        $nr = intdiv(count($w), 4) - 1; $k = 4;
        for ($r = 1; $r < $nr; $r++) {
            $e = $T0[$a >> 24] ^ $T1[($b >> 16) & 0xFF] ^ $T2[($c >> 8) & 0xFF] ^ $T3[$d & 0xFF] ^ $w[$k];
            $f = $T0[$b >> 24] ^ $T1[($c >> 16) & 0xFF] ^ $T2[($d >> 8) & 0xFF] ^ $T3[$a & 0xFF] ^ $w[$k + 1];
            $g = $T0[$c >> 24] ^ $T1[($d >> 16) & 0xFF] ^ $T2[($a >> 8) & 0xFF] ^ $T3[$b & 0xFF] ^ $w[$k + 2];
            $h = $T0[$d >> 24] ^ $T1[($a >> 16) & 0xFF] ^ $T2[($b >> 8) & 0xFF] ^ $T3[$c & 0xFF] ^ $w[$k + 3];
            $a = $e; $b = $f; $c = $g; $d = $h; $k += 4;
        }
        $o = fn(int $p, int $q, int $r2, int $t, int $kk) => ((($s[$p >> 24] << 24) | ($s[($q >> 16) & 0xFF] << 16) | ($s[($r2 >> 8) & 0xFF] << 8) | $s[$t & 0xFF]) ^ $kk) & 0xFFFFFFFF;
        return pack('N4', $o($a, $b, $c, $d, $w[$k]), $o($b, $c, $d, $a, $w[$k + 1]), $o($c, $d, $a, $b, $w[$k + 2]), $o($d, $a, $b, $c, $w[$k + 3]));
    }
}
