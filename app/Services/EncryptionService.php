<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

final class EncryptionService
{
    public static function encrypt(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;
        $key = hash('sha256', (string) Env::get('APP_KEY', 'development-only-key'), true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(trim($value), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) throw new \RuntimeException('Sensitive value encryption failed.');
        return base64_encode($iv . $encrypted);
    }
}
