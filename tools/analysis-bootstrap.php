<?php

declare(strict_types=1);

// Static analysis must not load .env, open sessions, or connect to the live DB.
define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_ROOT', APP_ROOT . '/public');
