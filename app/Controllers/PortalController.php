<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\NotificationService;
use App\Services\TicketWorkflowService;
use App\Services\UploadService;

final class PortalController extends Controller
{
    public function staff():void
    {
        if(auth()['user_type']!=='staff')$this->redirect('dashboard');$db=Database::connection();$q=$db->prepare('SELECT s.*,d.name department_name,g.name designation_name FROM staff_profiles s LEFT JOIN departments d ON d.id=s.department_id LEFT JOIN designations g ON g.id=s.designation_id WHERE s.user_id=?');$q->execute([auth()['id']]);$staff=$q->fetch();if(!$staff)throw new \RuntimeException('Staff profile not found.');$q=$db->prepare('SELECT * FROM attendance WHERE staff_id=? AND attendance_date=CURDATE()');$q->execute([$staff['id']]);$today=$q->fetch();$q=$db->prepare("SELECT t.*,p.name project_name FROM tasks t JOIN task_assignees ta ON ta.task_id=t.id LEFT JOIN projects p ON p.id=t.project_id WHERE ta.user_id=? AND t.deleted_at IS NULL AND t.status<>'Completed' ORDER BY t.due_date LIMIT 8");$q->execute([auth()['id']]);$tasks=$q->fetchAll();$q=$db->prepare("SELECT status,COUNT(*) total FROM attendance WHERE staff_id=? AND MONTH(attendance_date)=MONTH(CURDATE()) AND YEAR(attendance_date)=YEAR(CURDATE()) GROUP BY status");$q->execute([$staff['id']]);$attendance=[];foreach($q->fetchAll() as $row)$attendance[$row['status']]=$row['total'];$q=$db->prepare('SELECT pe.*,pp.period_month FROM payroll_entries pe JOIN payroll_periods pp ON pp.id=pe.payroll_period_id WHERE pe.staff_id=? ORDER BY pp.period_month DESC LIMIT 1');$q->execute([$staff['id']]);$salary=$q->fetch();$q=$db->prepare('SELECT lb.*,lt.name FROM leave_balances lb JOIN leave_types lt ON lt.id=lb.leave_type_id WHERE lb.staff_id=? AND lb.year=YEAR(CURDATE())');$q->execute([$staff['id']]);$leaveBalances=$q->fetchAll();$notifications=NotificationService::unread((int)auth()['id']);$this->render('portal/staff',compact('staff','today','tasks','attendance','salary','leaveBalances','notifications')+['title'=>'Staff Portal']);
    }
    public function client():void
    {
        if(auth()['user_type']!=='client')$this->redirect('dashboard');$db=Database::connection();$client=$this->clientRecord();$q=$db->prepare('SELECT * FROM projects WHERE client_id=? AND deleted_at IS NULL ORDER BY expected_completion_date');$q->execute([$client['id']]);$projects=$q->fetchAll();$q=$db->prepare("SELECT * FROM invoices WHERE client_id=? AND deleted_at IS NULL AND status<>'Draft' ORDER BY invoice_date DESC");$q->execute([$client['id']]);$invoices=$q->fetchAll();$q=$db->prepare('SELECT * FROM tickets WHERE client_id=? AND deleted_at IS NULL ORDER BY created_at DESC');$q->execute([$client['id']]);$tickets=$q->fetchAll();$q=$db->prepare('SELECT * FROM client_documents WHERE client_id=? AND deleted_at IS NULL ORDER BY created_at DESC');$q->execute([$client['id']]);$documents=$q->fetchAll();$q=$db->prepare('SELECT pay.*,r.receipt_number,i.invoice_number FROM payments pay JOIN invoices i ON i.id=pay.invoice_id LEFT JOIN payment_receipts r ON r.payment_id=pay.id WHERE i.client_id=? AND pay.deleted_at IS NULL ORDER BY pay.payment_date DESC');$q->execute([$client['id']]);$payments=$q->fetchAll();$q=$db->prepare("SELECT * FROM announcements WHERE is_active=1 AND audience IN ('all','clients') AND starts_at<=NOW() AND (ends_at IS NULL OR ends_at>=NOW()) ORDER BY created_at DESC");$q->execute();$announcements=$q->fetchAll();$this->render('portal/client',compact('client','projects','invoices','payments','tickets','documents','announcements')+['title'=>'Client Portal']);
    }
    public function clientProject(string $id):void
    {
        $client=$this->clientRecord();$db=Database::connection();$q=$db->prepare('SELECT p.* FROM projects p WHERE p.id=? AND p.client_id=? AND p.deleted_at IS NULL');$q->execute([(int)$id,$client['id']]);$project=$q->fetch();if(!$project){http_response_code(404);view('errors/404',['title'=>'Project not found']);return;}$q=$db->prepare('SELECT pm.role_name,u.name FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? AND pm.is_client_visible=1');$q->execute([$id]);$members=$q->fetchAll();$q=$db->prepare('SELECT * FROM project_milestones WHERE project_id=? ORDER BY due_date');$q->execute([$id]);$milestones=$q->fetchAll();$q=$db->prepare('SELECT pu.*,u.name FROM project_updates pu JOIN users u ON u.id=pu.user_id WHERE pu.project_id=? AND pu.is_client_visible=1 ORDER BY pu.created_at DESC');$q->execute([$id]);$updates=$q->fetchAll();$q=$db->prepare('SELECT * FROM project_files WHERE project_id=? AND is_client_visible=1 AND deleted_at IS NULL ORDER BY created_at DESC');$q->execute([$id]);$files=$q->fetchAll();$this->render('portal/project',compact('project','members','milestones','updates','files')+['title'=>$project['name']]);
    }
    public function ticket(string $id):void
    {
        $client=$this->clientRecord();$db=Database::connection();$q=$db->prepare('SELECT t.*,p.name project_name FROM tickets t LEFT JOIN projects p ON p.id=t.project_id WHERE t.id=? AND t.client_id=? AND t.deleted_at IS NULL');$q->execute([(int)$id,$client['id']]);$ticket=$q->fetch();if(!$ticket){http_response_code(404);view('errors/404',['title'=>'Ticket not found']);return;}$q=$db->prepare('SELECT m.*,u.name,u.user_type FROM ticket_messages m JOIN users u ON u.id=m.user_id WHERE m.ticket_id=? AND m.is_internal=0 AND m.deleted_at IS NULL ORDER BY m.created_at');$q->execute([(int)$id]);$messages=$q->fetchAll();$this->render('portal/ticket',compact('ticket','messages')+['title'=>$ticket['ticket_number']]);
    }
    public function createTicket():void
    {
        $client = $this->clientRecord();
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($subject === '' || $description === '') {
            flash('error', 'Subject and description are required.');
            $this->redirect('portal/client#support');
        }

        $db = Database::connection();
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $projectManagerId = (int) ($client['project_manager_id'] ?? 0);
        if ($projectId) {
            $ownership = $db->prepare('SELECT project_manager_id FROM projects WHERE id=? AND client_id=? AND deleted_at IS NULL');
            $ownership->execute([$projectId, $client['id']]);
            $project = $ownership->fetch();
            if (!$project) {
                flash('error', 'Select a project that belongs to your client account.');
                $this->redirect('portal/client#support');
            }
            $projectManagerId = $projectManagerId ?: (int) ($project['project_manager_id'] ?? 0);
        }
        $projectManagerId = $projectManagerId ?: (int) ($client['account_manager_id'] ?? 0);
        $supportStaffId = (int) ($client['support_staff_id'] ?? 0);
        $category = in_array($_POST['category'] ?? '', ['General','Technical','Billing','Project','Content','Access'], true) ? (string) $_POST['category'] : 'General';
        $priority = in_array($_POST['priority'] ?? '', ['Low','Medium','High','Urgent'], true) ? (string) $_POST['priority'] : 'Medium';

        try {
            $created = Database::transaction(function (\PDO $connection) use ($client, $projectId, $projectManagerId, $supportStaffId, $subject, $description, $category, $priority): array {
                $last = $connection->query("SELECT ticket_number FROM tickets WHERE ticket_number LIKE 'RM-TKT-%' ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
                $next = $last ? (int) substr((string) $last, strlen('RM-TKT-')) + 1 : 1;
                $number = 'RM-TKT-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);

                $recipientIds = array_values(array_unique(array_filter([$projectManagerId, $supportStaffId])));
                $activeRecipients = [];
                if ($recipientIds) {
                    $placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
                    $recipients = $connection->prepare("SELECT id,user_type FROM users WHERE id IN ({$placeholders}) AND status='active' AND deleted_at IS NULL");
                    $recipients->execute($recipientIds);
                    foreach ($recipients->fetchAll() as $recipient) {
                        $activeRecipients[(int) $recipient['id']] = $recipient['user_type'];
                    }
                }

                $assignedTo = isset($activeRecipients[$supportStaffId]) && $activeRecipients[$supportStaffId] === 'staff'
                    ? $supportStaffId
                    : (isset($activeRecipients[$projectManagerId]) && $activeRecipients[$projectManagerId] === 'staff' ? $projectManagerId : null);
                $status = $assignedTo ? 'Assigned' : 'Open';
                $slaHours = ['Urgent'=>4, 'High'=>8, 'Medium'=>24, 'Low'=>48][$priority];
                $statement = $connection->prepare("INSERT INTO tickets(ticket_number,client_id,project_id,subject,category,priority,description,assigned_to,status,response_due_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL ? HOUR),?)");
                $statement->execute([$number, $client['id'], $projectId ?: null, mb_substr($subject, 0, 190), $category, $priority, $description, $assignedTo, $status, $slaHours, auth()['id']]);
                $ticketId = (int) $connection->lastInsertId();

                $title = 'New support ticket '.$number;
                $message = mb_substr($client['company_name'].' raised a '.$priority.' priority ticket: '.$subject, 0, 500);
                foreach (array_keys($activeRecipients) as $recipientId) {
                    NotificationService::send($recipientId, 'ticket_created', $title, $message, '/tickets/'.$ticketId);
                }
                return ['id'=>$ticketId, 'number'=>$number];
            });
            flash('success', 'Support ticket '.$created['number'].' created and the assigned team was notified.');
            $this->redirect('portal/tickets/'.$created['id']);
        } catch (\Throwable $error) {
            flash('error', 'The support ticket could not be created. Please try again.');
            $this->redirect('portal/client#support');
        }
    }
    public function ticketConfirmation(string $id):void
    {
        $this->clientRecord();
        $answer=(string)($_POST['resolved']??'');
        if(!in_array($answer,['yes','no'],true)){flash('error','Please confirm whether your issue is resolved.');$this->redirect('portal/tickets/'.$id);}
        try{
            $status=TicketWorkflowService::confirm((int)$id,(int)auth()['id'],$answer==='yes',trim((string)($_POST['client_note']??'')));
            flash('success',$status==='Closed'?'Thank you. The ticket is now closed.':'The ticket has been reopened and the concerned team was notified.');
        }catch(\Throwable $error){flash('error',$error->getMessage());}
        $this->redirect('portal/tickets/'.$id);
    }
    public function uploadDocument():void
    {
        $client=$this->clientRecord();$title=trim($_POST['title']??'');if($title===''){flash('error','Document title is required.');$this->redirect('portal/client');}try{$path=UploadService::store($_FILES['document']??[],'private_uploads');Database::connection()->prepare('INSERT INTO client_documents(client_id,title,file_path,is_client_visible,uploaded_by) VALUES(?,?,?,1,?)')->execute([$client['id'],$title,$path,auth()['id']]);flash('success','Document uploaded securely.');}catch(\Throwable $e){flash('error',$e->getMessage());}$this->redirect('portal/client#documents');
    }
    public function staffProfile():void
    {
        if(auth()['user_type']!=='staff'){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$name=trim($_POST['name']??'');$phone=trim($_POST['phone']??'');if($name===''||$phone===''){flash('error','Name and phone are required.');$this->redirect('portal/staff#profile');}$db=Database::connection();$db->prepare('UPDATE users SET name=?,phone=? WHERE id=?')->execute([mb_substr($name,0,150),mb_substr($phone,0,30),auth()['id']]);$db->prepare('UPDATE staff_profiles SET emergency_contact=?,address=?,work_location=? WHERE user_id=? AND deleted_at IS NULL')->execute([mb_substr(trim($_POST['emergency_contact']??''),0,30),trim($_POST['address']??''),mb_substr(trim($_POST['work_location']??''),0,150),auth()['id']]);flash('success','Your staff profile was updated.');$this->redirect('portal/staff#profile');
    }
    public function clientProfile():void
    {
        $client=$this->clientRecord();$contact=trim($_POST['primary_contact']??'');$phone=trim($_POST['phone']??'');if($contact===''||$phone===''){flash('error','Contact name and phone are required.');$this->redirect('portal/client#profile');}$db=Database::connection();$db->prepare('UPDATE clients SET primary_contact=?,phone=?,billing_address=?,service_address=?,city=?,state=? WHERE id=?')->execute([mb_substr($contact,0,150),mb_substr($phone,0,30),trim($_POST['billing_address']??''),trim($_POST['service_address']??''),mb_substr(trim($_POST['city']??''),0,100),mb_substr(trim($_POST['state']??''),0,100),$client['id']]);$db->prepare('UPDATE users SET name=?,phone=? WHERE id=?')->execute([mb_substr($contact,0,150),mb_substr($phone,0,30),auth()['id']]);flash('success','Your client profile was updated.');$this->redirect('portal/client#profile');
    }
    public function clientDocument(string $id):void
    {
        $client=$this->clientRecord();$q=Database::connection()->prepare('SELECT * FROM client_documents WHERE id=? AND client_id=? AND deleted_at IS NULL');$q->execute([(int)$id,$client['id']]);$file=$q->fetch();$this->streamFile($file);
    }
    public function projectFile(string $id):void
    {
        $client=$this->clientRecord();$q=Database::connection()->prepare('SELECT pf.* FROM project_files pf JOIN projects p ON p.id=pf.project_id WHERE pf.id=? AND p.client_id=? AND pf.is_client_visible=1 AND pf.deleted_at IS NULL');$q->execute([(int)$id,$client['id']]);$file=$q->fetch();$this->streamFile($file);
    }
    public function invoice(string $id):void
    {
        $client=$this->clientRecord();$q=Database::connection()->prepare('SELECT id FROM invoices WHERE id=? AND client_id=? AND deleted_at IS NULL');$q->execute([(int)$id,$client['id']]);if(!$q->fetch()){http_response_code(404);view('errors/404',['title'=>'Invoice not found']);return;}(new InvoiceController())->show($id);
    }
    private function clientRecord():array{if(auth()['user_type']!=='client'){http_response_code(403);view('errors/403',['title'=>'Access denied']);exit;}$q=Database::connection()->prepare("SELECT * FROM clients WHERE portal_user_id=? AND portal_enabled=1 AND status='active' AND deleted_at IS NULL");$q->execute([auth()['id']]);$client=$q->fetch();if(!$client){http_response_code(403);view('errors/403',['title'=>'Client portal unavailable']);exit;}return $client;}
    private function streamFile(array|false $file):never{if(!$file||empty($file['file_path'])||!is_file(root_path($file['file_path']))){http_response_code(404);exit('File not found.');}$path=root_path($file['file_path']);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);header('Content-Type:'.$mime);header('Content-Length:'.filesize($path));header('Content-Disposition:attachment;filename="'.preg_replace('/[^A-Za-z0-9._-]/','_',($file['title']??'document')).'.'.pathinfo($path,PATHINFO_EXTENSION).'"');readfile($path);exit;}
}
