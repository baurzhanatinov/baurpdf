<?php
declare(strict_types=1);
namespace BaurPDF\Pdf;

/** Разбор JPEG/PNG (+GIF/WebP/BMP через GD, если есть) в формат для PDF XObject. Ошибки → null (никогда не падаем). */
final class Image
{
    public static function parse(string $d): ?array
    {
        try {
            if (str_starts_with($d, "\xFF\xD8")) return self::jpeg($d);
            if (str_starts_with($d, "\x89PNG")) return self::png($d);
            if (function_exists('imagecreatefromstring')) return self::viaGd($d);
        } catch (\Throwable) {}
        return null;
    }

    /** Есть ли у картинки прозрачность — по заголовку формата, без обхода пикселей. */
    private static function formatAlpha(string $d, \GdImage $im): bool
    {
        if (str_starts_with($d, 'RIFF') && substr($d, 8, 4) === 'WEBP') {
            $fourcc = substr($d, 12, 4);
            if ($fourcc === 'VP8 ') return false;
            if ($fourcc === 'VP8X') return (ord($d[20] ?? "\0") & 0x10) !== 0;
            if ($fourcc === 'VP8L') return (ord($d[24] ?? "\0") & 0x10) !== 0; // alpha_is_used — бит 28 заголовка VP8L
            return true;
        }
        if (str_starts_with($d, 'GIF')) return imagecolortransparent($im) >= 0;
        if (str_starts_with($d, 'BM')) return false;
        return true;
    }

    private static function jpeg(string $d): ?array
    {
        $i = 2; $n = strlen($d);
        while ($i < $n - 9) {
            if ($d[$i] !== "\xFF") { $i++; continue; }
            $m = ord($d[$i + 1]);
            if ($m >= 0xC0 && $m <= 0xCF && !in_array($m, [0xC4, 0xC8, 0xCC], true)) {
                $h = self::u16($d, $i + 5); $w = self::u16($d, $i + 7); $c = ord($d[$i + 9]);
                $cs = $c === 1 ? 'DeviceGray' : ($c === 4 ? 'DeviceCMYK' : 'DeviceRGB');
                return ['w' => $w, 'h' => $h, 'cs' => $cs, 'filter' => 'DCTDecode', 'data' => $d, 'alpha' => null, 'orient' => self::orientation($d)];
            }
            $i += 2 + self::u16($d, $i + 2);
        }
        return null;
    }

    /** EXIF Orientation (1–8) JPEG; 1 — как есть. Снимки с телефона хранят пиксели повёрнутыми, а поворот — здесь. */
    public static function orientation(string $d): int
    {
        if (!str_starts_with($d, "\xFF\xD8")) return 1;
        $i = 2; $n = min(strlen($d), 131072);
        while ($i < $n - 4 && $d[$i] === "\xFF") {
            $m = ord($d[$i + 1]); $len = self::u16($d, $i + 2);
            if ($m === 0xDA || $m === 0xD9) break; // начало данных — EXIF дальше не бывает
            if ($m === 0xE1 && substr($d, $i + 4, 6) === "Exif\0\0") {
                $t = $i + 10; $le = substr($d, $t, 2) === 'II';
                $u16 = fn(int $o) => $le ? (ord($d[$o] ?? "\0") | (ord($d[$o + 1] ?? "\0") << 8)) : self::u16($d, $o);
                $u32 = fn(int $o) => $le ? ($u16($o) | ($u16($o + 2) << 16)) : self::u32($d, $o);
                $ifd = $t + $u32($t + 4); $cnt = $u16($ifd);
                for ($e = 0; $e < $cnt && $e < 200; $e++) {
                    $p = $ifd + 2 + $e * 12;
                    if ($u16($p) === 0x0112) { $v = $u16($p + 8); return $v >= 1 && $v <= 8 ? $v : 1; }
                }
                return 1;
            }
            $i += 2 + $len;
        }
        return 1;
    }

    private static function png(string $d): ?array
    {
        $p = 8; $idat = ''; $pal = ''; $trns = null; $w = $h = $bit = $ct = $il = 0;
        while ($p < strlen($d)) {
            $len = self::u32($d, $p); $type = substr($d, $p + 4, 4); $chunk = substr($d, $p + 8, $len);
            if ($type === 'IHDR') { $w = self::u32($chunk, 0); $h = self::u32($chunk, 4); $bit = ord($chunk[8]); $ct = ord($chunk[9]); $il = ord($chunk[12]); }
            elseif ($type === 'PLTE') $pal = $chunk;
            elseif ($type === 'tRNS') $trns = $chunk;
            elseif ($type === 'IDAT') $idat .= $chunk;
            elseif ($type === 'IEND') break;
            $p += 12 + $len;
        }
        if (!$w || !$h || $w * $h > 40_000_000) return null;
        if (!$il && $trns === null && (($ct === 3 && $pal !== '' && in_array($bit, [1, 2, 4, 8], true)) || ($ct === 0 && in_array($bit, [1, 2, 4], true)))) {
            // палитра / серый 1-4 бит: IDAT как есть, Indexed-цветовое пространство (в 3 раза меньше, чем RGB)
            $n = intdiv(strlen($pal), 3);
            $cs = $ct === 3 ? '[/Indexed /DeviceRGB ' . max(0, $n - 1) . ' <' . bin2hex(substr($pal, 0, $n * 3)) . '>]' : 'DeviceGray';
            return ['w' => $w, 'h' => $h, 'cs' => $cs, 'bpc' => $bit, 'filter' => 'Flate', 'raw' => true,
                'parms' => "<< /Predictor 15 /Colors 1 /BitsPerComponent $bit /Columns $w >>", 'data' => $idat, 'alpha' => null];
        }
        if ($il || $bit !== 8 || $trns !== null) return self::viaGd($d, $ct === 4 || $ct === 6 || $trns !== null) ?? (($bit === 8 && $ct === 3 && !$il) ? self::decode($idat, $w, $h, $ct, $pal, $trns) : null);
        if ($ct === 0 || $ct === 2) {
            // fast path: отдаём IDAT как есть с PNG-предиктором
            $colors = $ct === 2 ? 3 : 1;
            return ['w' => $w, 'h' => $h, 'cs' => $ct === 2 ? 'DeviceRGB' : 'DeviceGray', 'filter' => 'Flate', 'raw' => true,
                'parms' => "<< /Predictor 15 /Colors $colors /BitsPerComponent 8 /Columns $w >>", 'data' => $idat, 'alpha' => null];
        }
        return self::decode($idat, $w, $h, $ct, $pal, $trns);
    }

    /**
     * Перекодировать через GD в 8-битный PNG без чересстрочности. Без альфы получается RGB-PNG,
     * который уходит в PDF как есть (IDAT + предиктор) — ни обхода пикселей, ни сырых байтов в памяти.
     */
    private static function viaGd(string $d, ?bool $alpha = null): ?array
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $im = @imagecreatefromstring($d);
        if (!$im) return null;
        $alpha ??= self::formatAlpha($d, $im);
        imagepalettetotruecolor($im); // палитра с прозрачным цветом иначе снова даст tRNS и рекурсию
        imageinterlace($im, false);   // GD сохраняет флаг чересстрочности источника — без сброса снова попали бы сюда
        imagesavealpha($im, $alpha);
        ob_start(); imagepng($im); $png = (string)ob_get_clean(); imagedestroy($im);
        return $png !== '' && $png !== $d ? self::png($png) : null;
    }

    /** Чистый PHP: inflate + unfilter + разделение альфы. */
    private static function decode(string $idat, int $w, int $h, int $ct, string $pal, ?string $trns): ?array
    {
        $raw = @gzuncompress($idat);
        if ($raw === false) return null;
        $bpp = match ($ct) { 0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4, default => 0 };
        if (!$bpp) return null;
        $stride = $w * $bpp; $prev = str_repeat("\0", $stride); $rgb = ''; $alpha = '';
        $hasA = $ct === 4 || $ct === 6;
        for ($y = 0; $y < $h; $y++) {
            $f = ord($raw[$y * ($stride + 1)] ?? "\0");
            $line = substr($raw, $y * ($stride + 1) + 1, $stride);
            if (strlen($line) < $stride) $line = str_pad($line, $stride, "\0");
            if ($f !== 0) {
                $out = $line;
                for ($i = 0; $i < $stride; $i++) {
                    $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0; $b = ord($prev[$i]); $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0; $x = ord($line[$i]);
                    $v = match ($f) { 1 => $x + $a, 2 => $x + $b, 3 => $x + (($a + $b) >> 1), 4 => $x + self::paeth($a, $b, $c), default => $x };
                    $out[$i] = chr($v & 255);
                }
                $line = $out;
            }
            $prev = $line;
            if ($ct === 6) { for ($i = 0; $i < $stride; $i += 4) { $rgb .= substr($line, $i, 3); $alpha .= $line[$i + 3]; } }
            elseif ($ct === 4) { for ($i = 0; $i < $stride; $i += 2) { $rgb .= $line[$i]; $alpha .= $line[$i + 1]; } }
            elseif ($ct === 3) {
                for ($i = 0; $i < $stride; $i++) { $ix = ord($line[$i]); $rgb .= substr($pal, $ix * 3, 3) ?: "\0\0\0"; if ($trns !== null) $alpha .= $trns[$ix] ?? "\xFF"; }
                if ($trns !== null) $hasA = true;
            } else $rgb .= $line;
        }
        $cs = ($ct === 0 || $ct === 4) ? 'DeviceGray' : 'DeviceRGB';
        return ['w' => $w, 'h' => $h, 'cs' => $cs, 'filter' => 'Flate', 'data' => $rgb, 'alpha' => $hasA ? $alpha : null];
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    /**
     * Уменьшение через GD (bicubic resample). JPEG → JPEG ($q), остальное → PNG с альфой.
     * Ошибка или отсутствие GD → null (картинка остаётся как была).
     */
    public static function downscale(string $d, int $w, int $h, int $q = 85, int $orient = 1): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;
        try {
            $src = @imagecreatefromstring($d);
            if (!$src) return null;
            // $w×$h — размер на экране; при EXIF 5–8 пиксели лежат повёрнутыми, поэтому ресемплим в обменённые размеры и доворачиваем
            $rot = $orient >= 5 && $orient <= 8;
            $dst = imagecreatetruecolor($rot ? $h : $w, $rot ? $w : $h);
            if (!$dst) return null;
            $jpeg = str_starts_with($d, "\xFF\xD8");
            $png = str_starts_with($d, "\x89PNG");
            $alpha = !$jpeg && (!$png || in_array(ord($d[25] ?? "\0"), [4, 6], true) || str_contains(substr($d, 0, 8192), 'tRNS'));
            if ($alpha) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, (int)imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $rot ? $h : $w, $rot ? $w : $h, imagesx($src), imagesy($src));
            // EXIF 2–8: поворот (GD — против часовой) и зеркало; 5 — транспонирование, 7 — антитранспонирование
            $ang = [3 => 180, 5 => -90, 6 => -90, 7 => 90, 8 => 90][$orient] ?? 0;
            if ($ang) { $r = imagerotate($dst, $ang, 0); if ($r) $dst = $r; }
            if (in_array($orient, [2, 5, 7], true)) imageflip($dst, IMG_FLIP_HORIZONTAL);
            elseif ($orient === 4) imageflip($dst, IMG_FLIP_VERTICAL);
            ob_start();
            $jpeg ? imagejpeg($dst, null, $q) : imagepng($dst, null, 9);
            $out = (string)ob_get_clean();
            return $out !== '' && strlen($out) < strlen($d) ? $out : null;
        } catch (\Throwable) { return null; }
    }

    /** Размер без полного декодирования. */
    public static function size(string $d): ?array
    {
        $r = @getimagesizefromstring($d);
        if (!$r) return null;
        return in_array(self::orientation($d), [5, 6, 7, 8], true) ? [$r[1], $r[0]] : [$r[0], $r[1]]; // размер на экране
    }

    private static function u16(string $d, int $o): int { $v = @unpack('n', $d, $o); return is_array($v) ? (int)$v[1] : 0; }
    private static function u32(string $d, int $o): int { $v = @unpack('N', $d, $o); return is_array($v) ? (int)$v[1] : 0; }
}
