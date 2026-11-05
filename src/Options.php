<?php
declare(strict_types=1);
namespace BaurPDF;

use BaurPDF\Exception\BaurPdfException;

/** Параметры рендера. null = взять из CSS @page или значения по умолчанию. */
final class Options
{
    public const FORMATS = [ // мм
        'A2' => [420, 594], 'A3' => [297, 420], 'A4' => [210, 297], 'A5' => [148, 210], 'A6' => [105, 148],
        'LETTER' => [215.9, 279.4], 'LEGAL' => [215.9, 355.6], 'TABLOID' => [279.4, 431.8],
        'SLIDE' => [338.667, 190.5], 'SLIDE16X9' => [338.667, 190.5], '16:9' => [338.667, 190.5], 'SLIDE4X3' => [254, 190.5],
    ];

    public string|array|null $format = null;     // 'A4' | [w,h] в мм
    public ?bool $landscape = null;
    /** @var array{0:float,1:float,2:float,3:float}|null мм: top,right,bottom,left */
    public ?array $margins = null;
    /** HTML шапки/подвала. Плейсхолдеры: {PAGENO} {nbpg} {page} {pages} {DATE} {TITLE}. Или callable(int $page, int $total): ?string */
    public mixed $header = null;
    public mixed $footer = null;
    public bool $headerOnFirstPage = true;
    public bool $footerOnFirstPage = true;
    public float $headerOffset = 7;              // мм от края листа
    public float $footerOffset = 7;
    public ?array $watermark = null;             // ['text'=>'ЧЕРНОВИК','opacity'=>0.08,'angle'=>45,'color'=>'#000','size'=>90] | ['image'=>path]
    public array $meta = [];                     // title, author, subject, keywords, creator, lang
    public ?string $userPassword = null;
    public ?string $ownerPassword = null;
    public array $permissions = ['print', 'copy'];
    public bool $theme = true;                   // встроенная тема (типографика, таблицы, карточки, слайды)
    public array $css = [];
    public ?string $baseDir = null;              // база для относительных путей
    public array $allowedPaths = [];             // доп. каталоги, откуда можно читать файлы
    public bool $allowRemote = false;            // http(s)-ресурсы запрещены по умолчанию
    public float $timeLimit = 60;                // сек
    public int $maxPages = 2000;
    public array|bool $toc = false;              // true | ['title'=>'Содержание','levels'=>3]
    public bool $outline = true;                 // PDF-закладки из h1..h6
    public bool $compress = true;
    /** Кернинг пар по таблице kern шрифта (как font-kerning: auto в браузерах). */
    public bool $kerning = true;
    /** Уменьшать картинки крупнее нужного для этого DPI (null — как есть). Нужен GD. */
    public ?int $imageDpi = null;
    /** Качество JPEG при пережатии уменьшенных фото. */
    public int $jpegQuality = 85;
    public ?int $fixedTime = null;               // детерминированный вывод (unix time)
    public bool $presentation = false;           // 16:9, поля 0, каждая .slide — новая страница
    /** @var list<array{0:string,1:?string}> fallback-цепочка: [regular, bold] TTF для отсутствующих глифов (CJK, арабский, …) */
    public array $fallbackFonts = [];
    /** @var list<string> каталоги, в которых разрешено искать шрифты для недостающих глифов (opt-in, рекурсивно) */
    public array $fontDirs = [];
    public array $fonts = [];                    // ['Family' => ['regular'=>path,'bold'=>path,'italic'=>path,'bolditalic'=>path]]
    public int $pageNumberStart = 1;
    public bool $autoLandscape = true;           // широкие таблицы — на альбомные страницы (data-overflow="auto|landscape")
    public string $encryption = 'aes256';        // 'aes256' (PDF 2.0/AESV3, R6) | 'rc4' (совместимость со старыми ридерами)
    public bool $tagged = false;                 // Tagged PDF: логическая структура для скринридеров и переразметки
    public bool $pdfua = false;                  // PDF/UA-1 (ISO 14289): теги + заголовок + язык + метаданные
    public bool $pdfa = false;                   // PDF/A-2b: XMP, OutputIntent sRGB, без шифрования

    public static function fromArray(array $a): self
    {
        $o = new self();
        foreach ($a as $k => $v) {
            if (!property_exists($o, $k)) {
                $names = array_keys(get_object_vars($o)); usort($names, fn($a, $b) => levenshtein((string)$k, $a) <=> levenshtein((string)$k, $b));
                throw new BaurPdfException("Unknown option '$k'. Did you mean '{$names[0]}'? Valid options: " . implode(', ', array_keys(get_object_vars($o))));
            }
            $o->$k = $v;
        }
        return $o;
    }

    public static function formatMm(string|array $format, bool $landscape = false): array
    {
        if (is_array($format)) { [$w, $h] = array_values($format); }
        else {
            $k = strtoupper(str_replace(['-', ' ', '_'], '', $format));
            if ($k === '169') $k = '16:9';
            if (!isset(self::FORMATS[$k])) throw new BaurPdfException("Unknown page format '$format'. Use one of: " . implode(', ', array_keys(self::FORMATS)) . ' or [width, height] in mm');
            [$w, $h] = self::FORMATS[$k];
        }
        if ($w <= 0 || $h <= 0) throw new BaurPdfException('Page size must be positive');
        if ($landscape && $w < $h) [$w, $h] = [$h, $w];
        return [(float)$w, (float)$h];
    }
}
