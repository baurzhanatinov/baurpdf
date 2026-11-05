<?php
/** @var string $title */ /** @var callable $e */
use BaurPDF\Chart\Chart;
$title ??= 'Отчёт'; $subtitle ??= ''; $author ??= ''; $date ??= date('d.m.Y');
$kpis ??= []; $sections ??= []; $toc ??= true;
?><!DOCTYPE html>
<html lang="ru"><head><meta charset="utf-8"><title><?= $e($title) ?></title>
<?php if ($author): ?><meta name="author" content="<?= $e($author) ?>"><?php endif ?>
<style><?= $css ?? '' ?></style></head><body>
<div class="bp-cover">
  <h1 class="no-toc"><?= $e($title) ?></h1>
  <p><?= $e($subtitle) ?><?= $subtitle && ($author || $date) ? ' · ' : '' ?><?= $e(trim($author . ($author ? ', ' : '') . $date)) ?></p>
</div>
<?php if ($kpis): ?>
<div class="bp-grid-<?= min(4, max(2, count($kpis))) ?>" style="margin-bottom:14pt">
<?php foreach ($kpis as $k): ?>
  <div class="bp-kpi"><div class="bp-kpi-label"><?= $e($k['label'] ?? '') ?></div><div class="bp-kpi-value"><?= $e($k['value'] ?? '') ?></div>
  <?php if (isset($k['delta'])): $up = !str_starts_with((string)$k['delta'], '-'); ?><div class="bp-kpi-delta <?= $up ? 'bp-up' : 'bp-down' ?>"><?= $up ? '▲' : '▼' ?> <?= $e(ltrim((string)$k['delta'], '+-')) ?> <span class="bp-muted"><?= $e($k['note'] ?? '') ?></span></div><?php endif ?>
  </div>
<?php endforeach ?>
</div>
<?php endif ?>
<?php if ($toc): ?><toc title="Содержание" levels="2"></toc><?php endif ?>
<?php foreach ($sections as $s): ?>
  <h2><?= $e($s['title'] ?? '') ?></h2>
  <?php if (!empty($s['text'])): ?><p><?= $e($s['text']) ?></p><?php endif ?>
  <?= $s['html'] ?? '' ?>
  <?php if (!empty($s['chart'])): $c = $s['chart']; echo $c instanceof Chart ? $c : '<chart data-chart="' . $e(json_encode($c, JSON_UNESCAPED_UNICODE)) . '"></chart>'; endif ?>
  <?php if (!empty($s['table'])): $t = $s['table']; ?>
  <table class="bp-striped"><thead><tr><?php foreach ($t['headers'] as $i => $h): ?><th<?= in_array($i, $t['numeric'] ?? [], true) ? ' class="bp-num"' : '' ?>><?= $e($h) ?></th><?php endforeach ?></tr></thead>
  <tbody><?php foreach ($t['rows'] as $r): ?><tr><?php foreach (array_values($r) as $i => $v): ?><td<?= in_array($i, $t['numeric'] ?? [], true) ? ' class="bp-num"' : '' ?>><?= $e($v) ?></td><?php endforeach ?></tr><?php endforeach ?></tbody>
  <?php if (!empty($t['footer'])): ?><tfoot><tr><?php foreach ($t['footer'] as $i => $v): ?><td<?= in_array($i, $t['numeric'] ?? [], true) ? ' class="bp-num"' : '' ?>><?= $e($v) ?></td><?php endforeach ?></tr></tfoot><?php endif ?>
  </table>
  <?php endif ?>
<?php endforeach ?>
</body></html>
