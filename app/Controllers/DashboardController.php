<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index():void
    {
        if(auth()['user_type']==='staff'){$this->redirect('portal/staff');}
        if(auth()['user_type']==='client'){$this->redirect('portal/client');}
        $db=Database::connection();
        $metrics=[
            'Total leads'=>[$db->query("SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL")->fetchColumn(),'leads','fa-bullseye','cyan'],
            'Hot leads'=>[$db->query("SELECT COUNT(*) FROM leads WHERE classification='Hot' AND deleted_at IS NULL")->fetchColumn(),'leads?classification=Hot','fa-fire','red'],
            'Follow-ups today'=>[$db->query("SELECT COUNT(*) FROM lead_followups WHERE DATE(scheduled_at)=CURDATE() AND status='Scheduled' AND deleted_at IS NULL")->fetchColumn(),'followups','fa-calendar-day','orange'],
            'Overdue follow-ups'=>[$db->query("SELECT COUNT(*) FROM lead_followups WHERE scheduled_at<NOW() AND status='Scheduled' AND deleted_at IS NULL")->fetchColumn(),'followups?status=Scheduled','fa-clock','red'],
            'Active clients'=>[$db->query("SELECT COUNT(*) FROM clients WHERE status='active' AND deleted_at IS NULL")->fetchColumn(),'clients?status=active','fa-building','navy'],
            'Active projects'=>[$db->query("SELECT COUNT(*) FROM projects WHERE status='Active' AND deleted_at IS NULL")->fetchColumn(),'projects?status=Active','fa-diagram-project','cyan'],
            'Open tasks'=>[$db->query("SELECT COUNT(*) FROM tasks WHERE status NOT IN ('Completed') AND deleted_at IS NULL")->fetchColumn(),'tasks','fa-list-check','navy'],
            'Overdue tasks'=>[$db->query("SELECT COUNT(*) FROM tasks WHERE due_date<CURDATE() AND status<>'Completed' AND deleted_at IS NULL")->fetchColumn(),'tasks','fa-triangle-exclamation','red'],
            'Present today'=>[$db->query("SELECT COUNT(*) FROM attendance WHERE attendance_date=CURDATE() AND status IN ('Present','Late')")->fetchColumn(),'attendance','fa-user-check','green'],
            'Pending payments'=>[$db->query("SELECT COALESCE(SUM(pending_amount),0) FROM invoices WHERE status IN ('Sent','Partially Paid','Overdue') AND deleted_at IS NULL")->fetchColumn(),'invoices','fa-indian-rupee-sign','orange','money'],
            'Payments received'=>[$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE deleted_at IS NULL AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetchColumn(),'payments','fa-circle-check','green','money'],
            'Open complaints'=>[$db->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('Resolved','Closed') AND deleted_at IS NULL")->fetchColumn(),'tickets','fa-headset','red'],
        ];
        $followups=$db->query("SELECT f.*,l.person_name,u.name assigned_name FROM lead_followups f LEFT JOIN leads l ON l.id=f.lead_id LEFT JOIN users u ON u.id=f.assigned_to WHERE f.status='Scheduled' AND f.deleted_at IS NULL ORDER BY f.scheduled_at LIMIT 7")->fetchAll();
        $tasks=$db->query("SELECT t.*,p.name project_name FROM tasks t LEFT JOIN projects p ON p.id=t.project_id WHERE t.status<>'Completed' AND t.deleted_at IS NULL ORDER BY t.due_date LIMIT 7")->fetchAll();
        $activities=$db->query("SELECT a.*,u.name user_name FROM activity_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 8")->fetchAll();
        $tickets=$db->query("SELECT t.*,c.company_name FROM tickets t JOIN clients c ON c.id=t.client_id WHERE t.status NOT IN ('Resolved','Closed') AND t.deleted_at IS NULL ORDER BY FIELD(t.priority,'Urgent','High','Medium','Low'),t.created_at DESC LIMIT 6")->fetchAll();
        $leadStages=$db->query("SELECT stage label,COUNT(*) value FROM leads WHERE deleted_at IS NULL GROUP BY stage")->fetchAll();
        $projectStatuses=$db->query("SELECT status label,COUNT(*) value FROM projects WHERE deleted_at IS NULL GROUP BY status")->fetchAll();
        $invoiceTrend=$db->query("SELECT DATE_FORMAT(invoice_date,'%b') label,SUM(grand_total) invoiced,SUM(paid_amount) received FROM invoices WHERE invoice_date>=DATE_SUB(CURDATE(),INTERVAL 5 MONTH) AND deleted_at IS NULL GROUP BY YEAR(invoice_date),MONTH(invoice_date) ORDER BY YEAR(invoice_date),MONTH(invoice_date)")->fetchAll();
        $this->render('dashboard/index',compact('metrics','followups','tasks','activities','tickets','leadStages','projectStatuses','invoiceTrend')+['title'=>'Executive Dashboard']);
    }
}
