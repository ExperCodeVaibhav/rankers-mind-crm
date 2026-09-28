<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class AuthService
{
    private static ?array $cachedUser = null;

    public static function attempt(string $email, string $password, bool $remember = false): array
    {
        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1');
        $statement->execute([mb_strtolower(trim($email))]);
        $user = $statement->fetch();

        if (!$user || $user['status'] !== 'active') {
            usleep(250000);
            return ['ok' => false, 'message' => 'The email or password is incorrect.'];
        }
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return ['ok' => false, 'message' => 'Too many attempts. Try again after ' . date('g:i A', strtotime($user['locked_until'])) . '.'];
        }
        if (!password_verify($password, $user['password'])) {
            $attempts = (int) $user['failed_login_attempts'] + 1;
            $lockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
            $db->prepare('UPDATE users SET failed_login_attempts=?, locked_until=? WHERE id=?')->execute([$attempts, $lockedUntil, $user['id']]);
            AuditService::log('login_failed', 'users', (int) $user['id'], [], ['email' => $email]);
            return ['ok' => false, 'message' => $lockedUntil ? 'Too many attempts. This account is locked for 15 minutes.' : 'The email or password is incorrect.'];
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['_last_activity'] = time();
        $db->prepare('UPDATE users SET failed_login_attempts=0, locked_until=NULL, last_login_at=NOW(), last_login_ip=? WHERE id=?')
            ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $user['id']]);
        self::$cachedUser = null;

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $db->prepare('INSERT INTO user_sessions (user_id,token_hash,ip_address,user_agent,expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 30 DAY))')
                ->execute([$user['id'], hash('sha256', $token), $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)]);
            setcookie('rm_remember', $token, [
                'expires' => time() + 2592000, 'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        AuditService::log('login', 'users', (int) $user['id']);
        return ['ok' => true, 'must_change_password' => (bool) $user['must_change_password']];
    }

    public static function check(): bool
    {
        if (!empty($_SESSION['user_id'])) return true;
        self::restoreRememberedSession();
        return !empty($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) return null;
        if (self::$cachedUser !== null) return self::$cachedUser;
        $db = Database::connection();
        $statement = $db->prepare("SELECT u.*, GROUP_CONCAT(DISTINCT r.slug) role_slugs, GROUP_CONCAT(DISTINCT r.name) role_names
            FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id
            WHERE u.id=? AND u.status='active' AND u.deleted_at IS NULL GROUP BY u.id");
        $statement->execute([$_SESSION['user_id']]);
        $user = $statement->fetch();
        if (!$user) {
            self::logout();
            return null;
        }
        $permissions = $db->prepare('SELECT DISTINCT p.slug FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id JOIN user_roles ur ON ur.role_id=rp.role_id WHERE ur.user_id=?');
        $permissions->execute([$user['id']]);
        $user['permissions'] = $permissions->fetchAll(PDO::FETCH_COLUMN);
        $user['roles'] = $user['role_slugs'] ? explode(',', $user['role_slugs']) : [];
        self::$cachedUser = $user;
        return $user;
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if (!$user) return false;
        if (in_array('super-admin', $user['roles'], true)) return true;
        return in_array($permission, $user['permissions'], true);
    }

    public static function hasRole(string $role): bool
    {
        return in_array($role, self::user()['roles'] ?? [], true);
    }

    public static function logout(): void
    {
        if (!empty($_COOKIE['rm_remember'])) {
            Database::connection()->prepare('DELETE FROM user_sessions WHERE token_hash=?')->execute([hash('sha256', $_COOKIE['rm_remember'])]);
            setcookie('rm_remember', '', time() - 3600, '/');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        self::$cachedUser = null;
    }

    public static function createResetToken(string $email): ?string
    {
        $db = Database::connection();
        $statement = $db->prepare("SELECT id FROM users WHERE email=? AND status='active' AND deleted_at IS NULL");
        $statement->execute([mb_strtolower(trim($email))]);
        $id = $statement->fetchColumn();
        if (!$id) return null;
        $token = bin2hex(random_bytes(32));
        $db->prepare('DELETE FROM password_resets WHERE user_id=?')->execute([$id]);
        $db->prepare('INSERT INTO password_resets (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 60 MINUTE))')
            ->execute([$id, hash('sha256', $token)]);
        return $token;
    }

    public static function resetPassword(string $token, string $password): bool
    {
        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1');
        $statement->execute([hash('sha256', $token)]);
        $reset = $statement->fetch();
        if (!$reset) return false;
        return Database::transaction(function (PDO $db) use ($reset, $password): bool {
            $db->prepare('UPDATE users SET password=?,must_change_password=0 WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
            $db->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=?')->execute([$reset['id']]);
            $db->prepare('DELETE FROM user_sessions WHERE user_id=?')->execute([$reset['user_id']]);
            return true;
        });
    }

    public static function changePassword(int $userId, string $current, string $new): array
    {
        $db = Database::connection();
        $statement = $db->prepare('SELECT password FROM users WHERE id=?');
        $statement->execute([$userId]);
        $hash = $statement->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) return ['ok'=>false,'message'=>'Current password is incorrect.'];
        $db->prepare('UPDATE users SET password=?,must_change_password=0 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT),$userId]);
        return ['ok'=>true,'message'=>'Password changed successfully.'];
    }

    private static function restoreRememberedSession(): void
    {
        $token = $_COOKIE['rm_remember'] ?? '';
        if (!$token) return;
        $db = Database::connection();
        $statement = $db->prepare('SELECT us.user_id FROM user_sessions us JOIN users u ON u.id=us.user_id WHERE us.token_hash=? AND us.expires_at>NOW() AND u.status=\'active\' LIMIT 1');
        $statement->execute([hash('sha256', $token)]);
        $id = $statement->fetchColumn();
        if ($id) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $id;
            $_SESSION['_last_activity'] = time();
        } else {
            setcookie('rm_remember', '', time() - 3600, '/');
        }
    }
}
