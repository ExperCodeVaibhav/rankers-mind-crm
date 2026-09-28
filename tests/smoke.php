<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$base = rtrim((string) \App\Config\Config::get('app.url'), '/');
$passes = 0;
$failures = [];

function check(bool $condition, string $label): void
{
    global $passes, $failures;
    if ($condition) {
        $passes++;
        echo "PASS  {$label}\n";
    } else {
        $failures[] = $label;
        echo "FAIL  {$label}\n";
    }
}

final class TestBrowser
{
    private string $cookieFile;
    public function __construct(private string $base)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'rmcrm_') ?: throw new RuntimeException('Cookie file failed.');
    }
    public function __destruct() { @unlink($this->cookieFile); }
    public function get(string $path): array { return $this->request('GET', $path); }
    public function post(string $path, array $data): array { return $this->request('POST', $path, $data); }
    public function login(string $email, string $password): array
    {
        $login = $this->get('/login');
        preg_match('/name="_token" value="([^"]+)"/', $login['body'], $matches);
        return $this->post('/login', ['_token'=>$matches[1]??'', 'email'=>$email, 'password'=>$password]);
    }
    private function request(string $method, string $path, array $data = []): array
    {
        $curl = curl_init($this->base . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$this->cookieFile,CURLOPT_COOKIEFILE=>$this->cookieFile,CURLOPT_TIMEOUT=>15]);
        if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
        $raw = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE); $error = curl_error($curl); curl_close($curl);
        if ($raw === false) throw new RuntimeException($error);
        return ['status'=>$status,'headers'=>substr($raw,0,$headerSize),'body'=>substr($raw,$headerSize)];
    }
}

$db = Database::connection();
check((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() >= 50, 'Normalized schema contains at least 50 tables');
check((int) $db->query('SELECT COUNT(*) FROM leads WHERE deleted_at IS NULL')->fetchColumn() >= 15, 'Realistic lead seed data installed');
check((int) $db->query('SELECT COUNT(*) FROM staff_profiles WHERE deleted_at IS NULL')->fetchColumn() >= 5, 'Five staff profiles installed');
check((int) $db->query('SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL')->fetchColumn() >= 3, 'Three client accounts installed');

foreach ([['admin@rankersmind.local','Admin@12345'],['staff@rankersmind.local','Staff@12345'],['client@rankersmind.local','Client@12345']] as [$email,$password]) {
    $q=$db->prepare('SELECT password FROM users WHERE email=?');$q->execute([$email]);check(password_verify($password,(string)$q->fetchColumn()),"Hashed development credential verifies for {$email}");
}
check((int)$db->query("SELECT COUNT(*) FROM permissions WHERE slug='settings.manage'")->fetchColumn()===1,'Permission matrix seeded');
check((int)$db->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.slug='super-admin'")->fetchColumn()>100,'Super Admin has complete permission set');
check((int)$db->query('SELECT COUNT(*) FROM invoices WHERE ABS(grand_total-paid_amount-pending_amount)>0.01')->fetchColumn()===0,'Invoice paid and pending balances reconcile');
check((int)$db->query('SELECT COUNT(*) FROM payroll_entries WHERE ABS(gross_pay-total_deduction-net_pay)>0.01')->fetchColumn()===0,'Payroll gross, deductions and net pay reconcile');
check((int)$db->query("SELECT COUNT(*) FROM leads l JOIN clients c ON c.source_lead_id=l.id WHERE l.converted_client_id<>c.id")->fetchColumn()===0,'Converted lead/client links are internally consistent');

$anonymous = new TestBrowser($base);
$invalidCsrf = $anonymous->post('/login',['email'=>'admin@rankersmind.local','password'=>'Admin@12345']);
check($invalidCsrf['status']===403,'Invalid CSRF token rejected');

$admin = new TestBrowser($base);$adminLogin=$admin->login('admin@rankersmind.local','Admin@12345');
check($adminLogin['status']===302 && str_contains($adminLogin['headers'],'/dashboard'),'Admin login redirects to dashboard');
$adminDashboard=$admin->get('/dashboard');check($adminDashboard['status']===200 && str_contains($adminDashboard['body'],'Executive overview'),'Admin dashboard renders live metrics');
check($admin->get('/leads')['status']===200,'Admin can open lead management');
check($admin->get('/payroll')['status']===200,'Admin can open payroll');
check($admin->get('/invoices/1')['status']===200,'Admin can open an invoice');
$estimates=$admin->get('/estimates');check($estimates['status']===200 && str_contains($estimates['body'],'RM-EST-0001'),'Admin can open estimates and quotations');
$estimatePdf=$admin->get('/estimates/1/print?format=pdf');check($estimatePdf['status']===200 && str_contains($estimatePdf['headers'],'application/pdf') && str_starts_with($estimatePdf['body'],'%PDF'),'Admin can download a generated quotation PDF');
$receiptPdf=$admin->get('/payments/1/receipt?format=pdf');check($receiptPdf['status']===200 && str_contains($receiptPdf['headers'],'application/pdf') && str_starts_with($receiptPdf['body'],'%PDF'),'Admin can download a generated payment receipt PDF');
$reportPdf=$admin->get('/reports/print?type=attendance&format=pdf&from='.date('Y-m-01').'&to='.date('Y-m-d'));
check($reportPdf['status']===200 && str_contains($reportPdf['headers'],'application/pdf') && str_starts_with($reportPdf['body'],'%PDF'),'Admin can download a generated attendance PDF');
$projectMemberId=(int)$db->query('SELECT user_id FROM project_members WHERE project_id=1 ORDER BY user_id LIMIT 1')->fetchColumn();
$projectEdit=$admin->get('/projects/1/edit');
check($projectEdit['status']===200 && (bool)preg_match('/option value="'.$projectMemberId.'" selected/', $projectEdit['body']),'Project edit retains existing team assignments');

$staff = new TestBrowser($base);$staffLogin=$staff->login('staff@rankersmind.local','Staff@12345');
check($staffLogin['status']===302,'Staff login succeeds');
$staffPortal=$staff->get('/portal/staff');check($staffPortal['status']===200 && str_contains($staffPortal['body'],'My tasks') && str_contains($staffPortal['body'],'My profile'),'Staff portal renders personal dashboard and self-service profile');
check($staff->get('/leads')['status']===403,'Staff cannot access leads');
check($staff->get('/payroll')['status']===403,'Staff cannot browse company payroll');
$staffTaskId=(int)$db->query("SELECT ta.task_id FROM task_assignees ta JOIN users u ON u.id=ta.user_id WHERE u.email='staff@rankersmind.local' ORDER BY ta.task_id LIMIT 1")->fetchColumn();
$staffTaskEdit=$staff->get('/tasks/'.$staffTaskId.'/edit');
check($staffTaskEdit['status']===200 && !str_contains($staffTaskEdit['body'],'name="title"') && str_contains($staffTaskEdit['body'],'name="progress"'),'Staff task edit exposes only permitted progress fields');
check($staff->get('/projects/1/edit')['status']===403,'Staff cannot edit project administration fields');
$staffTasks=$staff->get('/tasks');check($staffTasks['status']===200 && !str_contains($staffTasks['body'],'Campaign week-two optimisation'),'Staff task list is scoped to assignments');

$client = new TestBrowser($base);$clientLogin=$client->login('client@rankersmind.local','Client@12345');
check($clientLogin['status']===302,'Client login succeeds');
$clientPortal=$client->get('/portal/client');check($clientPortal['status']===200 && str_contains($clientPortal['body'],'Kapoor Retail Private Limited') && str_contains($clientPortal['body'],'Company profile'),'Client portal renders linked account and self-service profile');
check($client->get('/clients')['status']===403,'Client cannot browse CRM clients');
check($client->get('/invoices')['status']===403,'Client cannot browse global invoice list');
check($client->get('/estimates')['status']===403,'Client cannot browse internal estimate administration');
check($client->get('/invoices/1')['status']===200,'Client can view own invoice');
check($client->get('/payments/1/receipt?format=pdf')['status']===200,'Client can download a receipt for an own-account payment');
check($client->get('/payments/2/receipt?format=pdf')['status']===404,'Client is blocked from another client payment receipt');
check($client->get('/invoices/2')['status']===404,'Client is blocked from another client invoice');
check($client->get('/portal/projects/3')['status']===404,'Client is blocked from another client project');

echo "\n{$passes} checks passed; " . count($failures) . " failed.\n";
if ($failures) exit(1);
