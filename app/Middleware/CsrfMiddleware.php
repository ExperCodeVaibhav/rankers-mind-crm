<?php

declare(strict_types=1);

namespace App\Middleware;

final class CsrfMiddleware
{
    public function handle(?string $argument = null): void
    {
        $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$token || !hash_equals($_SESSION['_csrf'] ?? '', (string) $token)) {
            http_response_code(403);
            json_response(['ok' => false, 'message' => 'Your session token expired. Refresh the page and try again.'], 403);
        }
    }
}
