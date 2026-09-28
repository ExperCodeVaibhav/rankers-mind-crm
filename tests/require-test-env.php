<?php

declare(strict_types=1);

// Check process variables before bootstrap can load the local installation's .env.
if (getenv('APP_ENV') !== 'testing' || !preg_match('/_test$/', (string) getenv('DB_DATABASE'))) {
    fwrite(STDERR, "Integration tests require APP_ENV=testing and a disposable DB_DATABASE ending in _test.\n");
    exit(1);
}
