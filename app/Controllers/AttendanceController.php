<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\AuditService;
use App\Services\UploadService;

final class AttendanceController extends Controller
{
    public function index():void
    {
        if(!can('attendance.view')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$date=$_GET['date']??date('Y-m-d');$db=Database::connection();$q=$db->prepare('SELECT a.*,s.employee_code,u.name,d.name department_name FROM attendance a JOIN staff_profiles s ON s.id=a.staff_id JOIN users u ON u.id=s.user_id LEFT JOIN departments d ON d.id=s.department_id WHERE a.attendance_date=? ORDER BY a.check_in_at');$q->execute([$date]);$rows=$q->fetchAll();$summary=[];$s=$db->prepare('SELECT status,COUNT(*) total FROM attendance WHERE attendance_date=? GROUP BY status');$s->execute([$date]);foreach($s->fetchAll() as $row)$summary[$row['status']]=$row['total'];$this->render('attendance/index',compact('date','rows','summary')+['title'=>'Attendance']);
    }
    public function punchPage():void
    {
        $staff=$this->staff();$q=Database::connection()->prepare('SELECT * FROM attendance WHERE staff_id=? AND attendance_date=CURDATE()');$q->execute([$staff['id']]);$today=$q->fetch();$this->render('attendance/punch',compact('staff','today')+['title'=>'Punch Attendance']);
    }
    public function punch():void
    {
        try{$staff=$this->staff();$lat=(float)($_POST['latitude']??0);$lng=(float)($_POST['longitude']??0);$accuracy=(float)($_POST['accuracy']??99999);if(!$lat||!$lng)throw new \RuntimeException('Location is required to punch attendance.');$db=Database::connection();$office=$db->query('SELECT * FROM office_locations WHERE is_active=1 ORDER BY id LIMIT 1')->fetch();$rules=$db->query('SELECT * FROM attendance_rules ORDER BY id LIMIT 1')->fetch();if(!$office)throw new \RuntimeException('No active office location is configured.');$distance=$this->distance($lat,$lng,(float)$office['latitude'],(float)$office['longitude']);if($rules&&$rules['require_geofence']&&$distance>(float)$office['radius_metres'])throw new \RuntimeException('You are '.round($distance).' m from the configured office; the allowed radius is '.$office['radius_metres'].' m.');if($accuracy>(float)($rules['max_accuracy_metres']??300))throw new \RuntimeException('Location accuracy is currently ±'.round($accuracy).' m. Move near a window and retry.');
            $q=$db->prepare('SELECT * FROM attendance WHERE staff_id=? AND attendance_date=CURDATE() FOR UPDATE');$q->execute([$staff['id']]);$record=$q->fetch();$photo=UploadService::storeBase64Image($_POST['photo']??'');$now=date('Y-m-d H:i:s');$shiftQ=$db->prepare('SELECT sh.* FROM staff_profiles s LEFT JOIN shifts sh ON sh.id=s.shift_id WHERE s.id=?');$shiftQ->execute([$staff['id']]);$shift=$shiftQ->fetch();
            if(!$record){$start=strtotime(date('Y-m-d').' '.($shift['start_time']??'10:00:00'));$late=max(0,(int)floor((time()-$start)/60)-(int)($shift['grace_minutes']??10));$status=$late>0?'Late':'Present';$db->prepare('INSERT INTO attendance(staff_id,attendance_date,check_in_at,check_in_photo,check_in_latitude,check_in_longitude,location_accuracy,distance_from_office,ip_address,user_agent,status,late_minutes) VALUES(?,CURDATE(),?,?,?,?,?,?,?,?,?,?)')->execute([$staff['id'],$now,$photo,$lat,$lng,$accuracy,$distance,$_SERVER['REMOTE_ADDR']??null,substr($_SERVER['HTTP_USER_AGENT']??'',0,500),$status,$late]);$message='Checked in successfully at '.date('g:i A').'.';}
            elseif(!$record['check_out_at']){$minutes=max(0,(int)floor((time()-strtotime($record['check_in_at']))/60));$overtime=max(0,$minutes-(int)($shift['overtime_after_minutes']??540));$status=$minutes<(int)($shift['half_day_minutes']??240)?'Half Day':$record['status'];$db->prepare('UPDATE attendance SET check_out_at=?,check_out_photo=?,check_out_latitude=?,check_out_longitude=?,location_accuracy=?,working_minutes=?,overtime_minutes=?,status=? WHERE id=?')->execute([$now,$photo,$lat,$lng,$accuracy,$minutes,$overtime,$status,$record['id']]);$message='Checked out successfully at '.date('g:i A').'.';}else throw new \RuntimeException('Today’s check-in and check-out are already complete.');
            AuditService::activity('attendance','punch',$message,'attendance',(int)($record['id']??$db->lastInsertId()));$this->json(['ok'=>true,'message'=>$message]);
        }catch(\Throwable $e){$this->json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function photo(string $id,string $type):void
    {
        if(!can('attendance.view')&&auth()['user_type']!=='staff'){http_response_code(403);exit;}$q=Database::connection()->prepare('SELECT a.*,s.user_id FROM attendance a JOIN staff_profiles s ON s.id=a.staff_id WHERE a.id=?');$q->execute([(int)$id]);$record=$q->fetch();if(!$record||(auth()['user_type']==='staff'&&(int)$record['user_id']!==(int)auth()['id'])){http_response_code(404);exit;}$path=$record[$type==='out'?'check_out_photo':'check_in_photo']??null;if(!$path||!is_file(root_path($path))){http_response_code(404);exit;}$mime=(new \finfo(FILEINFO_MIME_TYPE))->file(root_path($path));header('Content-Type:'.$mime);header('Content-Disposition:inline;filename="attendance-photo.jpg"');readfile(root_path($path));exit;
    }
    private function staff():array{$q=Database::connection()->prepare('SELECT * FROM staff_profiles WHERE user_id=? AND employment_status=\'active\' AND deleted_at IS NULL');$q->execute([auth()['id']]);$staff=$q->fetch();if(!$staff)throw new \RuntimeException('An active staff profile is required.');return $staff;}
    private function distance(float $lat1,float $lon1,float $lat2,float $lon2):float{$earth=6371000;$dLat=deg2rad($lat2-$lat1);$dLon=deg2rad($lon2-$lon1);$a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;return $earth*2*atan2(sqrt($a),sqrt(1-$a));}
}
