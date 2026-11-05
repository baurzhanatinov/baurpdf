<?php
/** @var callable $e */
$number ??= '0001'; $date ??= date('d.m.Y'); $due ??= ''; $currency ??= '₸'; $vat ??= 12; $vatIncluded ??= false;
$seller ??= []; $buyer ??= []; $items ??= []; $notes ??= ''; $title ??= 'Счёт на оплату';
$fmt = fn($v) => number_format((float)$v, 2, ',', ' ') . ' ' . $currency;
$sum = 0; foreach ($items as $it) $sum += ($it['qty'] ?? 1) * ($it['price'] ?? 0);
$vatSum = $vatIncluded ? $sum * $vat / (100 + $vat) : $sum * $vat / 100; $total = $vatIncluded ? $sum : $sum + $vatSum;
$party = function (array $p) use ($e) { $o = '<div class="bp-bold" style="font-size:11pt;margin-bottom:2pt">' . $e($p['name'] ?? '') . '</div>'; foreach (['address', 'bin', 'iban', 'bank', 'phone', 'email'] as $k) if (!empty($p[$k])) $o .= '<div class="bp-muted">' . $e(['address' => '', 'bin' => 'БИН/ИИН: ', 'iban' => 'IBAN: ', 'bank' => 'Банк: ', 'phone' => 'Тел.: ', 'email' => ''][$k] . $p[$k]) . '</div>'; return $o; };
?><!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><title><?= $e($title . ' № ' . $number) ?></title>
<style>
.inv-meta td { border: 0; padding: 1pt 0 1pt 12pt; text-align: right; }
.parties { display: flex; gap: 20pt; margin-bottom: 18pt; } .parties > div { flex: 1; background: #F8FAFC; border-radius: 6pt; padding: 10pt 12pt; }
.lbl { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .6pt; color: #9CA3AF; margin-bottom: 4pt; }
.totals { width: 46%; margin-left: auto; } .totals td { border: 0; padding: 3pt 7pt; } .totals tr.grand td { border-top: 1pt solid #111827; font-size: 13pt; font-weight: bold; padding-top: 6pt; }
<?= $css ?? '' ?></style></head><body>
<div class="bp-invoice-head">
  <div><div class="bp-invoice-title"><?= $e($title) ?></div><div class="bp-muted">№ <?= $e($number) ?></div></div>
  <table class="inv-meta" style="width:auto;margin:0"><tr><td class="bp-muted">Дата</td><td class="bp-bold"><?= $e($date) ?></td></tr>
  <?php if ($due): ?><tr><td class="bp-muted">Оплатить до</td><td class="bp-bold"><?= $e($due) ?></td></tr><?php endif ?></table>
</div>
<div class="parties"><div><div class="lbl">Поставщик</div><?= $party($seller) ?></div><div><div class="lbl">Покупатель</div><?= $party($buyer) ?></div></div>
<table class="bp-striped">
<thead><tr><th style="width:24pt">№</th><th>Наименование</th><th class="bp-num" style="width:50pt">Кол-во</th><th class="bp-num" style="width:90pt">Цена</th><th class="bp-num" style="width:100pt">Сумма</th></tr></thead>
<tbody><?php foreach ($items as $i => $it): $q = $it['qty'] ?? 1; $p = $it['price'] ?? 0; ?>
<tr><td class="bp-muted"><?= $i + 1 ?></td><td><?= $e($it['name'] ?? '') ?><?php if (!empty($it['note'])): ?><br><small><?= $e($it['note']) ?></small><?php endif ?></td><td class="bp-num"><?= $e($q) ?> <?= $e($it['unit'] ?? '') ?></td><td class="bp-num"><?= $fmt($p) ?></td><td class="bp-num"><?= $fmt($q * $p) ?></td></tr>
<?php endforeach ?></tbody></table>
<table class="totals">
<tr><td class="bp-muted">Итого</td><td class="bp-num"><?= $fmt($sum) ?></td></tr>
<tr><td class="bp-muted">НДС <?= $e($vat) ?>%<?= $vatIncluded ? ' (в т.ч.)' : '' ?></td><td class="bp-num"><?= $fmt($vatSum) ?></td></tr>
<tr class="grand"><td>К оплате</td><td class="bp-num"><?= $fmt($total) ?></td></tr></table>
<?php if ($notes): ?><div class="bp-alert" style="margin-top:16pt"><?= $e($notes) ?></div><?php endif ?>
<?php if (!empty($qr)): ?><div style="margin-top:14pt"><?= \BaurPDF\Barcode\QrCode::svg((string)$qr, 80) ?><div class="bp-muted" style="font-size:7.5pt">Отсканируйте для оплаты</div></div><?php endif ?>
</body></html>
