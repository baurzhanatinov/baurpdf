<?php
declare(strict_types=1);
namespace BaurPDF;

use BaurPDF\Css\Color;
use BaurPDF\Css\CssParser;
use BaurPDF\Css\Length;
use BaurPDF\Css\StyleResolver;
use BaurPDF\Font\FontManager;
use BaurPDF\Layout\Engine;
use BaurPDF\Layout\Flow;
use BaurPDF\Layout\Paginator;
use BaurPDF\Layout\ResourceLoader;
use BaurPDF\Pdf\Painter;
use BaurPDF\Layout\Ops;
use BaurPDF\Pdf\Writer;

/** Полный конвейер: HTML → DOM → CSS-каскад → вёрстка → пагинация → PDF. */
final class Renderer
{
    public array $warnings = [];
    public int $pages = 0;
    private ResourceLoader $loader;
    private FontManager $fonts;
    private StyleResolver $resolver;
    private Options $o;
    private string $title = '';
    private float $pageW = 595.28;
    private float $pageH = 841.89;
    /** @var array{0:float,1:float,2:float,3:float} */
    private array $margins = [0.0, 0.0, 0.0, 0.0];

    public static function parseHtml(string $html): \DOMDocument
    {
        if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1251');
        $html = preg_replace('/^\xEF\xBB\xBF/', '', $html) ?? '';
        $html = preg_replace('#<script\b.*?(</script\s*>|$)#is', '', $html) ?? ''; // JS не исполняется и не нужен
        if (!preg_match('/<(html|body)\b/i', $html)) $html = '<html><body>' . $html . '</body></html>';
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        foreach (iterator_to_array($doc->childNodes) as $c) if ($c instanceof \DOMProcessingInstruction) $doc->removeChild($c);
        if (!$doc->getElementsByTagName('body')->item(0)) {
            $h = $doc->getElementsByTagName('html')->item(0) ?? $doc->appendChild($doc->createElement('html'));
            $h->appendChild($doc->createElement('body'));
        }
        return $doc;
    }

    /** @param array{rows:iterable<mixed>,columns:list<string>,row:?callable,chunk:int}|null $stream */
    public function render(string $html, Options $o, ?array $stream = null): string
    {
        $this->o = $o;
        $t0 = microtime(true);
        $doc = self::parseHtml($html);
        $base = $o->baseDir ?? (string)getcwd();
        $this->loader = new ResourceLoader($base, $o->allowedPaths, $o->allowRemote);
        $this->fonts = new FontManager();
        foreach ($o->fallbackFonts as [$fr, $fbold]) $this->fonts->addFallback($fr, $fbold);
        foreach ($o->fontDirs as $fd) $this->fonts->addFontDir($fd);
        foreach ($o->fonts as $fam => $files) foreach ((array)$files as $variant => $file) {
            $v = strtolower((string)$variant);
            $this->fonts->register($fam, $file, str_contains($v, 'bold') ? 'bold' : 'normal', str_contains($v, 'italic') ? 'italic' : 'normal');
        }

        // CSS: UA → тема (отдельный уровень каскада: любое авторское правило её перебивает) → <style>/<link> → опции
        $css = '';
        $themeSheet = $o->theme ? CssParser::parse((string)@file_get_contents(dirname(__DIR__) . '/resources/css/baurpdf.css'), $base) : null;
        $xp = new \DOMXPath($doc);
        foreach ($xp->query('//style | //link[@rel="stylesheet"]') ?: [] as $n) {
            if (!$n instanceof \DOMElement) continue;
            if ($n->nodeName === 'style') $css .= $n->textContent . "\n";
            else { $data = $this->loader->load($n->getAttribute('href')); if ($data !== null) $css .= $data . "\n"; }
        }
        foreach ($o->css as $c) $css .= $c . "\n";
        $sheet = CssParser::parse($css, $base);
        $ua = CssParser::parse(StyleResolver::UA);
        foreach ($sheet['fonts'] as $ff) $this->fontFace($ff);
        $this->title = (string)($o->meta['title'] ?? trim(self::xs($xp, 'string(//title)')));

        // Геометрия страницы
        $page = $sheet['page']['*'] ?? [];
        [$wmm, $hmm] = $this->pageSize($page);
        $mm = $o->margins ?? $this->pageMargins($page, $wmm, $hmm);
        $pt = 72 / 25.4;
        $W = $wmm * $pt; $H = $hmm * $pt;
        [$mt, $mr, $mb, $ml] = array_map(fn($v) => $v * $pt, $mm);
        $cw = max(20, $W - $ml - $mr); $ch = max(20, $H - $mt - $mb);
        $this->pageW = $W; $this->pageH = $H; $this->margins = [$mt, $mr, $mb, $ml];

        $extra = ":root{--page-width:{$cw}pt;--page-height:{$ch}pt}";
        if ($o->presentation) $extra .= ".slide{height:{$ch}pt;width:{$cw}pt;break-after:page;overflow:hidden}";
        $sheets = [$ua, CssParser::parse($extra), $sheet];
        $this->resolver = new StyleResolver($sheets, $themeSheet);

        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) { $body = $doc->createElement('body'); ($doc->documentElement ?? $doc)->appendChild($body); }
        if ($o->toc && !$doc->getElementsByTagName('toc')->length) {
            $toc = $doc->createElement('toc');
            $cfg = is_array($o->toc) ? $o->toc : [];
            $toc->setAttribute('title', (string)($cfg['title'] ?? 'Содержание'));
            $toc->setAttribute('levels', (string)($cfg['levels'] ?? 3));
            $body->insertBefore($toc, $body->firstChild);
        }
        \BaurPDF\Css\Pseudo::apply($body, $this->resolver);
        $hasToc = $doc->getElementsByTagName('toc')->length > 0;
        $remain = fn() => max(1, $o->timeLimit - (microtime(true) - $t0));

        // Проходы: (1) собрать заголовки; (2) узнать их страницы; (3) финальный
        $tocSource = []; $tocPages = [];
        if ($hasToc) {
            [$e] = $this->layout($body, $cw, $ch, $remain(), [], []);
            $tocSource = $e->headings;
            /** @var list<array{ops:array}> $pages2 */
            [$e2, $pages2] = $this->layout($body, $cw, $ch, $remain(), $tocSource, array_fill_keys(array_column($tocSource, 'key'), '00'));
            foreach ($pages2 as $pi => $p) foreach (Ops::each($p['ops']) as $op) if (is_array($op) && ($op['t'] ?? '') === 'heading' && is_string($op['key'] ?? null)) $tocPages[$op['key']] ??= (string)($pi + $o->pageNumberStart);
        }
        if ($stream !== null) {
            if ($hasToc) $this->warnings[] = 'streamTable: table of contents is not supported in streaming mode';
            $engine = $this->newEngine($cw, $ch, $remain());
            $bodySt = $engine->style($body);
            $pages = $this->streamPages($engine, $body, $cw, $ch, $stream);
        } else {
            /** @var list<array{ops:array,landscape?:bool}> $list */
            [$engine, $list, $bodySt] = $this->layout($body, $cw, $ch, $remain(), $tocSource, $tocPages);
            while (count($list) > 1 && !array_filter(iterator_to_array(Ops::each(self::opsOf(end($list))), false), fn($o) => !is_array($o) || !in_array($o['t'] ?? '', ['dest', 'heading'], true))) array_pop($list); // хвостовые пустые страницы
            if (!$list) $list = [['ops' => []]];
            $pages = (static function () use (&$list): \Generator { foreach (array_keys($list) as $i) { $p = $list[$i]; unset($list[$i]); yield $p; } })(); // освобождать память по мере вывода
        }

        $w = new Writer();
        $w->compress = $o->compress;
        $w->fixedTime = $o->fixedTime;
        $w->imageDpi = $o->imageDpi; $w->jpegQuality = $o->jpegQuality;
        $painter = new Painter($w);
        $pageBg = Color::parse((string)($bodySt['background-color'] ?? '')) ?: (Color::parse((string)($engine->style($doc->documentElement ?? $body)['background-color'] ?? '')) ?: null);
        $pageCss = isset($page['background-color']) ? Color::parse($page['background-color'][0]) : null;
        $pageBg = $pageCss ?: $pageBg;
        $sizes = [];
        foreach ($pages as $p) {
            [$W, $H] = empty($p['landscape']) ? [$this->pageW, $this->pageH] : [$this->pageH, $this->pageW];
            $idx = $w->addPage($W, $H);
            $sizes[$idx] = [$W, $H];
            if ($pageBg && $pageBg[3] > 0) $painter->paint($idx, [['t' => 'rect', 'x' => 0, 'y' => 0, 'w' => $W, 'h' => $H, 'fill' => $pageBg]], 0, 0);
            $w->tags = $engine->tags;
            $painter->paint($idx, $p['ops'], $ml, $mt);
            unset($p);
            if (microtime(true) - $t0 > $o->timeLimit * 1.5) { $this->warnings[] = 'Time limit reached while painting'; break; }
        }
        if (!$sizes) $sizes[$w->addPage($this->pageW, $this->pageH)] = [$this->pageW, $this->pageH];
        $this->warnings = array_merge($this->warnings, $engine->warnings);
        $this->pages = $total = count($sizes);
        // колонтитулы и водяной знак — когда известно общее число страниц
        foreach ($sizes as $i => [$W, $H]) {
            $idx = $i;
            $num = $i + $o->pageNumberStart; $tot = $total + $o->pageNumberStart - 1;
            foreach (['header' => true, 'footer' => false] as $kind => $top) {
                if ($i === 0 && !($top ? $o->headerOnFirstPage : $o->footerOnFirstPage)) continue;
                $tpl = $o->$kind;
                if (is_callable($tpl) && !is_string($tpl)) $tpl = $tpl($num, $tot, $i);
                if (!is_string($tpl) || trim($tpl) === '') continue;
                try {
                    [$ops, $hh] = $this->snippet($engine, $tpl, $num, $tot, $W - $ml - $mr, $kind);
                    $off = ($top ? $o->headerOffset : $o->footerOffset) * $pt;
                    $painter->paint($idx, [['t' => 'group', 'dx' => 0.0, 'dy' => 0.0, 'ops' => $ops, 'artifact' => true]], $ml, $top ? $off : $H - $off - $hh); // колонтитулы — Artifact
                } catch (\Throwable $ex) { $this->warnings[] = "$kind: " . $ex->getMessage(); }
            }
            if ($o->watermark) $this->watermark($painter, $engine, $idx, $W, $H, $o->watermark, $w);
        }
        if ($o->outline) $w->outline = array_values(array_filter($painter->headings, fn($h) => $h['title'] !== ''));
        $meta = $o->meta + $this->docMeta($xp);
        $meta['title'] ??= $this->title;
        $meta['creator'] ??= 'BaurPDF ' . BaurPDF::VERSION;
        $w->info = array_filter($meta, fn($v) => $v !== null && $v !== '');
        $w->pdfa = $o->pdfa;
        $w->pdfua = $o->pdfua;
        if ($o->pdfua) {
            if (($w->info['title'] ?? '') === '') { $w->info['title'] = 'Document'; $this->warnings[] = 'PDF/UA requires a document title: <title> is missing'; }
            $w->info['lang'] ??= 'ru';
        }
        if ($o->pdfa && ($o->userPassword !== null || $o->ownerPassword !== null)) $this->warnings[] = 'PDF/A forbids encryption: password ignored';
        elseif ($o->userPassword !== null || $o->ownerPassword !== null) $w->encrypt((string)$o->userPassword, $o->ownerPassword, $o->permissions, $o->encryption);
        $this->warnings = array_values(array_unique(array_merge($this->warnings, $this->loader->warnings, $this->fonts->warnings)));
        $tw = hrtime(true); $bytes = $w->output();
        if (getenv('BAURPDF_PROFILE')) fprintf(STDERR, "write %.0f ms, mem %.1f MB (peak %.1f)\n", (hrtime(true) - $tw) / 1e6, memory_get_usage() / 1048576, memory_get_peak_usage() / 1048576);
        return $bytes;
    }

    /** @return array{0:Engine,1:array,2:array} */
    private function layout(\DOMElement $body, float $cw, float $ch, float $time, array $tocSource, array $tocPages): array
    {
        $e = $this->newEngine($cw, $ch, $time);
        $e->tocSource = $tocSource; $e->tocPages = $tocPages;
        $flow = new Flow();
        $e->mainFlow = $flow;
        $st = $e->style($body);
        $y = 0.0;
        $t0 = hrtime(true); if (getenv("BAURPDF_PROFILE")) fprintf(STDERR, "pre-layout mem %.1f MB\n", memory_get_usage() / 1048576);
        $e->maxHeight = $ch * $this->o->maxPages;
        $e->layoutBlock($body, $st, 0, $cw, $flow, $y, 0);
        $t1 = hrtime(true);
        $pages = Paginator::paginate($flow, $ch, $this->o->maxPages, 0.0, [], $pinfo);
        if (!empty($pinfo['brokenKeep'])) $e->warnings[] = "break-inside: avoid ignored for {$pinfo['brokenKeep']} block(s) taller than a page";
        if (getenv('BAURPDF_PROFILE')) fprintf(STDERR, "layout %.0f ms, paginate %.0f ms, atoms %d, mem %.1f MB (peak %.1f)\n", ($t1 - $t0) / 1e6, (hrtime(true) - $t1) / 1e6, count($flow->atoms), memory_get_usage() / 1048576, memory_get_peak_usage() / 1048576);
        if (count($pages) >= $this->o->maxPages) $e->warnings[] = "maxPages ({$this->o->maxPages}) reached, output truncated";
        return [$e, $pages, $st];
    }

    private function newEngine(float $cw, float $ch, float $time): Engine
    {
        $e = new Engine($this->resolver, $this->fonts, $this->loader, $time);
        $e->pageContentHeight = $ch;
        $e->kerning = $this->o->kerning;
        if ($this->o->autoLandscape && $this->pageH > $this->pageW) $e->landscapeArea = [$this->pageH - $this->margins[1] - $this->margins[3], $this->pageW - $this->margins[0] - $this->margins[2]];
        if ($this->o->tagged || $this->o->pdfua) $e->tags = new \BaurPDF\Layout\Tags();
        return $e;
    }

    /**
     * Потоковая таблица: документ до таблицы раскладывается обычно, строки — порциями; готовые страницы
     * сразу отдаются на отрисовку, в памяти остаются только атомы последней страницы.
     * @param array{rows:iterable<mixed>,columns:list<string>,row:?callable,chunk:int} $s
     * @return \Generator<int, array{ops:array,landscape?:bool}>
     */
    private function streamPages(Engine $e, \DOMElement $body, float $cw, float $ch, array $s): \Generator
    {
        $doc = $body->ownerDocument;
        assert($doc instanceof \DOMDocument);
        $t = null;
        foreach ($body->getElementsByTagName('table') as $c) if ($c->hasAttribute('data-bp-stream')) { $t = $c; break; }
        if ($t === null) {
            $t = $doc->createElement('table');
            $t->setAttribute('data-bp-stream', '');
            if ($s['columns']) {
                $tr = $t->appendChild($doc->createElement('thead'))->appendChild($doc->createElement('tr'));
                foreach ($s['columns'] as $c) $tr->appendChild($doc->createElement('th'))->appendChild($doc->createTextNode($c));
            }
            $body->appendChild($t);
        }
        if (!$t instanceof \DOMElement) return;
        $t->setAttribute('style', rtrim($t->getAttribute('style'), '; ') . ';table-layout:fixed');
        // содержимое после таблицы (2.30): отсоединяем и выводим после последней строки
        $after = []; $lvl = 0;
        for ($n = $t; $n instanceof \DOMElement && $n !== $body; $n = $n->parentNode, $lvl++) {
            $pp = $n->parentNode;
            while ($n->nextSibling) {
                $nx = $n->nextSibling; $pp?->removeChild($nx);
                if ($nx instanceof \DOMElement ? in_array(strtolower($nx->nodeName), ['style', 'script', 'link', 'meta'], true) : trim($nx->textContent) === '') continue;
                if (!$nx instanceof \DOMElement) { $w = $doc->createElement('div'); $w->appendChild($nx); $nx = $w; }
                $after[] = [$nx, $pp, $lvl];
            }
        }
        $thead = null;
        foreach ($t->childNodes as $c) if ($c instanceof \DOMElement && strtolower($c->nodeName) === 'thead') { $thead = $c; break; }
        $parent = $t->parentNode;
        $flow = new Flow();
        $e->mainFlow = $flow;
        $e->streamTable = $t;
        $y = 0.0;
        $e->layoutBlock($body, $e->style($body), 0, $cw, $flow, $y, 0);
        $box = $e->streamBox ?? [0.0, $cw, 1];
        $end = 0.0;
        foreach ($flow->atoms as $a) $end = max($end, $a['y'] + $a['h']);
        $maxPages = $this->o->maxPages; $out = 0; $shift = 0.0; $hdr = [];
        $off = null; $buf = ''; $cnt = 0; $rowFn = $s['row'];
        // Bound intermediate HTML buffers even when a single row is unusually large.
        $maxChunkBytes = 1048576;
        $flush = function (bool $final) use ($flow, $ch, &$maxPages, &$out, &$shift, &$hdr): array {
            $info = [];
            $pages = Paginator::paginate($flow, $ch, max(1, $maxPages - $out), $shift, $hdr, $info);
            if ($final) return $pages;
            $last = count($pages) - 1;
            if ($last < 1) return [];
            $keep = count($flow->atoms);
            foreach ($info['place'] as $i => [$pg]) if ($pg === $last) { $keep = $i; break; }
            $shift = $info['place'][$keep][1] ?? $shift;
            $hdr = $info['headers'][$last] ?? [];
            $flow->atoms = array_slice($flow->atoms, $keep);
            $decos = [];
            foreach ($flow->decos as $d) {
                if ($d['last'] < $keep) continue;
                if ($d['first'] < $keep) { $d['first'] = 0; $d['top'] = max($d['top'], ($flow->atoms[0]['y'] ?? $d['top'])); } else $d['first'] -= $keep;
                $d['last'] -= $keep; $decos[] = $d;
            }
            $flow->decos = $decos;
            return array_slice($pages, 0, $last);
        };
        $chunk = function (string $rows) use ($e, $doc, $t, $thead, $parent, $flow, $box, &$end, &$off): void {
            $frag = self::parseHtml('<html><body><table><tbody>' . $rows . '</tbody></table></body></html>');
            $tb = $frag->getElementsByTagName('tbody')->item(0);
            if (!$tb || !$parent) return;
            $c = $t->cloneNode(false);
            assert($c instanceof \DOMElement);
            $c->removeAttribute('id');
            if ($thead) $c->appendChild($thead->cloneNode(true));
            $c->appendChild($doc->importNode($tb, true));
            $parent->appendChild($c);
            $n0 = count($flow->atoms); $d0 = count($flow->decos);
            $yy = $end - ($off ?? 0.0);
            $e->layoutBlock($c, $e->style($c), $box[0], $box[1], $flow, $yy, $box[2]);
            array_splice($flow->decos, $d0); // рамка/фон таблицы — только у первой части
            $atoms = [];
            foreach (array_slice($flow->atoms, $n0) as $a) if (!isset($a['thead'])) $atoms[] = $a;
            if ($atoms && $off === null) { // первая порция: сдвинуть вплотную к концу документа
                $off = $atoms[0]['y'] - $end;
                foreach ($atoms as &$a) { $a['ops'] = Ops::translate($a['ops'], 0, -$off); $a['y'] -= $off; }
                unset($a);
            }
            array_splice($flow->atoms, $n0, null, $atoms);
            foreach ($atoms as $a) $end = max($end, $a['y'] + $a['h']);
            $e->forget($c);
            $parent->removeChild($c);
        };
        foreach ($s['rows'] as $r) {
            if ($rowFn) {
                $buf .= (string)$rowFn($r); // explicitly trusted custom HTML callback
            } elseif (is_string($r)) {
                $buf .= $r; // explicitly trusted legacy HTML row
            } else {
                $buf .= '<tr>';
                foreach ((array)$r as $v) {
                    $cell = is_scalar($v) || $v instanceof \Stringable ? (string)$v : '';
                    $buf .= '<td>' . htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true) . '</td>';
                }
                $buf .= '</tr>';
            }
            ++$cnt;
            if ($cnt % $s['chunk'] === 0 || strlen($buf) >= $maxChunkBytes) {
                $chunk($buf); $buf = '';
                foreach ($flush(false) as $p) { $out++; yield $p; }
                if ($out >= $maxPages) { $e->warnings[] = "maxPages ({$maxPages}) reached, output truncated"; return; }
            }
        }
        if ($buf !== '') $chunk($buf);
        foreach ($after as [$node, $pp, $lvl]) {
            if (!$pp instanceof \DOMNode) continue;
            $pp->appendChild($node);
            $bx = $lvl === 0 ? $box : [0.0, $cw, 1]; // соседи таблицы — в её контейнере, внешние — по ширине страницы
            $n0 = count($flow->atoms);
            $yy = $end;
            $e->layoutBlock($node, $e->style($node), $bx[0], $bx[1], $flow, $yy, $bx[2]);
            foreach (array_slice($flow->atoms, $n0) as $a) $end = max($end, $a['y'] + $a['h']);
            $end = max($end, $yy);
            $e->forget($node);
            $pp->removeChild($node);
            foreach ($flush(false) as $p) { $out++; yield $p; }
            if ($out >= $maxPages) { $e->warnings[] = "maxPages ({$maxPages}) reached, output truncated"; return; }
        }
        foreach ($flush(true) as $p) { $out++; yield $p; }
    }

    private function snippet(Engine $e, string $tpl, int $num, int $tot, float $w, string $kind): array
    {
        $date = date('d.m.Y', $this->o->fixedTime ?? time());
        $map = ['{PAGENO}' => $num, '{page}' => $num, '{nbpg}' => $tot, '{pages}' => $tot, '{DATE}' => $date, '{date}' => $date, '{TITLE}' => htmlspecialchars($this->title), '{title}' => htmlspecialchars($this->title)];
        $html = strtr($tpl, $map);
        $html = preg_replace(['#<span class="pageNumber"></span>#', '#<span class="totalPages"></span>#'], [(string)$num, (string)$tot], $html);
        $d = self::parseHtml('<html><body><div class="bp-' . $kind . '">' . $html . '</div></body></html>');
        $div = $d->getElementsByTagName('div')->item(0);
        $bodyEl = $d->getElementsByTagName('body')->item(0);
        if ($div === null || $bodyEl === null) return [];
        $flow = new Flow(); $y = 0.0;
        $bst = $e->style($bodyEl);
        $bst['margin-top'] = $bst['margin-bottom'] = $bst['margin-left'] = $bst['margin-right'] = '0';
        $e->setStyle($bodyEl, $bst);
        $e->layoutBlock($div, $e->style($div), 0, $w, $flow, $y, 0);
        $pg = Paginator::paginate($flow, 1e6, 1);
        $ops = Ops::flat($pg[0]['ops'] ?? []);
        return [$ops, max($flow->height(), $y)];
    }

    private function watermark(Painter $p, Engine $e, int $idx, float $W, float $H, array $wm, Writer $w): void
    {
        $op = (float)($wm['opacity'] ?? 0.08);
        if (!empty($wm['image'])) {
            $data = $this->loader->load($wm['image']);
            if ($data === null) return;
            $iw = min($W * 0.6, (float)($wm['width'] ?? $W * 0.5));
            $sz = \BaurPDF\Pdf\Image::size($data);
            $ih = $sz ? $iw * $sz[1] / max(1, $sz[0]) : $iw;
            $p->paint($idx, [['t' => 'alpha', 'a' => $op], ['t' => 'image', 'data' => $data, 'x' => ($W - $iw) / 2, 'y' => ($H - $ih) / 2, 'w' => $iw, 'h' => $ih], ['t' => 'endalpha']], 0, 0);
            return;
        }
        $text = (string)($wm['text'] ?? '');
        if ($text === '') return;
        $angle = (float)($wm['angle'] ?? 45);
        $col = Color::parse((string)($wm['color'] ?? '#000')) ?? [0, 0, 0, 1];
        $col[3] = $op;
        [$font] = $this->fonts->get((string)($wm['font'] ?? 'sans-serif'), 'bold', 'normal');
        $size = (float)($wm['size'] ?? 0);
        $diag = sqrt($W * $W + $H * $H) * 0.7;
        $tw1 = max(1, $e->textWidth($font, $text, 1));
        if ($size <= 0) $size = min(120, $diag / $tw1);
        $tw = $tw1 * $size;
        $r = deg2rad($angle);
        $x = $W / 2 - cos($r) * $tw / 2 - sin($r) * $size * 0.35;
        $y = $H / 2 + sin($r) * $tw / 2 + cos($r) * $size * 0.35;
        $ops = $e->segmentsOps($font, false, false, $text, 0, 0, $size, $col);
        foreach ($ops as &$o) if ($o['t'] === 'text') { $dx = $o['x']; $o['x'] = $x + cos($r) * $dx; $o['y'] = $y - sin($r) * $dx; $o['rot'] = $angle; }
        $p->paint($idx, $ops, 0, 0);
    }

    private function fontFace(array $ff): void
    {
        if (!preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)\s*(?:format\(\s*[\'"]?([\w-]+))?/i', $ff['src'], $m, PREG_SET_ORDER)) return;
        foreach ($m as $u) {
            $fmt = strtolower($u[2] ?? '');
            if ($fmt && !in_array($fmt, ['truetype', 'opentype', 'ttf'], true)) continue;
            $data = $this->loader->load($u[1]);
            if ($data === null || !in_array(substr($data, 0, 4), ["\x00\x01\x00\x00", 'true', 'OTTO'], true)) { if ($data !== null) $this->warnings[] = "@font-face {$ff['family']}: only TrueType or OpenType (glyf/CFF) fonts are supported"; continue; }
            $tmp = sys_get_temp_dir() . '/baurpdf-font-' . md5($data) . '.ttf';
            if (!is_file($tmp)) @file_put_contents($tmp, $data);
            $wt = strtolower(trim($ff['weight'])); $bold = $wt === 'bold' || (is_numeric($wt) && (int)$wt >= 600);
            if ($this->fonts->register($ff['family'], $tmp, $bold ? 'bold' : 'normal', str_contains(strtolower($ff['style']), 'italic') ? 'italic' : 'normal')) return;
        }
    }

    private function pageSize(array $page): array
    {
        $o = $this->o;
        if ($o->format !== null) return Options::formatMm($o->format, (bool)$o->landscape);
        if ($o->presentation) return Options::formatMm('SLIDE16X9', true);
        if (isset($page['size'])) {
            $v = strtolower(trim($page['size'][0]));
            $land = str_contains($v, 'landscape') || (bool)$o->landscape;
            $v = trim(str_replace(['landscape', 'portrait'], '', $v));
            if ($v === '' || $v === 'auto') return Options::formatMm('A4', $land);
            $parts = preg_split('/\s+/', $v) ?: [$v];
            if (count($parts) === 2 && is_numeric($parts[0][0] ?? '') ) {
                $a = Length::toPt($parts[0], 0, 12); $b = Length::toPt($parts[1], 0, 12);
                if ($a > 0 && $b > 0) return [$a * 25.4 / 72, $b * 25.4 / 72];
            }
            if (count($parts) === 1 && is_numeric($parts[0][0] ?? '')) { $a = Length::toPt($parts[0], 0, 12); if ($a > 0) return [$a * 25.4 / 72, $a * 25.4 / 72]; }
            try { return Options::formatMm($parts[0], $land); } catch (\Throwable) { $this->warnings[] = "@page size '$v' not recognized"; }
        }
        return Options::formatMm('A4', (bool)$o->landscape);
    }

    private function pageMargins(array $page, float $wmm, float $hmm): array
    {
        $def = $this->o->presentation ? [0, 0, 0, 0] : [15, 15, 15, 15];
        $toMm = fn($v, $ref) => ($p = Length::toPt($v, $ref * 72 / 25.4, 12)) === null ? null : $p * 25.4 / 72;
        $map = ['margin-top' => 0, 'margin-right' => 1, 'margin-bottom' => 2, 'margin-left' => 3];
        foreach ($map as $k => $i) if (isset($page[$k])) { $v = $toMm($page[$k][0], $i % 2 ? $wmm : $hmm); if ($v !== null) $def[$i] = max(0, $v); }
        return $def;
    }

    private function docMeta(\DOMXPath $xp): array
    {
        $r = [];
        foreach (['author' => 'author', 'description' => 'subject', 'keywords' => 'keywords'] as $n => $k) {
            $v = trim(self::xs($xp, "string(//meta[@name='$n']/@content)"));
            if ($v !== '') $r[$k] = $v;
        }
        $lang = trim(self::xs($xp, 'string(/html/@lang)'));
        if ($lang !== '') $r['lang'] = $lang;
        return $r;
    }
    private static function xs(\DOMXPath $xp, string $q): string { $v = $xp->evaluate($q); return is_string($v) ? $v : ''; }
    private static function opsOf(mixed $p): array { return is_array($p) && is_array($p['ops'] ?? null) ? $p['ops'] : []; }
}
