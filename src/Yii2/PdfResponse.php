<?php
declare(strict_types=1);
namespace BaurPDF\Yii2;

use BaurPDF\Document;

/**
 * Отдача PDF через \yii\web\Response без жёсткой зависимости от Yii2.
 *
 *     return PdfResponse::send($doc, 'report.pdf');            // attachment
 *     return PdfResponse::send($doc, 'report.pdf', true);      // inline
 */
final class PdfResponse
{
    /** Заголовки ответа (без Content-Length) — используются и с Yii, и без него. */
    public static function headers(Document $doc, string $filename, bool $inline): array
    {
        [$name, $value] = explode(': ', Document::disposition($inline ? 'inline' : 'attachment', $filename), 2);
        return [
            'Content-Type' => $doc->mime(),
            $name => $value,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ];
    }

    /**
     * С Yii: настраивает переданный (или текущий Yii::$app->response) ответ и возвращает его.
     * Без Yii: отправляет PDF средствами PHP (Document::stream()/download()) и возвращает null.
     */
    public static function send(Document $doc, string $filename = 'document.pdf', bool $inline = false, mixed $response = null): mixed
    {
        if ($response === null && class_exists('\Yii', false) && isset(\Yii::$app) && \Yii::$app !== null && \Yii::$app->has('response')) {
            $response = \Yii::$app->getResponse();
        }
        if ($response instanceof \yii\web\Response) {
            $response->format = 'raw'; // \yii\web\Response::FORMAT_RAW
            $response->content = $doc->content();
            $h = $response->getHeaders();
            foreach (self::headers($doc, $filename, $inline) as $k => $v) $h->set($k, $v);
            $h->set('Content-Length', (string)$doc->size());
            return $response;
        }
        $inline ? $doc->stream($filename) : $doc->download($filename);
        return null;
    }
}
