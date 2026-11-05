<?php
declare(strict_types=1);
namespace BaurPDF;

use BaurPDF\Exception\BaurPdfException;

/**
 * BaurPDF — HTML → PDF на чистом PHP.
 *
 *   BaurPDF::html('<h1>Привет</h1>')->save('a.pdf');
 *   BaurPDF::html($html)->format('A4')->landscape()->footer('Стр. {PAGENO} из {nbpg}')->toc()->download('report.pdf');
 */
final class BaurPDF
{
    public const VERSION = '2.36.2';

    private Options $o;
    private string $html = '';
    private ?string $cacheDir = null;
    private array $warnings = [];
    private ?Document $doc = null;
    /** @var array{rows:iterable<mixed>,columns:list<string>,row:?callable,chunk:int}|null */
    private ?array $stream = null;
    private bool $streamUsed = false;

    public function __construct(array|Options $options = [])
    {
        $this->o = $options instanceof Options ? clone $options : Options::fromArray($options);
    }

    // ---------- точки входа ----------
    public static function html(string $html, array|Options $options = []): self { $m = new self($options); $m->html = $html; return $m; }
    public static function fromHtml(string $html, array|Options $options = []): self { return self::html($html, $options); }

    public static function fromFile(string $path, array|Options $options = []): self
    {
        if (!is_file($path) || !is_readable($path)) throw new BaurPdfException("HTML file not found or not readable: $path");
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new BaurPdfException("Unable to read HTML file: $path");
        }
        $m = self::html($content, $options);
        $m->o->baseDir ??= dirname((string)realpath($path));
        return $m;
    }

    /** Рендер PHP-шаблона: переменные $params доступны внутри. */
    public static function fromView(string $file, array $params = [], array|Options $options = []): self
    {
        if (!is_file($file)) throw new BaurPdfException("View not found: $file");
        $m = self::html(self::renderPhp($file, $params), $options);
        $m->o->baseDir ??= dirname((string)realpath($file));
        return $m;
    }

    /** Встроенные шаблоны: report, invoice, presentation, contract, certificate, dashboard. */
    public static function template(string $name, array $data = [], array|Options $options = []): self
    {
        $file = dirname(__DIR__) . '/resources/templates/' . basename($name) . '.php';
        if (!is_file($file)) throw new BaurPdfException("Unknown template '$name'. Available: " . implode(', ', array_map(fn($f) => basename($f, '.php'), glob(dirname(__DIR__) . '/resources/templates/*.php') ?: [])));
        $m = self::fromView($file, $data, $options);
        if ($name === 'presentation') $m->o->presentation = true;
        return $m;
    }

    public static function renderPhp(string $file, array $params): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars(is_scalar($v) || $v instanceof \Stringable ? (string)$v : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        try { (static function () use ($file, $params, $e) { $e = $e; extract($params, EXTR_SKIP); include $file; })(); }
        catch (\Throwable $ex) { ob_end_clean(); throw new BaurPdfException('Template error in ' . basename($file) . ': ' . $ex->getMessage(), 0, $ex); }
        return (string)ob_get_clean();
    }

    // ---------- построители без HTML ----------
    /**
     * Таблица из массива: BaurPDF::table($rows) — заголовки берутся из ключей первой строки.
     * @param array<int,array<string|int,scalar|null>> $rows
     */
    public static function table(array $rows, ?array $headers = null, string $title = '', array|Options $options = []): self
    {
        return self::html(self::tableHtml($rows, $headers, $title), $options);
    }

    public static function tableHtml(array $rows, ?array $headers = null, string $title = ''): string
    {
        $escape = static function (mixed $value): string {
            if (!is_scalar($value) && !$value instanceof \Stringable) return '';
            return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true);
        };
        $first = $rows ? (array)reset($rows) : [];
        $keys = array_keys($first);
        $associative = !array_is_list($first);
        $headers ??= $associative ? array_map('strval', $keys) : [];
        $numeric = [];
        foreach (array_values($first) as $i => $v) {
            if (is_int($v) || is_float($v)) $numeric[$i] = true;
        }
        $h = $title !== '' ? '<h1>' . $escape($title) . '</h1>' : '';
        $h .= '<table class="bp-striped">';
        if ($headers) {
            $h .= '<thead><tr>';
            foreach (array_values($headers) as $i => $column) {
                $h .= '<th' . (isset($numeric[$i]) ? ' class="bp-num"' : '') . '>' . $escape($column) . '</th>';
            }
            $h .= '</tr></thead>';
        }
        $h .= '<tbody>';
        $columnCount = count($keys);
        foreach ($rows as $row) {
            $values = (array)$row;
            // Preserve the first row's column order, including when later
            // associative rows have a different insertion order.
            $cells = $associative
                ? array_map(static fn($key) => $values[$key] ?? null, $keys)
                : array_values($values);
            // Keep a rectangular table: omit excess values and pad missing
            // positional cells when the first row established the schema.
            if ($columnCount > 0) {
                $cells = array_slice(array_pad($cells, $columnCount, null), 0, $columnCount);
            }
            $h .= '<tr>';
            foreach ($cells as $i => $value) {
                $num = is_int($value) || is_float($value);
                $text = is_float($value) ? number_format($value, 2, ',', "\u{00A0}")
                    : (is_int($value) ? number_format($value, 0, ',', "\u{00A0}") : $value);
                $h .= '<td' . ($num || isset($numeric[$i]) ? ' class="bp-num"' : '') . '>' . $escape($text) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    }

    /**
     * График без HTML: BaurPDF::chart('bar', ['Янв' => 10, 'Фев' => 20]) или
     * BaurPDF::chart('line', ['labels' => [...], 'datasets' => ['2025' => [...], '2026' => [...]]]).
     */
    public static function chart(string $type, array $data, string $title = '', array|Options $options = []): self
    {
        return self::html(($title !== '' ? '<h1>' . htmlspecialchars($title) . '</h1>' : '') . self::chartHtml($type, $data, ['height' => 380]), $options);
    }

    public static function chartHtml(string $type, array $data, array $opt = []): string
    {
        if (isset($data['labels'])) { $labels = $data['labels']; $series = $data['datasets'] ?? $data['series'] ?? []; }
        else { $labels = array_keys($data); $series = [array_values($data)]; }
        $c = match (strtolower($type)) {
            'pie' => \BaurPDF\Chart\Chart::pie(isset($data['labels']) ? array_combine($labels, (array)reset($series)) : $data),
            'donut', 'doughnut' => \BaurPDF\Chart\Chart::donut(isset($data['labels']) ? array_combine($labels, (array)reset($series)) : $data),
            'line' => \BaurPDF\Chart\Chart::line($labels, $series), 'area' => \BaurPDF\Chart\Chart::area($labels, $series),
            'hbar' => \BaurPDF\Chart\Chart::hbar($labels, $series), 'stacked' => \BaurPDF\Chart\Chart::stacked($labels, $series),
            'bar' => \BaurPDF\Chart\Chart::bar($labels, $series),
            'gauge' => \BaurPDF\Chart\Chart::gauge((float)($data['value'] ?? 0), (float)($data['max'] ?? 100)),
            default => throw new BaurPdfException("Неизвестный тип графика '$type'. Доступны: bar, hbar, stacked, line, area, pie, donut, gauge"),
        };
        foreach ($opt as $k => $v) $k === 'height' ? $c->height($v) : $c->option($k, $v);
        return $c->html();
    }

    /**
     * Презентация 16:9 из массива слайдов:
     * BaurPDF::slides([['title' => 'Итоги года', 'subtitle' => '2026'], ['title' => 'Цифры', 'bullets' => [...]], ['title' => 'Продажи', 'chart' => Chart::bar(...)]])
     * Первый слайд (если есть только title/subtitle) оформляется как титульный.
     */
    /** SVG-разметка QR-кода для вставки в HTML: BaurPDF::qr('https://…', 90). */
    public static function qr(string $data, float $sizePt = 80, string $ecl = 'M'): string { return Barcode\QrCode::svg($data, $sizePt, $ecl); }

    /** SVG-разметка штрихкода Code 128. */
    public static function barcode(string $text, float $widthPt = 160, float $heightPt = 40): string { return Barcode\Code128::svg($text, $widthPt, $heightPt); }

    public static function slides(array $slides, array|Options $options = []): self
    {
        foreach ($slides as $i => &$s) {
            if (is_string($s)) $s = ['title' => $s];
            $s['type'] ??= ($i === 0 && !isset($s['bullets']) && !isset($s['chart']) && !isset($s['html'])) ? 'title' : 'content';
        }
        return self::template('presentation', ['slides' => $slides], $options);
    }

    // ---------- fluent-опции ----------
    public function format(string|array $f): self { $this->doc = null; Options::formatMm($f); $this->o->format = $f; return $this; }
    public function landscape(bool $on = true): self { $this->doc = null; $this->o->landscape = $on; return $this; }
    public function portrait(): self { $this->doc = null; return $this->landscape(false); }
    /** Поля: margins(15) (мм) | margins('15mm') | margins('1cm 2cm') | margins(10, 15, 10, 15) */
    public function margins(float|string $top, float|string|null $right = null, float|string|null $bottom = null, float|string|null $left = null): self
    {
        $this->doc = null;
        if (is_string($top) && $right === null && str_contains(trim($top), ' ')) {
            $p = preg_split('/\s+/', trim($top)) ?: [$top];
            [$top, $right, $bottom, $left] = [$p[0], $p[1] ?? $p[0], $p[2] ?? $p[0], $p[3] ?? ($p[1] ?? $p[0])];
        }
        $top = self::mm($top); $right = $right === null ? $top : self::mm($right);
        $bottom = $bottom === null ? $top : self::mm($bottom); $left = $left === null ? $right : self::mm($left);
        foreach ([$top, $right, $bottom, $left] as $v) if ($v < 0) throw new BaurPdfException('Margins must be >= 0');
        $this->o->margins = [$top, $right, $bottom, $left]; return $this;
    }
    private static function mm(float|string $v): float
    {
        if (is_float($v) || is_numeric($v)) return (float)$v;
        $pt = \BaurPDF\Css\Length::toPt(trim($v), 0, 12);
        if ($pt === null) throw new BaurPdfException("Invalid margin '$v'. Use a number (mm) or a CSS length like '15mm', '1cm', '0.5in', '20px'");
        return $pt * 25.4 / 72;
    }

    public function header(string|callable|null $html, bool $onFirstPage = true): self { $this->doc = null; $this->o->header = $html; $this->o->headerOnFirstPage = $onFirstPage; return $this; }
    public function footer(string|callable|null $html, bool $onFirstPage = true): self { $this->doc = null; $this->o->footer = $html; $this->o->footerOnFirstPage = $onFirstPage; return $this; }
    /** Стандартный подвал «Стр. X из Y». */
    public function pageNumbers(string $format = 'Стр. {PAGENO} из {nbpg}', string $align = 'center'): self
    {
        $this->doc = null;
        return $this->footer('<div style="text-align:' . $align . '">' . $format . '</div>');
    }
    public function watermark(string $text, float $opacity = 0.08, float $angle = 45, string $color = '#000000'): self { $this->doc = null; $this->o->watermark = ['text' => $text, 'opacity' => $opacity, 'angle' => $angle, 'color' => $color]; return $this; }
    public function watermarkImage(string $path, float $opacity = 0.1, ?float $widthPt = null): self { $this->doc = null; $this->o->watermark = ['image' => $path, 'opacity' => $opacity, 'width' => $widthPt]; return $this; }
    public function title(string $t): self { $this->doc = null; $this->o->meta['title'] = $t; return $this; }
    public function meta(array $meta): self { $this->doc = null; $this->o->meta = $meta + $this->o->meta; return $this; }
    public function password(?string $user, ?string $owner = null, array $permissions = ['print', 'copy']): self
    {
        $this->doc = null;
        $this->o->userPassword = $user ?? ''; $this->o->ownerPassword = $owner; $this->o->permissions = $permissions; return $this;
    }
    /** Метод шифрования: 'aes256' (по умолчанию, R6/AESV3) или 'rc4' (только для очень старых ридеров). */
    public function encryption(string $method): self { $this->doc = null; $m = strtolower($method); if (!in_array($m, ['aes256', 'rc4'], true)) throw new \InvalidArgumentException("Unknown encryption '$method' (aes256|rc4)"); $this->o->encryption = $m; return $this; }
    /** PDF/A-2b (архивный): XMP-метаданные, OutputIntent sRGB; несовместим с шифрованием. */
    public function pdfa(bool $on = true): self { $this->doc = null; $this->o->pdfa = $on; return $this; }
    /** Tagged PDF: дерево структуры (заголовки, абзацы, списки, таблицы, рисунки с Alt). */
    public function tagged(bool $on = true): self { $this->doc = null; $this->o->tagged = $on; return $this; }
    /** PDF/UA-1: Tagged PDF + обязательные заголовок, язык и XMP-метаданные. */
    public function pdfua(bool $on = true): self { $this->doc = null; $this->o->pdfua = $on; if ($on) $this->o->tagged = true; return $this; }
    public function autoLandscape(bool $on = true): self { $this->doc = null; $this->o->autoLandscape = $on; return $this; }
    public function toc(string $title = 'Содержание', int $levels = 3): self { $this->doc = null; $this->o->toc = ['title' => $title, 'levels' => $levels]; return $this; }
    public function outline(bool $on = true): self { $this->doc = null; $this->o->outline = $on; return $this; }
    public function presentation(bool $on = true): self { $this->doc = null; $this->o->presentation = $on; return $this; }
    public function css(string $css): self { $this->doc = null; $this->o->css[] = $css; return $this; }
    public function theme(bool $on): self { $this->doc = null; $this->o->theme = $on; return $this; }
    public function kerning(bool $on = true): self { $this->doc = null; $this->o->kerning = $on; return $this; }
    public function baseDir(string $dir): self { $this->doc = null; $this->o->baseDir = $dir; return $this; }
    public function allowPath(string ...$dirs): self { $this->doc = null; array_push($this->o->allowedPaths, ...$dirs); return $this; }
    public function allowRemote(bool $on = true): self { $this->doc = null; $this->o->allowRemote = $on; return $this; }
    public function font(string $family, string $regular, ?string $bold = null, ?string $italic = null, ?string $boldItalic = null): self
    {
        $this->doc = null;
        foreach (array_filter([$regular, $bold, $italic, $boldItalic]) as $f) if (!is_file(Font\TrueTypeFont::split($f)[0])) throw new BaurPdfException("Font file not found: $f");
        $this->o->fonts[$family] = array_filter(['regular' => $regular, 'bold' => $bold, 'italic' => $italic, 'bolditalic' => $boldItalic]);
        return $this;
    }
    /** Шрифт в цепочку fallback (по порядку): используется для символов, которых нет в основном шрифте. */
    /**
     * Потоковая таблица: строки берутся из итератора порциями по $chunk и сразу выводятся в PDF,
     * поэтому память не растёт с числом строк (50 000+ строк в 128 МБ). Содержимое после таблицы выводится после последней строки (2.30). Источник таблицы
     * — либо <table data-bp-stream> в HTML (его thead повторяется на каждой странице), либо
     * создаётся из $columns. Строка — массив ячеек (экранируется) или готовый HTML '<tr>…</tr>';
     * $rowHtml(mixed $row): string задаёт свою разметку. Раскладка столбцов всегда table-layout:fixed.
     */
    public function streamTable(iterable $rows, array $columns = [], ?callable $rowHtml = null, int $chunk = 400): self
    {
        $this->doc = null;
        $this->stream = ['rows' => $rows, 'columns' => array_values(array_map('strval', $columns)), 'row' => $rowHtml, 'chunk' => min(2000, max(20, $chunk))];
        $this->streamUsed = false;
        return $this;
    }

    /**
     * Разрешить поиск шрифтов для недостающих глифов в каталогах (например '/usr/share/fonts').
     * Каталог — это allowlist: файлы вне него (в том числе через симлинки) не читаются.
     */
    public function fontDir(string ...$dirs): self { $this->doc = null; foreach ($dirs as $d) $this->o->fontDirs[] = $d; return $this; }

    public function fallbackFont(string $regular, ?string $bold = null): self { $this->doc = null; $this->o->fallbackFonts[] = [$regular, $bold]; return $this; }
    public function timeLimit(float $seconds): self { $this->doc = null; $this->o->timeLimit = max(1, $seconds); return $this; }
    public function maxPages(int $n): self { $this->doc = null; $this->o->maxPages = max(1, $n); return $this; }
    /** Уменьшать картинки до $dpi при их размере на странице (фото 4000 px в блоке 6 см → ~710 px при 300 dpi). */
    public function imageDpi(?int $dpi = 150, int $jpegQuality = 85): self { $this->doc = null; $this->o->imageDpi = $dpi === null ? null : max(36, $dpi); $this->o->jpegQuality = max(30, min(100, $jpegQuality)); return $this; }
    public function compress(bool $on = true): self { $this->doc = null; $this->o->compress = $on; return $this; }
    /** Детерминированный вывод: одинаковый вход → одинаковые байты. */
    public function deterministic(int $timestamp = 0): self { $this->doc = null; $this->o->fixedTime = $timestamp ?: 946684800; return $this; }
    public function cache(string $dir): self { $this->cacheDir = $dir; return $this; }
    public function options(): Options { return $this->o; }

    // ---------- рендер ----------
    public function render(): Document
    {
        if ($this->doc) return $this->doc;
        $key = null;
        if ($this->cacheDir && $this->stream === null) {
            $key = $this->cacheDir . '/' . hash('sha256', self::VERSION . serialize([$this->html, array_map(fn($v) => $v instanceof \Closure ? 'closure' : $v, get_object_vars($this->o))])) . '.pdf';
            if (is_file($key)) return $this->doc = new Document((string)file_get_contents($key));
        }
        if ($this->stream !== null) {
            // одноразовый итератор (генератор) нельзя прочитать второй раз: без этой проверки повторный рендер молча дал бы пустую таблицу
            $rows = $this->stream['rows'];
            if ($this->streamUsed && ($rows instanceof \Generator || $rows instanceof \NoRewindIterator)) throw new BaurPdfException('Stream rows were already consumed. Call streamTable() with a new generator before rendering again.');
            $this->streamUsed = true;
        }
        $r = new Renderer();
        try {
            $bytes = $r->render($this->html, $this->o, $this->stream);
        } catch (BaurPdfException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Никогда не отдаём битый файл: страница с ошибкой — валидный PDF
            $r = new Renderer();
            $bytes = $r->render('<h1>Ошибка рендера</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>', Options::fromArray(['timeLimit' => 10]));
            $r->warnings[] = 'Render failed: ' . $e->getMessage();
        }
        $this->warnings = $r->warnings;
        $this->doc = new Document($bytes, 'application/pdf', $r->pages);
        if ($key) { @mkdir(dirname($key), 0775, true); $this->doc->save($key); }
        return $this->doc;
    }

    public function save(string $path): string { return $this->render()->save($path); }
    public function stream(string $filename = 'document.pdf'): void { $this->render()->stream($filename); }
    public function download(string $filename = 'document.pdf'): void { $this->render()->download($filename); }
    public function toString(): string { return $this->render()->content(); }
    public function output(): string { return $this->toString(); }
    /** Предупреждения рендера (пропущенные картинки, неподдерживаемый CSS и т.п.). */
    public function warnings(): array { $this->render(); return $this->warnings; }

    /** Пакетный рендер: ['file.pdf' => '<html>', ...] или ['file.pdf' => BaurPDF]. */
    public static function batch(array $jobs, array|Options $options = []): array
    {
        $out = [];
        foreach ($jobs as $path => $job) {
            $m = $job instanceof self ? $job : self::html((string)$job, $options);
            try { $out[$path] = $m->save((string)$path); } catch (\Throwable $e) { $out[$path] = $e; }
        }
        return $out;
    }

    /** Склейка: объединяет HTML нескольких документов в один PDF (с общей нумерацией и оглавлением). */
    public static function merge(array $parts, array|Options $options = []): self
    {
        $html = [];
        foreach ($parts as $i => $p) {
            $h = $p instanceof self ? $p->html : (string)$p;
            if (preg_match('#<body[^>]*>(.*)</body>#is', $h, $m)) {
                preg_match_all('#<style[^>]*>.*?</style>#is', $h, $st);
                $h = implode('', $st[0]) . $m[1];
            }
            $html[] = ($i > 0 ? '<pagebreak></pagebreak>' : '') . $h;
        }
        return self::html(implode("\n", $html), $options);
    }
}
