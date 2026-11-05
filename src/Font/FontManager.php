<?php
declare(strict_types=1);
namespace BaurPDF\Font;

/** Семейства шрифтов: встроенные PT Sans/Serif/Mono (кириллица, казахский), DejaVu (символы), @font-face TTF. */
final class FontManager
{
    private array $families = [];
    private array $loaded = [];
    private array $resolved = [];
    public array $warnings = [];
    /** @var list<array<string, string>> цепочка fallback-шрифтов (лица '400n'/'700n') */
    private array $chain = [];
    /** @var array<string, true> */
    private array $inChain = [];
    /** @var array<string, TrueTypeFont> */
    private array $fbCache = [];
    private string $dir;

    public function __construct(?string $fontDir = null)
    {
        $this->dir = $fontDir ?? dirname(__DIR__, 2) . '/resources/fonts';
        $sans = ['400n' => 'PTSans-Regular.ttf', '700n' => 'PTSans-Bold.ttf', '400i' => 'PTSans-Italic.ttf', '700i' => 'PTSans-BoldItalic.ttf'];
        $serif = ['400n' => 'PTSerif-Regular.ttf', '700n' => 'PTSerif-Bold.ttf'];
        $mono = ['400n' => 'PTMono-Regular.ttf'];
        $dejavu = ['400n' => 'DejaVuSans.ttf', '700n' => 'DejaVuSans-Bold.ttf'];
        foreach (['sans-serif', 'pt sans', 'arial', 'helvetica', 'helvetica neue', 'inter', 'roboto', 'system-ui', '-apple-system', 'segoe ui', 'open sans', 'verdana', 'tahoma', 'calibri', 'ubuntu', 'noto sans', 'lato', 'montserrat', 'liberation sans', 'blinkmacsystemfont', 'ui-sans-serif'] as $f) $this->families[$f] = $sans;
        foreach (['serif', 'pt serif', 'times', 'times new roman', 'georgia', 'cambria', 'garamond', 'liberation serif', 'ui-serif'] as $f) $this->families[$f] = $serif;
        foreach (['monospace', 'pt mono', 'courier', 'courier new', 'consolas', 'menlo', 'monaco', 'ui-monospace', 'sfmono-regular', 'liberation mono', 'dejavu sans mono'] as $f) $this->families[$f] = $mono;
        $this->families['dejavu sans'] = $dejavu;
        $this->families['__fallback'] = $dejavu;
    }

    /** Явный fallback-шрифт (TTF с glyf). */
    public function addFallback(string $regular, ?string $bold = null): bool
    {
        foreach (array_filter([$regular, $bold]) as $f) if (($err = TrueTypeFont::probe($f)) !== null) { $this->warnings[] = "Fallback font not usable: $err"; return false; }
        $this->addChain(['400n' => $regular] + ($bold !== null ? ['700n' => $bold] : []));
        return true;
    }

    private function addChain(array $faces): void
    {
        $k = implode('|', $faces);
        if (isset($this->inChain[$k])) return;
        $this->inChain[$k] = true; $this->chain[] = $faces; $this->fbCache = [];
    }

    /** Первый шрифт цепочки (семейства из font-family после основного, затем явные fallback, затем DejaVu), где есть символ. */
    public function fallbackFor(int $cp, bool $bold): TrueTypeFont
    {
        $ck = ($bold ? 'b' : 'r') . $cp;
        if (isset($this->fbCache[$ck])) return $this->fbCache[$ck];
        if (count($this->fbCache) > 20000) $this->fbCache = [];
        foreach ($this->chain as $faces) {
            $file = $bold ? ($faces['700n'] ?? $faces['400n'] ?? reset($faces)) : ($faces['400n'] ?? reset($faces));
            if (!is_string($file)) continue;
            try { $f = $this->load($file); } catch (\Throwable $e) { $this->warnings[] = 'Fallback font failed: ' . $e->getMessage(); continue; }
            if ($f->hasChar($cp)) return $this->fbCache[$ck] = $f;
        }
        if ($found = $this->scanFor($cp)) { $this->fbCache = []; return $this->fallbackFor($cp, $bold); }
        return $this->fbCache[$ck] = $this->fallback($bold);
    }

    // ---------- автоматический fallback из каталогов шрифтов (opt-in) ----------
    /** @var list<string> каталоги, разрешённые для поиска шрифтов */
    private array $fontDirs = [];
    /** @var list<string>|null очередь ещё не просмотренных файлов */
    private ?array $scanQueue = null;
    /** @var array<string,array<int,bool>|null> просмотренный файл => блоки по 256 кодпоинтов (null — непригоден) */
    private array $scanBlocks = [];
    /** @var array<int,true> кодпоинты, для которых ничего не нашлось */
    private array $scanMiss = [];
    private int $scanLoads = 0;
    /** @var array<string,true> файлы, добавленные сканированием */
    private array $scanAdded = [];
    private const SCAN_MAX_FILES = 4000, SCAN_MAX_LOADS = 300;

    /** Разрешить поиск недостающих глифов в TTF/TTC из каталога (рекурсивно). */
    public function addFontDir(string $dir): bool
    {
        $real = realpath($dir);
        if ($real === false || !is_dir($real)) { $this->warnings[] = "Font directory not found: $dir"; return false; }
        $this->fontDirs[] = $real; $this->scanQueue = null;
        return true;
    }

    /** Найти в разрешённых каталогах шрифт с символом $cp и добавить его в цепочку fallback. */
    private function scanFor(int $cp): bool
    {
        if (!$this->fontDirs || isset($this->scanMiss[$cp]) || $cp < 0x20) return false;
        // невидимые форматирующие символы (bidi-метки, ZW*, BOM) не рисуются — ради них шрифты не ищем
        if (($cp >= 0x200B && $cp <= 0x200F) || ($cp >= 0x202A && $cp <= 0x202E) || ($cp >= 0x2060 && $cp <= 0x206F) || $cp === 0xFEFF || $cp === 0xAD || $cp === 0x34F) return false;
        if ($this->scanQueue === null) $this->scanQueue = self::$fileLists[implode("\0", $this->fontDirs)] ??= $this->scanFiles();
        $blk = $cp >> 8;
        if ($cp >= 0xFB50 && $cp <= 0xFEFC) { // арабская форма представления: искать по блоку базовой буквы (формы синтезируются из GSUB)
            if (self::$formBase === []) { foreach (\BaurPDF\Text\Bidi::forms() as $base => $fs) foreach ($fs as $f) self::$formBase[$f] = $base; foreach (\BaurPDF\Text\Bidi::lamAlef() as $fs) foreach ($fs as $f) self::$formBase[$f] = 0x0644; }
            if (isset(self::$formBase[$cp])) $blk = self::$formBase[$cp] >> 8;
        }
        // уже просмотренные шрифты, покрывающие этот блок
        foreach ($this->scanBlocks as $file => $blocks) {
            if (!isset($blocks[$blk]) || isset($this->scanAdded[$file])) continue;
            if ($this->tryScanned($file, $cp)) return true;
        }
        while ($this->scanQueue && $this->scanLoads < self::SCAN_MAX_LOADS) {
            $file = array_shift($this->scanQueue);
            if (isset($this->scanAdded[$file])) continue;
            $blocks = $this->blocksOf($file);
            if ($blocks === null || !isset($blocks[$blk])) continue; // по индексу блоков: шрифт без нужного блока не загружается
            if ($this->tryScanned($file, $cp)) return true;
        }
        if ($this->scanLoads >= self::SCAN_MAX_LOADS && $this->scanQueue) $this->warnings['fontscan'] = 'Font directory scan limit reached (' . self::SCAN_MAX_LOADS . ' fonts)';
        $this->scanMiss[$cp] = true;
        return false;
    }

    /** @var array<int,int> форма представления => базовая арабская буква */
    private static array $formBase = [];
    /** @var array<string,list<string>> упорядоченные списки файлов по набору каталогов (на процесс) */
    private static array $fileLists = [];
    /** @var array<string,array<int,bool>> индекс блоков cmap на процесс: ключ — файл, размер, mtime и смещение cmap (общий cmap граней TTC считается один раз) */
    private static array $blockIndex = [];

    /** @return array<int,bool>|null блоки (cp >> 8), покрытые шрифтом; null — шрифт непригоден */
    private function blocksOf(string $spec): ?array
    {
        if (array_key_exists($spec, $this->scanBlocks)) return $this->scanBlocks[$spec];
        [$file, $idx] = TrueTypeFont::split($spec);
        $key = null;
        $h = @fopen($file, 'rb');
        if ($h !== false) {
            $st = fstat($h); $hd = (string)fread($h, 12); $base = 0;
            if (str_starts_with($hd, 'ttcf')) { fseek($h, 12 + 4 * $idx); $b = (string)fread($h, 4); $base = strlen($b) === 4 ? (int)(unpack('N', $b) ?: [1 => 0])[1] : 0; }
            fseek($h, $base + 4); $nb = (string)fread($h, 2); $n = strlen($nb) === 2 ? (int)(unpack('n', $nb) ?: [1 => 0])[1] : 0;
            $dir = (string)fread($h, max(1, 8 + 16 * min($n, 64))); fclose($h);
            for ($i = 0; $i < min($n, 64); $i++) if (substr($dir, 6 + 16 * $i, 4) === 'cmap') { $key = $file . '|' . ($st['size'] ?? 0) . '|' . ($st['mtime'] ?? 0) . '|' . substr($dir, 6 + 16 * $i + 8, 4); break; }
        }
        if ($key === null) return $this->scanBlocks[$spec] = null;
        // грани одной коллекции (SC/TC/JP/KR) покрывают одни и те же блоки по 256 — индексируется одна; наличие символа проверит tryScanned
        if (str_starts_with($hd ?? '', 'ttcf')) $key = $file . '|' . ($st['size'] ?? 0) . '|' . ($st['mtime'] ?? 0) . '|ttc';
        if (isset(self::$blockIndex[$key])) return $this->scanBlocks[$spec] = self::$blockIndex[$key];
        if (TrueTypeFont::probe($spec) !== null) return $this->scanBlocks[$spec] = null;
        try { $this->scanLoads++; $f = new TrueTypeFont($spec); }
        catch (\Throwable) { return $this->scanBlocks[$spec] = null; }
        $b = []; foreach ($f->cmap as $c => $_) $b[$c >> 8] = true;
        $this->probed = [$spec, $f];
        return $this->scanBlocks[$spec] = self::$blockIndex[$key] = $b;
    }

    /** @var array{0:string,1:TrueTypeFont}|null последний загруженный при индексации шрифт (переиспользуется, чтобы не читать файл дважды) */
    private ?array $probed = null;

    private function tryScanned(string $file, int $cp): bool
    {
        if ($this->probed !== null && $this->probed[0] === $file) $f = $this->probed[1];
        else { try { $this->scanLoads++; $f = new TrueTypeFont($file); } catch (\Throwable) { return false; } }
        $this->probed = null;
        if (!$f->hasChar($cp)) return false;
        $bold = null;
        [$fp, $fi] = TrueTypeFont::split($file);
        foreach (['Regular' => 'Bold', 'regular' => 'bold', '-R.' => '-B.'] as $a => $b) {
            if (str_contains(basename($fp), $a) && is_file($c = dirname($fp) . '/' . str_replace($a, $b, basename($fp)))) { $bold = $fi ? "$c#$fi" : $c; break; }
        }
        if (!$this->addFallback($file, $bold) && !($bold !== null && $this->addFallback($file))) return false;
        $this->loaded[$file] ??= $f;
        $this->scanAdded[$file] = true;
        return true;
    }

    /** @return list<string> файлы шрифтов: сначала Noto Sans / DejaVu Sans, обычное начертание раньше остальных */
    private function scanFiles(): array
    {
        $files = [];
        foreach ($this->fontDirs as $dir) {
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $fi) {
                    if (count($files) >= self::SCAN_MAX_FILES) break 2;
                    if (!$fi instanceof \SplFileInfo || !preg_match('/\.(ttf|ttc|otf|otc)$/i', $fi->getFilename())) continue;
                    $real = realpath($fi->getPathname());
                    if ($real === false || !str_starts_with($real, rtrim($dir, '/') . '/')) continue; // симлинки наружу не идут
                    $files[$real] = true;
                }
            } catch (\Throwable $e) { $this->warnings[] = 'Font directory scan failed: ' . $e->getMessage(); }
        }
        $rank = static function (string $f): int {
            $b = basename($f);
            // декоративные/дисплейные гарнитуры — в самый конец (Dela Gothic One, Diphylleia, *Display, рукописные…)
            if (preg_match('/display|decor|dela|diphylleia|script|hand|brush|comic|marker|graffiti|stencil|pixel|poster|dot|sketch|shadow|outline|inline|fancy|kids|cursive|calligraph|zen ?maru|yusei|rampart|reggae|stick|dokdo|gugi|kirang|monoton|black ?han|bungee|creepster|lobster|pacifico|caveat|emoji|symbol|icon/i', $f)) return 100;
            $r = preg_match('/bold|italic|oblique|light|thin|black|medium|semi|extra|heavy|condensed|mono/i', $b) ? 10 : 0;
            if (preg_match('/^NotoSans(?!Mono)/i', $b)) $r += 0; elseif (preg_match('/^DejaVuSans/i', $b)) $r += 1; elseif (preg_match('/^Noto/i', $b)) $r += 2; elseif (preg_match('/sans|gothic|arimo|liberation/i', $b)) $r += 3; else $r += 4;
            return $r;
        };
        $list = array_keys($files);
        usort($list, static fn($a, $b) => [$rank($a), $a] <=> [$rank($b), $b]);
        // коллекции: все начертания по индексу; у Noto CJK первым — SC (как Chromium по умолчанию)
        $out = [];
        foreach ($list as $f) {
            if (!preg_match('/\.(ttc|otc)$/i', $f)) { $out[] = $f; continue; }
            $h = @fopen($f, 'rb'); $hd = $h ? (string)fread($h, 12) : ''; if ($h) fclose($h);
            $n = str_starts_with($hd, 'ttcf') ? min(8, (int)(unpack('N', $hd, 8) ?: [1 => 1])[1]) : 1;
            $idx = range(0, max(0, $n - 1));
            if ($n > 2 && stripos(basename($f), 'CJK') !== false) $idx = array_values(array_unique([2, ...$idx]));
            foreach ($idx as $i) $out[] = $i ? "$f#$i" : $f;
        }
        return $out;
    }

    /** @font-face: путь к .ttf (локальный файл). */
    public function register(string $family, string $file, string $weight = 'normal', string $style = 'normal'): bool
    {
        if (($err = TrueTypeFont::probe($file)) !== null) { $this->warnings[] = $err; return false; }
        $w = self::weight($weight) >= 600 ? '700' : '400';
        $this->families[strtolower($family)][$w . (str_contains($style, 'italic') || str_contains($style, 'oblique') ? 'i' : 'n')] = $file;
        $this->resolved = [];
        return true;
    }

    public static function weight(string $w): int
    {
        $w = strtolower(trim($w));
        return match ($w) { 'bold', 'bolder' => 700, 'normal', '' => 400, 'lighter' => 300, default => is_numeric($w) ? (int)$w : 400 };
    }

    /** @return array{0:TrueTypeFont,1:bool,2:bool} font, fakeBold, fakeItalic */
    public function get(string $familyList, string $weight, string $style): array
    {
        $key = "$familyList|$weight|$style";
        if (isset($this->resolved[$key])) return $this->resolved[$key];
        $bold = self::weight($weight) >= 600; $italic = in_array(strtolower($style), ['italic', 'oblique'], true);
        $want = ($bold ? '700' : '400') . ($italic ? 'i' : 'n');
        $faces = null;
        foreach (explode(',', $familyList) as $fam) {
            $fam = strtolower(trim($fam, " \t\"'"));
            if (!isset($this->families[$fam])) continue;
            if ($faces === null) $faces = $this->families[$fam];
            elseif ($this->families[$fam] !== $faces) $this->addChain($this->families[$fam]); // остальные семейства списка — fallback-цепочка (CSS Fonts §5)
        }
        $faces ??= $this->families['sans-serif'];
        $fakeB = false; $fakeI = false;
        if (isset($faces[$want])) $file = $faces[$want];
        elseif ($italic && isset($faces[($bold ? '700' : '400') . 'n'])) { $file = $faces[($bold ? '700' : '400') . 'n']; $fakeI = true; }
        elseif ($bold && isset($faces['400' . ($italic ? 'i' : 'n')])) { $file = $faces['400' . ($italic ? 'i' : 'n')]; $fakeB = true; }
        else { $file = reset($faces); $fakeB = $bold; $fakeI = $italic; }
        return $this->resolved[$key] = [$this->load($file), $fakeB, $fakeI];
    }

    public function load(string $file): TrueTypeFont
    {
        $path = str_contains($file, '/') ? $file : $this->dir . '/' . $file;
        return $this->loaded[$path] ??= new TrueTypeFont($path);
    }

    public function fallback(bool $bold): TrueTypeFont
    {
        return $this->load($this->families['__fallback'][$bold ? '700n' : '400n']);
    }
}
