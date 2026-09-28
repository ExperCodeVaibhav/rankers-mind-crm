<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [$root . '/install.php'];
foreach (['app', 'routes', 'cron', 'public', 'tests', 'tools'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root . '/' . $directory,
        FilesystemIterator::SKIP_DOTS
    ));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);
$failures = 0;
foreach ($files as $file) {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
        $failures++;
    }
}
printf("PHP syntax: %d files checked, %d failures.\n", count($files), $failures);
exit($failures > 0 ? 1 : 0);
