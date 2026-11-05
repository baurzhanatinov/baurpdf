<?php
declare(strict_types=1);
// DEV-ONLY: php render.php in.html out.pdf [--theme] → печатает {"ms":..,"mem":..} (время внутри процесса)
require __DIR__ . '/../../vendor/autoload.php';
[$_, $in, $outPdf] = $argv;
$theme = in_array('--theme', $argv, true);
$t = hrtime(true);
$pdf = \BaurPDF\BaurPDF::html((string)file_get_contents($in))->baseDir(dirname($in))->theme($theme)->deterministic();
// Fallback-цепочка из системных TTF (Chromium тоже берёт CJK/арабские шрифты из системы через fontconfig)
$G = '/usr/share/fonts/truetype/sand-box/google';
foreach ([["$G/BIZ UDPMincho/BIZUDPMincho-Regular.ttf", "$G/BIZ UDPMincho/BIZUDPMincho-Bold.ttf"], ["$G/ZCOOL XiaoWei/ZCOOLXiaoWei-Regular.ttf", null],
          ["$G/Nanum Gothic/NanumGothic-Regular.ttf", "$G/Nanum Gothic/NanumGothic-Bold.ttf"]] as [$r, $b]) if (is_file($r)) $pdf->fallbackFont($r, $b !== null && is_file($b) ? $b : null);
$ar = "$G/Noto Sans Arabic/NotoSansArabic-VariableFont_wdth,wght.ttf"; // переменный TTF: берётся default-инстанс (wght 400)
if (is_file($ar)) $pdf->font('Noto Sans Arabic', $ar);
$dm = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf'; // Chromium берёт настоящий DejaVu Sans Mono из системы
if (is_file($dm)) $pdf->font('DejaVu Sans Mono', $dm, is_file($b = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf') ? $b : null);
$pdf->save($outPdf);
echo json_encode(['ms' => round((hrtime(true) - $t) / 1e6, 1), 'mem' => round(memory_get_peak_usage(true) / 1048576, 1)]), "\n";
