<?php

declare(strict_types=1);

namespace App\Config;

final class Config
{
    private static array $items = [];

    public static function load(string $root): void
    {
        self::$items['app'] = require $root . '/app/Config/app.php';
        self::$items['database'] = require $root . '/app/Config/database.php';
        self::$items['entities'] = require $root . '/app/Config/entities.php';
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
