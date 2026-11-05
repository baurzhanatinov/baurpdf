<?php
/** @var callable $e  Договор: number, date, city, title, parties[{role,name,rep,basis,details[]}], subject, sections[{title,items[]}], signatures(bool) */
$number ??= '1'; $date ??= date('d.m.Y'); $city ??= 'г. Алматы'; $title ??= 'Договор'; $parties ??= []; $subject ??= ''; $sections ??= [];
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title><?= $e($title . ' № ' . $number) ?></title>
<style>
body { font-size: 10.5pt; line-height: 1.45; text-align: justify; hyphens: auto }
h1 { text-align: center; font-size: 15pt; margin: 0 0 2pt; letter-spacing: .5pt }
.sub { text-align: center; color: #6B7280; margin-bottom: 14pt }
.place { display: flex; justify-content: space-between; margin-bottom: 12pt }
h2 { font-size: 11pt; text-align: center; text-transform: uppercase; margin: 14pt 0 6pt; letter-spacing: .4pt; break-after: avoid }
ol.cl { counter-reset: cl; list-style: none; padding: 0; margin: 0 }
ol.cl > li { counter-increment: cl; margin: 0 0 4pt; text-indent: 0 }

.req { display: flex; gap: 18pt; margin-top: 18pt; break-inside: avoid } .req > div { flex: 1; border-top: 1.5pt solid #111827; padding-top: 6pt; text-align: left }
.req .role { font-size: 8pt; text-transform: uppercase; color: #6B7280; letter-spacing: .6pt }
.sign { margin-top: 26pt; border-bottom: .75pt solid #111827; height: 14pt; position: relative }
.sign span { position: absolute; right: 0; bottom: -12pt; font-size: 7.5pt; color: #9CA3AF }
<?= $css ?? '' ?></style></head><body>
<h1><?= $e(mb_strtoupper($title)) ?> № <?= $e($number) ?></h1>
<?php if ($subject): ?><div class="sub"><?= $e($subject) ?></div><?php endif ?>
<div class="place"><span><?= $e($city) ?></span><span><?= $e($date) ?></span></div>
<p><?php foreach ($parties as $i => $p): ?><b><?= $e($p['name'] ?? '') ?></b><?= !empty($p['rep']) ? ', в лице ' . $e($p['rep']) : '' ?><?= !empty($p['basis']) ? ', действующего на основании ' . $e($p['basis']) : '' ?>, именуемое в дальнейшем «<?= $e($p['role'] ?? '') ?>»<?= $i < count($parties) - 1 ? ', с одной стороны, и ' : ', с другой стороны, ' ?><?php endforeach ?>совместно именуемые «Стороны», заключили настоящий договор о нижеследующем.</p>
<?php foreach ($sections as $si => $s): ?>
<h2><?= $si + 1 ?>. <?= $e($s['title'] ?? '') ?></h2>
<ol class="cl"><?php foreach ($s['items'] ?? [] as $ii => $it): ?><li><b><?= $si + 1 ?>.<?= $ii + 1 ?>.</b> <?= $e($it) ?></li><?php endforeach ?></ol>
<?php endforeach ?>
<h2><?= count($sections) + 1 ?>. Реквизиты и подписи сторон</h2>
<div class="req"><?php foreach ($parties as $p): ?><div><div class="role"><?= $e($p['role'] ?? '') ?></div><div class="bp-bold" style="margin:3pt 0"><?= $e($p['name'] ?? '') ?></div>
<?php foreach ($p['details'] ?? [] as $d): ?><div class="bp-muted" style="font-size:9pt"><?= $e($d) ?></div><?php endforeach ?>
<div class="sign"><span><?= $e($p['signer'] ?? '') ?> / подпись, М.П.</span></div></div><?php endforeach ?></div>
</body></html>
