<?php

declare(strict_types=1);

namespace App\Config;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            self::$values[$key] = $value;
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $processValue = getenv($key);
        $value = $processValue !== false ? $processValue : (self::$values[$key] ?? $_ENV[$key] ?? null);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }
        if (is_int($default)) {
            return (int) $value;
        }
        return $value;
    }
}
