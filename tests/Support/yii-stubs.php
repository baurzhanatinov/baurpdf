<?php
declare(strict_types=1);
// Минимальные заглушки Yii2 для тестов компонента без зависимости от yiisoft/yii2. Не грузятся, если Yii уже есть.

namespace yii\base {
    if (!class_exists(Component::class, false)) {
        class Component
        {
            public function __construct(array $config = []) { foreach ($config as $k => $v) $this->$k = $v; }
        }
    }
}

namespace yii\web {
    if (!class_exists(Response::class, false)) {
        class HeaderCollection
        {
            /** @var array<string, string> */
            public array $all = [];
            public function set(string $name, string $value): self { $this->all[strtolower($name)] = $value; return $this; }
            public function get(string $name): ?string { return $this->all[strtolower($name)] ?? null; }
        }
        class Response
        {
            public string $format = 'html';
            public mixed $content = null;
            private ?HeaderCollection $headers = null;
            public function getHeaders(): HeaderCollection { return $this->headers ??= new HeaderCollection(); }
        }
    }
}

namespace {
    if (!class_exists('Yii', false)) {
        class Yii
        {
            public static mixed $app = null;
            public static function getAlias(string $alias, bool $throwException = true): string|false
            {
                if ($alias === '@webroot') return sys_get_temp_dir();
                if ($alias === '@runtime') return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bp-yii-runtime';
                if (str_starts_with($alias, '@')) { if ($throwException) throw new \RuntimeException("Unknown alias $alias"); return false; }
                return $alias;
            }
        }
    }
}
