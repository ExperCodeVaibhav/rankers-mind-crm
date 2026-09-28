<?php

use App\Config\Env;

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => Env::get('DB_PORT', 3306),
    'database' => Env::get('DB_DATABASE', 'rankers_mind_crm'),
    'username' => Env::get('DB_USERNAME', 'root'),
    'password' => Env::get('DB_PASSWORD', ''),
];
