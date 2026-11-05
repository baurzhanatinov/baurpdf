<?php
declare(strict_types=1);
namespace BaurPDF\Yii2;

use BaurPDF\Document;
use BaurPDF\BaurPDF;

/**
 * Обёртка над BaurPDF, у которой download()/inline() возвращают yii\web\Response
 * (правильно для Yii2: return из action). Все fluent-методы BaurPDF доступны через __call.
 * @mixin BaurPDF
 */
final class PdfBuilder
{
    public function __construct(private BaurPDF $pdf) {}

    public function __call(string $name, array $args): mixed
    {
        $r = $this->pdf->$name(...$args);
        return $r instanceof BaurPDF ? $this : $r;
    }

    public function pdf(): BaurPDF { return $this->pdf; }
    public function document(): Document { return $this->pdf->render(); }
    public function save(string $path): string { return $this->pdf->save((string)(\Yii::getAlias($path) ?: $path)); }
    public function toString(): string { return $this->pdf->toString(); }

    /** Скачать файл: return Yii::$app->pdf->render('view')->download('a.pdf'); */
    public function download(string $filename = 'document.pdf'): ?\yii\web\Response { $r = PdfResponse::send($this->document(), $filename, false); return $r instanceof \yii\web\Response ? $r : null; }
    /** Показать в браузере. */
    public function stream(string $filename = 'document.pdf'): ?\yii\web\Response { $r = PdfResponse::send($this->document(), $filename, true); return $r instanceof \yii\web\Response ? $r : null; }
    public function inline(string $filename = 'document.pdf'): ?\yii\web\Response { return $this->stream($filename); }
}
