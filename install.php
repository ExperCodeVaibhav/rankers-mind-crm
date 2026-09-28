<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this installer from the command line.');
}

require __DIR__ . '/app/Config/Env.php';

use App\Config\Env;

Env::load(__DIR__ . '/.env');

$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = (int) Env::get('DB_PORT', 3306);
$database = (string) Env::get('DB_DATABASE', 'rankers_mind_crm');
$username = (string) Env::get('DB_USERNAME', 'root');
$password = (string) Env::get('DB_PASSWORD', '');

if (!preg_match('/^[A-Za-z0-9_$]+$/', $database)) {
    throw new RuntimeException('DB_DATABASE contains unsupported characters.');
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$database}`");

echo "Importing database/schema.sql...\n";
$pdo->exec((string) file_get_contents(__DIR__ . '/database/schema.sql'));

echo "Importing production-safe system defaults...\n";
$pdo->exec((string) file_get_contents(__DIR__ . '/database/seed.sql'));

$superAdminCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM users u "
    . "JOIN user_roles ur ON ur.user_id=u.id "
    . "JOIN roles r ON r.id=ur.role_id "
    . "WHERE r.slug='super-admin' AND u.status='active' AND u.deleted_at IS NULL "
    . "AND u.email<>'admin@rankersmind.local'"
)->fetchColumn();

if ($superAdminCount === 0) {
    $adminName = trim((string) Env::get('INITIAL_ADMIN_NAME', ''));
    $adminEmail = mb_strtolower(trim((string) Env::get('INITIAL_ADMIN_EMAIL', '')));
    $adminPassword = (string) Env::get('INITIAL_ADMIN_PASSWORD', '');
    $adminPhone = trim((string) Env::get('INITIAL_ADMIN_PHONE', ''));
    $placeholderPassword = str_starts_with($adminPassword, 'replace-with-');
    $placeholderEmail = str_ends_with($adminEmail, '@example.com') || str_ends_with($adminEmail, '@rankersmind.local');

    if (
        $adminName === ''
        || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
        || $placeholderEmail
        || mb_strlen($adminPassword) < 12
        || $placeholderPassword
    ) {
        throw new RuntimeException(
            'A production Super Admin is required. Set INITIAL_ADMIN_NAME, a real INITIAL_ADMIN_EMAIL, '
            . 'and a unique INITIAL_ADMIN_PASSWORD of at least 12 characters in .env, then rerun the installer.'
        );
    }

    $legacyAdmin = $pdo->query(
        "SELECT id FROM users WHERE email='admin@rankersmind.local' ORDER BY id LIMIT 1"
    )->fetchColumn();

    $pdo->beginTransaction();
    try {
        if ($legacyAdmin !== false) {
            $statement = $pdo->prepare(
                "UPDATE users SET branch_id=(SELECT id FROM branches WHERE code='HO' LIMIT 1),"
                . "name=?,email=?,phone=?,password=?,user_type='admin',status='active',"
                . "must_change_password=1,email_verified_at=NOW(),deleted_at=NULL WHERE id=?"
            );
            $statement->execute([
                $adminName,
                $adminEmail,
                $adminPhone !== '' ? $adminPhone : null,
                password_hash($adminPassword, PASSWORD_DEFAULT),
                (int) $legacyAdmin,
            ]);
            $adminId = (int) $legacyAdmin;
            $adminAction = 'rotated from the former development account';
        } else {
            $statement = $pdo->prepare(
                "INSERT INTO users (branch_id,name,email,phone,password,user_type,status,must_change_password,email_verified_at) "
                . "VALUES ((SELECT id FROM branches WHERE code='HO' LIMIT 1),?,?,?,?, 'admin','active',1,NOW())"
            );
            $statement->execute([
                $adminName,
                $adminEmail,
                $adminPhone !== '' ? $adminPhone : null,
                password_hash($adminPassword, PASSWORD_DEFAULT),
            ]);
            $adminId = (int) $pdo->lastInsertId();
            $adminAction = 'created';
        }

        $pdo->prepare("INSERT IGNORE INTO user_roles (user_id,role_id) SELECT ?,id FROM roles WHERE slug='super-admin'")
            ->execute([$adminId]);
        $pdo->commit();
        echo "Production Super Admin {$adminAction}: {$adminEmail}\n";
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
} else {
    echo "Existing production Super Admin preserved.\n";
}

$migrationFiles = glob(__DIR__ . '/database/migrations/*.sql') ?: [];
sort($migrationFiles);
foreach ($migrationFiles as $migrationFile) {
    echo 'Applying '.basename($migrationFile)."...\n";
    $pdo->exec((string) file_get_contents($migrationFile));
}

$verifiedSuperAdminCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM users u "
    . "JOIN user_roles ur ON ur.user_id=u.id "
    . "JOIN roles r ON r.id=ur.role_id "
    . "WHERE r.slug='super-admin' AND u.status='active' AND u.deleted_at IS NULL"
)->fetchColumn();
if ($verifiedSuperAdminCount === 0) {
    throw new RuntimeException('Installation stopped because no active Super Admin remains.');
}

echo "Rankers Mind CRM installed successfully with no development or mock business data.\n";
echo "Open: " . Env::get('APP_URL', 'http://localhost/rankers-mind-crm/public') . "\n";
