<?php

declare(strict_types=1);

define('PUBLIC_ROOT', __DIR__);

require dirname(__DIR__) . '/app/bootstrap.php';

$router = new \App\Core\Router();
require APP_ROOT . '/routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
