<?php

use App\Config\Config;
use App\Core\Database;
use App\Services\AuthService;

function root_path(string $path = ''): string
{
    return dirname(__DIR__, 2) . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
}

function public_path(string $path = ''): string
{
    $root = defined('PUBLIC_ROOT') ? (string) PUBLIC_ROOT : root_path('public');
    return rtrim($root, '/\\') . ($path ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
}

function base_url(): string
{
    $configured = (string) Config::get('app.url', '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/index.php'));
    return $scheme . '://' . $host . rtrim($dir, '/');
}

function url(string $path = ''): string
{
    return base_url() . ($path ? '/' . ltrim($path, '/') : '');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function view(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $viewFile = root_path('app/Views/' . $name . '.php');
    if (!is_file($viewFile)) {
        throw new RuntimeException('View not found: ' . $name);
    }
    ob_start();
    require $viewFile;
    $content = ob_get_clean();
    if (str_starts_with($name, 'auth/') || str_starts_with($name, 'errors/')) {
        require root_path('app/Views/layouts/auth.php');
    } else {
        require root_path('app/Views/layouts/app.php');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function auth(): ?array
{
    return AuthService::user();
}

function can(string $permission): bool
{
    return AuthService::can($permission);
}

function flash(string $type, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$type] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$type] ?? null;
    unset($_SESSION['_flash'][$type]);
    return $value;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function validation_errors(): array
{
    $errors = $_SESSION['_errors'] ?? [];
    unset($_SESSION['_errors']);
    return $errors;
}

function money(mixed $amount): string
{
    $value = (float) $amount;
    $negative = $value < 0 ? '-' : '';
    $value = abs($value);
    $parts = explode('.', number_format($value, 2, '.', ''));
    $lastThree = substr($parts[0], -3);
    $rest = substr($parts[0], 0, -3);
    if ($rest !== '') {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',';
    }
    return $negative . '₹' . $rest . $lastThree . '.' . $parts[1];
}

function setting(string $key, mixed $default = ''): mixed
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        foreach (Database::connection()->query('SELECT `key`,`value` FROM settings')->fetchAll() as $row) {
            $settings[$row['key']] = $row['value'];
        }
    }
    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

function status_class(string $status): string
{
    $value = strtolower($status);
    if (preg_match('/paid|won|active|present|completed|approved|resolved|success/', $value)) return 'success';
    if (preg_match('/late|pending|warm|review|progress|partial|hold|medium/', $value)) return 'warning';
    if (preg_match('/lost|overdue|cancel|rejected|absent|blocked|inactive|high|urgent/', $value)) return 'danger';
    return 'info';
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
