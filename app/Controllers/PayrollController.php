<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\PayrollService;

final class PayrollController extends Controller
{
    public function index():void
    {
        if(auth()['user_type']==='staff'){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        if(!can('payroll.view')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$db=Database::connection();$periods=$db->query('SELECT pp.*,u.name approver_name,(SELECT COUNT(*) FROM payroll_entries pe WHERE pe.payroll_period_id=pp.id) staff_count,(SELECT COALESCE(SUM(net_pay),0) FROM payroll_entries pe WHERE pe.payroll_period_id=pp.id) net_total FROM payroll_periods pp LEFT JOIN users u ON u.id=pp.approved_by ORDER BY period_month DESC')->fetchAll();$selected=(int)($_GET['period']??($periods[0]['id']??0));$q=$db->prepare('SELECT pe.*,s.employee_code,u.name FROM payroll_entries pe JOIN staff_profiles s ON s.id=pe.staff_id JOIN users u ON u.id=s.user_id WHERE pe.payroll_period_id=? ORDER BY u.name');$q->execute([$selected]);$entries=$q->fetchAll();$this->render('payroll/index',compact('periods','selected','entries')+['title'=>'Payroll']);
    }
    public function generate():void{if(auth()['user_type']!=='admin'||!can('payroll.create')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}try{$id=PayrollService::generate($_POST['month']??date('Y-m'),max(1,(int)($_POST['working_days']??26)));flash('success','Payroll calculated from attendance and configured deduction rules.');$this->redirect('payroll?period='.$id);}catch(\Throwable $e){flash('error',$e->getMessage());$this->redirect('payroll');}}
    public function status(string $id):void
    {
        if(auth()['user_type']!=='admin'){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        if(!can('payroll.approve')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$allowed=['Draft','Calculated','Under Review','Approved','Paid','Locked'];$status=$_POST['status']??'';if(!in_array($status,$allowed,true)){flash('error','Invalid payroll status.');$this->redirect('payroll?period='.$id);}$columns=['Approved'=>'approved_at','Paid'=>'paid_at','Locked'=>'locked_at'];$extra=isset($columns[$status])?', '.$columns[$status].'=NOW()':'';Database::connection()->prepare("UPDATE payroll_periods SET status=?,approved_by=?{$extra} WHERE id=?")->execute([$status,auth()['id'],(int)$id]);flash('success','Payroll status changed to '.$status.'.');$this->redirect('payroll?period='.$id);
    }
    public function slip(string $id):void
    {
        $db=Database::connection();$sql='SELECT pe.*,pp.period_month,pp.status payroll_status,s.employee_code,s.user_id,u.name,u.email,d.name department_name,g.name designation_name FROM payroll_entries pe JOIN payroll_periods pp ON pp.id=pe.payroll_period_id JOIN staff_profiles s ON s.id=pe.staff_id JOIN users u ON u.id=s.user_id LEFT JOIN departments d ON d.id=s.department_id LEFT JOIN designations g ON g.id=s.designation_id WHERE pe.id=?';$q=$db->prepare($sql);$q->execute([(int)$id]);$entry=$q->fetch();if(!$entry||(!can('payroll.view')&&(int)$entry['user_id']!==(int)auth()['id'])){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}ob_start();require root_path('app/Views/documents/salary-slip.php');$html=(string)ob_get_clean();if(($_GET['format']??'')==='pdf'&&class_exists('Dompdf\\Dompdf')){$pdf=new \Dompdf\Dompdf();$pdf->loadHtml($html);$pdf->setPaper('A4');$pdf->render();$pdf->stream('salary-slip-'.date('Y-m',strtotime($entry['period_month'])).'.pdf',['Attachment'=>true]);exit;}header('Content-Type:text/html;charset=utf-8');echo $html;exit;
    }
}
