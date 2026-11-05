<?php
declare(strict_types=1);
namespace BaurPDF\Pdf;

/**
 * Компактный ICC v2 профиль sRGB (IEC 61966-2-1), генерируется кодом — для OutputIntent PDF/A.
 * Первичные цвета адаптированы к D50 (Bradford), TRC — таблица 1024 точки по формуле sRGB.
 */
final class Icc
{
    public static function srgb(): string
    {
        $s15 = fn(float $v) => pack('N', (int)round($v * 65536) & 0xFFFFFFFF);
        $xyz = fn(float $x, float $y, float $z) => 'XYZ ' . "\0\0\0\0" . $s15($x) . $s15($y) . $s15($z);
        $desc = function (string $t): string {
            return 'desc' . "\0\0\0\0" . pack('N', strlen($t) + 1) . $t . "\0" . pack('NN', 0, 0) . pack('nC', 0, 0) . str_repeat("\0", 67);
        };
        $curve = ''; $n = 1024;
        for ($i = 0; $i < $n; $i++) {
            $v = $i / ($n - 1);
            $l = $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            $curve .= pack('n', (int)round($l * 65535));
        }
        $trc = 'curv' . "\0\0\0\0" . pack('N', $n) . $curve;
        $tags = [
            'desc' => $desc('sRGB IEC61966-2.1 (BaurPDF)'),
            'cprt' => "text\0\0\0\0Public Domain\0",
            'wtpt' => $xyz(0.9642, 1.0, 0.8249),
            'rXYZ' => $xyz(0.4361, 0.2225, 0.0139),
            'gXYZ' => $xyz(0.3851, 0.7169, 0.0971),
            'bXYZ' => $xyz(0.1431, 0.0606, 0.7141),
            'rTRC' => $trc,
        ];
        $sigs = array_merge(array_keys($tags), ['gTRC', 'bTRC']);
        $tableLen = 4 + 12 * count($sigs);
        $off = 128 + $tableLen; $data = ''; $entries = []; $where = [];
        foreach ($tags as $sig => $body) {
            $where[$sig] = [$off + strlen($data), strlen($body)];
            $data .= $body . str_repeat("\0", (4 - strlen($body) % 4) % 4);
        }
        $where['gTRC'] = $where['bTRC'] = $where['rTRC']; // общие данные кривой
        $table = pack('N', count($sigs));
        foreach ($sigs as $sig) $table .= $sig . pack('NN', ...$where[$sig]);
        $size = 128 + $tableLen + strlen($data);
        $hdr = pack('N', $size) . "\0\0\0\0" . pack('N', 0x02100000) . 'mntrRGB XYZ ' . pack('n6', 2000, 1, 1, 0, 0, 0)
            . 'acsp' . "\0\0\0\0" . pack('N', 0) . "\0\0\0\0\0\0\0\0" . str_repeat("\0", 8) . pack('N', 0)
            . $s15(0.9642) . $s15(1.0) . $s15(0.8249) . "\0\0\0\0" . str_repeat("\0", 44);
        return $hdr . $table . $data;
    }
}
