# BaurPDF — HTML → PDF на чистом PHP

`baur/pdf` — генератор PDF из HTML/CSS **без системных зависимостей**: ни Chromium, ни wkhtmltopdf, ни Gotenberg.
Нужны только PHP 8.1+ и стандартные расширения `mbstring`, `zlib`, `dom` (`gd` — опционально, для экзотических PNG).

```bash
composer require baur/pdf
```

```php
use BaurPDF\BaurPDF;

BaurPDF::html('<h1>Привет, мир</h1>')->save('hello.pdf');
```

## Возможности

| Область | Что есть |
|---|---|
| HTML/CSS | HTML5-парсинг, каскад и специфичность, `var()`/custom properties, `calc()`, flexbox, grid, `position:absolute`, градиенты, скругления, `box-shadow` (несколько теней, blur, spread), `float` с обтеканием текстом, `clear`, CSS-колонки (`columns`, `column-count/width/gap/rule`, `column-span: all`, балансировка), 2D `transform` + `transform-origin`, `::before/::after` с `content` и CSS-счётчиками, CSS Grid (`grid-template-areas`, `grid-area`, линии и `span` по рядам и колонкам, `auto/min-content/max-content/minmax/fit-content`, `auto-fill/auto-fit`, `grid-auto-flow: dense|column`, `grid-auto-rows`, `justify/align-items/-self`, `place-*`), списки, `@font-face` (TTF) |
| Страницы | `@page` (размер, поля), A2–A6, Letter, Legal, Tabloid, 16:9 / 4:3, `break-before/after`, `<pagebreak>`, keep-with-next для заголовков |
| Типографика | выключка по ширине жадной разбивкой, как в Chromium (Knuth–Plass остался как `LineBreaker::$optimal`), словарные переносы Лианга для русского, казахского и английского (`hyphens:auto`; по умолчанию `manual`, как в CSS и Chromium), язык из `lang` |
| Таблицы | авто-ширины колонок (water-fill), перенос длинных слов/URL, повтор `thead` **и `tfoot`** на каждой странице, автоматическая альбомная страница для очень широких таблиц, строка не рвётся, если помещается; строка выше страницы режется по строкам текста; rowspan/colspan; зебра; авто-уменьшение шрифта или разбиение широкой таблицы на группы колонок с повтором ключевой колонки |
| Шрифты | TTF, сабсеттинг, ToUnicode (текст копируется и ищется), кириллица и казахский алфавит из коробки (PT Sans/Serif/Mono, DejaVu) |
| Документ | колонтитулы с `{page}/{pages}`, оглавление (TOC) с номерами страниц, закладки (outline), внутренние и внешние ссылки, водяной знак (текст/картинка), метаданные, шифрование **AES-256** (PDF 2.0 / R6, по умолчанию) или RC4-128, пароли и права, **PDF/A-2b** (проходит veraPDF), склейка документов |
| Графика | PNG (в т.ч. альфа), JPEG, SVG (path, arc, transform, text…), нативные векторные графики: bar, hbar, stacked, line, area, pie, donut, gauge |
| Коды | QR (ISO 18004, версии 1–40, уровни L/M/Q/H) и Code 128 — нативно, вектором |
| Шаблоны | `report`, `invoice` (с QR для оплаты), `presentation` + тема `baurpdf.css` (сетка, карточки, KPI, бейджи, алерты) |
| Интеграции | Yii2-компонент, CLI `bin/baurpdf`, пакетный рендер, кэш |

## API

### Вывод
```php
$pdf = BaurPDF::html($html);
$pdf->save('/path/report.pdf');   // в файл
$pdf->download('report.pdf');     // отдать браузеру как вложение
$pdf->stream('report.pdf');       // показать inline
$bytes = $pdf->toString();        // строка с PDF
```

### Fluent-настройки (все необязательны — умолчания разумные: A4, поля 20 мм, сжатие, тема)
```php
BaurPDF::html($html)
    ->format('A4')->landscape()          // или ->format('LETTER'), ->format([210, 100]) в мм
    ->margins('15mm')                    // '15mm', '1cm 2cm', 15 (мм), margins(10, 15, 10, 15)
    ->header('<b>ТОО «Ромашка»</b>')
    ->footer('Стр. {page} из {pages}')   // также {PAGENO} {nbpg} {DATE} {TITLE}
    ->toc('Содержание')                  // оглавление по h1–h3 + закладки
    ->watermark('ЧЕРНОВИК')
    ->password('secret')                 // открытие по паролю (AES-256)
    ->encryption('rc4')                  // только для очень старых просмотрщиков; по умолчанию 'aes256'
    ->pdfa()                             // PDF/A-2b: XMP, sRGB OutputIntent (несовместимо с паролем)
    ->tagged()                           // Tagged PDF: структура H1…/P/L/Table/Figure(alt)/Link/Form для скринридеров
    ->pdfua()                            // PDF/UA-1 (+ tagged); с ->pdfa() — PDF/A-2a
    ->autoLandscape(false)               // не выносить широкие таблицы на альбомные страницы
    ->title('Годовой отчёт')->meta(['author' => 'Бухгалтерия'])
    ->fallbackFont('/fonts/NanumGothic.ttf', '/fonts/NanumGothic-Bold.ttf') // fallback для недостающих глифов (CJK…), можно несколько
    ->fontDir('/usr/share/fonts')               // opt-in: искать недостающие глифы в TTF/TTC каталога (allowlist, симлинки наружу игнорируются)
    ->imageDpi(150)                      // уменьшить фото крупнее нужного (GD; JPEG пережимается с q=85), по умолчанию выкл.
    ->deterministic()                    // побайтно одинаковый результат
    ->save('report.pdf');
```

### Построители
```php
BaurPDF::table($rows, title: 'Продажи')->save('t.pdf');                       // заголовки из ключей, числа — вправо
BaurPDF::chart('bar', ['labels' => ['Q1','Q2'], 'series' => ['2026' => [10, 14]]], 'Выручка')->save('c.pdf');
BaurPDF::slides([['title' => 'BaurPDF', 'subtitle' => 'Чистый PHP'], ['title' => 'Пункты', 'bullets' => ['а', 'б']]])->save('s.pdf');
BaurPDF::template('invoice', ['number' => 'INV-1', 'items' => [...], 'qr' => 'ST00012|…'])->save('inv.pdf');
echo BaurPDF::qr('https://example.kz', 90);   // SVG-разметка для вставки в любой HTML
echo BaurPDF::barcode('ABC-123');
// в HTML: <chart data-chart='{"type":"pie",...}'></chart>  или  \BaurPDF\Chart\Chart::line($labels, $series)->title('…')
BaurPDF::merge([$html1, $html2])->save('all.pdf');
BaurPDF::batch(['a.pdf' => $htmlA, 'b.pdf' => $htmlB]);
```

### Широкие и длинные таблицы
```html
<table data-overflow="auto">      <!-- auto (по умолчанию): ужать шрифт до 65%; если мало — альбомная страница; иначе разбить по колонкам -->
<table data-overflow="landscape"> <!-- всегда выносить таблицу на альбомную страницу (шрифт ужимается, если нужно) -->
<table data-overflow="split">  <!-- группы колонок на отдельных страницах, первая колонка повторяется -->
<table data-overflow="shrink"> <!-- только уменьшение шрифта -->
<table data-overflow="wrap">   <!-- ничего не делать -->
```
или CSS: `table { -bp-overflow: split }`. `<tfoot>` повторяется внизу каждой страницы таблицы; отключить — `data-repeat-footer="false"`. Ячейки `.num` не переносятся. Пример на 1200 строк — `examples/stress-table.php`.

**Потоковые таблицы (50 000+ строк).** Обычный рендер держит в памяти всю раскладку (~8 КБ на строку: 20 000 строк ≈ 214 МБ).
`streamTable()` берёт строки из итератора порциями и сразу отдаёт готовые страницы в PDF — память почти не растёт:
```php
$rows = (function () use ($pdo) { foreach ($pdo->query('SELECT id, name, total FROM orders') as $r) yield [$r['id'], $r['name'], $r['total']]; })();
BaurPDF::html('<h1>Заказы</h1><table data-bp-stream class="table"><thead><tr><th>#</th><th>Клиент</th><th>Сумма</th></tr></thead></table>')
    ->footer('{page} / {pages}')
    ->streamTable($rows)                                  // массив ячеек экранируется; строка '<tr>…</tr>' вставляется как есть
    // ->streamTable($rows, ['#', 'Клиент', 'Сумма'])      // без <table data-bp-stream> в HTML: таблица создаётся в конце
    // ->streamTable($models, [], fn($m) => "<tr><td>{$m->id}</td>…</tr>", 400)   // своя разметка и размер порции
    ->save('orders.pdf');
```
50 000 строк × 4 колонки: 1191 страница, 10 с, пик 87 МБ при `memory_limit=128M` (`php tools/bench/stream.php 50000`).
Правила: содержимое после таблицы (итоги, подпись) выводится сразу после последней строки (с 2.30; соседи таблицы — в её контейнере, более внешние элементы — на всю ширину страницы), колонки — `table-layout: fixed`
(ширины из `<thead>`/`<col>`), `<thead>` повторяется на каждой странице, `{pages}` в колонтитулах работает. Не поддерживаются: оглавление,
`rowspan` через границу порции, рамка/фон самого `<table>` (рамки ячеек работают).

### Ошибки
Неверное использование даёт понятное исключение `BaurPDF\Exception\BaurPdfException`
с подсказкой, как исправить: `Unknown page format 'A9'. Use one of: A2, A3, A4…`, `Unknown option 'formt'. Did you mean 'format'?`,
`Cannot send PDF: output already started in file.php:12. Remove any echo/whitespace…`, `Invalid margin 'abc'. Use a number (mm) or a CSS length like '15mm'…`.
Битый HTML/CSS исключений не вызывает: движок деградирует, а предупреждения доступны через `->warnings()`.

## Yii2

```php
// config/web.php
'components' => [
    'pdf' => [
        'class' => \BaurPDF\Yii2\Pdf::class,
        'options' => ['format' => 'A4', 'footer' => 'Стр. {page} из {pages}'],
        'cachePath' => '@runtime/baurpdf',   // необязательно
        'layout' => null,                    // или '@app/views/layouts/pdf'
    ],
],

// контроллер
public function actionReport(int $id)
{
    return Yii::$app->pdf->render('report', ['model' => $this->findModel($id)])
        ->landscape()->watermark('DRAFT')
        ->download("report-$id.pdf");        // возвращает yii\web\Response
}
```
`render()` использует view текущего контроллера (`renderPartial`) или путь-алиас `@app/views/...`.
Также доступны `Yii::$app->pdf->html($html)` и `Yii::$app->pdf->template('invoice', $data)`. Yii2 не является зависимостью пакета.

Большой отчёт из ActiveRecord без загрузки всех моделей в память — `each()` + `streamTable()`:
```php
public function actionOrders()
{
    $rows = (function () { foreach (Order::find()->orderBy('id')->each(500) as $o) yield [$o->id, $o->customer, Yii::$app->formatter->asDecimal($o->total, 2)]; })();
    return Yii::$app->pdf->html($this->renderPartial('orders-head'))   // заголовок + <table data-bp-stream>…<thead>
        ->streamTable($rows)->download('orders.pdf');
}
```
Шрифты для арабского/CJK подключаются в `options`: `'fallbackFonts' => [['@app/fonts/NotoSansArabic.ttf', null], ['@app/fonts/NotoSansJP.ttf', null]]`
(в `Options` пути без алиасов — используйте `Yii::getAlias()`), RTL включается атрибутом `dir="rtl"` в шаблоне.

## CLI
```bash
vendor/bin/baurpdf input.html output.pdf --format=A4 --landscape --toc --footer="{page}/{pages}" --watermark=DRAFT
```

## Принципы

- **Без системных зависимостей.** Только `composer require`; работает на любом shared-хостинге.
- **Secure by default (no network fetching unless explicitly allowed, no JS execution, path allowlist for local resources).**
  Сетевые ресурсы запрещены, пока не вызван `->allowRemote()`; `<script>` удаляется, JS никогда не выполняется;
  локальные файлы читаются только из `baseDir` и каталогов `->allowPath()`. libxml работает с `LIBXML_NONET`. Заблокированные ресурсы попадают в `warnings()`.
- **В процессе и экономно.** Всё внутри PHP-процесса, без временных браузеров; лимиты `timeLimit()` и `maxPages()` защищают от «бомб».
- **Детерминизм.** `->deterministic()` или `SOURCE_DATE_EPOCH` → одинаковые байты при одинаковом входе (удобно для кэша и тестов).
- **Корректность PDF.** PDF 1.7, object streams + сжатый XRef-поток, Flate, сабсеттинг шрифтов с ToUnicode. Все примеры проходят `qpdf --check` без ошибок и предупреждений.
- **Дизайн по умолчанию.** Тема `baurpdf.css`: 4-pt сетка отступов, модульная типографическая шкала, сдержанная палитра, готовые компоненты (`bp-card`, `bp-kpi`, `bp-grid-3`, `bp-badge`, `bp-alert`, `bp-cover`, `bp-striped`). Отключается `->theme(false)`.

## Производительность (PHP 8.3, один поток, тот же HTML)

| Документ | BaurPDF 2.2 | BaurPDF 2.1 | BaurPDF 2.0 | BaurPDF 1.0 | mPDF 8.2 |
|---|---:|---:|---:|---:|---:|
| Счёт (1 стр.) | 22 мс / 8 МБ / 17 КБ | **21 мс** / 8 МБ / 17 КБ | 25 мс / 8 МБ / 17 КБ | 28 мс / 8 МБ / 32 КБ | 99 мс / 20 МБ / 46 КБ |
| Текст, 200 разделов (~40 стр.) | **204 мс** / **18 МБ** / **103 КБ** | 207 мс / 18 МБ / 103 КБ | 305 мс / 18 МБ / 103 КБ | 331 мс / 50 МБ / 224 КБ | 1590 мс / 22 МБ / 132 КБ |
| Таблица 1000 строк × 4 | **463 мс** / **32 МБ** / **154 КБ** | 473 мс / 32 МБ / 154 КБ | 700 мс / 32 МБ / 154 КБ | 571 мс / 66 МБ / 225 КБ | 2530 мс / 110 МБ / 222 КБ |

Время (медиана из 3) / пиковая память (`memory_get_peak_usage(true)`) / размер файла. Скрипты: `php tools/bench/bench.php baur|mpdf invoice|text|table` и `php tools/bench/scale.php N` (mPDF — только для сравнения, путь через `MPDF_AUTOLOAD`).
В 2.1 таблица снова быстрее 1.0 (473 против 571 мс) при вдвое меньшей памяти: мемоизация каскада стилей (ключ — родительский стиль + сработавшие правила + атрибуты), ширин слов, сегментов шрифта, `boxMetrics`, разбора длин и кодирования текста.
В 2.2 сопоставление селекторов ускорено ~3× (52 → 16 мс на таблице) за счёт префильтра по предкам; добавленные псевдоэлементы, grid и transform не замедлили базовые сценарии.
Масштабирование линейное (таблица, 2 колонки; 2.1): 1000 строк — 180 мс / 16 МБ, 2000 — 361 мс / 26 МБ, 4000 — 714 мс / 46 МБ, 8000 — 1405 мс / 84 МБ; 2.2: 1000 — 202 мс, 8000 — 1359 мс / 88 МБ (2.0: 263 / 511 / 1028 / 2071 мс).

За счёт чего уменьшились память и размер:
- страницы ссылаются на операции атомов группами (`group` с dx/dy), а не копируют их;
- слова строки склеиваются в одну текстовую операцию (массивы смещений), слоги переносимых слов — в одно слово;
- одинаковые вычисленные стили интернируются (общий массив, copy-on-write);
- страница освобождается сразу после записи; object streams + сжатый XRef, сабсеттинг шрифтов, общие ресурсы.

### Профилирование
`BAURPDF_PROFILE=1 php script.php` печатает в STDERR время фаз (layout / paginate / write) и память до вёрстки, после неё и при записи.
Найденные и устранённые узкие места:
- `:nth-child` пересчитывал индекс обходом соседей → O(n²) на зебре длинных таблиц. Теперь индексы считаются одним проходом по родителю и кэшируются.
- Расчёт rowspan при выводе каждой группы строк обходил всю сетку → O(n²). Теперь обходится только диапазон строк.
Профиль 2.0 (таблица): каскад ~15%, ширина текста 12%, boxMetrics 12%, разбор длин 10% — всё это теперь кэшируется. Остались сопоставление селекторов (~8%) и Painter; узких мест O(n²) нет.

## Сравнение с mPDF и Chromium (эталон)

`php tools/compare/compare.php` рендерит документы из `tools/compare/docs/` тремя движками и кладёт в `tools/compare/out/` PNG «бок о бок» и `scorecard.md`.
Chromium и mPDF нужны **только** для этого dev-инструмента и не являются зависимостями пакета.

| Критерий | BaurPDF | mPDF 8.2 | Chromium |
|---|---|---|---|
| Системные зависимости | нет | нет | браузер ~300 МБ |
| Flexbox / Grid | да | нет (блоки в столбик) | да |
| CSS-переменные, calc() | да | нет | да |
| Повтор thead, разрыв высоких строк | да | да | да |
| Разбиение широкой таблицы с ключевой колонкой | да | нет | нет |
| Нативные графики, QR, Code128 | да | штрихкоды | нет (JS) |
| Оглавление с номерами страниц, закладки | да | да | нет |
| Шифрование | AES-256 | AES-128 | нет |
| PDF/A-2b | да (veraPDF PASS) | да | нет |
| float, columns, box-shadow | да (колонки рвутся по страницам, гауссово размытие) | частично | да |
| Tagged PDF / PDF/UA-1 | да (veraPDF PASS) | частично | теги без PDF/UA |
| Формы AcroForm | да | да | нет |
| Время, отчёт 1 стр. | 16 мс | 36 мс | 436 мс |
| Время, реестр 120 строк (9 стр.) | 96 мс | 377 мс | 1862 мс |
| Совпадение раскладки с Chromium | высокое (те же переносы и число страниц) | среднее | — |

### Scorecard 2.35 (`python3 tools/compare/scorecard.py --runs 3`, медиана 3 запусков, Chrome 156 headless, 23 документа)

| Ось | BaurPDF | Chromium |
|---|---|---|
| Скорость, холодный процесс (геом. среднее) | **в 2,6× быстрее** | 1× |
| Скорость, вызов внутри PHP | **в 14,3× быстрее** | 1× |
| Память, пиковый RSS дерева процессов | **в 41,8× меньше** | ~1,5–1,8 ГБ |
| Память, пиковый PSS | **в 12,1× меньше** | ~430–580 МБ |
| Размер файла | **в 1,63× меньше** (21/23 документов) | 1× |
| Визуальная близость к Chromium (ink-F1, среднее) | 0,991 | эталон |
| Текст (Jaccard слов); совпадение числа страниц | 0,971; **23/23** | эталон |
| CSS-покрытие (50 проверок, F1 ≥ 0,80) | **50/50** | эталон |

Полный отчёт и картинки side-by-side: `examples/output/preview/scorecard.md`. Близость и покрытие измеряются относительно Chromium, поэтому у самого Chromium они равны 1 по определению. С 2.22 `hyphens` по умолчанию `manual`, как в CSS, поэтому число страниц совпадает во всех 23 документах (раньше было 21/23). Jaccard для арабского (0,33) — артефакт: `pdftotext` извлекает из PDF Chromium слова, разорванные пробелами, а из PDF BaurPDF — целые слова. Проверка `hyphens:auto (ru)` (F1 0,805) отличается, потому что в headless-Chromium под Linux нет словарей переносов для русского.

**mPDF 8 (замер 2.30; 22 из 23 HTML, по процессу на документ, настройки по умолчанию):** BaurPDF в среднем в 11,9× быстрее и потребляет в 3,4× меньше пиковой памяти PHP. Сравнивать размер файлов (у mPDF в 33× больше) нельзя: на большинстве этих документов mPDF неверно разбивает страницы (от сотен до десятков тысяч страниц), а на документе с формой выходит за 1 ГБ памяти.

## Тесты
```bash
composer install
vendor/bin/phpunit                       # 175 тестов, 2750 проверок
VERAPDF=/path/verapdf vendor/bin/phpunit  # + проверка PDF/UA-1 через veraPDF
vendor/bin/phpstan analyse               # level max — без ошибок
```
API и построители, валидность каждого PDF через `qpdf --check`, извлечение кириллицы `pdftotext`,
шифрование (AES-256 с паролями пользователя и владельца, RC4, неверный пароль), векторы FIPS-197 для pure-PHP AES, PDF/A-2b (XMP, OutputIntent),
детерминизм (одинаковый md5), TOC/outline, таблицы (повтор thead и tfoot на каждой странице, ни одна строка не теряется, нарезка строки выше страницы,
split и альбомная страница для широкой таблицы, rowspan/colspan), переносы RU/EN/KZ, выключка по ширине без потери слов, float, колонки, box-shadow,
память на длинном тексте, QR (декодирование всех уровней коррекции через zxing), фаззинг: 40 случайных документов из битого HTML/CSS + бинарный мусор,
Tagged PDF/PDF/UA (дерево структуры, MCID, OBJR), поля AcroForm, SVG `<use>`/`<symbol>`/`<style>`, радиальные градиенты, новые шаблоны, скорость/память таблицы на 1000 строк,
а также безопасность (`/etc/passwd`, `http://127.0.0.1`, `<script>`).
Внешние утилиты (qpdf, poppler, zxing) необязательны: соответствующие проверки пропускаются, если утилит нет.
Все примеры из `examples/output/`, сгенерированные с `->pdfa()`, проходят veraPDF 1.30 (профиль PDF/A-2B).

## Формы, доступность, шаблоны (2.1)
- `<input>` (text, password, checkbox, radio), `<select>`, `<textarea>` превращаются в интерактивные поля AcroForm с готовым внешним видом (/AP), подписью `/TU` из `aria-label`/`title`/`placeholder`, флагами `readonly`/`disabled`/`required`/`maxlength`; одноимённые radio образуют группу.
- `hyphenate-limit-chars: <слово> <до> <после>`; по умолчанию 6/2/2, а в узких строках (< 10em) не меньше 8/3/3.
- Шаблоны: `report`, `invoice`, `presentation`, `contract`, `certificate`, `dashboard` — `BaurPDF::template('contract', [...])`.
- SVG: `<use>` (только локальные `#id`), `<symbol>` с viewBox, вложенный `<svg>`, правила `<style>` (tag/.class/#id). CSS: `radial-gradient()`.

## Примеры
`php examples/generate.php` создаёт в `examples/output/` файлы `report-with-charts.pdf`, `invoice.pdf`, `presentation-16x9.pdf`, `cyrillic.pdf` `v2-features.pdf` (float, колонки, тени, tfoot, альбомная таблица, PDF/A-2b), а также `contract.pdf` (Tagged PDF), `certificate.pdf`, `dashboard.pdf` (одна альбомная страница) и `form.pdf` (AcroForm + PDF/UA-1), `v22-features.pdf` (transform, счётчики в `::before`, grid-areas, `column-span`);
`php examples/stress-table.php` создаёт `stress-table-1200-rows.pdf` (1200 строк длинного кириллического текста с URL: 77 стр., ~1,4 с).

## Ограничения
- `widows`/`orphans` (2.8) соблюдаются при разрыве страниц и колонок, но не внутри ячеек таблиц и flex-элементов.
- Колонки: строки абзаца делятся между колонками и страницами; `column-span: all` поддерживается только у прямых детей контейнера колонок.
- `float` упрощён: обтекание считается по прямоугольнику врезки, без `shape-outside`; врезки шире 60% контейнера сжимаются. `box-shadow: inset` не поддерживается (пропускается); размытие — растровая маска прозрачности (SMask, 3 прохода box-blur ≈ гаусс, ≤2 px/pt).
- `transform` у HTML-блоков (2D: translate/scale/rotate/skew/matrix, `transform-origin`) рисуется матрицей; блок с трансформацией не разрывается между страницами, а ссылки и поля формы внутри него сохраняют нетрансформированные прямоугольники. 3D-функции игнорируются. Арабский: контекстные формы через Unicode Presentation Forms и лигатуры лам-алиф (шрифт с этими формами в cmap, например DejaVu Sans или Noto Sans Arabic, либо шрифт, получающий их через GSUB `isol/fina/init/medi` + лигатуры лам-алиф `rlig`, — например Harmattan; с 2.28 формы синтезируются из GSUB, подстановки 1→1), RTL через `dir`/`direction` (упрощённый UAX #9: переупорядочение слов в строке, выравнивание по правому краю, зеркальные скобки); без контекстных GSUB (type 5/6) и без позиционирования меток GPOS (огласовки не позиционируются, персидские/урду-расширения — частично), в RTL таблицы зеркалируются (первая колонка справа), маркеры списков стоят справа, отступ списка — справа; float и flex/grid не зеркалируются. Кернинг включён по умолчанию (GPOS PairPos из фичи `kern`, иначе таблица `kern`; ширины совпадают с Chromium, включая пары с пробелом), отключается `font-kerning: none` или `->kerning(false)`. CJK переносится между иероглифами/каной без пробелов с запретом начинать строку с `。、，）」` и заканчивать на `「（` (подмножество UAX #14; `word-break: keep-all` отключает). Нет индийских письменностей и CJK-шрифтов в комплекте (подключите TTF через `->fallbackFont()`, `@font-face`, `->font()` или разрешите автопоиск `->fontDir('/usr/share/fonts')`: явные fallback имеют приоритет, затем Noto Sans / DejaVu / прочие sans; не больше 300 загрузок шрифтов за рендер; поддерживаются TrueType (`glyf`) и OpenType/CFF (`.otf`, Noto CJK `.ttc`; встраиваются как CIDFontType0C с сабсеттингом глифов и подпрограмм), в том числе коллекции `.ttc` — `'fonts.ttc#1'` выбирает второй шрифт; при автопоиске декоративные гарнитуры идут последними, у Noto CJK первым берётся SC).
- Переносы в ячейках таблиц тоже включены, поэтому в очень узких колонках длинные слова делятся по слогам.
- `::before/::after`: `content` (строки, `attr()`, `counter()/counters()`, кавычки, `url()`), `counter-reset/increment/set`; `::marker`, `::first-line/letter` и `content` у обычных элементов не поддерживаются. Grid: именованные линии и `subgrid` игнорируются, `fr` в `grid-template-rows` считается `auto`. Радиальный градиент рисуется концентрическими эллипсами (48 шагов), без `repeating-*`.
- Формы: text/password/checkbox/radio/select/textarea; кнопки рисуются статично; без JavaScript-действий, калькуляции и отправки. Нет цифровой подписи. `overflow:hidden` обрезает только блоки с явной `height` меньше страницы; `position:relative` у блоков выше ~90% страницы смещение не применяет. Шифрование и PDF/A несовместимы (по стандарту): с `->pdfa()` пароль игнорируется с предупреждением.
- OpenType-CFF: CharStrings не перенумеровываются (неиспользуемые → `endchar`), поэтому subset CJK-шрифта весит ~200–700 КБ; CFF2 (вариативные) не поддерживается.
- PHPStan level max чист, но в `phpstan.neon` отключено одно правило — `missingType.iterableValue` (типы элементов массивов в phpdoc) — у внутренних массивов операций/стилей нет фиксированной формы.

## Дорожная карта
1. Разрыв трансформированных блоков и грид-полос между страницами по строкам.
2. `::marker`, `::first-letter`, именованные линии grid.

## Лицензии
Код — MIT. Шрифты PT Sans/Serif/Mono — SIL OFL 1.1 (`resources/fonts/OFL-PT.txt`), DejaVu — Bitstream Vera license (`resources/fonts/LICENSE-DejaVu.txt`).
