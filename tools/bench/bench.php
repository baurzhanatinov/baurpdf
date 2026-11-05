<?php
// usage: php bench.php engine doc  -> JSON {t,mem,size,pages}
$mp = getenv('MPDF_AUTOLOAD') ?: '/workspace/bench/vendor/autoload.php'; if (is_file($mp)) require $mp; // mPDF — только для сравнения
require __DIR__ . '/../../vendor/autoload.php';
[$_, $eng, $doc] = $argv;
mt_srand(42);
$w = explode(' ', 'отчёт выручка клиент договор поставка Алматы Астана сумма услуга период анализ региональный показатель эффективность');
$txt = fn($n) => implode(' ', array_map(fn() => $w[mt_rand(0, count($w)-1)], range(1, $n)));
$css = 'body{font-family:dejavusans;font-size:10pt} table{border-collapse:collapse;width:100%} td,th{border:1px solid #ccc;padding:3pt} th{background:#eee} h1{color:#336}';
if ($doc === 'text') { $h = ''; for ($i=0;$i<200;$i++) $h .= "<h2>Раздел $i</h2><p>".$txt(120)."</p>"; }
elseif ($doc === 'table') { $h = '<table><thead><tr><th>№</th><th>Клиент</th><th>Описание</th><th>Сумма</th></tr></thead><tbody>'; for($i=1;$i<=1000;$i++) $h .= "<tr><td>$i</td><td>".$txt(2)."</td><td>".$txt(14)."</td><td>".number_format($i*1371,0,'',' ')."</td></tr>"; $h .= '</tbody></table>'; }
else { $h = '<h1>Счёт</h1><p>'.$txt(40).'</p><table><tr><th>A</th><th>B</th><th>C</th></tr>'; for($i=0;$i<15;$i++) $h.="<tr><td>".$txt(3)."</td><td>$i</td><td>".($i*100)."</td></tr>"; $h.='</table>'; }
$html = "<html><head><style>$css</style></head><body>$h</body></html>";
$t = microtime(true);
if ($eng === 'mpdf') { $m = new \Mpdf\Mpdf(['tempDir'=>sys_get_temp_dir() . '/mpdftmp','mode'=>'utf-8']); $m->WriteHTML($html); $out = $m->Output('', 'S'); }
else { $out = \BaurPDF\BaurPDF::html(str_replace('dejavusans','DejaVu Sans',$html))->toString(); }
$t = microtime(true) - $t;
file_put_contents(sys_get_temp_dir() . "/bench-$eng-$doc.pdf", $out);
echo json_encode(['t'=>round($t*1000),'mem'=>round(memory_get_peak_usage(true)/1048576,1),'size'=>round(strlen($out)/1024)]),"\n";
