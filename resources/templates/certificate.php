<?php
/** @var callable $e  Сертификат: title, kind, name, text, date, number, issuer, signers[{name,role}], accent */
$title ??= 'Сертификат'; $kind ??= 'о прохождении курса'; $name ??= ''; $text ??= ''; $date ??= date('d.m.Y'); $number ??= ''; $issuer ??= ''; $signers ??= []; $accent ??= '#B45309';
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title><?= $e($title . ' — ' . $name) ?></title>
<style>
@page { size: A4 landscape; margin: 0 }
body { margin: 0; color: #1F2937 }
.frame { position: absolute; left: 18pt; top: 18pt; right: 18pt; bottom: 18pt; border: 3pt solid <?= $e($accent) ?>; }
.inner { position: absolute; left: 28pt; top: 28pt; right: 28pt; bottom: 28pt; border: .75pt solid <?= $e($accent) ?>; background: linear-gradient(135deg, #FFFBEB, #FFFFFF 55%, #FEF3C7) }
.c { position: absolute; left: 70pt; right: 70pt; top: 70pt; text-align: center }
.issuer { font-size: 10pt; letter-spacing: 3pt; text-transform: uppercase; color: #6B7280 }
.t { font-size: 44pt; font-weight: bold; color: <?= $e($accent) ?>; letter-spacing: 6pt; text-transform: uppercase; margin-top: 18pt }
.k { font-size: 13pt; color: #6B7280; margin-top: 2pt }
.lead { margin-top: 26pt; font-size: 11pt; color: #6B7280 }
.n { font-size: 30pt; font-style: italic; margin: 6pt auto 0; padding-bottom: 6pt; border-bottom: 1pt solid #D1D5DB; width: 70% }
.txt { margin: 14pt auto 0; width: 76%; font-size: 11.5pt; line-height: 1.5 }
.seal { position: absolute; left: 50%; margin-left: -38pt; bottom: 64pt; width: 76pt; height: 76pt; border-radius: 50%; background: radial-gradient(circle, #FCD34D, <?= $e($accent) ?>); box-shadow: 0 2pt 8pt rgba(0,0,0,.25); color: #fff; text-align: center; font-weight: bold; font-size: 9pt; line-height: 76pt; letter-spacing: 1pt }
.sig { position: absolute; bottom: 70pt; width: 190pt; text-align: center; font-size: 9.5pt }
.sig .l { border-top: .75pt solid #374151; padding-top: 4pt; font-weight: bold } .sig .r { color: #6B7280; font-size: 8.5pt }
.meta { position: absolute; left: 0; right: 0; bottom: 40pt; text-align: center; font-size: 8.5pt; color: #9CA3AF }
<?= $css ?? '' ?></style></head><body>
<div class="frame"></div><div class="inner"></div>
<div class="c"><div class="issuer"><?= $e($issuer) ?></div><div class="t"><?= $e($title) ?></div><div class="k"><?= $e($kind) ?></div>
<div class="lead">Настоящим подтверждается, что</div><div class="n"><?= $e($name) ?></div><div class="txt"><?= $e($text) ?></div></div>
<?php foreach (array_slice($signers, 0, 2) as $i => $s): ?><div class="sig" style="<?= $i ? 'right' : 'left' ?>:90pt"><div class="l"><?= $e($s['name'] ?? '') ?></div><div class="r"><?= $e($s['role'] ?? '') ?></div></div><?php endforeach ?>
<div class="seal">BAUR</div>
<div class="meta"><?= $number ? '№ ' . $e($number) . ' · ' : '' ?><?= $e($date) ?></div>
</body></html>
