<?php
declare(strict_types=1);
namespace BaurPDF\Tests;

require_once __DIR__ . '/Support/yii-stubs.php';

use BaurPDF\Yii2\Pdf;
use BaurPDF\Yii2\PdfBuilder;
use BaurPDF\Yii2\PdfResponse;

/** Компонент Yii2 на заглушках: без yiisoft/yii2 в зависимостях, но с реальными контрактами Component/Response. */
final class Yii2ComponentTest extends TestCase
{
    protected function tearDown(): void { \Yii::$app = null; }

    public function testHtmlBuilderAppliesComponentOptionsAndFluentCalls(): void
    {
        $pdf = new Pdf(['options' => ['format' => 'A4', 'footer' => 'Стр. {page} из {pages}']]);
        $b = $pdf->html('<h1>Отчёт</h1>' . str_repeat('<p>абзац</p>', 150));
        $this->assertInstanceOf(PdfBuilder::class, $b);
        $this->assertSame($b, $b->landscape()->title('Тест'));
        $doc = $b->document();
        $this->assertSame('841x595', $this->pageSizes($doc->content())[0], 'landscape() через __call дошёл до Options');
        $this->assertStringContainsString('Стр. 1 из ' . $doc->pageCount(), $this->text($doc->content()));
        $this->assertSame(sys_get_temp_dir(), $b->pdf()->options()->baseDir, 'baseDir = @webroot');
    }

    public function testRenderUsesApplicationViewWhenNoController(): void
    {
        \Yii::$app = new class {
            public mixed $controller = null;
            public object $view;
            public function __construct() { $this->view = new class { public function render(string $view, array $params = []): string { return '<p>view:' . htmlspecialchars($view) . ':' . ($params['n'] ?? '') . '</p>'; } }; }
            public function has(string $id): bool { return false; }
        };
        $pdf = new Pdf(['layout' => '@app/views/layouts/pdf']);
        $doc = $pdf->render('@app/views/report', ['n' => 7])->document();
        $t = $this->text($doc->content());
        $this->assertStringContainsString('view:@app/views/layouts/pdf:7', $t, 'layout обернул контент');
    }

    public function testSendFillsYiiResponse(): void
    {
        $doc = \BaurPDF\BaurPDF::html('<p>x</p>')->deterministic()->render();
        $resp = new \yii\web\Response();
        $r = PdfResponse::send($doc, 'отчёт.pdf', false, $resp);
        $this->assertSame($resp, $r);
        $this->assertSame('raw', $resp->format);
        $this->assertSame($doc->content(), $resp->content);
        $h = $resp->getHeaders();
        $this->assertSame('application/pdf', $h->get('Content-Type'));
        $this->assertSame((string)$doc->size(), $h->get('Content-Length'));
        $this->assertStringContainsString('attachment', (string)$h->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=UTF-8''%D0%BE%D1%82%D1%87%D1%91%D1%82.pdf", (string)$h->get('Content-Disposition'), 'кириллическое имя по RFC 5987');
        $inline = new \yii\web\Response();
        PdfResponse::send($doc, 'a.pdf', true, $inline);
        $this->assertStringStartsWith('inline', (string)$inline->getHeaders()->get('Content-Disposition'));
    }

    public function testDownloadWithoutYiiAppSendsBytesViaPhp(): void
    {
        // без Yii::$app send() отдаёт файл средствами PHP (header + echo) и сбрасывает буферы — проверяем в отдельном процессе
        // код — во временном файле: escapeshellarg на Windows портит кавычки внутри -r
        $script = tempnam(sys_get_temp_dir(), 'bpy') . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . '; require ' . var_export(__DIR__ . '/Support/yii-stubs.php', true) . ";\n"
            . '$r = (new BaurPDF\Yii2\Pdf())->html(\'<p>x</p>\')->download(\'a.pdf\'); fwrite(STDERR, var_export($r, true));');
        $err = tempnam(sys_get_temp_dir(), 'bpy');
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>' . escapeshellarg($err));
        @unlink($script);
        $this->assertStringStartsWith('%PDF-', $out);
        $this->assertSame('NULL', trim((string)file_get_contents($err)));
        @unlink($err);
    }
}
