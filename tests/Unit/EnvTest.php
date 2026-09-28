<?php

declare(strict_types=1);

use App\Config\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testProcessEnvironmentOverridesDotenv(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'crm_env_');
        self::assertNotFalse($file);
        $key = 'CRM_TEST_' . bin2hex(random_bytes(8));
        try {
            file_put_contents($file, "# comment\n{$key}=\"file value\"\n");
            Env::load($file);
            self::assertSame('file value', Env::get($key));
            putenv($key . '=process value');
            self::assertSame('process value', Env::get($key));
        } finally {
            putenv($key);
            unset($_ENV[$key]);
            unlink($file);
        }
    }

    public function testTypedDefaultsAndMissingFile(): void
    {
        $key = 'CRM_TEST_' . bin2hex(random_bytes(8));
        try {
            Env::load(__DIR__ . '/does-not-exist.env');
            self::assertSame('fallback', Env::get($key, 'fallback'));
            putenv($key . '=false');
            self::assertFalse(Env::get($key, true));
            putenv($key . '=42');
            self::assertSame(42, Env::get($key, 0));
        } finally {
            putenv($key);
        }
    }
}
