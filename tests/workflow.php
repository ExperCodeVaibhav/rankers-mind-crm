<?php

declare(strict_types=1);

require __DIR__ . '/require-test-env.php';

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Config;
use App\Core\Database;
use App\Services\EntityService;
use App\Services\EstimateService;
use App\Services\InvoiceService;
use App\Services\LeadConversionService;
use App\Services\PayrollService;

$db = Database::connection();
$auditStart=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn();$activityStart=(int)$db->query('SELECT COALESCE(MAX(id),0) FROM activity_logs')->fetchColumn();
$adminId = (int) $db->query("SELECT id FROM users WHERE email='admin@rankersmind.local'")->fetchColumn();
$_SESSION['user_id'] = $adminId;
$_SESSION['_last_activity'] = time();
$suffix = date('His') . random_int(100, 999);
    $created = ['user'=>null,'staff'=>null,'portal_user'=>null,'portal_client'=>null,'portal_project'=>null,'portal_ticket'=>null,'manual_task'=>null,'lead'=>null,'client'=>null,'followup'=>null,'project'=>null,'task'=>null,'estimate'=>null,'estimate_invoice'=>null,'invoice'=>null,'payment'=>null,'ticket'=>null,'payroll'=>null,'photos'=>[],'files'=>[]];
$passed = 0;

function workflowCheck(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('Workflow check failed: ' . $label);
    $passed++;
    echo "PASS  {$label}\n";
}

final class WorkflowHttp
{
    private string $cookie;
    private string $csrf = '';
    public function __construct(private string $base) { $this->cookie = tempnam(sys_get_temp_dir(), 'rmwf_') ?: throw new RuntimeException('Cookie file failed.'); }
    public function __destruct() { @unlink($this->cookie); }
    public function login(string $email,string $password): int { $page=$this->request('GET','/login');preg_match('/name="_token" value="([^"]+)"/',$page['body'],$m);$this->csrf=$m[1]??'';return $this->request('POST','/login',['_token'=>$this->csrf,'email'=>$email,'password'=>$password])['status']; }
    public function get(string $path):array{return $this->request('GET',$path);}
    public function post(string $path,array $data):array{$data['_token']=$this->csrf;return $this->request('POST',$path,$data);}
    public function postFile(string $path,string $field,string $file,string $name,string $mime,array $data=[]):array{$data['_token']=$this->csrf;$data[$field]=new CURLFile($file,$mime,$name);return $this->request('POST',$path,$data);}
    private function request(string $method,string $path,array $data=[]):array{$c=curl_init($this->base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$this->cookie,CURLOPT_COOKIEFILE=>$this->cookie,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);if($method==='POST'){$multipart=(bool)array_filter($data,fn($value)=>$value instanceof CURLFile);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$data:http_build_query($data)]);}$raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);$headerSize=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);if($raw===false)throw new RuntimeException(curl_error($c));curl_close($c);return ['status'=>$status,'body'=>substr($raw,$headerSize)];}
}

try {
    $staffEmail = "acceptance.staff.{$suffix}@rankersmind.local";
    $staffPassword = bin2hex(random_bytes(16));
    $db->beginTransaction();
    $db->prepare("INSERT INTO users(branch_id,name,email,phone,password,user_type,status,must_change_password,created_by) VALUES((SELECT id FROM branches ORDER BY id LIMIT 1),?,?,?,?, 'staff','active',0,?)")
        ->execute(['Acceptance Staff '.$suffix,$staffEmail,'+91'.$suffix,password_hash($staffPassword,PASSWORD_DEFAULT),$adminId]);
    $created['user']=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE slug='staff'")->execute([$created['user']]);
    $db->prepare("INSERT INTO staff_profiles(user_id,employee_code,department_id,designation_id,shift_id,joining_date,employment_type,monthly_salary,daily_salary_rate,work_location,employment_status) VALUES(?, ?, (SELECT id FROM departments WHERE code='TECH'), (SELECT id FROM designations WHERE code='DEV'), (SELECT id FROM shifts ORDER BY id LIMIT 1), CURDATE(), 'full_time', 42000, 1615.38, 'Head Office', 'active')")
        ->execute([$created['user'],'RM-TEST-'.$suffix]);
    $created['staff']=(int)$db->lastInsertId();
    $db->commit();
    workflowCheck(password_verify($staffPassword,(string)$db->query('SELECT password FROM users WHERE id='.$created['user'])->fetchColumn()),'Admin-created staff login uses a password hash');

    $staffHttp = new WorkflowHttp((string)Config::get('app.url'));
    workflowCheck($staffHttp->login($staffEmail,$staffPassword)===302,'New staff can sign in');
    $adminHttp = new WorkflowHttp((string)Config::get('app.url'));$adminHttp->login('admin@rankersmind.local','Admin@12345');
    $portalEmail="acceptance.portal.{$suffix}@rankersmind.local";$portalPassword=bin2hex(random_bytes(16));
    $projectManagerId=(int)$db->query("SELECT id FROM users WHERE email='pm@rankersmind.local'")->fetchColumn();
    $clientCreate=$adminHttp->post('/clients',['company_name'=>'Acceptance Portal Company '.$suffix,'primary_contact'=>'Acceptance Client '.$suffix,'phone'=>'+9177'.$suffix,'email'=>$portalEmail,'country'=>'India','status'=>'active','project_manager_id'=>$projectManagerId,'support_staff_id'=>$created['user'],'payment_terms'=>30,'credit_limit'=>0,'portal_enabled'=>1,'portal_login_email'=>$portalEmail,'portal_password'=>$portalPassword,'portal_password_confirmation'=>$portalPassword,'portal_account_status'=>'active']);
    $portalLink=$db->prepare('SELECT c.id client_id,c.portal_user_id,u.password FROM clients c JOIN users u ON u.id=c.portal_user_id WHERE c.email=? AND c.deleted_at IS NULL');$portalLink->execute([$portalEmail]);$portalLink=$portalLink->fetch();$created['portal_client']=(int)($portalLink['client_id']??0);$created['portal_user']=(int)($portalLink['portal_user_id']??0);
    workflowCheck($clientCreate['status']===302&&$created['portal_client']>0&&$created['portal_user']>0&&password_verify($portalPassword,(string)($portalLink['password']??'')),'Admin creates a client record and separate hashed portal credentials');
    workflowCheck((int)$db->query("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=".$created['portal_user']." AND r.slug='client'")->fetchColumn()===1,'Admin-created client login receives the Client role');
    $portalProjectConfig=Config::get('entities.projects');$created['portal_project']=EntityService::create($portalProjectConfig,['client_id'=>$created['portal_client'],'name'=>'Portal-isolated Project '.$suffix,'description'=>'Only the linked client can see this project','project_type'=>'CRM','start_date'=>date('Y-m-d'),'expected_completion_date'=>date('Y-m-d',strtotime('+20 days')),'project_manager_id'=>$adminId,'priority'=>'Medium','status'=>'Active','progress'=>20,'estimated_budget'=>100000,'actual_cost'=>0,'billing_type'=>'Fixed','internal_notes'=>'Internal','client_visible_notes'=>'Client-visible acceptance project']);
    $clientHttp=new WorkflowHttp((string)Config::get('app.url'));workflowCheck($clientHttp->login($portalEmail,$portalPassword)===302,'New client credentials can sign in');$portalPage=$clientHttp->get('/portal/client');
    workflowCheck($portalPage['status']===200&&str_contains($portalPage['body'],'Acceptance Portal Company')&&str_contains($portalPage['body'],'Portal-isolated Project')&&!str_contains($portalPage['body'],'Kapoor Retail Private Limited'),'Client dashboard renders only its linked company data');
    $foreignProjectId=(int)$db->query('SELECT id FROM projects WHERE client_id<>'.$created['portal_client'].' AND deleted_at IS NULL ORDER BY id LIMIT 1')->fetchColumn();$foreignProject=$clientHttp->get('/portal/projects/'.$foreignProjectId);
    workflowCheck($foreignProject['status']===404,'Client cannot open another client project by changing the URL');
    $beforeForeignTicket=(int)$db->query('SELECT COUNT(*) FROM tickets WHERE client_id='.$created['portal_client'])->fetchColumn();$clientHttp->post('/portal/tickets',['subject'=>'Tampered project attempt','description'=>'Must be rejected','project_id'=>$foreignProjectId,'category'=>'Project','priority'=>'High']);$afterForeignTicket=(int)$db->query('SELECT COUNT(*) FROM tickets WHERE client_id='.$created['portal_client'])->fetchColumn();
    workflowCheck($beforeForeignTicket===$afterForeignTicket,'Client cannot attach a support ticket to another client project');
    $clientHttp->post('/portal/tickets',['subject'=>'Acceptance portal support '.$suffix,'description'=>'Legitimate client-scoped ticket','project_id'=>$created['portal_project'],'category'=>'Project','priority'=>'Medium']);$created['portal_ticket']=(int)$db->query("SELECT id FROM tickets WHERE client_id=".$created['portal_client']." AND subject='Acceptance portal support ".$suffix."'")->fetchColumn();workflowCheck($created['portal_ticket']>0&&(int)$db->query('SELECT assigned_to FROM tickets WHERE id='.$created['portal_ticket'])->fetchColumn()===$created['user'],'Client ticket is assigned to its client-level support staff');
    workflowCheck((int)$db->query("SELECT COUNT(*) FROM notifications WHERE type='ticket_created' AND link='/tickets/".$created['portal_ticket']."' AND user_id IN (".$projectManagerId.','.$created['user'].')')->fetchColumn()===2,'Client ticket notifies its assigned project manager and support staff');
    $adminHttp->post('/tasks',['project_id'=>'','parent_task_id'=>'','title'=>'Manual admin task '.$suffix,'description'=>'Entered manually from the admin side','priority'=>'Medium','status'=>'To Do','start_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d',strtotime('+5 days')),'estimated_hours'=>2,'actual_hours'=>0,'progress'=>0,'completion_note'=>'','approval_status'=>'Not Required','assignee_ids'=>[$created['user']]]);$created['manual_task']=(int)$db->query("SELECT id FROM tasks WHERE title='Manual admin task ".$suffix."' AND deleted_at IS NULL")->fetchColumn();
    workflowCheck($created['manual_task']>0&&(int)$db->query('SELECT assigned_by FROM tasks WHERE id='.$created['manual_task'])->fetchColumn()===$adminId&&(int)$db->query('SELECT COUNT(*) FROM task_assignees WHERE task_id='.$created['manual_task'].' AND user_id='.$created['user'])->fetchColumn()===1,'Admin can manually write and assign a standalone task');
    $staffHttp->post('/portal/staff/profile',['name'=>'Acceptance Staff Updated '.$suffix,'phone'=>'+9199'.$suffix,'emergency_contact'=>'+9188'.$suffix,'address'=>'Acceptance address','work_location'=>'Hybrid']);
    workflowCheck((string)$db->query('SELECT name FROM users WHERE id='.$created['user'])->fetchColumn()==='Acceptance Staff Updated '.$suffix&&(string)$db->query('SELECT work_location FROM staff_profiles WHERE id='.$created['staff'])->fetchColumn()==='Hybrid','Staff can update only their own basic portal profile');
    $pixel='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $punch=$staffHttp->post('/attendance/punch',['photo'=>$pixel,'latitude'=>'26.8101600','longitude'=>'75.8635100','accuracy'=>'12']);
    workflowCheck($punch['status']===200&&str_contains($punch['body'],'Checked in'),'Staff punches attendance with live-photo payload and geolocation');
    $attendance=$db->query('SELECT * FROM attendance WHERE staff_id='.$created['staff'].' AND attendance_date=CURDATE()')->fetch();
    workflowCheck($attendance&&$attendance['check_in_at']&&abs((float)$attendance['distance_from_office'])<1,'Attendance uses server time and validates geofence distance');
    if($attendance['check_in_photo'])$created['photos'][]=$attendance['check_in_photo'];
    $checkout=$staffHttp->post('/attendance/punch',['photo'=>$pixel,'latitude'=>'26.8101600','longitude'=>'75.8635100','accuracy'=>'12']);
    workflowCheck($checkout['status']===200&&str_contains($checkout['body'],'Checked out'),'Staff completes checkout without duplicate attendance row');
    $attendance=$db->query('SELECT * FROM attendance WHERE staff_id='.$created['staff'].' AND attendance_date=CURDATE()')->fetch();if($attendance['check_out_photo'])$created['photos'][]=$attendance['check_out_photo'];
    workflowCheck((int)$db->query('SELECT COUNT(*) FROM attendance WHERE staff_id='.$created['staff'].' AND attendance_date=CURDATE()')->fetchColumn()===1,'Duplicate daily attendance is prevented');

    $leadConfig=Config::get('entities.leads');
    $created['lead']=EntityService::create($leadConfig,['person_name'=>'Acceptance Buyer '.$suffix,'company_name'=>'Acceptance Company '.$suffix,'phone'=>'+91-TEST-'.$suffix,'email'=>"acceptance.client.{$suffix}@example.test",'country'=>'India','service_interest'=>'CRM Development','estimated_budget'=>200000,'expected_value'=>250000,'source'=>'Acceptance Test','assigned_to'=>$adminId,'stage'=>'Qualified','classification'=>'Hot','score'=>88,'conversion_probability'=>80,'priority'=>'High','next_followup_at'=>date('Y-m-d H:i:s',strtotime('+2 hours'))]);
    workflowCheck((int)$db->query('SELECT score FROM leads WHERE id='.$created['lead'])->fetchColumn()===88,'Admin creates and classifies a Hot scored lead');
    $adminHttp->post('/leads/'.$created['lead'].'/activities',['activity_type'=>'call','subject'=>'Acceptance discovery call','details'=>'Decision-maker confirmed scope and timing.']);$adminHttp->post('/leads/'.$created['lead'].'/tags',['tag'=>'Acceptance']);
    workflowCheck((int)$db->query('SELECT COUNT(*) FROM lead_activities WHERE lead_id='.$created['lead']." AND activity_type='call'")->fetchColumn()===1&&(int)$db->query('SELECT COUNT(*) FROM lead_tags WHERE lead_id='.$created['lead'])->fetchColumn()===1,'Lead timeline activity and reusable tag are database-backed');
    $adminHttp->post('/leads/bulk',['ids'=>[$created['lead']],'bulk_action'=>'stage','bulk_value'=>'Proposal Sent']);
    workflowCheck((string)$db->query('SELECT stage FROM leads WHERE id='.$created['lead'])->fetchColumn()==='Proposal Sent','Bulk lead pipeline action updates selected database records');
    $duplicate=$db->prepare('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL AND (phone=? OR email=?)');$duplicate->execute(['+91-TEST-'.$suffix,"acceptance.client.{$suffix}@example.test"]);workflowCheck((int)$duplicate->fetchColumn()===1,'Duplicate lead detection keys identify the existing lead');

    $followupConfig=Config::get('entities.followups');
    $created['followup']=EntityService::create($followupConfig,['lead_id'=>$created['lead'],'client_id'=>null,'assigned_to'=>$adminId,'followup_type'=>'Call','scheduled_at'=>date('Y-m-d H:i:s',strtotime('+2 hours')),'priority'=>'High','notes'=>'Acceptance follow-up','status'=>'Scheduled','reminder_at'=>date('Y-m-d H:i:s',strtotime('+90 minutes'))]);
    workflowCheck((int)$db->query('SELECT COUNT(*) FROM lead_followups WHERE id='.$created['followup'].' AND status=\'Scheduled\'')->fetchColumn()===1,'Follow-up reminder is stored and dashboard-ready');

    $conversion=LeadConversionService::convert($created['lead'],false);$created['client']=$conversion['client_id'];
    workflowCheck((string)$db->query('SELECT stage FROM leads WHERE id='.$created['lead'])->fetchColumn()==='Won','Lead conversion transaction marks pipeline Won');
    workflowCheck((int)$db->query('SELECT source_lead_id FROM clients WHERE id='.$created['client'])->fetchColumn()===$created['lead'],'Lead conversion links the new client without duplication');

    $projectConfig=Config::get('entities.projects');
    $created['project']=EntityService::create($projectConfig,['client_id'=>$created['client'],'name'=>'Acceptance Project '.$suffix,'description'=>'End-to-end workflow project','project_type'=>'Web Development','start_date'=>date('Y-m-d'),'expected_completion_date'=>date('Y-m-d',strtotime('+30 days')),'project_manager_id'=>$adminId,'priority'=>'High','status'=>'Active','progress'=>10,'estimated_budget'=>250000,'actual_cost'=>10000,'billing_type'=>'Milestone','internal_notes'=>'Acceptance only','client_visible_notes'=>'Project initiated']);
    $taskConfig=Config::get('entities.tasks');
    $created['task']=EntityService::create($taskConfig,['project_id'=>$created['project'],'parent_task_id'=>null,'title'=>'Acceptance delivery task '.$suffix,'description'=>'Post daily update','assigned_by'=>$adminId,'priority'=>'High','status'=>'To Do','start_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d',strtotime('+7 days')),'estimated_hours'=>12,'actual_hours'=>0,'progress'=>0,'completion_note'=>null,'approval_status'=>'Pending','assignee_ids'=>[$created['user']]]);
    workflowCheck((int)$db->query('SELECT COUNT(*) FROM task_assignees WHERE task_id='.$created['task'].' AND user_id='.$created['user'])->fetchColumn()===1,'Project task assignment creates staff workload and notification');
    $staffHttp->get('/tasks/'.$created['task']);$staffHttp->post('/tasks/'.$created['task'].'/favourite',[]);$staffHttp->post('/tasks/filters',['name'=>'Acceptance open work','query'=>'status=To+Do']);$personalWorkspace=$staffHttp->get('/favourites');
    workflowCheck((int)$db->query("SELECT COUNT(*) FROM favourites WHERE user_id=".$created['user']." AND record_type='tasks' AND record_id=".$created['task'])->fetchColumn()===1&&str_contains($personalWorkspace['body'],'Acceptance delivery task')&&(int)$db->query("SELECT COUNT(*) FROM saved_filters WHERE user_id=".$created['user']." AND module='tasks'")->fetchColumn()===1,'Favourites, recent records and saved filters are user-specific and functional');
    $staffHttp->post('/tasks/'.$created['task'].'/updates',['update_text'=>'Acceptance daily update','progress'=>35]);
    workflowCheck((int)$db->query('SELECT progress FROM tasks WHERE id='.$created['task'])->fetchColumn()===35,'Assigned staff can post a scoped daily task update');
    $staffHttp->post('/tasks/'.$created['task'].'/checklists',['item'=>'Acceptance checklist item']);
    workflowCheck((int)$db->query('SELECT COUNT(*) FROM task_checklists WHERE task_id='.$created['task'])->fetchColumn()===1,'Assigned staff can add a task checklist item');
    $staffHttp->post('/tasks/'.$created['task'].'/time-logs',['work_date'=>date('Y-m-d'),'minutes'=>90,'note'=>'Acceptance time log']);
    workflowCheck(abs((float)$db->query('SELECT actual_hours FROM tasks WHERE id='.$created['task'])->fetchColumn()-1.5)<.01,'Task time log recalculates actual working hours');
    $validUpload=tempnam(sys_get_temp_dir(),'rm_upload_');file_put_contents($validUpload,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    $staffHttp->postFile('/tasks/'.$created['task'].'/files','attachment',$validUpload,'acceptance.png','image/png');
    $storedFile=$db->query("SELECT * FROM file_uploads WHERE related_type='task' AND related_id=".$created['task'].' ORDER BY id DESC LIMIT 1')->fetch();if($storedFile)$created['files'][]=$storedFile['path'];
    workflowCheck($storedFile&&is_file(root_path($storedFile['path']))&&$storedFile['mime_type']==='image/png','Secure task upload stores an allowlisted MIME with a random filename');
    $adminHttp->postFile('/leads/'.$created['lead'].'/files','attachment',$validUpload,'lead-brief.png','image/png',['title'=>'Acceptance lead brief']);$leadFile=$db->query('SELECT * FROM lead_attachments WHERE lead_id='.$created['lead'].' ORDER BY id DESC LIMIT 1')->fetch();if($leadFile)$created['files'][]=$leadFile['file_path'];
    $adminHttp->postFile('/projects/'.$created['project'].'/files','attachment',$validUpload,'project-handover.png','image/png',['title'=>'Acceptance project file','is_client_visible'=>1]);$projectFile=$db->query('SELECT * FROM project_files WHERE project_id='.$created['project'].' ORDER BY id DESC LIMIT 1')->fetch();if($projectFile)$created['files'][]=$projectFile['file_path'];
    workflowCheck($leadFile&&$projectFile&&(int)$projectFile['is_client_visible']===1,'Lead and client-visible project attachments are stored through protected upload workflows');
    $beforeUploadCount=(int)$db->query("SELECT COUNT(*) FROM file_uploads WHERE related_type='task' AND related_id=".$created['task'])->fetchColumn();$invalidUpload=tempnam(sys_get_temp_dir(),'rm_bad_');file_put_contents($invalidUpload,"<?php echo 'unsafe';");$staffHttp->postFile('/tasks/'.$created['task'].'/files','attachment',$invalidUpload,'unsafe.php','application/x-php');$afterUploadCount=(int)$db->query("SELECT COUNT(*) FROM file_uploads WHERE related_type='task' AND related_id=".$created['task'])->fetchColumn();workflowCheck($afterUploadCount===$beforeUploadCount,'Executable upload MIME is rejected without creating a database record');@unlink($validUpload);@unlink($invalidUpload);

    $created['estimate']=EstimateService::create(['estimate_type'=>'Quotation','client_id'=>$created['client'],'project_id'=>$created['project'],'estimate_date'=>date('Y-m-d'),'valid_until'=>date('Y-m-d',strtotime('+30 days')),'discount_total'=>1000,'notes'=>'Acceptance quotation','terms'=>'Test estimate terms'],[['description'=>'Discovery and implementation','quantity'=>2,'rate'=>25000,'tax_percent'=>18]]);
    $db->prepare("UPDATE estimates SET status='Sent' WHERE id=?")->execute([$created['estimate']]);
    $created['estimate_invoice']=EstimateService::convertToInvoice($created['estimate']);
    $convertedInvoice=$db->query('SELECT * FROM invoices WHERE id='.$created['estimate_invoice'])->fetch();
    workflowCheck($convertedInvoice&&abs((float)$convertedInvoice['grand_total']-58000)<.01&&(string)$db->query('SELECT status FROM estimates WHERE id='.$created['estimate'])->fetchColumn()==='Converted','Accepted quotation converts transactionally into a calculated invoice');

    $created['invoice']=InvoiceService::create(['invoice_type'=>'Tax Invoice','client_id'=>$created['client'],'project_id'=>$created['project'],'invoice_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d',strtotime('+30 days')),'gst_type'=>'CGST_SGST','additional_charges'=>500,'notes'=>'Acceptance invoice','terms'=>'Test terms'],[['description'=>'CRM milestone','hsn_sac'=>'998314','quantity'=>2,'rate'=>50000,'tax_percent'=>18,'discount'=>1000]]);
    $invoice=$db->query('SELECT * FROM invoices WHERE id='.$created['invoice'])->fetch();workflowCheck(abs((float)$invoice['grand_total']-117320)<.01,'Invoice service calculates quantity, discount, GST and charges');
    $created['payment']=InvoiceService::recordPayment($created['invoice'],['amount'=>50000,'payment_date'=>date('Y-m-d'),'payment_mode'=>'Bank Transfer','reference_number'=>'TEST'.$suffix,'bank_account'=>'Acceptance account','notes'=>'Partial acceptance payment']);
    $invoice=$db->query('SELECT * FROM invoices WHERE id='.$created['invoice'])->fetch();workflowCheck($invoice['status']==='Partially Paid'&&abs((float)$invoice['pending_amount']-67320)<.01,'Partial payment updates paid amount, pending amount and status');

    $ticketConfig=Config::get('entities.tickets');
    $created['ticket']=EntityService::create($ticketConfig,['client_id'=>$created['client'],'project_id'=>$created['project'],'subject'=>'Acceptance support query '.$suffix,'category'=>'Technical','priority'=>'High','description'=>'Workflow ticket','assigned_to'=>$created['user'],'status'=>'Assigned','response_due_at'=>date('Y-m-d H:i:s',strtotime('+1 day')),'resolution_note'=>null]);
    $db->prepare("INSERT INTO ticket_messages(ticket_id,user_id,message,is_internal) VALUES(?,?,?,0)")->execute([$created['ticket'],$created['user'],'Acceptance response and resolution']);
    $db->prepare("UPDATE tickets SET status='Resolved',resolved_at=NOW(),resolution_note='Acceptance resolved' WHERE id=?")->execute([$created['ticket']]);
    workflowCheck((string)$db->query('SELECT status FROM tickets WHERE id='.$created['ticket'])->fetchColumn()==='Resolved','Client complaint can be assigned, replied to and resolved');

    $payrollMonth=date('Y-m',strtotime('+3 months'));$created['payroll']=PayrollService::generate($payrollMonth,26);
    workflowCheck((int)$db->query('SELECT COUNT(*) FROM payroll_entries WHERE payroll_period_id='.$created['payroll'])->fetchColumn()>=6,'Monthly payroll generation creates attendance-based entries');
    $testEntry=$db->query('SELECT * FROM payroll_entries WHERE payroll_period_id='.$created['payroll'].' AND staff_id='.$created['staff'])->fetch();workflowCheck($testEntry&&abs((float)$testEntry['gross_pay']-(float)$testEntry['total_deduction']-(float)$testEntry['net_pay'])<.01,'Generated staff payroll breakdown reconciles');
    echo "\n{$passed} acceptance workflow checks passed.\n";
} finally {
    $db->beginTransaction();
    try {
        if($created['payroll'])$db->prepare('DELETE FROM payroll_periods WHERE id=?')->execute([$created['payroll']]);
        if($created['portal_ticket'])$db->prepare("DELETE FROM notifications WHERE link=?")->execute(['/tickets/'.$created['portal_ticket']]);
        if($created['portal_ticket'])$db->prepare('DELETE FROM tickets WHERE id=?')->execute([$created['portal_ticket']]);
        if($created['ticket'])$db->prepare('DELETE FROM tickets WHERE id=?')->execute([$created['ticket']]);
        if($created['payment'])$db->prepare('DELETE FROM payments WHERE id=?')->execute([$created['payment']]);
        if($created['invoice'])$db->prepare('DELETE FROM invoices WHERE id=?')->execute([$created['invoice']]);
        if($created['estimate_invoice'])$db->prepare('DELETE FROM invoices WHERE id=?')->execute([$created['estimate_invoice']]);
        if($created['estimate'])$db->prepare('DELETE FROM estimates WHERE id=?')->execute([$created['estimate']]);
        if($created['task'])$db->prepare("DELETE FROM file_uploads WHERE related_type='task' AND related_id=?")->execute([$created['task']]);
        if($created['task'])$db->prepare('DELETE FROM tasks WHERE id=?')->execute([$created['task']]);
        if($created['manual_task'])$db->prepare('DELETE FROM tasks WHERE id=?')->execute([$created['manual_task']]);
        if($created['project'])$db->prepare('DELETE FROM projects WHERE id=?')->execute([$created['project']]);
        if($created['portal_project'])$db->prepare('DELETE FROM projects WHERE id=?')->execute([$created['portal_project']]);
        if($created['lead'])$db->prepare('UPDATE leads SET converted_client_id=NULL WHERE id=?')->execute([$created['lead']]);
        if($created['client'])$db->prepare('DELETE FROM clients WHERE id=?')->execute([$created['client']]);
        if($created['portal_client'])$db->prepare('DELETE FROM clients WHERE id=?')->execute([$created['portal_client']]);
        if($created['lead'])$db->prepare('DELETE FROM leads WHERE id=?')->execute([$created['lead']]);
        if($created['staff'])$db->prepare('DELETE FROM staff_profiles WHERE id=?')->execute([$created['staff']]);
        if($created['portal_user'])$db->prepare('DELETE FROM users WHERE id=?')->execute([$created['portal_user']]);
        if($created['user']){$db->prepare("DELETE FROM notifications WHERE user_id=? OR (title='New task assignment' AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR))")->execute([$created['user']]);$db->prepare('DELETE FROM users WHERE id=?')->execute([$created['user']]);}
        $db->prepare('DELETE FROM audit_logs WHERE id>?')->execute([$auditStart]);$db->prepare('DELETE FROM activity_logs WHERE id>?')->execute([$activityStart]);
        $db->commit();
    } catch (Throwable $cleanupError) { $db->rollBack(); fwrite(STDERR,'Cleanup failed: '.$cleanupError->getMessage()."\n"); }
    foreach(array_merge($created['photos'],$created['files']) as $relative)if(is_file(root_path($relative)))@unlink(root_path($relative));
}
