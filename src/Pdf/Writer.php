<?php
declare(strict_types=1);
namespace BaurPDF\Pdf;

use BaurPDF\Font\TrueTypeFont;
use BaurPDF\BaurPDF;

/**
 * Низкоуровневый PDF 1.7 writer: страницы, встраиваемые TrueType-шрифты (subset, Identity-H, ToUnicode),
 * изображения (JPEG/PNG с альфой), прозрачность, ссылки, закладки (outline), метаданные, шифрование AES-256 (R6) / RC4-128.
 */
final class Writer
{
    /** @var array<int,array{w:float,h:float,content:string,annots:array}> */
    public array $pages = [];
    /** @var array<string,array{font:TrueTypeFont,used:array<int,int>,res:string}> */
    private array $fonts = [];
    private array $images = [];
    private array $alphas = [];
    /** @var array<string,array{page:int,y:float}> */
    public array $dests = [];
    /** @var array<int,array{title:string,level:int,page:int,y:float}> */
    public array $outline = [];
    public array $info = [];
    private ?array $enc = null;
    private array $objs = [];
    private string $fileId;
    public bool $compress = true;
    public ?int $imageDpi = null;
    public int $jpegQuality = 85;
    /** @var array<string,string> */
    private array $scaled = [];

    public function __construct() { $this->fileId = md5(uniqid('', true) . random_bytes(8)); }

    public function addPage(float $w, float $h): int
    {
        $this->pages[] = ['w' => $w, 'h' => $h, 'content' => '', 'annots' => []];
        return count($this->pages) - 1;
    }

    public function fontRes(TrueTypeFont $f): string
    {
        $k = $f->path;
        if (!isset($this->fonts[$k])) $this->fonts[$k] = ['font' => $f, 'used' => [], 'res' => 'F' . (count($this->fonts) + 1)];
        return $this->fonts[$k]['res'];
    }

    /** Кодирование UTF-8 строки в hex glyph ids + учёт использованных глифов. */
    /** @var array<string, array<string, array{0: string, 1: array<int, int>}>> */
    private array $encCache = [];

    public function encodeText(TrueTypeFont $f, string $text, bool $kerning = true): string
    {
        $k = $f->path . ($kerning ? '' : "\0nk"); $this->fontRes($f);
        if (isset($this->encCache[$k][$text])) { [$hex, $used] = $this->encCache[$k][$text]; $this->fonts[$f->path]['used'] += $used; return $hex; }
        $hex = ''; $used = []; $parts = []; $prev = -1; $kern = $kerning && $f->hasKerning();
        $marks = $kerning && $f->hasMarks() && preg_match('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', $text) === 1;
        $base = 0; $baseAdv = 0.0; $lastMark = null; $upm = $f->unitsPerEm ?: 1000;
        foreach (mb_str_split($text) as $ch) {
            $cp = mb_ord($ch);
            $g = $f->glyph($cp);
            // GPOS mark-to-base: сдвинуть диакритику к якорю базы (по горизонтали) и вернуть перо назад
            // mark-to-mark: следующая диакритика крепится к предыдущей (шадда + фатха)
            if ($marks && $g && $lastMark && ($cp >= 0x064B && $cp <= 0x06ED) && ($mm = $f->markAnchor($lastMark[0], $g, true)) !== null) $a = [$lastMark[1] + $mm[0], $lastMark[2] + $mm[1]];
            elseif ($marks && $g && $base && ($cp >= 0x064B && $cp <= 0x06ED)) $a = $f->markAnchor($base, $g);
            else $a = null;
            if ($a === null) $lastMark = null;
            if ($a !== null) {
                $lastMark = [$g, $a[0], $a[1]];
                $dx = $a[0] - $baseAdv; $ma = $f->glyphWidth($g) * $upm / 1000;
                $parts[] = '<' . $hex . '>'; $parts[] = self::num(-$dx * 1000 / $upm); $hex = '';
                $dy = $a[1] * 1000 / $upm; if (abs($dy) >= 1) $parts[] = "\x01R" . self::num($dy);
                $used[$g] = $cp; $hex .= sprintf('%04X', $g);
                $parts[] = '<' . $hex . '>'; $parts[] = self::num(($dx + $ma) * 1000 / $upm); $hex = '';
                if (abs($dy) >= 1) $parts[] = "\x01R0";
                $prev = -1; continue;
            }
            if ($marks && $g && !($cp >= 0x064B && $cp <= 0x06ED)) { $lastMark = null; $base = $g; $baseAdv = $f->glyphWidth($g) * $upm / 1000; }
            if ($kern && $prev >= 0 && $g && ($kv = $f->kernCp($prev, $cp)) != 0) { $parts[] = '<' . $hex . '>'; $parts[] = self::num(-$kv); $hex = ''; }
            $prev = $g ? $cp : -1;
            $used[$g] = $cp;
            $hex .= sprintf('%04X', $g);
        }
        $out = $parts ? '[' . implode(' ', $parts) . ' <' . $hex . '>]' : '<' . $hex . '>';
        $this->fonts[$f->path]['used'] += $used;
        if (count($this->encCache[$k] ?? []) < 50000) $this->encCache[$k][$text] = [$out, $used];
        return $out;
    }

    /** Оператор показа текста: Tj для строки, TJ для массива с кернингом. */
    public static function show(string $enc, float $size = 0.0): string
    {
        if (!str_contains($enc, "\x01")) return $enc . ($enc[0] === '[' ? ' TJ' : ' Tj');
        // вертикальные сдвиги диакритик (GPOS mark): маркеры \x01R<1/1000 em> → Ts между фрагментами TJ
        $seg = preg_split('/\x01R(-?[0-9.]+)/', substr($enc, 1, -1), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
        $out = '[' . trim($seg[0]) . '] TJ';
        for ($i = 1; $i + 1 < count($seg); $i += 2) $out .= ' ' . self::num((float)$seg[$i] * $size / 1000) . ' Ts [' . trim($seg[$i + 1]) . '] TJ';
        return $out;
    }

    private static function num(float $v): string { $s = rtrim(rtrim(sprintf('%.2F', $v), '0'), '.'); return $s === '-0' ? '0' : $s; }

    /** Регистрирует картинку; возвращает [resName, wPx, hPx] или null. */
    public function image(string $data, float $wPt = 0.0, float $hPt = 0.0): ?array
    {
        if ($this->imageDpi && $wPt > 0 && $hPt > 0 && function_exists('imagecreatefromstring') && ($sz = Image::size($data)) && $sz[0] > 0 && $sz[1] > 0) {
            $k = max($wPt / 72 * $this->imageDpi / $sz[0], $hPt / 72 * $this->imageDpi / $sz[1]);
            if ($k < 0.8) {
                $tw = max(1, (int)ceil($sz[0] * $k)); $ck = md5($data) . ":$tw";
                $data = $this->scaled[$ck] ??= (Image::downscale($data, $tw, max(1, (int)ceil($sz[1] * $k)), $this->jpegQuality, Image::orientation($data)) ?? $data);
            }
        }
        $key = md5($data);
        $ret = static function (array $im): array { $o = $im['orient'] ?? 1; $sw = in_array($o, [5, 6, 7, 8], true); return [$im['res'], $sw ? $im['h'] : $im['w'], $sw ? $im['w'] : $im['h'], $o]; };
        if (isset($this->images[$key])) return $ret($this->images[$key]);
        $img = Image::parse($data);
        if (!$img) return null;
        // сырые пиксели сжимаем сразу: десятки скриншотов иначе держат сотни мегабайт до записи файла
        if ($img['filter'] === 'Flate' && empty($img['raw']) && $this->compress) {
            $img['data'] = (string)gzcompress($img['data'], 6); $img['raw'] = true; $img['parms'] = '';
            if (!empty($img['alpha'])) { $img['alpha'] = (string)gzcompress($img['alpha'], 6); $img['alphaRaw'] = true; }
        }
        $img['res'] = 'Im' . (count($this->images) + 1);
        $this->images[$key] = $img;
        return $ret($img);
    }

    /**
     * Размытая тень: картинка сплошного цвета с маской прозрачности (SMask). Одинаковые тени — один объект.
     * @param array $color [r,g,b,a] 0..1
     */
    public function shadowImage(float $w, float $h, float $r, float $sigma, float $pad, array $color): string
    {
        $key = 'sh:' . md5(sprintf('%.2f|%.2f|%.2f|%.2f|%.2f|%s', $w, $h, $r, $sigma, $pad, implode(',', array_map(fn($v) => round((float)$v, 3), $color))));
        if (isset($this->images[$key])) return $this->images[$key]['res'];
        [$mw, $mh, $mask] = ShadowMask::build($w, $h, $r, $sigma, $pad, (float)($color[3] ?? 1));
        $rgb = chr((int)round($color[0] * 255)) . chr((int)round($color[1] * 255)) . chr((int)round($color[2] * 255));
        $this->images[$key] = ['w' => $mw, 'h' => $mh, 'cs' => 'DeviceRGB', 'data' => str_repeat($rgb, $mw * $mh), 'alpha' => $mask, 'filter' => '', 'res' => 'Im' . (count($this->images) + 1)];
        return $this->images[$key]['res'];
    }

    public function alphaRes(float $a): string
    {
        $k = (string)round($a, 3);
        return $this->alphas[$k] ??= 'GS' . (count($this->alphas) + 1);
    }

    public function link(int $page, array $rect, string $target, ?int $sid = null): void
    {
        $a = ['rect' => $rect, 'target' => $target];
        if ($this->tags && $sid !== null) {
            $l = $this->tags->child($sid, 'Link', $target);
            $this->attach($l);
            $this->kids[$l][] = ['o', $page, count($this->pages[$page]['annots'])];
            $a['lsid'] = $l;
        }
        $this->pages[$page]['annots'][] = $a;
    }

    // ---------- AcroForm ----------
    /** @var list<array{page:int,rect:array,o:array,res:string,hex:list<string>,lsid:?int}> */
    private array $fields = [];
    /** @var array<string, true> имена полей (уникальность) */
    private array $fieldNames = [];

    /** Регистрирует поле формы; глифы значения и базовых алфавитов (RU/KZ/EN) попадают в подмножество шрифта для редактирования. */
    public function field(int $page, array $rect, array $o, ?int $sid = null): void
    {
        $f = $o['font'];
        $res = $this->fontRes($f);
        $k = $f->path;
        if (!isset($this->formFonts[$k])) {
            $this->formFonts[$k] = true;
            foreach ([[0x20, 0x7E], [0x401, 0x401], [0x410, 0x44F], [0x451, 0x451], [0x2116, 0x2116]] as [$a, $b]) for ($cp = $a; $cp <= $b; $cp++) if ($f->hasChar($cp)) $this->fonts[$k]['used'][$f->glyph($cp)] = $cp;
            foreach ([0x4D8, 0x4D9, 0x492, 0x493, 0x49A, 0x49B, 0x4A2, 0x4A3, 0x4E8, 0x4E9, 0x4B0, 0x4B1, 0x4AE, 0x4AF, 0x4BA, 0x4BB, 0x406, 0x456] as $cp) if ($f->hasChar($cp)) $this->fonts[$k]['used'][$f->glyph($cp)] = $cp;
        }
        $lines = $o['kind'] === 'textarea' ? explode("\n", str_replace("\r", '', (string)$o['text'])) : [(string)$o['text']];
        $hex = array_map(fn($l) => $this->encodeText($f, $l), $lines);
        $name = (string)preg_replace('/[^\w\-\x{0400}-\x{04FF}]/u', '_', (string)$o['name']);
        if ($o['kind'] !== 'radio') { $base = $name; for ($i = 2; isset($this->fieldNames[$name]); $i++) $name = "$base.$i"; $this->fieldNames[$name] = true; }
        $o['name'] = $name;
        $lsid = null;
        if ($this->tags) {
            $lsid = $this->tags->child($sid ?? 0, 'Form', (string)$o['tu']);
            $this->attach($lsid);
            $this->kids[$lsid][] = ['o', $page, count($this->pages[$page]['annots'])];
        }
        $this->pages[$page]['annots'][] = ['rect' => $rect, 'target' => '', 'field' => count($this->fields), 'lsid' => $lsid];
        $this->fields[] = ['page' => $page, 'rect' => $rect, 'o' => array_diff_key($o, ['font' => 1]), 'res' => $res, 'hex' => $hex, 'lsid' => $lsid];
    }
    /** @var array<string, true> */
    private array $formFonts = [];

    /** Внешний вид поля (/AP /N): Form XObject. */
    private function apStream(float $w, float $h, string $content, string $fontRes = '', int $fontObj = 0): int
    {
        $n = $this->newObj();
        $res = $fontRes !== '' ? " /Resources << /Font << /$fontRes $fontObj 0 R >> >>" : ' /Resources << >>';
        $this->stream($n, sprintf('/Type /XObject /Subtype /Form /BBox [0 0 %.2F %.2F]%s', $w, $h, $res), $content);
        return $n;
    }

    private static function nm(string $s): string
    {
        $o = '';
        foreach (str_split($s) as $ch) $o .= (ctype_alnum($ch) || $ch === '_' || $ch === '-' || $ch === '.') ? $ch : sprintf('#%02X', ord($ch));
        return $o === '' ? 'on' : $o;
    }

    /**
     * Объект виджета поля. @param array<string,int> $fontObjs
     * @param array<string, array{obj:int,kids:list<int>,name:string,value:?string,tu:string,ff:int}> $radios
     */
    private function fieldWidget(int $an, array $fd, string $rect, int $pageObj, array $fontObjs, array &$radios, array &$topFields): string
    {
        $o = $fd['o']; $kind = $o['kind'];
        [$x0, $y0, $x1, $y1] = $fd['rect']; $w = $x1 - $x0; $h = $y1 - $y0;
        $c = $o['color']; $col = sprintf('%.3F %.3F %.3F', $c[0], $c[1], $c[2]);
        $size = (float)$o['size']; $fo = $fontObjs[$fd['res']] ?? 0;
        $da = $this->str(sprintf('/%s %s Tf %s rg', $fd['res'], rtrim(rtrim(sprintf('%.2F', $size), '0'), '.'), $col), $an);
        $ff = ($o['readonly'] ? 1 : 0) | ($o['required'] ? 2 : 0);
        $tu = ' /TU ' . $this->str((string)$o['tu'], $an);
        $common = "/Type /Annot /Subtype /Widget /F 4 /Rect $rect /P $pageObj 0 R";
        if ($kind === 'checkbox' || $kind === 'radio') {
            if ($kind === 'checkbox') {
                $on = 'Yes';
                $mark = sprintf("q %s RG %s w 1 J 1 j %.2F %.2F m %.2F %.2F l %.2F %.2F l S Q\n", $col, rtrim(sprintf('%.2F', max(0.8, $w * 0.12)), '0'), $w * 0.2, $h * 0.52, $w * 0.42, $h * 0.28, $w * 0.8, $h * 0.76);
            } else {
                $on = self::nm((string)$o['export']);
                $r = min($w, $h) * 0.25; $cx = $w / 2; $cy = $h / 2; $k = $r * 0.5523;
                $mark = sprintf("q %s rg %.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c f Q\n", $col,
                    $cx + $r, $cy, $cx + $r, $cy + $k, $cx + $k, $cy + $r, $cx, $cy + $r, $cx - $k, $cy + $r, $cx - $r, $cy + $k, $cx - $r, $cy, $cx - $r, $cy - $k, $cx - $k, $cy - $r, $cx, $cy - $r, $cx + $k, $cy - $r, $cx + $r, $cy - $k, $cx + $r, $cy);
            }
            $apOn = $this->apStream($w, $h, $mark); $apOff = $this->apStream($w, $h, '');
            $st = $o['checked'] ? $on : 'Off';
            $ap = " /AP << /N << /$on $apOn 0 R /Off $apOff 0 R >> >> /AS /$st /MK << >>";
            if ($kind === 'checkbox') { $topFields[] = $an; return "<< $common /FT /Btn /T " . $this->str((string)$o['name'], $an) . "$tu /V /$st /Ff $ff /DA $da$ap" . ($fd['lsid'] !== null ? '' : '') . ' >>'; }
            $g = (string)$o['name'];
            if (!isset($radios[$g])) { $radios[$g] = ['obj' => $this->newObj(), 'kids' => [], 'name' => $g, 'value' => null, 'tu' => (string)$o['tu'], 'ff' => $ff]; $topFields[] = $radios[$g]['obj']; }
            $radios[$g]['kids'][] = $an;
            if ($o['checked']) $radios[$g]['value'] = $on;
            return "<< $common /Parent {$radios[$g]['obj']} 0 R$ap >>";
        }
        // текст / список: значение рисуется в /AP
        $pad = 2.0; $body = "/Tx BMC\nq BT /{$fd['res']} " . rtrim(rtrim(sprintf('%.2F', $size), '0'), '.') . " Tf $col rg\n";
        if ($kind === 'textarea') {
            $y = $h - $pad - $size * 0.9;
            foreach ($fd['hex'] as $i => $hx) { $body .= sprintf("1 0 0 1 %.2F %.2F Tm %s\n", $pad, $y, self::show($hx)); $y -= $size * 1.2; }
        } else $body .= sprintf("1 0 0 1 %.2F %.2F Tm %s\n", $pad, max(1.0, ($h - $size * 0.72) / 2), self::show($fd['hex'][0]));
        $body .= "ET Q\nEMC\n";
        $ap = ' /AP << /N ' . $this->apStream($w, $h, "q 0 0 $w $h re W n\n" . $body . "Q\n", $fd['res'], $fo) . ' 0 R >> /MK << >>';
        $topFields[] = $an;
        $t = ' /T ' . $this->str((string)$o['name'], $an);
        if ($kind === 'select') {
            $opt = implode(' ', array_map(fn($p) => '[' . $this->str((string)$p[0], $an) . ' ' . $this->str((string)$p[1], $an) . ']', $o['opts']));
            return "<< $common /FT /Ch$t$tu /Ff " . ($ff | 131072) . " /Opt [$opt] /V " . $this->str((string)$o['value'], $an) . " /DA $da$ap >>";
        }
        $ff |= $kind === 'textarea' ? 4096 : 0; $ff |= $kind === 'password' ? 8192 : 0;
        return "<< $common /FT /Tx$t$tu /Ff $ff" . ($o['maxlen'] > 0 ? ' /MaxLen ' . $o['maxlen'] : '') . ' /V ' . $this->str((string)$o['value'], $an) . " /DA $da$ap >>";
    }

    // ---------- Tagged PDF ----------
    public ?\BaurPDF\Layout\Tags $tags = null;
    public bool $pdfua = false;
    /** @var array<int, list<int>> страница → sid по MCID */
    private array $mcids = [];
    /** @var array<int, list<array{0:string,1:int,2?:int}>> дети узлов в порядке чтения: ['e',sid] | ['m',page,mcid] | ['o',page,annot] */
    private array $kids = [];
    /** @var array<int, true> */
    private array $attached = [];

    public function mcid(int $page, int $sid): int
    {
        $this->attach($sid);
        $this->mcids[$page][] = $sid;
        $n = count($this->mcids[$page]) - 1;
        $this->kids[$sid][] = ['m', $page, $n];
        return $n;
    }

    private function attach(int $sid): void
    {
        if (isset($this->attached[$sid]) || !$this->tags) return;
        $this->attached[$sid] = true;
        $p = $this->tags->elems[$sid]['parent'] ?? null;
        if ($p !== null) { $this->attach($p); $this->kids[$p][] = ['e', $sid]; }
    }

    /** Объекты дерева структуры; возвращает номер StructTreeRoot. @param list<int> $pageObjs @param array<int, array<int,int>> $annotObjs */
    private function structTree(array $pageObjs, array $annotObjs, array &$annotKeys): int
    {
        $tags = $this->tags; assert($tags !== null);
        $root = $this->newObj();
        $this->attach(0);
        $obj = [];
        foreach (array_keys($this->attached) as $sid) $obj[$sid] = $this->newObj();
        foreach ($obj as $sid => $n) {
            $e = $tags->elems[$sid];
            $k = [];
            foreach ($this->kids[$sid] ?? [] as $kd) {
                if ($kd[0] === 'e') $k[] = $obj[$kd[1]] . ' 0 R';
                elseif ($kd[0] === 'm') $k[] = '<< /Type /MCR /Pg ' . $pageObjs[$kd[1]] . ' 0 R /MCID ' . ($kd[2] ?? 0) . ' >>';
                elseif (isset($annotObjs[$kd[1]][$kd[2] ?? -1])) $k[] = '<< /Type /OBJR /Pg ' . $pageObjs[$kd[1]] . ' 0 R /Obj ' . $annotObjs[$kd[1]][$kd[2] ?? -1] . ' 0 R >>';
            }
            $par = $e['parent'] === null ? $root : $obj[$e['parent']];
            $d = '<< /Type /StructElem /S /' . $e['type'] . " /P $par 0 R /K [" . implode(' ', $k) . ']';
            if ($e['alt'] !== null && in_array($e['type'], ['Figure', 'Link', 'Formula'], true)) $d .= ' /Alt ' . $this->str($e['alt'], $n);
            if ($e['lang'] !== null) $d .= ' /Lang ' . $this->str($e['lang'], $n);
            if ($e['attrs']) { $d .= ' /A << /O /Table'; foreach ($e['attrs'] as $ak => $av) $d .= " /$ak " . (ctype_digit($av) ? $av : "/$av"); $d .= ' >>'; }
            $this->setObj($n, $d . ' >>');
        }
        // ParentTree: страница → массив узлов по MCID; аннотации — отдельные ключи
        $nums = '';
        foreach ($pageObjs as $i => $_) { $nums .= "$i [" . implode(' ', array_map(fn($sid) => $obj[$sid] . ' 0 R', $this->mcids[$i] ?? [])) . '] '; }
        foreach ($annotKeys as $key => $lsid) $nums .= "$key " . $obj[$lsid] . ' 0 R ';
        $next = count($pageObjs) + count($annotKeys);
        $this->setObj($root, "<< /Type /StructTreeRoot /K [" . $obj[0] . " 0 R] /ParentTree << /Nums [$nums] >> /ParentTreeNextKey $next >>");
        return $root;
    }

    private ?array $encParams = null;
    /** Фиксированное время (unix) для детерминированного вывода; null = сейчас (или SOURCE_DATE_EPOCH). */
    public ?int $fixedTime = null;

    public function encrypt(string $user, ?string $owner, array $perms = ['print'], string $method = 'aes256'): void
    {
        $this->encParams = [$user, $owner, $perms];
        $this->encMethod = strtolower($method) === 'rc4' ? 'rc4' : 'aes256';
    }

    private string $encMethod = 'aes256';
    private int $ivCounter = 0;
    /** Секретное зерно детерминированного режима: зависит от паролей, а не только от публичного /ID. */
    private string $seed = '';

    /** Биты прав доступа /P. */
    private static function perms(array $perms): int
    {
        $P = -3904; // 0xFFFFF0C0: базовые биты
        if (in_array('print', $perms, true)) $P |= 4 | 2048;
        if (in_array('modify', $perms, true)) $P |= 8 | 1024;
        if (in_array('copy', $perms, true)) $P |= 16 | 512;
        if (in_array('annotate', $perms, true)) $P |= 32 | 256;
        return $P;
    }

    /** Случайные байты; при детерминированном выводе — производные от fileId (повторяемость сборок). */
    private function rnd(int $n, string $label): string
    {
        if (($this->fixedTime ?? null) === null) return random_bytes(max(1, $n));
        return substr(hash('sha256', $this->seed . '|' . $label . '|' . ($this->ivCounter++), true), 0, $n);
    }

    /** ISO 32000-2, алгоритм 2.B (хэш пароля для R6). */
    private static function hash2B(string $pwd, string $salt, string $udata): string
    {
        $K = hash('sha256', $pwd . $salt . $udata, true);
        for ($i = 0; ; $i++) {
            $K1 = str_repeat($pwd . $K . $udata, 64);
            $E = AES::cbc(substr($K, 0, 16), substr($K, 16, 16), $K1);
            $sum = 0; for ($j = 0; $j < 16; $j++) $sum += ord($E[$j]);
            $K = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $E, true);
            if ($i >= 63 && ord($E[strlen($E) - 1]) <= $i - 31) break;
        }
        return substr($K, 0, 32);
    }

    /** AES-256 (V5/R6, AESV3). */
    private function computeAes256(string $user, ?string $owner, array $perms): void
    {
        $prep = fn(string $p) => substr(class_exists(\Normalizer::class) ? (string)\Normalizer::normalize($p, \Normalizer::FORM_KC) : $p, 0, 127);
        $this->seed = hash('sha256', $this->fileId . "\0" . $user . "\0" . (string)$owner, true);
        $user = $prep($user); $owner = $prep($owner ?: ($user !== '' ? $user : bin2hex($this->rnd(16, 'owner'))));
        $key = $this->rnd(32, 'key');
        $uvs = $this->rnd(8, 'uvs'); $uks = $this->rnd(8, 'uks');
        $U = self::hash2B($user, $uvs, '') . $uvs . $uks;
        $UE = AES::cbc(self::hash2B($user, $uks, ''), str_repeat("\0", 16), $key);
        $ovs = $this->rnd(8, 'ovs'); $oks = $this->rnd(8, 'oks');
        $O = self::hash2B($owner, $ovs, $U) . $ovs . $oks;
        $OE = AES::cbc(self::hash2B($owner, $oks, $U), str_repeat("\0", 16), $key);
        $P = self::perms($perms);
        $Perms = AES::ecbBlock($key, pack('V', $P) . "\xFF\xFF\xFF\xFFTadb" . $this->rnd(4, 'perms'));
        $this->enc = ['key' => $key, 'O' => $O, 'U' => $U, 'OE' => $OE, 'UE' => $UE, 'P' => $P, 'Perms' => $Perms, 'aes' => true];
    }

    /** Зашифровать строку/поток объекта $n текущим методом. */
    private function crypt(int $n, string $data): string
    {
        if (!empty($this->enc['aes'])) return AES::encryptPdf($this->enc['key'], $data, $this->rnd(16, 'iv'));
        return RC4::crypt($this->objKey($n), $data);
    }

    private function computeEncryption(string $user, ?string $owner, array $perms): void
    {
        if ($this->encMethod === 'aes256') { $this->computeAes256($user, $owner, $perms); return; }
        $owner = $owner ?: ($user !== '' ? $user : bin2hex(random_bytes(8)));
        $P = self::perms($perms);
        $pad = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
        $padd = fn(string $p) => substr($p . $pad, 0, 32);
        $ok = md5($padd($owner), true);
        for ($i = 0; $i < 50; $i++) $ok = md5($ok, true);
        $O = RC4::crypt($ok, $padd($user));
        for ($i = 1; $i <= 19; $i++) $O = RC4::crypt(self::xorKey($ok, $i), $O);
        $id = hex2bin($this->fileId);
        $key = md5($padd($user) . $O . pack('V', $P) . $id, true);
        for ($i = 0; $i < 50; $i++) $key = md5($key, true);
        $U = RC4::crypt($key, md5($pad . $id, true));
        for ($i = 1; $i <= 19; $i++) $U = RC4::crypt(self::xorKey($key, $i), $U);
        $U .= str_repeat("\0", 16);
        $this->enc = ['key' => $key, 'O' => $O, 'U' => $U, 'P' => $P];
    }

    private static function xorKey(string $k, int $i): string { $o = ''; for ($j = 0; $j < strlen($k); $j++) $o .= chr(ord($k[$j]) ^ $i); return $o; }

    private function objKey(int $n): string { return substr(md5((string)($this->enc['key'] ?? '') . substr(pack('V', $n), 0, 3) . "\0\0", true), 0, 16); }

    /** Строковый литерал PDF (UTF-16BE для не-ASCII), зашифрованный при необходимости. */
    private function str(string $s, int $obj): string
    {
        $bin = preg_match('/^[\x20-\x7E]*$/', $s) ? $s : "\xFE\xFF" . mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
        if ($this->enc) return '<' . bin2hex($this->crypt($obj, $bin)) . '>';
        if ($bin === $s) return '(' . strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        return '<' . bin2hex($bin) . '>';
    }

    private function newObj(): int { $this->objs[] = null; return count($this->objs); }
    private function setObj(int $n, string $body): void { $this->objs[$n - 1] = $body; }

    private function stream(int $n, string $dict, string $data, bool $compress = true): void
    {
        if ($compress && $this->compress) { $data = (string)gzcompress($data, 6); $dict .= ' /Filter /FlateDecode'; }
        if ($this->enc) $data = $this->crypt($n, $data);
        $this->setObj($n, '<<' . $dict . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream");
        $this->streamObjs[$n] = true;
    }
    private array $streamObjs = [];
    /** Объектные потоки + xref-поток (PDF 1.5+): меньше размер. Отключается при шифровании. */
    public bool $objectStreams = true;
    /** PDF/A-2b: XMP + OutputIntent sRGB (шифрование при этом не применяется). */
    public bool $pdfa = false;

    /** Описание схемы pdfuaid для PDF/A (нестандартные пространства имён XMP должны быть объявлены). */
    private const UA_EXT = '<rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
        . '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource"><pdfaSchema:schema>PDF/UA Universal Accessibility Schema</pdfaSchema:schema>'
        . '<pdfaSchema:namespaceURI>http://www.aiim.org/pdfua/ns/id/</pdfaSchema:namespaceURI><pdfaSchema:prefix>pdfuaid</pdfaSchema:prefix>'
        . '<pdfaSchema:property><rdf:Seq><rdf:li rdf:parseType="Resource"><pdfaProperty:name>part</pdfaProperty:name><pdfaProperty:valueType>Integer</pdfaProperty:valueType>'
        . '<pdfaProperty:category>internal</pdfaProperty:category><pdfaProperty:description>Indicates, which part of ISO 14289 standard is followed</pdfaProperty:description></rdf:li></rdf:Seq></pdfaSchema:property>'
        . '</rdf:li></rdf:Bag></pdfaExtension:schemas></rdf:Description>';

    /** XMP-пакет, согласованный со словарём /Info (требование PDF/A). */
    private function xmp(int $time): string
    {
        $e = fn(string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $date = gmdate('Y-m-d\TH:i:s\Z', $time);
        $i = $this->info;
        $dc = '';
        if (($i['title'] ?? '') !== '') $dc .= '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $e((string)$i['title']) . '</rdf:li></rdf:Alt></dc:title>';
        if (($i['author'] ?? '') !== '') $dc .= '<dc:creator><rdf:Seq><rdf:li>' . $e((string)$i['author']) . '</rdf:li></rdf:Seq></dc:creator>';
        if (($i['subject'] ?? '') !== '') $dc .= '<dc:description><rdf:Alt><rdf:li xml:lang="x-default">' . $e((string)$i['subject']) . '</rdf:li></rdf:Alt></dc:description>';
        $pdf = '<pdf:Producer>' . $e('BaurPDF ' . BaurPDF::VERSION . ' (pure PHP)') . '</pdf:Producer>';
        if (($i['keywords'] ?? '') !== '') $pdf .= '<pdf:Keywords>' . $e((string)$i['keywords']) . '</pdf:Keywords>';
        $tool = ($i['creator'] ?? '') !== '' ? '<xmp:CreatorTool>' . $e((string)$i['creator']) . '</xmp:CreatorTool>' : '';
        return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n"
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . ($this->pdfa ? '<rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/"><pdfaid:part>2</pdfaid:part><pdfaid:conformance>' . ($this->pdfua && $this->tags ? 'A' : 'B') . '</pdfaid:conformance></rdf:Description>' : '')
            . ($this->pdfua ? '<rdf:Description rdf:about="" xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/"><pdfuaid:part>1</pdfuaid:part></rdf:Description>' . ($this->pdfa ? self::UA_EXT : '') : '')
            . '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:format>application/pdf</dc:format>' . $dc . '</rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">' . $pdf . '</rdf:Description>'
            . '<rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/"><xmp:CreateDate>' . $date . '</xmp:CreateDate><xmp:ModifyDate>' . $date . '</xmp:ModifyDate><xmp:MetadataDate>' . $date . '</xmp:MetadataDate>' . $tool . '</rdf:Description>'
            . "</rdf:RDF></x:xmpmeta>\n" . str_repeat(' ', 512) . "\n<?xpacket end=\"w\"?>";
    }

    public function output(): string
    {
        $this->objs = [];
        $time = $this->fixedTime ?? (getenv('SOURCE_DATE_EPOCH') !== false ? (int)getenv('SOURCE_DATE_EPOCH') : null);
        if ($time !== null) {
            $h = hash_init('md5');
            foreach ($this->pages as $p) hash_update($h, $p['content']);
            hash_update($h, json_encode($this->info) . $time);
            $this->fileId = hash_final($h);
        }
        if ($this->encParams) $this->computeEncryption(...$this->encParams);
        $catalog = $this->newObj(); $pagesObj = $this->newObj();
        $pageObjs = []; foreach ($this->pages as $i => $_) $pageObjs[$i] = $this->newObj();
        // шрифты
        $fontRefs = ''; $fontObjs = [];
        foreach ($this->fonts as $fd) {
            /** @var TrueTypeFont $f */
            $f = $fd['font']; $used = $fd['used']; $used[0] = 0; ksort($used);
            $type0 = $this->newObj(); $cid = $this->newObj(); $desc = $this->newObj(); $file = $this->newObj(); $tu = $this->newObj();
            $tag = strtoupper(substr(md5($f->path . implode(',', array_keys($used))), 0, 6));
            $tag = strtr($tag, '0123456789', 'GHIJKLMNOP');
            $base = $tag . '+' . $f->name;
            $sc = 1000 / $f->unitsPerEm;
            $w = '';
            foreach ($used as $g => $_) $w .= $g . ' [' . round($f->glyphWidth($g), 2) . '] ';
            $this->setObj($type0, "<< /Type /Font /Subtype /Type0 /BaseFont /$base /Encoding /Identity-H /DescendantFonts [$cid 0 R] /ToUnicode $tu 0 R >>");
            $this->setObj($cid, "<< /Type /Font /Subtype /" . ($f->isCff ? 'CIDFontType0' : 'CIDFontType2') . " /BaseFont /$base /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $desc 0 R /DW 0 /W [ $w]" . ($f->isCff ? '' : ' /CIDToGIDMap /Identity') . " >>");
            $bb = array_map(fn($v) => (int)round($v * $sc), $f->bbox);
            $this->setObj($desc, sprintf('<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%d %d %d %d] /ItalicAngle %s /Ascent %d /Descent %d /CapHeight %d /StemV 80 /' . ($f->isCff ? 'FontFile3' : 'FontFile2') . ' %d 0 R >>',
                $base, $f->flags, ...[...$bb, round($f->italicAngle, 2), (int)round($f->ascent * $sc), (int)round($f->descent * $sc), (int)round($f->capHeight * $sc), $file]));
            $sub = $f->subset($used);
            $this->stream($file, $f->isCff ? '/Subtype /CIDFontType0C' : '/Length1 ' . strlen($sub), $sub);
            $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
            $chunks = array_chunk($used, 100, true);
            foreach ($chunks as $ch) {
                $cmap .= count($ch) . " beginbfchar\n";
                foreach ($ch as $g => $cp) {
                    $u = \BaurPDF\Text\Bidi::baseOf((int)$cp); // арабские формы представления → базовые буквы (поиск/копирование)
                    $cmap .= sprintf("<%04X> <%s>\n", $g, strtoupper(bin2hex(mb_convert_encoding($u !== null ? implode('', array_map('mb_chr', $u)) : mb_chr($cp ?: 32), 'UTF-16BE', 'UTF-8'))));
                }
                $cmap .= "endbfchar\n";
            }
            $cmap .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
            $this->stream($tu, '', $cmap);
            $fontRefs .= '/' . $fd['res'] . " $type0 0 R ";
            $fontObjs[$fd['res']] = $type0;
        }
        // изображения
        $imgRefs = '';
        foreach ($this->images as $im) {
            $n = $this->newObj(); $smask = '';
            if (!empty($im['alpha'])) {
                $sn = $this->newObj();
                $ad = "/Type /XObject /Subtype /Image /Width {$im['w']} /Height {$im['h']} /ColorSpace /DeviceGray /BitsPerComponent 8";
                if (!empty($im['alphaRaw'])) $this->stream($sn, $ad . ' /Filter /FlateDecode', $im['alpha'], false);
                else $this->stream($sn, $ad, $im['alpha']);
                $smask = " /SMask $sn 0 R";
            }
            $cs = str_starts_with($im['cs'], '[') ? $im['cs'] : '/' . $im['cs'];
            $dict = "/Type /XObject /Subtype /Image /Width {$im['w']} /Height {$im['h']} /ColorSpace $cs /BitsPerComponent " . ($im['bpc'] ?? 8) . $smask;
            if ($im['filter'] === 'DCTDecode') $this->stream($n, $dict . ' /Filter /DCTDecode', $im['data'], false);
            elseif (!empty($im['raw'])) $this->stream($n, $dict . ' /Filter /FlateDecode' . (!empty($im['parms']) ? ' /DecodeParms ' . $im['parms'] : ''), $im['data'], false);
            else $this->stream($n, $dict, $im['data']);
            $imgRefs .= '/' . $im['res'] . " $n 0 R ";
        }
        $gsRefs = '';
        foreach ($this->alphas as $a => $res) { $n = $this->newObj(); $this->setObj($n, "<< /Type /ExtGState /ca $a /CA $a >>"); $gsRefs .= "/$res $n 0 R "; }
        $resources = $this->newObj();
        $this->setObj($resources, "<< /ProcSet [/PDF /Text /ImageC /ImageB] /Font << $fontRefs>> /XObject << $imgRefs>> /ExtGState << $gsRefs>> >>");
        // страницы
        $annotKeys = []; $annotObjs = []; $radios = []; $topFields = [];
        foreach ($this->pages as $i => $p) {
            $c = $this->newObj();
            $this->stream($c, '', $p['content']);
            $annots = [];
            foreach ($p['annots'] as $ai => $a) {
                $an = $this->newObj();
                $r = sprintf('[%.2F %.2F %.2F %.2F]', ...$a['rect']);
                if (isset($a['field'])) {
                    $fd = $this->fields[$a['field']];
                    $sp = '';
                    if ($a['lsid'] !== null) { $key = count($this->pages) + count($annotKeys); $annotKeys[$key] = $a['lsid']; $annotObjs[$i][$ai] = $an; $sp = " /StructParent $key"; }
                    $wd = $this->fieldWidget($an, $fd, $r . $sp, $pageObjs[$i], $fontObjs, $radios, $topFields);
                    $this->setObj($an, $wd);
                    $annots[] = "$an 0 R";
                    continue;
                }
                if (isset($a['lsid'])) { $key = count($this->pages) + count($annotKeys); $annotKeys[$key] = $a['lsid']; $annotObjs[$i][$ai] = $an; $r .= " /StructParent $key /Contents " . $this->str($a['target'], $an); }
                if (str_starts_with($a['target'], '#')) {
                    $d = $this->dests[substr($a['target'], 1)] ?? null;
                    if (!$d) { $this->setObj($an, "<< /Type /Annot /Subtype /Link /F 4 /Rect $r /Border [0 0 0] >>"); }
                    else $this->setObj($an, "<< /Type /Annot /Subtype /Link /F 4 /Rect $r /Border [0 0 0] /Dest [{$pageObjs[$d['page']]} 0 R /XYZ 0 " . sprintf('%.2F', $d['y']) . ' null] >>');
                } else $this->setObj($an, "<< /Type /Annot /Subtype /Link /F 4 /Rect $r /Border [0 0 0] /A << /S /URI /URI " . $this->str($a['target'], $an) . ' >> >>');
                $annots[] = "$an 0 R";
            }
            $this->setObj($pageObjs[$i], sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources %d 0 R /Contents %d 0 R%s%s >>',
                $pagesObj, $p['w'], $p['h'], $resources, $c, $annots ? ' /Annots [' . implode(' ', $annots) . ']' : '', $this->tags ? " /StructParents $i /Tabs /S" : ''));
        }
        foreach ($radios as $g) $this->setObj($g['obj'], '<< /FT /Btn /Ff ' . ($g['ff'] | 49152) . ' /T ' . $this->str($g['name'], $g['obj']) . ' /TU ' . $this->str($g['tu'], $g['obj']) . ' /V /' . ($g['value'] ?? 'Off') . ' /Kids [' . implode(' ', array_map(fn($k) => "$k 0 R", $g['kids'])) . '] >>');
        $acro = '';
        if ($topFields) {
            $dr = ''; foreach ($fontObjs as $res => $fo) $dr .= "/$res $fo 0 R ";
            $acro = ' /AcroForm << /Fields [' . implode(' ', array_map(fn($k) => "$k 0 R", array_values(array_unique($topFields)))) . "] /DR << /Font << $dr>> >> /DA (/" . array_key_first($fontObjs) . ' 0 Tf 0 g) >>';
        }
        $kids = implode(' ', array_map(fn($n) => "$n 0 R", $pageObjs));
        $this->setObj($pagesObj, "<< /Type /Pages /Kids [$kids] /Count " . count($pageObjs) . ' >>');
        // outline
        $outlineRef = '';
        if ($this->outline) {
            $root = $this->newObj();
            $items = [];
            foreach ($this->outline as $o) $items[] = $o + ['obj' => $this->newObj(), 'parent' => null, 'children' => []];
            $stack = [];
            foreach ($items as $idx => $it) {
                while ($stack && $items[end($stack)]['level'] >= $it['level']) array_pop($stack);
                $parent = $stack ? end($stack) : -1;
                $items[$idx]['parent'] = $parent;
                if ($parent >= 0) $items[$parent]['children'][] = $idx; else $topLevel[] = $idx;
                $stack[] = $idx;
            }
            $topLevel ??= [];
            $siblings = function (array $list) use (&$items) { foreach ($list as $k => $idx) { $items[$idx]['prev'] = $list[$k - 1] ?? null; $items[$idx]['next'] = $list[$k + 1] ?? null; } };
            $siblings($topLevel);
            foreach ($items as $it) if ($it['children']) $siblings($it['children']);
            $countAll = function (int $idx) use (&$items, &$countAll): int { $c = 0; foreach ($items[$idx]['children'] as $ch) $c += 1 + $countAll($ch); return $c; };
            foreach ($items as $idx => $it) {
                $parentObj = $it['parent'] >= 0 ? $items[$it['parent']]['obj'] : $root;
                $s = '<< /Title ' . $this->str($it['title'], $it['obj']) . " /Parent $parentObj 0 R";
                if ($it['prev'] !== null) $s .= ' /Prev ' . $items[$it['prev']]['obj'] . ' 0 R';
                if ($it['next'] !== null) $s .= ' /Next ' . $items[$it['next']]['obj'] . ' 0 R';
                if ($it['children']) $s .= ' /First ' . $items[$it['children'][0]]['obj'] . ' 0 R /Last ' . $items[end($it['children'])]['obj'] . ' 0 R /Count ' . ($it['level'] < 2 ? $countAll((int)$idx) : -$countAll((int)$idx));
                $s .= sprintf(' /Dest [%d 0 R /XYZ 0 %.2F null] >>', $pageObjs[$it['page']] ?? $pageObjs[0], $it['y']);
                $this->setObj($it['obj'], $s);
            }
            $this->setObj($root, '<< /Type /Outlines /First ' . $items[$topLevel[0]]['obj'] . ' 0 R /Last ' . $items[end($topLevel)]['obj'] . ' 0 R /Count ' . count($items) . ' >>');
            $outlineRef = " /Outlines $root 0 R /PageMode /UseOutlines";
        }
        $lang = isset($this->info['lang']) ? ' /Lang ' . $this->str($this->info['lang'], $catalog) : '';
        $d = 'D:' . gmdate('YmdHis', $time ?? time()) . 'Z';
        if ($this->pdfa || $this->pdfua) {
            $xmp = $this->newObj();
            $this->stream($xmp, ' /Type /Metadata /Subtype /XML', $this->xmp($time ?? time()), false);
            $lang .= " /Metadata $xmp 0 R";
        }
        if ($this->pdfa) {
            $icc = $this->newObj();
            $this->stream($icc, ' /N 3', Icc::srgb());
            $lang .= " /OutputIntents [<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB IEC61966-2.1) /Info (sRGB IEC61966-2.1) /RegistryName (http://www.color.org) /DestOutputProfile $icc 0 R >>]";
        }
        if ($this->tags) $lang .= ' /MarkInfo << /Marked true >> /StructTreeRoot ' . $this->structTree($pageObjs, $annotObjs, $annotKeys) . ' 0 R';
        elseif ($this->pdfa) $lang .= ' /MarkInfo << /Marked false >>';
        $this->setObj($catalog, "<< /Type /Catalog /Pages $pagesObj 0 R$outlineRef$lang$acro /ViewerPreferences << /DisplayDocTitle true >>" . (!empty($this->enc['aes']) ? ' /Extensions << /ADBE << /BaseVersion /1.7 /ExtensionLevel 8 >> >>' : '') . ' >>');
        $info = $this->newObj();
        $is = '<< /Producer ' . $this->str('BaurPDF ' . BaurPDF::VERSION . ' (pure PHP)', $info) . ' /CreationDate ' . $this->str($d, $info) . ' /ModDate ' . $this->str($d, $info);
        foreach (['title' => 'Title', 'author' => 'Author', 'subject' => 'Subject', 'keywords' => 'Keywords', 'creator' => 'Creator'] as $k => $pk) {
            if (isset($this->info[$k]) && $this->info[$k] !== '') $is .= " /$pk " . $this->str((string)$this->info[$k], $info);
        }
        $this->setObj($info, $is . ' >>');
        $encRef = '';
        if ($this->enc) {
            $e = $this->newObj();
            $this->objs[$e - 1] = !empty($this->enc['aes'])
                ? sprintf('<< /Filter /Standard /V 5 /R 6 /Length 256 /CF << /StdCF << /Type /CryptFilter /CFM /AESV3 /AuthEvent /DocOpen /Length 32 >> >> /StmF /StdCF /StrF /StdCF /O <%s> /U <%s> /OE <%s> /UE <%s> /P %d /Perms <%s> /EncryptMetadata true >>',
                    bin2hex($this->enc['O']), bin2hex($this->enc['U']), bin2hex($this->enc['OE']), bin2hex($this->enc['UE']), $this->enc['P'], bin2hex($this->enc['Perms']))
                : sprintf('<< /Filter /Standard /V 2 /R 3 /Length 128 /O <%s> /U <%s> /P %d >>', bin2hex($this->enc['O']), bin2hex($this->enc['U']), $this->enc['P']);
            $encRef = " /Encrypt $e 0 R";
        }
        if ($this->objectStreams && $this->compress && !$this->enc) return $this->outputObjStm($catalog, $info);
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n"; $offs = [];
        foreach ($this->objs as $i => $body) { $offs[$i] = strlen($out); $out .= ($i + 1) . " 0 obj\n" . ($body ?? 'null') . "\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($this->objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= 'trailer << /Size ' . (count($this->objs) + 1) . " /Root $catalog 0 R /Info $info 0 R$encRef /ID [<{$this->fileId}> <{$this->fileId}>] >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    private function outputObjStm(int $catalog, int $info): string
    {
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $entries = []; // obj => [type, a, b]
        $packed = []; $total = count($this->objs);
        foreach ($this->objs as $i => $body) {
            $n = $i + 1;
            if (isset($this->streamObjs[$n])) { $entries[$n] = [1, strlen($out), 0]; $out .= "$n 0 obj\n$body\nendobj\n"; }
            else $packed[$n] = $body ?? 'null';
        }
        $stmNums = [];
        foreach (array_chunk($packed, 200, true) as $chunk) {
            $sn = ++$total; $stmNums[] = $sn;
            $hdr = ''; $data = ''; $k = 0;
            foreach ($chunk as $n => $body) { $hdr .= $n . ' ' . strlen($data) . ' '; $data .= $body . "\n"; $entries[$n] = [2, $sn, $k++]; }
            $hdr .= "\n";
            $z = (string)gzcompress($hdr . $data, 6);
            $entries[$sn] = [1, strlen($out), 0];
            $out .= "$sn 0 obj\n<< /Type /ObjStm /N $k /First " . strlen($hdr) . ' /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n$z\nendstream\nendobj\n";
        }
        $xn = ++$total;
        $entries[$xn] = [1, strlen($out), 0];
        $rows = "\x00\x00\x00\x00\x00\xFF\xFF";
        for ($n = 1; $n <= $total; $n++) { [$t, $a, $b] = $entries[$n] ?? [0, 0, 0]; $rows .= chr($t) . pack('N', $a) . pack('n', $b); }
        $z = (string)gzcompress($rows, 6);
        $out .= "$xn 0 obj\n<< /Type /XRef /Size " . ($total + 1) . " /W [1 4 2] /Root $catalog 0 R /Info $info 0 R /ID [<{$this->fileId}> <{$this->fileId}>] /Filter /FlateDecode /Length " . strlen($z) . " >>\nstream\n$z\nendstream\nendobj\n";
        return $out . "startxref\n{$entries[$xn][1]}\n%%EOF\n";
    }
}
