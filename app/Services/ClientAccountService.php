<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class ClientAccountService
{
    public static function create(array $config, array $data, array $portal): int
    {
        return Database::transaction(function (PDO $db) use ($config, $data, $portal): int {
            $portalUserId = null;
            if (!empty($data['portal_enabled'])) {
                $portalUserId = self::createPortalUser($db, $data, $portal);
            }

            $data['client_code'] = self::nextCode($db, $config);
            $data['branch_id'] = auth()['branch_id'] ?: null;
            $data['portal_user_id'] = $portalUserId;
            $data['created_by'] = auth()['id'];
            $id = self::insert($db, 'clients', $data);

            AuditService::log('created', 'clients', $id, [], self::safeAuditData($data, $portal));
            AuditService::activity('clients', 'created', 'Created client '.$data['client_code'].' with '.($portalUserId ? 'portal access' : 'no portal access'), 'clients', $id);
            return $id;
        });
    }

    public static function update(int $id, array $data, array $portal): void
    {
        Database::transaction(function (PDO $db) use ($id, $data, $portal): void {
            $query = $db->prepare('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $query->execute([$id]);
            $old = $query->fetch();
            if (!$old) {
                throw new \RuntimeException('Client record was not found.');
            }

            $portalUserId = $old['portal_user_id'] ? (int) $old['portal_user_id'] : null;
            if (!empty($data['portal_enabled'])) {
                if ($portalUserId) {
                    $sets = ['name=?', 'email=?', 'phone=?', 'status=?', 'must_change_password=?', 'updated_by=?'];
                    $values = [
                        $data['primary_contact'], $portal['email'], $data['phone'], $portal['status'],
                        $portal['must_change_password'], auth()['id'],
                    ];
                    if ($portal['password'] !== '') {
                        $sets[] = 'password=?';
                        $values[] = password_hash($portal['password'], PASSWORD_DEFAULT);
                        $sets[] = 'failed_login_attempts=0';
                        $sets[] = 'locked_until=NULL';
                    }
                    $values[] = $portalUserId;
                    $db->prepare('UPDATE users SET '.implode(',', $sets).' WHERE id=? AND user_type=\'client\'')->execute($values);
                    self::assignClientRole($db, $portalUserId);
                } else {
                    $portalUserId = self::createPortalUser($db, $data, $portal);
                }
            } elseif ($portalUserId) {
                $db->prepare("UPDATE users SET status='inactive',updated_by=? WHERE id=? AND user_type='client'")
                    ->execute([auth()['id'], $portalUserId]);
            }

            $data['portal_user_id'] = $portalUserId;
            $data['updated_by'] = auth()['id'];
            self::updateRow($db, 'clients', $id, $data);
            AuditService::log('updated', 'clients', $id, $old, self::safeAuditData($data, $portal));
            AuditService::activity('clients', 'updated', 'Updated client '.$old['client_code'].' and portal access', 'clients', $id);
        });
    }

    public static function delete(int $id): void
    {
        Database::transaction(function (PDO $db) use ($id): void {
            $query = $db->prepare('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $query->execute([$id]);
            $client = $query->fetch();
            if (!$client) {
                return;
            }
            $db->prepare('UPDATE clients SET portal_enabled=0,deleted_at=NOW(),updated_by=? WHERE id=?')->execute([auth()['id'], $id]);
            if ($client['portal_user_id']) {
                $db->prepare("UPDATE users SET status='inactive',updated_by=? WHERE id=? AND user_type='client'")
                    ->execute([auth()['id'], $client['portal_user_id']]);
            }
            AuditService::log('deleted', 'clients', $id, $client, []);
            AuditService::activity('clients', 'deleted', 'Archived client '.$client['client_code'].' and disabled its portal login', 'clients', $id);
        });
    }

    private static function createPortalUser(PDO $db, array $data, array $portal): int
    {
        $db->prepare("INSERT INTO users(branch_id,name,email,phone,password,user_type,status,must_change_password,created_by)
            VALUES(?,?,?,?,?,'client',?,?,?)")
            ->execute([
                auth()['branch_id'] ?: null,
                $data['primary_contact'],
                $portal['email'],
                $data['phone'],
                password_hash($portal['password'], PASSWORD_DEFAULT),
                $portal['status'],
                $portal['must_change_password'],
                auth()['id'],
            ]);
        $userId = (int) $db->lastInsertId();
        self::assignClientRole($db, $userId);
        return $userId;
    }

    private static function assignClientRole(PDO $db, int $userId): void
    {
        $db->prepare("INSERT IGNORE INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE slug='client'")
            ->execute([$userId]);
    }

    private static function nextCode(PDO $db, array $config): string
    {
        $field = $config['code']['field'];
        $prefix = $config['code']['prefix'];
        $digits = $config['code']['digits'];
        $query = $db->prepare("SELECT `{$field}` FROM clients WHERE `{$field}` LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $query->execute([$prefix.'%']);
        $last = (string) $query->fetchColumn();
        $number = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix.str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
    }

    private static function insert(PDO $db, string $table, array $data): int
    {
        $fields = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $db->prepare("INSERT INTO `{$table}` (`".implode('`,`', $fields)."`) VALUES ({$placeholders})")
            ->execute(array_values($data));
        return (int) $db->lastInsertId();
    }

    private static function updateRow(PDO $db, string $table, int $id, array $data): void
    {
        $sets = implode(',', array_map(static fn(string $field): string => "`{$field}`=?", array_keys($data)));
        $db->prepare("UPDATE `{$table}` SET {$sets} WHERE id=?")->execute([...array_values($data), $id]);
    }

    private static function safeAuditData(array $data, array $portal): array
    {
        return $data + [
            'portal_login_email' => $portal['email'] ?: null,
            'portal_account_status' => $portal['status'],
            'portal_password_changed' => $portal['password'] !== '',
        ];
    }
}
