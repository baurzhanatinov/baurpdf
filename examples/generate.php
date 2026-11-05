<?php
/** Генерация примеров: php examples/generate.php */
require __DIR__ . '/../vendor/autoload.php';
use BaurPDF\BaurPDF;
use BaurPDF\Chart\Chart;
$out = __DIR__ . '/output'; @mkdir($out, 0775, true);
$t = [];
$time = function (string $name, callable $f) use (&$t) { $s = microtime(true); $f(); $t[$name] = microtime(true) - $s; };

// 1. Отчёт с графиками
$time('report', fn() => BaurPDF::template('report', [
    'title' => 'Отчёт о продажах за 2026 год', 'subtitle' => 'Казахстан и СНГ', 'author' => 'Отдел аналитики', 'date' => '04.11.2026',
    'kpis' => [
        ['label' => 'Выручка', 'value' => '1,84 млрд ₸', 'delta' => '+12,4%', 'note' => 'к 2025'],
        ['label' => 'Клиенты', 'value' => '12 480', 'delta' => '+8,1%'],
        ['label' => 'Средний чек', 'value' => '147 300 ₸', 'delta' => '+3,9%'],
        ['label' => 'Отток', 'value' => '2,1%', 'delta' => '-0,6%'],
    ],
    'sections' => [
        ['title' => 'Динамика выручки', 'text' => 'Выручка росла каждый квартал; пик пришёлся на четвёртый квартал благодаря запуску новой линейки продуктов и сезонному спросу.',
         'chart' => Chart::bar(['Q1', 'Q2', 'Q3', 'Q4'], ['2025' => [310, 352, 371, 410], '2026' => [355, 398, 445, 512]])->title('Выручка по кварталам, млн ₸')],
        ['title' => 'Структура продаж', 'html' => '<div class="bp-grid-2">' . Chart::donut(['Алматы' => 42, 'Астана' => 28, 'Шымкент' => 14, 'Регионы' => 16])->title('По городам, %')->height(190) . Chart::hbar(['Оборудование', 'Сервис', 'Лицензии', 'Обучение'], ['Доля' => [48, 27, 17, 8]])->title('По направлениям, %')->height(190)->legend(false) . '</div>'],
        ['title' => 'Помесячная динамика', 'chart' => Chart::area(['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'], ['Заказы' => [120, 132, 128, 150, 162, 171, 168, 180, 195, 210, 232, 260], 'План' => [125, 130, 135, 145, 155, 165, 170, 180, 190, 205, 220, 240]])->title('Заказы, шт.')],
        ['title' => 'Топ клиентов', 'table' => ['headers' => ['Клиент', 'Город', 'Заказов', 'Сумма, ₸'], 'numeric' => [2, 3],
            'rows' => [['ТОО «Альфа Трейд»', 'Алматы', 142, '84 200 000'], ['АО «КазСтрой»', 'Астана', 98, '61 750 000'], ['ИП Сагындыков', 'Шымкент', 76, '23 400 000'], ['ТОО «Бета Логистик»', 'Караганда', 64, '19 980 000'], ['АО «Нур Энерго»', 'Атырау', 51, '17 300 000']],
            'footer' => ['Итого', '', 431, '206 630 000']]],
        ['title' => 'Выводы', 'html' => '<ul><li>Рост выручки на <b>12,4%</b> при снижении оттока.</li><li>Регионы дают 16% — главный резерв роста на 2027 год.</li><li>Рекомендуется расширить сервисное направление.</li></ul><div class="bp-alert">Данные предварительные; финальная версия — после аудита.</div>'],
    ],
], [])->footer('<div style="display:flex;justify-content:space-between"><span>{TITLE}</span><span>Стр. {PAGENO} из {nbpg}</span></div>', false)->deterministic()->save("$out/report-with-charts.pdf"));

// 2. Счёт
$time('invoice', fn() => BaurPDF::template('invoice', [
    'number' => 'INV-2026-0147', 'date' => '04.11.2026', 'due' => '14.11.2026', 'currency' => '₸', 'vat' => 12,
    'seller' => ['name' => 'ТОО «BaurSoft»', 'address' => 'г. Алматы, пр. Абая, 150', 'bin' => '180240012345', 'iban' => 'KZ12 3456 7890 1234 5678', 'bank' => 'АО «Halyk Bank»', 'email' => 'billing@baursoft.kz'],
    'buyer' => ['name' => 'АО «Казахстан Ритейл»', 'address' => 'г. Астана, ул. Кунаева, 12', 'bin' => '990140004321', 'phone' => '+7 717 200 00 00'],
    'items' => [['name' => 'Лицензия BaurPDF Enterprise', 'note' => 'годовая подписка, 10 серверов', 'qty' => 1, 'price' => 1200000], ['name' => 'Внедрение и настройка', 'qty' => 16, 'unit' => 'ч', 'price' => 25000], ['name' => 'Обучение сотрудников', 'qty' => 2, 'unit' => 'дн', 'price' => 150000], ['name' => 'Техническая поддержка 24/7', 'qty' => 12, 'unit' => 'мес', 'price' => 40000]],
    'notes' => 'Оплата в течение 10 банковских дней. В назначении платежа укажите номер счёта.',
    'qr' => 'ST00012|Name=ТОО «BaurSoft»|PersonalAcc=KZ1234567890|Sum=266560000|Purpose=INV-2026-0147',
])->deterministic()->save("$out/invoice.pdf"));

// 3. Презентация 16:9
$time('presentation', fn() => BaurPDF::slides([
    ['title' => 'BaurPDF', 'subtitle' => 'Красивые PDF на чистом PHP — без Chromium и системных зависимостей'],
    ['title' => 'Почему BaurPDF', 'bullets' => ['Современный CSS: flex, grid, переменные, градиенты', 'Нативные векторные графики без JavaScript', 'Оглавление, закладки, «Стр. X из Y»', 'Кириллица и казахский алфавит из коробки']],
    ['title' => 'Ключевые цифры', 'kpis' => [['label' => 'Зависимостей', 'value' => '0'], ['label' => 'Строк в секунду', 'value' => '900+'], ['label' => 'Версия PHP', 'value' => '8.1+']]],
    ['title' => 'Рост пользователей', 'chart' => Chart::line(['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн'], ['Пользователи' => [120, 340, 610, 980, 1500, 2300]])],
    ['title' => 'Спасибо!', 'text' => 'composer require baur/pdf', 'dark' => true],
])->deterministic()->save("$out/presentation-16x9.pdf"));

// 4. Кириллица / типографика
$time('cyrillic', fn() => BaurPDF::html('<html lang="ru"><head><title>Кириллица</title><style>
.serif{font-family:"PT Serif",serif;font-size:12pt} .mono{font-family:monospace} .cols{display:grid;grid-template-columns:1fr 1fr;gap:16pt}
</style></head><body>
<h1>Кириллица и типографика</h1>
<p>Съешь же ещё этих мягких французских булок, да выпей чаю. <b>Жирный</b>, <i>курсив</i>, <b><i>жирный курсив</i></b>, <u>подчёркнутый</u>, <s>зачёркнутый</s>, H<sub>2</sub>O, E=mc<sup>2</sup>.</p>
<h2>Қазақ тілі</h2><p>Әліпби: А Ә Б В Г Ғ Д Е Ё Ж З И Й К Қ Л М Н Ң О Ө П Р С Т У Ұ Ү Ф Х Һ Ц Ч Ш Щ Ъ Ы І Ь Э Ю Я. Қазақстан Республикасы — Орталық Азиядағы мемлекет.</p>
<h2>Українська, Беларуская, Српски</h2><p>Ґанок, їжак, єнот · Ўзор, і · Ђак, ћерка, љубав, његош, џеп.</p>
<div class="cols"><div class="serif"><h3>PT Serif</h3><p style="text-align:justify">Широкая электрификация южных губерний даст мощный толчок подъёму сельского хозяйства. Текст набран с выравниванием по ширине и автоматическим переносом длинных слов.</p></div>
<div><h3>PT Sans</h3><p style="text-align:justify">В чащах юга жил бы цитрус? Да, но фальшивый экземпляр! Эх, чужак, общий съём цен шляп (юфть) — вдрызг!</p><p class="mono">моноширинный: 0123456789 {}[]</p></div></div>
<blockquote>«Простота — необходимое условие красоты». — Л. Н. Толстой</blockquote>
<h2>Символы</h2><p>№ § © ® ™ € ₽ ₸ ← → ↑ ↓ ≤ ≥ ≠ ∞ ± × ÷ √ «ёлочки» „лапки“ — тире – …</p>
<ol><li>Первый пункт</li><li>Второй пункт<ol><li>Вложенный</li></ol></li></ol>
<ul style="list-style-type:cyrillic-lower"><li>а-пункт</li><li>б-пункт</li></ul>
</body></html>')->footer('<div style="text-align:center">{PAGENO} / {nbpg}</div>')->deterministic()->save("$out/cyrillic.pdf"));

// 5. Возможности v2: Knuth-Plass + переносы, float, колонки, тени, tfoot, авто-альбом, PDF/A-2b
$time('v2-features', function () use ($out) {
    $t = 'Казахстан — государство в Центральной Азии, крупнейшее по площади среди стран без выхода к Мировому океану. Международное сотрудничество и электрификация промышленности обеспечивают устойчивое развитие регионов. ';
    $rows = ''; for ($i = 1; $i <= 70; $i++) $rows .= "<tr><td>$i</td><td>Поставка оборудования, партия $i</td><td class='num'>" . number_format($i * 18350, 0, ',', "\u{00A0}") . '</td></tr>';
    $mon = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек']; $wh = ''; foreach ($mon as $mo) $wh .= "<th class='num'>$mo</th>";
    $wide = ''; foreach (['Алматы', 'Астана', 'Шымкент', 'Караганда', 'Актобе', 'Атырау', 'Павлодар', 'Костанай'] as $k => $city) { $wide .= "<tr><td>$city</td>"; $sum = 0; foreach ($mon as $m => $_) { $v = 1000 + ($k * 37 + $m * 53) % 900; $sum += $v; $wide .= "<td class='num'>" . number_format($v * 1000, 0, ',', "\u{00A0}") . '</td>'; } $wide .= "<td class='num'><b>" . number_format($sum * 1000, 0, ',', "\u{00A0}") . '</b></td></tr>'; }
    BaurPDF::html('<html lang="ru"><head><title>BaurPDF 2.0 — возможности</title><style>
      body{font-size:10.5pt} p{text-align:justify;hyphens:auto}
      .note{float:right;width:58mm;margin:0 0 8pt 12pt;padding:8pt 10pt;background:#f1f5f9;border-left:3pt solid #2563eb;font-size:9pt;text-align:left}
      .card{box-shadow:0 6px 16px rgba(15,23,42,.25), 0 0 0 1px rgba(15,23,42,.06);border-radius:8pt;padding:12pt 14pt;margin:14pt 0;background:#fff}
      .cols{columns:3;column-gap:14pt;column-rule:0.5pt solid #cbd5e1;font-size:9pt}
    </style></head><body>
      <h1>BaurPDF 2.0</h1>
      <div class="note"><b>Обтекание.</b> Эта врезка — <code>float:right</code>; текст абзацев слева обтекает её.</div>
      <p>' . $t . $t . '</p><p>' . $t . $t . '</p>
      <h2 style="clear:both">Выключка Knuth-Plass и словарные переносы</h2>
      <p style="width:85mm">' . $t . $t . '</p>
      <div class="card"><b>Карточка с box-shadow.</b> Несколько теней через запятую, размытие и spread.</div>
      <h2>Колонки</h2><div class="cols"><p>' . $t . $t . $t . '</p></div>
      <h2>Повтор tfoot</h2>
      <table class="bp-striped"><thead><tr><th>#</th><th>Позиция</th><th>Сумма, ₸</th></tr></thead><tbody>' . $rows . '</tbody>
      <tfoot><tr><td colspan="2">Перенос на следующую страницу</td><td class="bp-num">—</td></tr></tfoot></table>
      <h2>Широкая таблица → альбомная страница</h2>
      <table data-overflow="landscape"><thead><tr><th>Филиал</th>' . $wh . '<th class="bp-num">Итого, ₸</th></tr></thead><tbody>' . $wide . '</tbody></table>
      <p>После широкой таблицы документ продолжается в книжной ориентации.</p>
    </body></html>')->baseDir(__DIR__)->footer('<div style="text-align:center">{PAGENO} / {nbpg}</div>')->pdfa()->deterministic()->save("$out/v2-features.pdf");
});

// 6. Договор
$time('contract', fn() => BaurPDF::template('contract', [
    'number' => 'ДП-2026/118', 'date' => '«04» ноября 2026 г.', 'title' => 'Договор поставки', 'subject' => 'программного обеспечения и услуг по внедрению',
    'parties' => [
        ['role' => 'Поставщик', 'name' => 'ТОО «BaurSoft»', 'rep' => 'директора Атинова Б.', 'basis' => 'Устава', 'signer' => 'Атинов Б.', 'details' => ['БИН 180240012345', 'г. Алматы, пр. Абая, 150', 'IBAN KZ12 3456 7890 1234 5678', 'АО «Halyk Bank»']],
        ['role' => 'Покупатель', 'name' => 'АО «Казахстан Ритейл»', 'rep' => 'председателя правления Ахметова С.', 'basis' => 'Устава', 'signer' => 'Ахметов С.', 'details' => ['БИН 990140004321', 'г. Астана, ул. Кунаева, 12', 'IBAN KZ98 7654 3210 9876 5432', 'АО «Kaspi Bank»']],
    ],
    'sections' => [
        ['title' => 'Предмет договора', 'items' => ['Поставщик обязуется передать Покупателю неисключительные права на использование программного обеспечения BaurPDF Enterprise, а Покупатель — принять и оплатить их.', 'Перечень, количество и стоимость лицензий указываются в Спецификации (Приложение № 1), являющейся неотъемлемой частью договора.']],
        ['title' => 'Цена и порядок расчётов', 'items' => ['Общая стоимость договора составляет 2 665 600 (два миллиона шестьсот шестьдесят пять тысяч шестьсот) тенге с учётом НДС 12%.', 'Оплата производится в течение 10 (десяти) банковских дней с даты подписания договора на основании счёта Поставщика.', 'Датой оплаты считается дата зачисления денежных средств на расчётный счёт Поставщика.']],
        ['title' => 'Права и обязанности сторон', 'items' => ['Поставщик обязан предоставить лицензионные ключи и документацию в течение 3 (трёх) рабочих дней после оплаты.', 'Покупатель обязан использовать программное обеспечение в соответствии с условиями лицензионного соглашения.', 'Стороны обязуются соблюдать конфиденциальность сведений, полученных в ходе исполнения договора.']],
        ['title' => 'Ответственность сторон', 'items' => ['За просрочку оплаты Покупатель уплачивает пеню в размере 0,1% от суммы задолженности за каждый день просрочки, но не более 10% от стоимости договора.', 'Стороны освобождаются от ответственности при наступлении обстоятельств непреодолимой силы.']],
        ['title' => 'Срок действия и разрешение споров', 'items' => ['Договор вступает в силу с момента подписания и действует до полного исполнения обязательств.', 'Споры разрешаются путём переговоров, а при недостижении согласия — в суде по месту нахождения Поставщика в соответствии с законодательством Республики Казахстан.']],
    ],
])->footer('<div style="display:flex;justify-content:space-between;font-size:8pt;color:#9CA3AF"><span>Договор № ДП-2026/118</span><span>Стр. {PAGENO} из {nbpg} · Поставщик ______ Покупатель ______</span></div>')->tagged()->deterministic()->save("$out/contract.pdf"));

// 7. Сертификат
$time('certificate', fn() => BaurPDF::template('certificate', [
    'issuer' => 'Академия BaurSoft', 'title' => 'Сертификат', 'kind' => 'о прохождении курса повышения квалификации', 'name' => 'Бауржан Атинов',
    'text' => 'успешно завершил(а) курс «Генерация PDF на PHP: от HTML до PDF/UA» в объёме 72 академических часов и сдал(а) итоговую аттестацию с оценкой «отлично».',
    'date' => 'Алматы, 04.11.2026', 'number' => 'BS-2026-00418', 'signers' => [['name' => 'А. Сейткали', 'role' => 'Директор академии'], ['name' => 'Д. Нурланова', 'role' => 'Руководитель программы']],
])->deterministic()->save("$out/certificate.pdf"));

// 8. Дашборд
$time('dashboard', fn() => BaurPDF::template('dashboard', [
    'title' => 'Операционный дашборд', 'period' => 'Октябрь 2026 · обновлено 04.11.2026',
    'kpis' => [['label' => 'Выручка', 'value' => '512 млн ₸', 'delta' => '+15,1%'], ['label' => 'Заказы', 'value' => '8 214', 'delta' => '+6,3%'], ['label' => 'Конверсия', 'value' => '3,8%', 'delta' => '+0,4 п.п.'], ['label' => 'Возвраты', 'value' => '1,2%', 'delta' => '-0,3 п.п.'], ['label' => 'NPS', 'value' => '64', 'delta' => '+5']],
    'charts' => [
        ['title' => 'Выручка по неделям, млн ₸', 'span' => 2, 'chart' => Chart::area(['Н1', 'Н2', 'Н3', 'Н4', 'Н5', 'Н6', 'Н7', 'Н8'], ['2026' => [98, 104, 110, 121, 118, 126, 131, 140], '2025' => [90, 92, 99, 101, 104, 108, 111, 115]])->height(100)],
        ['title' => 'Каналы', 'chart' => Chart::donut(['Сайт' => 46, 'Kaspi' => 31, 'Офлайн' => 23])->height(100)],
        ['title' => 'Регионы', 'chart' => Chart::hbar(['Алматы', 'Астана', 'Шымкент', 'Другие'], ['Доля' => [41, 29, 12, 18]])->height(88)->legend(false)],
        ['title' => 'Средний чек, тыс. ₸', 'chart' => Chart::line(['Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт'], ['Чек' => [58, 60, 59, 61, 63, 62]])->height(88)->legend(false)],
        ['title' => 'Заказы по дням недели', 'chart' => Chart::bar(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'], ['Заказы' => [1180, 1210, 1150, 1240, 1390, 1120, 924]])->height(88)->legend(false)],
    ],
    'table' => ['headers' => ['Товар', 'Категория', 'Продажи, шт.', 'Выручка, ₸', 'Остаток'], 'rows' => [['BaurPDF Enterprise', 'Лицензии', '214', '256 800 000', '∞'], ['Внедрение', 'Услуги', '96', '38 400 000', '—'], ['Поддержка 24/7', 'Подписка', '402', '16 080 000', '—']]],
])->deterministic()->save("$out/dashboard.pdf"));

// 9. Интерактивная форма (AcroForm) + PDF/UA
$time('form', fn() => BaurPDF::html('<html lang="ru"><head><title>Заявка на подключение</title><style>label{display:inline-block;width:120pt;color:#475569} .r{margin:6pt 0} input[type=text],input:not([type]),select,textarea{width:260pt}</style></head><body>
<h1>Заявка на подключение</h1><p>Заполните поля прямо в PDF-просмотрщике и сохраните файл.</p>
<div class="r"><label>Организация</label><input name="org" value="ТОО «BaurSoft»" required></div>
<div class="r"><label>Контактное лицо</label><input name="person" placeholder="ФИО"></div>
<div class="r"><label>Тариф</label><select name="plan"><option>Старт</option><option selected>Бизнес</option><option>Enterprise</option></select></div>
<div class="r"><label>Оплата</label><input type="radio" name="pay" value="card" checked> карта <input type="radio" name="pay" value="invoice"> счёт</div>
<div class="r"><label>Комментарий</label><textarea name="note" rows="4"></textarea></div>
<div class="r"><label></label><input type="checkbox" name="agree" checked> согласен с условиями оферты</div>
</body></html>')->pdfua()->deterministic()->save("$out/form.pdf"));

// 10. v2.2: transform, ::before/::after + счётчики, column-span, CSS Grid (areas, span, dense)
$time('v22', fn() => BaurPDF::html('<html lang="ru"><head><title>BaurPDF 2.2</title><style>
body { counter-reset: sec }
h2 { counter-increment: sec; counter-reset: sub } h2::before { content: counter(sec) ". "; color: #2563EB }
h3 { counter-increment: sub } h3::before { content: counter(sec) "." counter(sub, lower-alpha) " "; color: #64748B }
.stamp { display: inline-block; transform: rotate(-12deg); border: 2pt solid #DC2626; color: #DC2626; padding: 4pt 10pt; font-weight: bold; margin: 10pt 0 10pt 300pt }
.layout { display: grid; grid-template-areas: "h h h" "s m m" "s f f"; grid-template-columns: 110pt 1fr 1fr; gap: 6pt; margin: 8pt 0 }
.layout > div { background: #EFF6FF; border-radius: 4pt; padding: 6pt }
.cols { columns: 2; column-gap: 16pt; column-rule: 0.5pt solid #CBD5E1; margin-top: 10pt }
.cols h4 { column-span: all; background: #F1F5F9; padding: 4pt 6pt; margin: 6pt 0 }
a[href]::after { content: " (" attr(href) ")"; color: #64748B; font-size: 8pt }
q::before { content: open-quote } q::after { content: close-quote }
</style></head><body>
<h1>Новое в BaurPDF 2.2</h1>
<div class="stamp">ОПЛАЧЕНО</div>
<h2>Счётчики и псевдоэлементы</h2><h3>Нумерация через CSS</h3><p>Номера разделов выше созданы <code>counter()</code> в <code>::before</code>. Ссылка: <a href="https://baurpdf.kz">сайт проекта</a>; цитата: <q>просто <q>работает</q></q>.</p>
<h3>Кавычки и attr()</h3><p>Вложенные кавычки «ёлочки» и „лапки“ по правилам русской типографики.</p>
<h2>CSS Grid</h2>
<div class="layout"><div style="grid-area:h">Шапка (grid-area: h)</div><div style="grid-area:s">Боковая колонка на два ряда</div><div style="grid-area:m">Основной контент</div><div style="grid-area:f">Подвал</div></div>
<h2>Колонки и column-span</h2>
<div class="cols"><p>' . str_repeat('Казахстан — государство в Центральной Азии, крупнейшее по площади среди стран без выхода к Мировому океану. ', 4) . '</p><h4>Заголовок на всю ширину (column-span: all)</h4><p>' . str_repeat('Столица — Астана, крупнейший город — Алматы. Официальные языки — казахский и русский. ', 5) . '</p></div>
</body></html>')->deterministic()->save("$out/v22-features.pdf"));

foreach ($t as $k => $v) printf("%-14s %6.0f ms\n", $k, $v * 1000);
