<?php
/** @var callable $e */
use BaurPDF\Chart\Chart;
$slides ??= []; $title ??= 'Презентация';
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title><?= $e($title) ?></title>
<style>
.slide-num { position: absolute; bottom: 18pt; right: 28pt; font-size: 11pt; color: #9CA3AF; }
.slide ul { padding-left: 22pt } .slide li { margin-bottom: 8pt }
.slide .lead { font-size: 24pt; color: #4B5563 }
.slide .bp-kpi { padding: 18pt 20pt } .slide .bp-kpi-value { font-size: 44pt } .slide .bp-kpi-label { font-size: 12pt }
.slide.title .lead { color: #E0E7FF }
<?= $css ?? '' ?></style></head><body>
<?php foreach ($slides as $i => $s): $type = $s['type'] ?? 'content'; ?>
<section class="slide <?= $e($type === 'title' ? 'title' : '') ?> <?= !empty($s['dark']) ? 'dark' : '' ?>">
<?php if ($type === 'title'): ?>
  <h1><?= $e($s['title'] ?? $title) ?></h1><p class="lead"><?= $e($s['subtitle'] ?? '') ?></p>
<?php else: ?>
  <h2><?= $e($s['title'] ?? '') ?></h2>
  <?php if (!empty($s['text'])): ?><p class="lead"><?= $e($s['text']) ?></p><?php endif ?>
  <?php if (!empty($s['bullets'])): ?><ul><?php foreach ($s['bullets'] as $b): ?><li><?= $e($b) ?></li><?php endforeach ?></ul><?php endif ?>
  <?php if (!empty($s['kpis'])): ?><div class="bp-grid-<?= min(4, max(2, count($s['kpis']))) ?>" style="margin-top:20pt"><?php foreach ($s['kpis'] as $k): ?><div class="bp-kpi"><div class="bp-kpi-label"><?= $e($k['label']) ?></div><div class="bp-kpi-value"><?= $e($k['value']) ?></div></div><?php endforeach ?></div><?php endif ?>
  <?php if (!empty($s['chart'])): $c = $s['chart']; echo $c instanceof Chart ? $c->height(330) : '<chart style="height:330pt" data-chart="' . $e(json_encode($c, JSON_UNESCAPED_UNICODE)) . '"></chart>'; endif ?>
  <?= $s['html'] ?? '' ?>
<?php endif ?>
  <div class="slide-num"><?= $i + 1 ?> / <?= count($slides) ?></div>
</section>
<?php endforeach ?>
</body></html>
