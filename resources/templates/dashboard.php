<?php
/** @var callable $e  Дашборд: title, period, kpis[{label,value,delta}], charts[{title,chart,span}], table{headers,rows}, accent */
$title ??= 'Дашборд'; $period ??= ''; $kpis ??= []; $charts ??= []; $table ??= null; $accent ??= '#2563EB';
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title><?= $e($title) ?></title>
<style>
@page { size: A4 landscape; margin: 14mm 12mm }
body { background: #F1F5F9; font-size: 9.5pt }
.top { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 10pt }
.top h1 { margin: 0; font-size: 18pt } .top .p { color: #64748B }
.k { display: grid; grid-template-columns: repeat(<?= max(1, min(6, count($kpis))) ?>, 1fr); gap: 8pt; margin-bottom: 8pt }
.k > div { background: #fff; border-radius: 8pt; padding: 9pt 11pt; box-shadow: 0 1pt 4pt rgba(15,23,42,.10); border-top: 3pt solid <?= $e($accent) ?> }
.k .l { color: #64748B; font-size: 8pt; text-transform: uppercase; letter-spacing: .5pt } .k .v { font-size: 17pt; font-weight: bold; margin-top: 2pt }
.up { color: #16A34A; font-size: 8.5pt } .down { color: #DC2626; font-size: 8.5pt }
.g { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8pt }
.card { background: #fff; border-radius: 8pt; padding: 8pt 10pt; box-shadow: 0 1pt 4pt rgba(15,23,42,.10); break-inside: avoid }
.card h3 { margin: 0 0 4pt; font-size: 10pt } .card chart { margin: 0 }
.s2 { grid-column: span 2 } .s3 { grid-column: span 3 }
table { font-size: 8.5pt; margin: 0 } .card th, .card td { padding: 3pt 6pt }
<?= $css ?? '' ?></style></head><body>
<div class="top"><h1><?= $e($title) ?></h1><div class="p"><?= $e($period) ?></div></div>
<div class="k"><?php foreach ($kpis as $k): $d = (string)($k['delta'] ?? ''); ?><div><div class="l"><?= $e($k['label'] ?? '') ?></div><div class="v"><?= $e($k['value'] ?? '') ?></div><?php if ($d !== ''): ?><div class="<?= str_starts_with($d, '-') ? 'down' : 'up' ?>"><?= str_starts_with($d, '-') ? '▼' : '▲' ?> <?= $e($d) ?></div><?php endif ?></div><?php endforeach ?></div>
<div class="g"><?php foreach ($charts as $c): ?><div class="card s<?= (int)($c['span'] ?? 1) ?>"><h3><?= $e($c['title'] ?? '') ?></h3><?= $c['chart'] ?? '' ?></div><?php endforeach ?>
<?php if ($table): ?><div class="card s3"><table class="bp-striped"><thead><tr><?php foreach ($table['headers'] as $h): ?><th><?= $e($h) ?></th><?php endforeach ?></tr></thead><tbody><?php foreach ($table['rows'] as $r): ?><tr><?php foreach ($r as $v): ?><td><?= $e($v) ?></td><?php endforeach ?></tr><?php endforeach ?></tbody></table></div><?php endif ?></div>
</body></html>
