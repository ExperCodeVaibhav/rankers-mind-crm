<?php

declare(strict_types=1);

use App\Services\HealthService;
use PHPUnit\Framework\TestCase;

final class HealthServiceTest extends TestCase
{
    public function testHealthyDatabase(): void
    {
        self::assertSame(['status' => 'ok'], HealthService::check(static fn(): bool => true));
    }

    public function testFailedProbe(): void
    {
        self::assertSame(['status' => 'unavailable'], HealthService::check(static fn(): bool => false));
    }

    public function testConnectionFailureDoesNotExposeDetails(): void
    {
        $result = HealthService::check(static function (): void {
            throw new RuntimeException('Private database diagnostic');
        });
        self::assertSame(['status' => 'unavailable'], $result);
        self::assertStringNotContainsString('Private', json_encode($result, JSON_THROW_ON_ERROR));
    }
}
