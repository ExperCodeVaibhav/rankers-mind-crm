<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\BackupService;

final class SettingsController extends Controller
{
    private function guard():void{if(!can('settings.manage')){http_response_code(403);view('errors/403',['title'=>'Access denied']);exit;}}
    public function index():void{$this->guard();$db=Database::connection();$settings=$db->query('SELECT * FROM settings ORDER BY `group`,`key`')->fetchAll();$office=$db->query('SELECT * FROM office_locations ORDER BY id LIMIT 1')->fetch();$rules=$db->query('SELECT * FROM attendance_rules ORDER BY id LIMIT 1')->fetch();$backups=$db->query('SELECT b.*,u.name FROM database_backups b LEFT JOIN users u ON u.id=b.created_by ORDER BY b.created_at DESC LIMIT 10')->fetchAll();$this->render('settings/index',compact('settings','office','rules','backups')+['title'=>'Settings']);}
    public function update():void
    {
        $this->guard();$db=Database::connection();foreach((array)($_POST['settings']??[]) as $key=>$value)$db->prepare('UPDATE settings SET `value`=?,updated_by=? WHERE `key`=?')->execute([trim((string)$value),auth()['id'],$key]);if(isset($_POST['office_latitude'])){$officeId=(int)($_POST['office_id']??0);$officeValues=[trim((string)($_POST['office_name']??'Head Office')),(float)$_POST['office_latitude'],(float)$_POST['office_longitude'],max(10,(int)$_POST['radius_metres'])];if($officeId>0){$db->prepare('UPDATE office_locations SET name=?,latitude=?,longitude=?,radius_metres=?,is_active=1 WHERE id=?')->execute([...$officeValues,$officeId]);}else{$db->prepare("INSERT INTO office_locations(branch_id,name,latitude,longitude,radius_metres,is_active) VALUES((SELECT id FROM branches ORDER BY id LIMIT 1),?,?,?,?,1)")->execute($officeValues);}}if(isset($_POST['rule_id']))$db->prepare('UPDATE attendance_rules SET grace_minutes=?,half_day_minutes=?,full_day_minutes=?,overtime_after_minutes=?,max_accuracy_metres=?,require_geofence=?,require_photo=?,late_fine_type=?,late_fine_amount=?,max_monthly_late_deduction=? WHERE id=?')->execute([(int)$_POST['grace_minutes'],(int)$_POST['half_day_minutes'],(int)$_POST['full_day_minutes'],(int)$_POST['overtime_after_minutes'],(int)$_POST['max_accuracy_metres'],isset($_POST['require_geofence'])?1:0,isset($_POST['require_photo'])?1:0,$_POST['late_fine_type'],(float)$_POST['late_fine_amount'],(float)$_POST['max_monthly_late_deduction'],$_POST['rule_id']]);flash('success','Settings saved.');$this->redirect('settings');
    }
    public function roles():void{$this->guard();$db=Database::connection();$roles=$db->query('SELECT * FROM roles ORDER BY id')->fetchAll();$permissions=$db->query('SELECT * FROM permissions ORDER BY module,action')->fetchAll();$grants=$db->query('SELECT role_id,permission_id FROM role_permissions')->fetchAll();$matrix=[];foreach($grants as $g)$matrix[$g['role_id']][$g['permission_id']]=true;$this->render('settings/roles',compact('roles','permissions','matrix')+['title'=>'Roles & Permissions']);}
    public function saveRoles():void{$this->guard();$db=Database::connection();$grants=$_POST['permissions']??[];$db->beginTransaction();try{foreach($db->query("SELECT id FROM roles WHERE slug<>'super-admin'")->fetchAll() as $role){$db->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$role['id']]);$insert=$db->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)');foreach((array)($grants[$role['id']]??[]) as $permissionId)$insert->execute([$role['id'],(int)$permissionId]);}$db->commit();flash('success','Permission matrix updated.');}catch(\Throwable $e){$db->rollBack();flash('error',$e->getMessage());}$this->redirect('settings/roles');}
    public function backup():void{$this->guard();try{$name=BackupService::create();flash('success','Database backup created: '.$name);}catch(\Throwable $e){flash('error',$e->getMessage());}$this->redirect('settings');}
}
