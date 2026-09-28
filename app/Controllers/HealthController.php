<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\HealthService;

final class HealthController
{
    public function show(): void
    {
        $result = HealthService::check();
        http_response_code($result['status'] === 'ok' ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($result, JSON_THROW_ON_ERROR);
    }
}
