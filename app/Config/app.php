<?php

use App\Config\Env;

return [
    'name' => Env::get('APP_NAME', 'Rankers Mind CRM'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::get('APP_DEBUG', false),
    'url' => rtrim((string) Env::get('APP_URL', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Kolkata'),
    'session_lifetime' => Env::get('SESSION_LIFETIME', 120),
    'upload_max_mb' => Env::get('UPLOAD_MAX_MB', 5),
];
