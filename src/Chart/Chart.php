<?php
declare(strict_types=1);
namespace BaurPDF\Chart;

/**
 * Построитель графиков → HTML-тег <chart>.
 *   echo Chart::bar(['Янв','Фев'], ['2026' => [10, 20]])->title('Продажи')->height(220);
 */
final class Chart implements \Stringable
{
    private array $cfg;
    private string $height = '220pt';
    private string $width = '100%';

    private function __construct(string $type, array $labels, array $series)
    {
        $ds = [];
        foreach ($series as $name => $data) {
            if (is_array($data) && isset($data['data'])) { $ds[] = $data + ['label' => is_string($name) ? $name : '']; continue; }
            $ds[] = ['label' => is_string($name) ? $name : '', 'data' => array_values((array)$data)];
        }
        $this->cfg = ['type' => $type, 'data' => ['labels' => array_values($labels), 'datasets' => $ds], 'options' => []];
    }

    public static function bar(array $labels, array $series): self { return new self('bar', $labels, self::series($series)); }
    public static function hbar(array $labels, array $series): self { return new self('hbar', $labels, self::series($series)); }
    public static function stacked(array $labels, array $series): self { $c = new self('bar', $labels, self::series($series)); $c->cfg['options']['stacked'] = true; return $c; }
    public static function line(array $labels, array $series): self { return new self('line', $labels, self::series($series)); }
    public static function area(array $labels, array $series): self { return new self('area', $labels, self::series($series)); }
    /** @param array<string,float> $data метка => значение */
    public static function pie(array $data): self { return new self('pie', array_keys($data), [['data' => array_values($data)]]); }
    public static function donut(array $data, ?string $center = null): self { $c = new self('donut', array_keys($data), [['data' => array_values($data)]]); if ($center !== null) $c->cfg['options']['center'] = $center; return $c; }
    public static function gauge(float $value, float $max = 100, ?string $unit = null): self { $c = new self('gauge', [], [['data' => [$value]]]); $c->cfg['options'] += ['max' => $max, 'unit' => $unit]; return $c; }

    private static function series(array $s): array { return $s && array_is_list($s) && !is_array($s[0] ?? null) ? [['data' => $s]] : $s; }

    public function title(string $t): self { $this->cfg['options']['title'] = $t; return $this; }
    public function unit(string $u): self { $this->cfg['options']['unit'] = $u; return $this; }
    public function colors(array $c): self { $this->cfg['options']['colors'] = array_values($c); return $this; }
    public function legend(bool $on = true): self { $this->cfg['options']['legend'] = $on; return $this; }
    public function valueLabels(bool $on = true): self { $this->cfg['options']['valueLabels'] = $on; return $this; }
    public function smooth(bool $on = true): self { $this->cfg['options']['smooth'] = $on; return $this; }
    public function height(int|float|string $h): self { $this->height = is_numeric($h) ? $h . 'pt' : $h; return $this; }
    public function width(int|float|string $w): self { $this->width = is_numeric($w) ? $w . 'pt' : $w; return $this; }
    public function option(string $k, mixed $v): self { $this->cfg['options'][$k] = $v; return $this; }
    public function config(): array { return $this->cfg; }

    public function html(): string
    {
        $json = htmlspecialchars((string)json_encode($this->cfg, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8');
        return '<chart data-chart="' . $json . '" style="width:' . $this->width . ';height:' . $this->height . '"></chart>';
    }

    public function __toString(): string { return $this->html(); }
}
