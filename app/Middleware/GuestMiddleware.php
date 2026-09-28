<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;

final class GuestMiddleware
{
    public function handle(?string $argument = null): void
    {
        if (AuthService::check()) redirect('dashboard');
    }
}
