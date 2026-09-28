<?php

declare(strict_types=1);

require __DIR__ . '/require-test-env.php';
$base = rtrim((string) getenv('APP_URL'), '/');
if ($base === '') {
    throw new RuntimeException('APP_URL is required.');
}
$checks = [
    ['GET', '/health', 200, '"status":"ok"'],
    ['GET', '/login', 200, 'name="_token"'],
    ['POST', '/login', 403, null],
    ['GET', '/dashboard', 302, null],
    ['GET', '/.env', 403, null],
    ['GET', '/assets/css/app.css', 200, null],
];
foreach ($checks as [$method, $path, $expected, $content]) {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $status !== $expected || ($content !== null && !str_contains($body, $content))) {
        fwrite(STDERR, "FAIL {$method} {$path}: expected {$expected}, got {$status}. {$error}\n");
        exit(1);
    }
    echo "PASS {$method} {$path} ({$status})\n";
}
echo count($checks) . " HTTP checks passed.\n";
