<?php require __DIR__ . '/../../vendor/autoload.php';
$n=(int)$argv[1]; mt_srand(1); $h='<table><thead><tr><th>№</th><th>Текст</th></tr></thead><tbody>';
for($i=0;$i<$n;$i++) $h.="<tr><td>$i</td><td>Строка отчёта с описанием поставки номер $i для клиента</td></tr>";
$t=microtime(true); $pdf=\BaurPDF\BaurPDF::html($h.'</tbody></table>')->toString();
printf("%5d rows: %4d ms, %5.1f MB, %d KB\n",$n,(microtime(true)-$t)*1000,memory_get_peak_usage(true)/1048576,strlen($pdf)/1024);
