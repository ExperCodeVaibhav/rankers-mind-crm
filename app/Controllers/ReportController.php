<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

final class ReportController extends Controller
{
    public function index(): void
    {
        if (!can('reports.view')) {
            http_response_code(403);
            view('errors/403', ['title' => 'Access denied']);
            return;
        }

        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');
        $filters = ['staff_id'=>(int)($_GET['staff_id']??0),'client_id'=>(int)($_GET['client_id']??0),'project_id'=>(int)($_GET['project_id']??0),'status'=>trim($_GET['status']??'')];
        $db = Database::connection();
        $staffOptions=$db->query("SELECT s.id,u.name FROM staff_profiles s JOIN users u ON u.id=s.user_id WHERE s.deleted_at IS NULL ORDER BY u.name")->fetchAll();
        $clientOptions=$db->query("SELECT id,company_name name FROM clients WHERE deleted_at IS NULL ORDER BY company_name")->fetchAll();
        $projectOptions=$db->query("SELECT id,name FROM projects WHERE deleted_at IS NULL ORDER BY name")->fetchAll();
        $reports = [];
        $reports['lead_funnel'] = $db->query("SELECT stage label, COUNT(*) value FROM leads WHERE deleted_at IS NULL GROUP BY stage ORDER BY value DESC")->fetchAll();
        $reports['sources'] = $db->query("SELECT COALESCE(source,'Unspecified') label, COUNT(*) value, SUM(stage='Won') won FROM leads WHERE deleted_at IS NULL GROUP BY source ORDER BY value DESC")->fetchAll();
        $financeWhere=['deleted_at IS NULL','invoice_date BETWEEN ? AND ?'];$financeParams=[$from,$to];if($filters['client_id']){$financeWhere[]='client_id=?';$financeParams[]=$filters['client_id'];}if($filters['project_id']){$financeWhere[]='project_id=?';$financeParams[]=$filters['project_id'];}if($filters['status']){$financeWhere[]='status=?';$financeParams[]=$filters['status'];}$q = $db->prepare("SELECT DATE_FORMAT(invoice_date,'%Y-%m') month, SUM(grand_total) invoiced, SUM(paid_amount) received, SUM(pending_amount) outstanding FROM invoices WHERE ".implode(' AND ',$financeWhere)." GROUP BY YEAR(invoice_date), MONTH(invoice_date) ORDER BY month");
        $q->execute($financeParams);
        $reports['finance'] = $q->fetchAll();
        $projectWhere=['deleted_at IS NULL'];$projectParams=[];if($filters['client_id']){$projectWhere[]='client_id=?';$projectParams[]=$filters['client_id'];}if($filters['status']){$projectWhere[]='status=?';$projectParams[]=$filters['status'];}$q=$db->prepare("SELECT status label, COUNT(*) value, SUM(estimated_budget) budget, SUM(actual_cost) cost FROM projects WHERE ".implode(' AND ',$projectWhere).' GROUP BY status');$q->execute($projectParams);$reports['projects']=$q->fetchAll();
        $attendanceWhere=['a.attendance_date BETWEEN ? AND ?'];$attendanceParams=[$from,$to];if($filters['staff_id']){$attendanceWhere[]='a.staff_id=?';$attendanceParams[]=$filters['staff_id'];}if($filters['status']){$attendanceWhere[]='a.status=?';$attendanceParams[]=$filters['status'];}$q = $db->prepare('SELECT a.attendance_date,u.name,a.status,a.check_in_at,a.check_out_at,a.late_minutes,a.working_minutes FROM attendance a JOIN staff_profiles s ON s.id=a.staff_id JOIN users u ON u.id=s.user_id WHERE '.implode(' AND ',$attendanceWhere).' ORDER BY a.attendance_date DESC,u.name');
        $q->execute($attendanceParams);
        $reports['attendance'] = $q->fetchAll();
        $this->render('reports/index', compact('reports', 'from', 'to','filters','staffOptions','clientOptions','projectOptions') + ['title' => 'Reports Centre']);
    }

    public function export(): void
    {
        if (!can('reports.export')) {
            http_response_code(403);
            exit;
        }
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');
        [$type, $rows] = $this->reportRows($_GET['type'] ?? 'attendance', $from, $to, $_GET);
        header('Content-Type:text/csv;charset=utf-8');
        header('Content-Disposition:attachment;filename="' . $type . '_' . $from . '_to_' . $to . '.csv"');
        $out = fopen('php://output', 'wb');
        if ($rows) {
            fputcsv($out, array_keys($rows[0]));
        }
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    public function print(): void
    {
        if (!can('reports.export')) {
            http_response_code(403);
            view('errors/403', ['title' => 'Access denied']);
            return;
        }
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');
        [$type, $rows] = $this->reportRows($_GET['type'] ?? 'attendance', $from, $to, $_GET);
        $title = ucfirst($type) . ' Report';
        ob_start();
        require root_path('app/Views/documents/report.php');
        $html = (string) ob_get_clean();

        if (($_GET['format'] ?? '') === 'pdf' && class_exists('Dompdf\\Dompdf')) {
            $pdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
            $pdf->loadHtml($html);
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            $pdf->stream($type . '_' . $from . '_to_' . $to . '.pdf', ['Attachment' => true]);
            exit;
        }
        header('Content-Type:text/html;charset=utf-8');
        echo $html;
        exit;
    }

    private function reportRows(string $type, string $from, string $to, array $filters=[]): array
    {
        $queries = [
            'attendance' => 'SELECT a.attendance_date,u.name,s.employee_code,a.status,a.check_in_at,a.check_out_at,a.late_minutes,a.working_minutes FROM attendance a JOIN staff_profiles s ON s.id=a.staff_id JOIN users u ON u.id=s.user_id WHERE a.attendance_date BETWEEN ? AND ? ORDER BY a.attendance_date,u.name',
            'leads' => 'SELECT lead_code,person_name,company_name,phone,email,service_interest,source,stage,classification,score,expected_value,created_at FROM leads WHERE deleted_at IS NULL AND DATE(created_at) BETWEEN ? AND ? ORDER BY created_at',
            'payments' => 'SELECT p.payment_number,i.invoice_number,c.company_name,p.amount,p.payment_date,p.payment_mode,p.reference_number FROM payments p JOIN invoices i ON i.id=p.invoice_id JOIN clients c ON c.id=i.client_id WHERE p.deleted_at IS NULL AND p.payment_date BETWEEN ? AND ? ORDER BY p.payment_date',
            'payroll' => 'SELECT pp.period_month,s.employee_code,u.name,pe.monthly_salary,pe.present_days,pe.absent_days,pe.late_count,pe.gross_pay,pe.total_deduction,pe.net_pay FROM payroll_entries pe JOIN payroll_periods pp ON pp.id=pe.payroll_period_id JOIN staff_profiles s ON s.id=pe.staff_id JOIN users u ON u.id=s.user_id WHERE pp.period_month BETWEEN ? AND ? ORDER BY pp.period_month,u.name',
        ];
        if (!isset($queries[$type])) {
            $type = 'attendance';
        }
        $sql=$queries[$type];$params=[$from,$to];$staffId=(int)($filters['staff_id']??0);$clientId=(int)($filters['client_id']??0);$projectId=(int)($filters['project_id']??0);$status=trim($filters['status']??'');
        if($type==='attendance'){if($staffId){$sql=str_replace(' ORDER BY',' AND a.staff_id=? ORDER BY',$sql);$params[]=$staffId;}if($status){$sql=str_replace(' ORDER BY',' AND a.status=? ORDER BY',$sql);$params[]=$status;}}
        if($type==='leads'&&$status){$sql=str_replace(' ORDER BY',' AND stage=? ORDER BY',$sql);$params[]=$status;}
        if($type==='payments'){if($clientId){$sql=str_replace(' ORDER BY',' AND i.client_id=? ORDER BY',$sql);$params[]=$clientId;}if($projectId){$sql=str_replace(' ORDER BY',' AND i.project_id=? ORDER BY',$sql);$params[]=$projectId;}}
        if($type==='payroll'&&$staffId){$sql=str_replace(' ORDER BY',' AND pe.staff_id=? ORDER BY',$sql);$params[]=$staffId;}
        $q = Database::connection()->prepare($sql);
        $q->execute($params);
        return [$type, $q->fetchAll()];
    }
}
