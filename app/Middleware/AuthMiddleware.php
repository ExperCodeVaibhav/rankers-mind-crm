<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuthService;

final class AuthMiddleware
{
    public function handle(?string $argument = null): void
    {
        if (!AuthService::check()) {
            flash('error', 'Please sign in to continue.');
            redirect('login');
        }
        if ((time() - (int) ($_SESSION['_last_activity'] ?? time())) > ((int) \App\Config\Config::get('app.session_lifetime', 120) * 60)) {
            AuthService::logout();
            flash('error', 'Your session expired. Please sign in again.');
            redirect('login');
        }
        $_SESSION['_last_activity'] = time();
    }
}
