<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;
use App\Core\Database;

final class BackupService
{
    public static function create():string
    {
        $db=Database::connection();$filename='rankers_mind_crm_'.date('Ymd_His').'.sql';$path=root_path('storage/backups/'.$filename);
        $handle=fopen($path,'wb');if(!$handle)throw new \RuntimeException('Backup file could not be created.');fwrite($handle,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        $tables=$db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach($tables as $table){$create=$db->query("SHOW CREATE TABLE `{$table}`")->fetch();fwrite($handle,"DROP TABLE IF EXISTS `{$table}`;\n".$create['Create Table'].";\n");$rows=$db->query("SELECT * FROM `{$table}`");while($row=$rows->fetch()){ $values=array_map(fn($v)=>$v===null?'NULL':$db->quote((string)$v),array_values($row));fwrite($handle,"INSERT INTO `{$table}` (`".implode('`,`',array_keys($row))."`) VALUES (".implode(',',$values).");\n");}}
        fwrite($handle,"SET FOREIGN_KEY_CHECKS=1;\n");fclose($handle);$size=filesize($path)?:0;$db->prepare("INSERT INTO database_backups(filename,file_size,status,created_by) VALUES(?,?,'completed',?)")->execute([$filename,$size,auth()['id']]);return $filename;
    }
}
