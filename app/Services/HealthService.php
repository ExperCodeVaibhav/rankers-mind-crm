<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class HealthService
{
    /** @return array{status: string} */
    public static function check(?callable $probe = null): array
    {
        $probe ??= static fn(): bool => (int) Database::connection()->query('SELECT 1')->fetchColumn() === 1;
        try {
            return ['status' => $probe() === true ? 'ok' : 'unavailable'];
        } catch (\Throwable $exception) {
            // Never expose credentials, queries or exception text in a public probe.
            return ['status' => 'unavailable'];
        }
    }
}
