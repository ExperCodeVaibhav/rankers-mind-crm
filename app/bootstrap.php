<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
if (!defined('PUBLIC_ROOT')) define('PUBLIC_ROOT', APP_ROOT . '/public');

require APP_ROOT . '/app/Config/Env.php';
\App\Config\Env::load(APP_ROOT . '/.env');

if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) return;
        $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    });
}

require APP_ROOT . '/app/Helpers/functions.php';

\App\Config\Config::load(APP_ROOT);
date_default_timezone_set((string) \App\Config\Config::get('app.timezone', 'Asia/Kolkata'));

foreach (['storage/logs', 'storage/cache', 'storage/private_uploads', 'storage/attendance_photos', 'storage/invoices', 'storage/salary_slips', 'storage/backups'] as $directory) {
    $path = APP_ROOT . '/' . $directory;
    if (!is_dir($path)) mkdir($path, 0775, true);
}
$publicUploads = rtrim((string) PUBLIC_ROOT, '/\\') . '/uploads';
if (!is_dir($publicUploads)) mkdir($publicUploads, 0775, true);

$forwardedProtocols = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$forwardedProtocol = strtolower(trim($forwardedProtocols[0] ?? ''));
$detectedSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProtocol === 'https' || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
$secure = (bool) \App\Config\Env::get('SESSION_SECURE', $detectedSecure);
session_name('rankers_mind_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

set_exception_handler(static function (Throwable $exception): void {
    $message = sprintf("[%s] %s in %s:%d\n%s\n", date('c'), $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception->getTraceAsString());
    $logDirectory = APP_ROOT . '/storage/logs';
    $logFile = $logDirectory . '/app.log';
    $written = (is_dir($logDirectory) || @mkdir($logDirectory, 0775, true)) && is_writable($logDirectory)
        ? error_log($message, 3, $logFile)
        : false;
    if (!$written) error_log($message);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message);
        exit(1);
    }
    http_response_code(500);
    if (\App\Config\Config::get('app.debug', false)) {
        echo '<pre style="padding:24px;white-space:pre-wrap">' . htmlspecialchars($message, ENT_QUOTES) . '</pre>';
    } else {
        echo '<h1>Something went wrong</h1><p>The error was logged. Please contact the administrator.</p>';
    }
});
