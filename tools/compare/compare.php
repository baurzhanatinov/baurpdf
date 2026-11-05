<?php
declare(strict_types=1);
/**
 * DEV-ONLY сравнение: BaurPDF vs mPDF vs Chromium (эталон). Chromium/mPDF НЕ являются зависимостями пакета.
 *   php tools/compare/compare.php [--mpdf=/path/to/vendor/autoload.php] [--chrome=google-chrome]
 * Результат: tools/compare/out/<doc>-side-by-side.png и scorecard.md
 */
require __DIR__ . '/../../vendor/autoload.php';
$opt = getopt('', ['mpdf::', 'chrome::']);
$mpdfAutoload = $opt['mpdf'] ?? '/workspace/bench/vendor/autoload.php';
$chrome = $opt['chrome'] ?? 'google-chrome';
if (is_file($mpdfAutoload)) require $mpdfAutoload;
$out = __DIR__ . '/out'; @mkdir($out, 0777, true);
$docs = [];
foreach (glob(__DIR__ . '/docs/*.html') as $f) $docs[basename($f, '.html')] = (string)file_get_contents($f);

function sh(string $cmd): string { return (string)shell_exec($cmd . ' 2>/dev/null'); }
function pages(string $f): int { preg_match('/Pages:\s+(\d+)/', sh('pdfinfo ' . escapeshellarg($f)), $m); return (int)($m[1] ?? 0); }
function qpdfOk(string $f): bool { exec('qpdf --check ' . escapeshellarg($f) . ' >/dev/null 2>&1', $o, $c); return $c === 0; }

$rows = [];
foreach ($docs as $name => $html) {
    $files = [];
    // BaurPDF
    $t = microtime(true); \BaurPDF\BaurPDF::html($html)->baseDir(__DIR__ . '/docs')->deterministic()->save($files['BaurPDF'] = "$out/$name-baurpdf.pdf"); $times['BaurPDF'] = microtime(true) - $t;
    // mPDF
    if (class_exists(\Mpdf\Mpdf::class)) {
        $t = microtime(true);
        try { $m = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir() . '/mpdf', 'mode' => 'utf-8', 'format' => 'A4']); $m->WriteHTML($html); $m->Output($files['mPDF'] = "$out/$name-mpdf.pdf", 'F'); }
        catch (\Throwable $e) { fwrite(STDERR, "mPDF $name: {$e->getMessage()}\n"); }
        $times['mPDF'] = microtime(true) - $t;
    }
    // Chromium (эталон)
    if (trim(sh("command -v $chrome"))) {
        $src = "$out/$name.src.html"; file_put_contents($src, str_replace('<head>', '<head><base href="file://' . __DIR__ . '/docs/">', $html));
        $t = microtime(true);
        sh("$chrome --headless=new --no-sandbox --disable-gpu --no-pdf-header-footer --print-to-pdf=" . escapeshellarg($files['Chromium'] = "$out/$name-chromium.pdf") . ' ' . escapeshellarg("file://$src"));
        $times['Chromium'] = microtime(true) - $t;
    }
    $pngs = [];
    foreach ($files as $eng => $f) {
        if (!is_file($f)) continue;
        sh('pdftoppm -r 60 -png -f 1 -l 1 -singlefile ' . escapeshellarg($f) . ' ' . escapeshellarg("$out/$name-$eng"));
        $pngs[$eng] = "$out/$name-$eng.png";
        $rows[] = [$name, $eng, round($times[$eng] * 1000), round(filesize($f) / 1024), pages($f), qpdfOk($f) ? 'да' : 'НЕТ', mb_strlen(preg_replace('/\s+/u', '', sh('pdftotext ' . escapeshellarg($f) . ' -')))];
    }
    // side-by-side (GD)
    if (extension_loaded('gd') && $pngs) {
        $ims = array_map('imagecreatefrompng', $pngs); $W = 0; $H = 0;
        foreach ($ims as $im) { $W += imagesx($im) + 10; $H = max($H, imagesy($im)); }
        $c = imagecreatetruecolor($W, $H + 24); imagefill($c, 0, 0, 0xDDDDDD); $x = 0;
        foreach ($ims as $eng => $im) { imagecopy($c, $im, $x, 24, 0, 0, imagesx($im), imagesy($im)); imagestring($c, 5, $x + 6, 4, $eng, 0x000000); $x += imagesx($im) + 10; }
        imagepng($c, "$out/$name-side-by-side.png");
    }
}
$md = "| Документ | Движок | Время, мс | Размер, КБ | Страниц | qpdf | Символов текста |\n|---|---|---:|---:|---:|---|---:|\n";
foreach ($rows as $r) $md .= '| ' . implode(' | ', $r) . " |\n";
file_put_contents("$out/scorecard.md", $md);
echo $md;
