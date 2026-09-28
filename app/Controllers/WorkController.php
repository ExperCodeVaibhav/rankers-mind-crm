<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\NotificationService;
use App\Services\TicketWorkflowService;
use App\Services\UploadService;

final class WorkController extends Controller
{
    public function taskComment(string $id):void{$this->taskAccess((int)$id);$text=trim($_POST['comment']??'');if($text!==''){Database::connection()->prepare('INSERT INTO task_comments(task_id,user_id,comment) VALUES(?,?,?)')->execute([(int)$id,auth()['id'],$text]);flash('success','Comment added.');}$this->redirect('tasks/'.$id);}
    public function taskUpdate(string $id):void{$this->taskAccess((int)$id);$text=trim($_POST['update_text']??'');$progress=max(0,min(100,(int)($_POST['progress']??0)));if($text!==''){Database::connection()->prepare('INSERT INTO task_updates(task_id,user_id,update_text,progress) VALUES(?,?,?,?)')->execute([(int)$id,auth()['id'],$text,$progress]);Database::connection()->prepare("UPDATE tasks SET progress=?,status=IF(?=100,'Under Review',IF(status='To Do','In Progress',status)) WHERE id=? AND NOT (approval_status='Approved' AND ?<100)")->execute([$progress,$progress,(int)$id,$progress]);flash('success','Daily work update posted.');}$this->redirect('tasks/'.$id);}
    public function taskChecklist(string $id):void
    {
        $this->taskAccess((int)$id);$item=trim($_POST['item']??'');if($item===''){flash('error','Checklist item is required.');$this->redirect('tasks/'.$id);}Database::connection()->prepare('INSERT INTO task_checklists(task_id,item) VALUES(?,?)')->execute([(int)$id,mb_substr($item,0,255)]);flash('success','Checklist item added.');$this->redirect('tasks/'.$id);
    }
    public function taskChecklistToggle(string $id,string $itemId):void
    {
        $this->taskAccess((int)$id);$completed=isset($_POST['completed'])?1:0;$q=Database::connection()->prepare('UPDATE task_checklists SET is_completed=?,completed_by=IF(?=1,?,NULL),completed_at=IF(?=1,NOW(),NULL) WHERE id=? AND task_id=?');$q->execute([$completed,$completed,auth()['id'],$completed,(int)$itemId,(int)$id]);flash('success','Checklist updated.');$this->redirect('tasks/'.$id);
    }
    public function taskTime(string $id):void
    {
        $this->taskAccess((int)$id);$minutes=(int)($_POST['minutes']??0);$date=$_POST['work_date']??'';if($minutes<1||$minutes>1440||strtotime($date)===false){flash('error','Enter a valid work date and 1 to 1440 minutes.');$this->redirect('tasks/'.$id);}$db=Database::connection();$db->prepare('INSERT INTO task_time_logs(task_id,user_id,work_date,minutes,note) VALUES(?,?,?,?,?)')->execute([(int)$id,auth()['id'],$date,$minutes,mb_substr(trim($_POST['note']??''),0,255)]);$db->prepare('UPDATE tasks SET actual_hours=(SELECT ROUND(COALESCE(SUM(minutes),0)/60,2) FROM task_time_logs WHERE task_id=?) WHERE id=?')->execute([(int)$id,(int)$id]);flash('success','Working time logged.');$this->redirect('tasks/'.$id);
    }
    public function taskFile(string $id):void
    {
        $this->taskAccess((int)$id);try{$path=UploadService::store($_FILES['attachment']??[],'private_uploads');$absolute=root_path($path);Database::connection()->prepare("INSERT INTO file_uploads(user_id,related_type,related_id,original_name,stored_name,path,mime_type,size_bytes,is_private) VALUES(?,'task',?,?,?,?,?,?,1)")->execute([auth()['id'],(int)$id,mb_substr((string)($_FILES['attachment']['name']??'attachment'),0,255),basename($path),$path,(new \finfo(FILEINFO_MIME_TYPE))->file($absolute),filesize($absolute)]);flash('success','Task attachment uploaded securely.');}catch(\Throwable $e){flash('error',$e->getMessage());}$this->redirect('tasks/'.$id);
    }
    public function taskFileDownload(string $id):void
    {
        $q=Database::connection()->prepare("SELECT * FROM file_uploads WHERE id=? AND related_type='task' AND deleted_at IS NULL");$q->execute([(int)$id]);$file=$q->fetch();if(!$file){http_response_code(404);exit('File not found.');}$this->taskAccess((int)$file['related_id']);$this->stream($file['path'],$file['original_name']);
    }
    public function projectUpdate(string $id):void
    {
        $this->projectAccess((int)$id);$title=trim($_POST['title']??'');$details=trim($_POST['details']??'');$progress=max(0,min(100,(int)($_POST['progress']??0)));if($title===''||$details===''){flash('error','Update title and details are required.');$this->redirect('projects/'.$id);}$visible=can('projects.edit')&&isset($_POST['is_client_visible'])?1:0;$db=Database::connection();$db->prepare('INSERT INTO project_updates(project_id,user_id,title,details,progress,is_client_visible) VALUES(?,?,?,?,?,?)')->execute([(int)$id,auth()['id'],$title,$details,$progress,$visible]);$db->prepare("UPDATE projects SET progress=? WHERE id=? AND status NOT IN('Completed','Cancelled')")->execute([$progress,(int)$id]);flash('success','Project update posted'.($visible?' and shared with the client.':'.'));$this->redirect('projects/'.$id);
    }
    public function projectMilestone(string $id):void
    {
        if(!can('projects.edit')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$title=trim($_POST['title']??'');$due=$_POST['due_date']??'';if($title===''||strtotime($due)===false){flash('error','Milestone title and due date are required.');$this->redirect('projects/'.$id);}Database::connection()->prepare("INSERT INTO project_milestones(project_id,title,description,due_date,status) VALUES(?,?,?,?,'Pending')")->execute([(int)$id,$title,trim($_POST['description']??''),$due]);flash('success','Project milestone added.');$this->redirect('projects/'.$id);
    }
    public function projectFile(string $id):void
    {
        $this->projectAccess((int)$id);$title=trim($_POST['title']??'');if($title===''){flash('error','File title is required.');$this->redirect('projects/'.$id);}try{$path=UploadService::store($_FILES['attachment']??[],'private_uploads');$visible=can('projects.edit')&&isset($_POST['is_client_visible'])?1:0;Database::connection()->prepare('INSERT INTO project_files(project_id,title,file_path,is_client_visible,uploaded_by) VALUES(?,?,?,?,?)')->execute([(int)$id,mb_substr($title,0,190),$path,$visible,auth()['id']]);flash('success','Project file uploaded'.($visible?' and shared with the client.':'.'));}catch(\Throwable $e){flash('error',$e->getMessage());}$this->redirect('projects/'.$id);
    }
    public function projectFileDownload(string $id):void
    {
        $q=Database::connection()->prepare('SELECT * FROM project_files WHERE id=? AND deleted_at IS NULL');$q->execute([(int)$id]);$file=$q->fetch();if(!$file){http_response_code(404);exit('File not found.');}$this->projectAccess((int)$file['project_id']);$this->stream($file['file_path'],$file['title'].'.'.pathinfo($file['file_path'],PATHINFO_EXTENSION));
    }
    public function leadActivity(string $id):void
    {
        if(!can('leads.edit')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$type=$_POST['activity_type']??'note';$allowed=['note','call','meeting','email','whatsapp'];$details=trim($_POST['details']??'');if(!in_array($type,$allowed,true)||$details===''){flash('error','Choose an activity type and enter details.');$this->redirect('leads/'.$id);}Database::connection()->prepare('INSERT INTO lead_activities(lead_id,user_id,activity_type,subject,details,happened_at) VALUES(?,?,?,?,?,NOW())')->execute([(int)$id,auth()['id'],$type,mb_substr(trim($_POST['subject']??''),0,190),$details]);flash('success','Lead activity recorded.');$this->redirect('leads/'.$id);
    }
    public function leadFile(string $id):void
    {
        if(!can('leads.edit')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$title=trim($_POST['title']??'');if($title===''){flash('error','Attachment title is required.');$this->redirect('leads/'.$id);}try{$path=UploadService::store($_FILES['attachment']??[],'private_uploads');Database::connection()->prepare('INSERT INTO lead_attachments(lead_id,title,file_path,uploaded_by) VALUES(?,?,?,?)')->execute([(int)$id,mb_substr($title,0,190),$path,auth()['id']]);flash('success','Lead attachment uploaded securely.');}catch(\Throwable $e){flash('error',$e->getMessage());}$this->redirect('leads/'.$id);
    }
    public function leadFileDownload(string $id):void
    {
        if(!can('leads.view')){http_response_code(403);exit;}$q=Database::connection()->prepare('SELECT * FROM lead_attachments WHERE id=? AND deleted_at IS NULL');$q->execute([(int)$id]);$file=$q->fetch();if(!$file){http_response_code(404);exit('File not found.');}$this->stream($file['file_path'],$file['title'].'.'.pathinfo($file['file_path'],PATHINFO_EXTENSION));
    }
    public function leadTag(string $id):void
    {
        if(!can('leads.edit')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$name=trim($_POST['tag']??'');if($name===''){flash('error','Tag name is required.');$this->redirect('leads/'.$id);}$db=Database::connection();$db->prepare('INSERT INTO tags(name) VALUES(?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([mb_substr($name,0,80)]);$tagId=(int)$db->lastInsertId();$db->prepare('INSERT IGNORE INTO lead_tags(lead_id,tag_id) VALUES(?,?)')->execute([(int)$id,$tagId]);flash('success','Lead tag added.');$this->redirect('leads/'.$id);
    }
    public function ticketReply(string $id):void
    {
        $db = Database::connection();
        $ticket = TicketWorkflowService::find((int) $id);
        $user = auth();
        $allowed = $ticket && (
            ($user['user_type'] === 'client' && (int) $ticket['portal_user_id'] === (int) $user['id'] && (int) $ticket['portal_enabled'] === 1 && $ticket['client_status'] === 'active')
            || TicketWorkflowService::canHandle($ticket, $user)
        );
        if (!$allowed) {
            http_response_code(403);
            view('errors/403', ['title'=>'Access denied']);
            return;
        }

        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message !== '') {
            if ($ticket['status'] === 'Closed') {
                flash('error', 'This ticket is closed after client confirmation.');
                $this->redirect($user['user_type'] === 'client' ? 'portal/tickets/'.$id : 'tickets/'.$id);
            }
            if ($user['user_type'] === 'client' && $ticket['status'] === 'Resolved' && $ticket['client_confirmation_status'] === 'Pending') {
                flash('error', 'Please answer the resolution confirmation before posting another reply.');
                $this->redirect('portal/tickets/'.$id);
            }
            $internal = $user['user_type'] !== 'client' && can('tickets.edit') && isset($_POST['is_internal']) ? 1 : 0;
            $db->prepare('INSERT INTO ticket_messages(ticket_id,user_id,message,is_internal) VALUES(?,?,?,?)')->execute([(int) $id, $user['id'], $message, $internal]);
            if ($user['user_type'] === 'client') {
                $status = in_array($ticket['status'], ['Resolved','Closed'], true) ? 'Reopened' : ($ticket['status'] === 'Waiting for Client' ? 'In Progress' : $ticket['status']);
            } else {
                $status = $_POST['status'] ?? $ticket['status'];
            }
            $allowedStatuses = ['Open','Assigned','In Progress','Waiting for Client','Reopened'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = $ticket['status'];
            }
            $db->prepare("UPDATE tickets SET status=?,resolved_at=IF(?='Resolved',NOW(),IF(?='Reopened',NULL,resolved_at)) WHERE id=?")->execute([$status, $status, $status, (int) $id]);

            if ($user['user_type'] === 'client') {
                TicketWorkflowService::notifyTeam($ticket, 'ticket_client_reply', 'Client reply on '.$ticket['ticket_number'], 'The client posted a new reply on this support ticket.');
            } elseif ($ticket['portal_user_id'] && !$internal) {
                NotificationService::send((int) $ticket['portal_user_id'], 'ticket_reply', 'Reply on '.$ticket['ticket_number'], 'A new reply was posted on your support ticket.', '/portal/tickets/'.$id);
            }
            flash('success', 'Ticket reply posted.');
        }
        $this->redirect($user['user_type'] === 'client' ? 'portal/tickets/'.$id : 'tickets/'.$id);
    }
    public function ticketResolve(string $id):void
    {
        $ticket=TicketWorkflowService::find((int)$id);$user=auth();
        if(!$ticket||!TicketWorkflowService::canHandle($ticket,$user)){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        $note=trim((string)($_POST['resolution_note']??''));
        if($note===''){flash('error','Enter a resolution summary before asking the client to confirm.');$this->redirect('tickets/'.$id);}
        try{TicketWorkflowService::resolve((int)$id,(int)$user['id'],$note);flash('success','Ticket marked resolved. The client was asked to confirm before closure.');}
        catch(\Throwable $error){flash('error',$error->getMessage());}
        $this->redirect('tickets/'.$id);
    }
    private function taskAccess(int $id):void{$db=Database::connection();$q=$db->prepare('SELECT t.id FROM tasks t LEFT JOIN task_assignees ta ON ta.task_id=t.id WHERE t.id=? AND t.deleted_at IS NULL AND (?=1 OR ta.user_id=?)');$q->execute([$id,can('tasks.edit')&&auth()['user_type']==='admin'?1:0,auth()['id']]);if(!$q->fetch()){http_response_code(403);view('errors/403',['title'=>'Access denied']);exit;}}
    private function projectAccess(int $id):void{$db=Database::connection();$q=$db->prepare('SELECT p.id FROM projects p LEFT JOIN project_members pm ON pm.project_id=p.id WHERE p.id=? AND p.deleted_at IS NULL AND (?=1 OR pm.user_id=?)');$q->execute([$id,can('projects.edit')&&auth()['user_type']==='admin'?1:0,auth()['id']]);if(!$q->fetch()){http_response_code(403);view('errors/403',['title'=>'Access denied']);exit;}}
    private function stream(string $relative,string $downloadName):never{$path=root_path($relative);if(!is_file($path)){http_response_code(404);exit('File not found.');}$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);header('Content-Type:'.$mime);header('Content-Length:'.filesize($path));header('Content-Disposition:attachment;filename="'.preg_replace('/[^A-Za-z0-9._-]/','_',$downloadName).'"');readfile($path);exit;}
}
