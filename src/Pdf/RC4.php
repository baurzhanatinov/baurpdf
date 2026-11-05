<?php
declare(strict_types=1);
namespace BaurPDF\Pdf;

final class RC4
{
    public static function crypt(string $key, string $data): string
    {
        $s = range(0, 255); $j = 0; $kl = strlen($key);
        for ($i = 0; $i < 256; $i++) { $j = ($j + $s[$i] + ord($key[$i % $kl])) & 255; [$s[$i], $s[$j]] = [$s[$j], $s[$i]]; }
        $i = $j = 0; $out = ''; $n = strlen($data);
        for ($k = 0; $k < $n; $k++) {
            $i = ($i + 1) & 255; $j = ($j + $s[$i]) & 255; [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) & 255]);
        }
        return $out;
    }
}
