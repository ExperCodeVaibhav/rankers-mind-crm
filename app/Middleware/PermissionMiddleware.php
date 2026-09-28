<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;

final class PermissionMiddleware
{
    public function handle(?string $permission = null): void
    {
        if (!$permission || !AuthService::can($permission)) {
            http_response_code(403);
            view('errors/403', ['title' => 'Access denied']);
            exit;
        }
    }
}
