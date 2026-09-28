<?php

declare(strict_types=1);

use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testParametersQueryStringsAndSubdirectoryInstall(): void
    {
        $previous = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/crm/public/index.php';
        try {
            $router = new Router();
            $received = null;
            $router->get('/clients/{id}', static function (string $id) use (&$received): void {
                $received = $id;
            });
            $router->dispatch('GET', '/crm/public/clients/42/?tab=projects');
            self::assertSame('42', $received);
        } finally {
            if ($previous === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $previous;
            }
        }
    }

    public function testPostRouteSelectsTheCorrectHandler(): void
    {
        $router = new Router();
        $received = '';
        $router->get('/record', static function () use (&$received): void {
            $received = 'get';
        });
        $router->post('/record', static function () use (&$received): void {
            $received = 'post';
        });
        $router->dispatch('POST', '/record');
        self::assertSame('post', $received);
    }
}
