<?php
declare(strict_types=1);
namespace BaurPDF\Yii2;

use BaurPDF\Document;
use BaurPDF\BaurPDF;
use BaurPDF\Options;

/**
 * Компонент Yii2 (yii2 не является зависимостью пакета).
 *
 * config/web.php:
 *   'components' => ['pdf' => ['class' => \BaurPDF\Yii2\Pdf::class, 'options' => ['format' => 'A4']]]
 *
 * В контроллере:
 *   return Yii::$app->pdf->render('report', ['model' => $m])->download('report.pdf');
 */
class Pdf extends \yii\base\Component
{
    /** Опции по умолчанию (свойства BaurPDF\Options). */
    public array $options = [];
    /** Каталог кэша (например '@runtime/baurpdf') или null. */
    public ?string $cachePath = null;
    /** Layout для render(): null = без layout. */
    public ?string $layout = null;

    /** Рендер view текущего контроллера (или пути '@app/views/pdf/report') в PDF-построитель. */
    public function render(string $view, array $params = [], array $options = []): PdfBuilder
    {
        $view = (string)$view;
        $app = \Yii::$app;
        if ($app === null) throw new \RuntimeException('Yii application is not initialized');
        $html = $app->controller !== null && !str_starts_with($view, '@') && !str_starts_with($view, '/')
            ? $app->controller->renderPartial($view, $params)
            : $app->view->render($view, $params);
        if ($this->layout) $html = $app->view->render($this->layout, ['content' => $html] + $params);
        return $this->html($html, $options);
    }

    public function html(string $html, array $options = []): PdfBuilder
    {
        $o = Options::fromArray(array_replace($this->options, $options));
        $o->baseDir ??= \Yii::getAlias('@webroot', false) ?: null;
        $m = BaurPDF::html($html, $o);
        if ($this->cachePath) $m->cache((string)\Yii::getAlias($this->cachePath));
        return new PdfBuilder($m);
    }

    public function template(string $name, array $data = [], array $options = []): PdfBuilder
    {
        return new PdfBuilder(BaurPDF::template($name, $data, array_replace($this->options, $options)));
    }
}
