<?php
declare(strict_types=1);
namespace BaurPDF\Barcode;

use BaurPDF\Exception\BaurPdfException;

/** Code 128 (набор B, ASCII 32–126) → SVG. */
final class Code128
{
    private const P = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];

    /** @return list<int> ширины полос/пробелов (в модулях), начиная с полосы */
    public static function widths(string $text): array
    {
        if ($text === '' || preg_match('/[^\x20-\x7E]/', $text)) throw new BaurPdfException('Code128: допускаются только ASCII-символы 32–126');
        $codes = [104]; $sum = 104;
        foreach (str_split($text) as $i => $ch) { $c = ord($ch) - 32; $codes[] = $c; $sum += $c * ($i + 1); }
        $codes[] = $sum % 103; $codes[] = 106;
        $w = [];
        foreach ($codes as $c) foreach (str_split(self::P[$c]) as $d) $w[] = (int)$d;
        return $w;
    }

    public static function svg(string $text, float $width = 160, float $height = 40, bool $label = true): string
    {
        $w = self::widths($text); $total = array_sum($w) + 20; $x = 10; $d = '';
        foreach ($w as $i => $bw) { if ($i % 2 === 0) $d .= "M$x 0h{$bw}v100h-{$bw}z"; $x += $bw; }
        $h = $label ? 118 : 100;
        $t = $label ? sprintf('<text x="%s" y="114" font-size="14" text-anchor="middle">%s</text>', $total / 2, htmlspecialchars($text)) : '';
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%spt" height="%spt" viewBox="0 0 %s %s" preserveAspectRatio="none"><rect width="%3$s" height="%4$s" fill="#fff"/><path d="%s"/>%s</svg>', $width, $height, $total, $h, $d, $t);
    }
}
