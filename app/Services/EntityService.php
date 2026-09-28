<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class EntityService
{
    public static function paginate(array $config, array $query, int $page = 1, int $perPage = 15): array
    {
        $db = Database::connection(); $table = $config['table'];
        $where = []; $params = [];
        if (!empty($config['soft_delete'])) $where[] = 'deleted_at IS NULL';
        if ((auth()['user_type'] ?? '') === 'staff') {
            $userId = (int) auth()['id'];
            if ($table === 'tasks') { $where[] = 'EXISTS (SELECT 1 FROM task_assignees scope_ta WHERE scope_ta.task_id=tasks.id AND scope_ta.user_id=?)'; $params[] = $userId; }
            elseif ($table === 'projects') { $where[] = 'EXISTS (SELECT 1 FROM project_members scope_pm WHERE scope_pm.project_id=projects.id AND scope_pm.user_id=?)'; $params[] = $userId; }
            elseif (in_array($table, ['leave_requests','salary_advances','attendance_correction_requests'], true)) { $where[] = 'EXISTS (SELECT 1 FROM staff_profiles scope_sp WHERE scope_sp.id='.$table.'.staff_id AND scope_sp.user_id=?)'; $params[] = $userId; }
            elseif ($table === 'tickets') {
                $where[] = '(assigned_to=?
                    OR EXISTS (SELECT 1 FROM clients scope_c WHERE scope_c.id=tickets.client_id AND scope_c.project_manager_id=? AND scope_c.deleted_at IS NULL)
                    OR EXISTS (SELECT 1 FROM projects scope_p WHERE scope_p.id=tickets.project_id AND scope_p.project_manager_id=? AND scope_p.deleted_at IS NULL)
                    OR EXISTS (SELECT 1 FROM project_members scope_pm WHERE scope_pm.project_id=tickets.project_id AND scope_pm.user_id=?))';
                $params[] = $userId;
                $params[] = $userId;
                $params[] = $userId;
                $params[] = $userId;
            }
        }
        $search = trim((string) ($query['q'] ?? ''));
        if ($search !== '') {
            $parts = [];
            foreach ($config['search'] as $field) { $parts[] = "`{$field}` LIKE ?"; $params[] = '%' . $search . '%'; }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        foreach ($config['filters'] ?? [] as $field) {
            if (isset($query[$field]) && $query[$field] !== '') { $where[] = "`{$field}` = ?"; $params[] = $query[$field]; }
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = $db->prepare("SELECT COUNT(*) FROM `{$table}`{$whereSql}"); $count->execute($params); $total = (int) $count->fetchColumn();
        $page = max(1, min($page, max(1, (int) ceil($total / $perPage)))); $offset = ($page - 1) * $perPage;
        $sort = in_array($query['sort'] ?? '', array_merge(['id'], $config['list']), true) ? $query['sort'] : 'id';
        $direction = strtolower($query['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $statement = $db->prepare("SELECT * FROM `{$table}`{$whereSql} ORDER BY `{$sort}` {$direction} LIMIT {$perPage} OFFSET {$offset}");
        $statement->execute($params);
        return ['rows'=>$statement->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$perPage)),'per_page'=>$perPage];
    }

    public static function find(array $config, int $id): ?array
    {
        $soft = !empty($config['soft_delete']) ? ' AND deleted_at IS NULL' : '';
        $statement = Database::connection()->prepare("SELECT * FROM `{$config['table']}` WHERE id=?{$soft}"); $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public static function create(array $config, array $data): int
    {
        return Database::transaction(function (PDO $db) use ($config,$data): int {
            if (!empty($config['code'])) $data[$config['code']['field']] = self::nextCode($db,$config);
            $virtual = self::extractVirtual($config,$data);
            if ($config['table'] === 'tasks') $data['assigned_by'] = auth()['id'];
            if (array_key_exists('created_by', self::columns($db,$config['table']))) $data['created_by'] = auth()['id'];
            $fields = array_keys($data); $placeholders = implode(',',array_fill(0,count($fields),'?'));
            $db->prepare("INSERT INTO `{$config['table']}` (`" . implode('`,`',$fields) . "`) VALUES ({$placeholders})")->execute(array_values($data));
            $id = (int) $db->lastInsertId(); self::syncVirtual($db,$config,$id,$virtual);
            AuditService::log('created',$config['table'],$id,[],$data); AuditService::activity($config['permission'],'created',"Created {$config['singular']} #{$id}",$config['table'],$id);
            return $id;
        });
    }

    public static function update(array $config, int $id, array $data): void
    {
        Database::transaction(function (PDO $db) use ($config,$id,$data): void {
            $old = self::find($config,$id); if (!$old) throw new \RuntimeException('Record not found.');
            $virtual = self::extractVirtual($config,$data);
            if (array_key_exists('updated_by', self::columns($db,$config['table']))) $data['updated_by'] = auth()['id'];
            if ($data) { $sets = implode(',',array_map(fn($f)=>"`{$f}`=?",array_keys($data))); $db->prepare("UPDATE `{$config['table']}` SET {$sets} WHERE id=?")->execute([...array_values($data),$id]); }
            self::syncVirtual($db,$config,$id,$virtual); AuditService::log('updated',$config['table'],$id,$old,$data);
        });
    }

    public static function delete(array $config, int $id): void
    {
        $old = self::find($config,$id); if (!$old) return;
        $sql = !empty($config['soft_delete']) ? "UPDATE `{$config['table']}` SET deleted_at=NOW() WHERE id=?" : "DELETE FROM `{$config['table']}` WHERE id=?";
        Database::connection()->prepare($sql)->execute([$id]); AuditService::log('deleted',$config['table'],$id,$old,[]);
    }

    public static function relationOptions(array $config): array
    {
        $db = Database::connection(); $result=[];
        foreach ($config['fields'] as $field=>$meta) {
            if (empty($meta['relation'])) continue; $r=$meta['relation']; $where=!empty($r['where'])?' WHERE '.$r['where']:'';
            $statement=$db->query("SELECT `{$r['value']}`, `{$r['label']}` FROM `{$r['table']}`{$where} ORDER BY `{$r['label']}`");
            $result[$field]=$statement->fetchAll();
        }
        return $result;
    }

    public static function sanitize(array $config, array $input): array
    {
        $data=[];
        foreach ($config['fields'] as $field=>$meta) {
            if (($meta['type'] ?? '')==='checkbox') $data[$field]=isset($input[$field])?1:0;
            elseif (($meta['type'] ?? '')==='multiselect') $data[$field]=array_map('intval',(array)($input[$field]??[]));
            elseif (array_key_exists($field,$input)) {
                $value=is_string($input[$field])?trim($input[$field]):$input[$field];
                if ($value==='' && str_contains($meta['rules']??'','nullable')) $value=null;
                if (($meta['type']??'')==='datetime-local' && $value) $value=str_replace('T',' ',$value).(strlen($value)===16?':00':'');
                $data[$field]=$value;
            }
        }
        return $data;
    }

    private static function nextCode(PDO $db,array $config): string
    {
        $field=$config['code']['field'];$prefix=$config['code']['prefix'];$digits=$config['code']['digits'];
        $statement=$db->prepare("SELECT `{$field}` FROM `{$config['table']}` WHERE `{$field}` LIKE ? ORDER BY id DESC LIMIT 1 FOR UPDATE");$statement->execute([$prefix.'%']);
        $last=(string)$statement->fetchColumn();$number=$last?(int)substr($last,strlen($prefix))+1:1;
        return $prefix.str_pad((string)$number,$digits,'0',STR_PAD_LEFT);
    }

    private static function extractVirtual(array $config,array &$data): array
    {
        $virtual=[]; foreach($config['fields'] as $field=>$meta) if(!empty($meta['virtual'])&&array_key_exists($field,$data)){ $virtual[$field]=$data[$field];unset($data[$field]); }
        return $virtual;
    }

    private static function syncVirtual(PDO $db,array $config,int $id,array $virtual): void
    {
        if($config['table']==='tasks'&&array_key_exists('assignee_ids',$virtual)){
            $db->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$id]);
            $insert=$db->prepare('INSERT INTO task_assignees (task_id,user_id) VALUES (?,?)');
            foreach(array_unique($virtual['assignee_ids']) as $userId) if($userId){$insert->execute([$id,$userId]);NotificationService::send((int)$userId,'task_assignment','New task assignment','You were assigned to a task.','/tasks');}
        }
        if($config['table']==='projects'&&array_key_exists('member_ids',$virtual)){
            $db->prepare('DELETE FROM project_members WHERE project_id=?')->execute([$id]);
            $insert=$db->prepare("INSERT INTO project_members(project_id,user_id,role_name,is_client_visible) VALUES(?,?,'Team Member',1)");
            foreach(array_unique($virtual['member_ids']) as $userId)if($userId){$insert->execute([$id,$userId]);NotificationService::send((int)$userId,'project_assignment','New project assignment','You were added to a project team.','/projects/'.$id);}
        }
    }

    private static array $columnCache=[];
    private static function columns(PDO $db,string $table): array
    {
        if(!isset(self::$columnCache[$table])){self::$columnCache[$table]=[];foreach($db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll() as $c)self::$columnCache[$table][$c['Field']]=true;}
        return self::$columnCache[$table];
    }
}
