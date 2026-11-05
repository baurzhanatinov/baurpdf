<?php
require __DIR__ . '/../../vendor/autoload.php';
$n=(int)($argv[1]??50000);
$gen=(function()use($n){for($i=1;$i<=$n;$i++) yield [$i,"Item $i","Category ".($i%17),number_format($i*1.37,2)];})();
$t=microtime(true);
$d=BaurPDF\BaurPDF::html('<h1>Report</h1><p>Intro</p><table data-bp-stream style="width:100%;border-collapse:collapse"><thead><tr><th>#</th><th>Name</th><th>Cat</th><th>Sum</th></tr></thead></table><style>td,th{border:1px solid #999;padding:2px}</style>')->footer('{PAGENO}/{nbpg}')->deterministic()->streamTable($gen);
$doc=$d->render(); 
printf("rows %d pages %d %.1fs peak %.1fMB size %dKB warn %s\n",$n,$doc->pageCount(),microtime(true)-$t,memory_get_peak_usage()/1048576,strlen($doc->content())/1024,json_encode($d->warnings()));
