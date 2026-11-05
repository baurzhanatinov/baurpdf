<?php
require __DIR__ . '/../vendor/autoload.php';
use BaurPDF\BaurPDF;
$words = explode(' ', 'отчёт продажи регион Алматы Астана Шымкент клиент договор поставка оборудования сервисное обслуживание гарантийный ремонт электроэнергия теплоснабжение высокотехнологичный многофункциональный https://example.kz/very/long/url/path/that/should/break/somewhere/index.php?id=12345');
mt_srand(42);
$rows = '';
for ($i = 1; $i <= 1200; $i++) {
    $n = mt_rand(3, $i % 50 === 0 ? 60 : 14); $t = [];
    for ($k = 0; $k < $n; $k++) $t[] = $words[mt_rand(0, count($words) - 1)];
    $rows .= sprintf('<tr><td class="bp-num">%d</td><td>%s</td><td>%s</td><td class="bp-num">%s</td><td><span class="bp-badge %s">%s</span></td></tr>',
        $i, ['ТОО «Альфа»', 'АО «Казахтелеком»', 'ИП Иванов', 'ТОО «Бета Строй Инвест Групп»'][$i % 4], htmlspecialchars(implode(' ', $t)), number_format(mt_rand(1000, 9999999) / 100, 2, ',', ' '), ['success', 'warning', 'danger'][$i % 3], ['Оплачен', 'Ожидает', 'Просрочен'][$i % 3]);
}
$html = '<h1>Реестр операций (1200 строк)</h1><table class="bp-striped"><thead><tr><th>№</th><th>Контрагент</th><th>Описание</th><th class="bp-num">Сумма, ₸</th><th>Статус</th></tr></thead><tbody>' . $rows . '</tbody><tfoot><tr><td></td><td>Итого</td><td></td><td class="bp-num">—</td><td></td></tr></tfoot></table>';
$t = microtime(true); $m0 = memory_get_usage();
$doc = BaurPDF::html($html)->title('Стресс-тест')->footer('<div style="text-align:right">Стр. {PAGENO} из {nbpg}</div>')->deterministic()->render();
$doc->save(__DIR__ . '/output/stress-table-1200-rows.pdf');
printf("pages=%d time=%.2fs peak=%.1fMB size=%.0fKB\n", $doc->pageCount(), microtime(true) - $t, memory_get_peak_usage() / 1048576, $doc->size() / 1024);
