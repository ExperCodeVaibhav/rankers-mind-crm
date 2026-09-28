<?php

declare(strict_types=1);

require __DIR__ . '/require-test-env.php';

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Config;
use App\Core\Database;

final class ResolutionHttp
{
    private string $cookieFile;
    private string $csrf = '';

    public function __construct(private string $base)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'rmresolve_') ?: throw new RuntimeException('Cookie file failed.');
    }

    public function __destruct() { @unlink($this->cookieFile); }

    public function login(string $email, string $password): int
    {
        $page = $this->request('GET', '/login');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $matches);
        $this->csrf = $matches[1] ?? '';
        return $this->request('POST', '/login', ['_token'=>$this->csrf, 'email'=>$email, 'password'=>$password])['status'];
    }

    public function get(string $path): array { return $this->request('GET', $path); }
    public function post(string $path, array $data): array { $data['_token']=$this->csrf; return $this->request('POST', $path, $data); }

    private function request(string $method, string $path, array $data = []): array
    {
        $curl = curl_init($this->base.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$this->cookieFile,CURLOPT_COOKIEFILE=>$this->cookieFile,CURLOPT_TIMEOUT=>15]);
        if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
        $raw = curl_exec($curl);
        if ($raw === false) throw new RuntimeException(curl_error($curl));
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        return ['status'=>$status,'headers'=>substr($raw,0,$headerSize),'body'=>substr($raw,$headerSize)];
    }
}

$passed = 0;
function resolutionCheck(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('Ticket resolution check failed: '.$label);
    $passed++;
    echo "PASS  {$label}\n";
}

$db = Database::connection();
$base = rtrim((string) Config::get('app.url'), '/');
$client = $db->query("SELECT c.*,u.email client_email FROM clients c JOIN users u ON u.id=c.portal_user_id WHERE u.email='client@rankersmind.local' AND c.deleted_at IS NULL")->fetch();
$project = $db->query('SELECT * FROM projects WHERE client_id='.(int)$client['id'].' AND deleted_at IS NULL ORDER BY id LIMIT 1')->fetch();
$memberQuery = $db->prepare("SELECT u.id,u.email FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? AND u.user_type='staff' AND u.status='active' ORDER BY (u.id=?) ASC,u.id LIMIT 1");
$memberQuery->execute([$project['id'],$client['support_staff_id']]);
$concernedStaff = $memberQuery->fetch();
resolutionCheck((bool)$client && (bool)$project && (bool)$concernedStaff, 'Client project has a concerned project staff member');

$ticketId = 0;
$link = '';
try {
    $clientHttp = new ResolutionHttp($base);
    resolutionCheck($clientHttp->login((string)$client['client_email'], 'Client@12345')===302, 'Client login succeeds');
    $subject = 'Client confirmation workflow '.date('His').random_int(100,999);
    $clientHttp->post('/portal/tickets', ['subject'=>$subject,'description'=>'Verify client-controlled ticket closure.','project_id'=>$project['id'],'category'=>'Technical','priority'=>'High']);
    $query=$db->prepare('SELECT * FROM tickets WHERE client_id=? AND subject=? ORDER BY id DESC LIMIT 1');$query->execute([$client['id'],$subject]);$ticket=$query->fetch();$ticketId=(int)($ticket['id']??0);$link='/tickets/'.$ticketId;
    resolutionCheck($ticketId>0 && $ticket['status']==='Assigned', 'Client creates an assigned project ticket');

    $premature=$clientHttp->post('/portal/tickets/'.$ticketId.'/confirmation',['resolved'=>'yes']);
    $status=(string)$db->query('SELECT status FROM tickets WHERE id='.$ticketId)->fetchColumn();
    resolutionCheck($premature['status']===302 && $status==='Assigned', 'Client cannot close a ticket before the team resolves it');

    $staffHttp = new ResolutionHttp($base);
    resolutionCheck($staffHttp->login((string)$concernedStaff['email'],'Staff@12345')===302, 'Concerned project staff login succeeds');
    $staffPage=$staffHttp->get('/tickets/'.$ticketId);
    resolutionCheck($staffPage['status']===200 && str_contains($staffPage['body'],'Mark resolved & ask client'), 'Concerned project staff sees the resolve-and-confirm option');

    $staffHttp->post('/tickets/'.$ticketId.'/reply',['message'=>'Direct close attempt must be blocked.','status'=>'Closed']);
    resolutionCheck((string)$db->query('SELECT status FROM tickets WHERE id='.$ticketId)->fetchColumn()==='Assigned', 'Staff cannot bypass client confirmation with a direct Closed status');

    $resolutionNote='Project staff fixed the reported issue and verified the affected workflow.';
    $resolved=$staffHttp->post('/tickets/'.$ticketId.'/resolve',['resolution_note'=>$resolutionNote]);
    $ticket=$db->query('SELECT * FROM tickets WHERE id='.$ticketId)->fetch();
    resolutionCheck($resolved['status']===302 && $ticket['status']==='Resolved' && $ticket['client_confirmation_status']==='Pending' && (int)$ticket['resolved_by']===(int)$concernedStaff['id'], 'Concerned staff marks resolved and starts client confirmation');
    resolutionCheck((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=".(int)$client['portal_user_id']." AND type='ticket_resolution_confirmation' AND link='/portal/tickets/".$ticketId."'")->fetchColumn()===1, 'Client receives the Is your issue resolved CRM message');

    $clientPrompt=$clientHttp->get('/portal/tickets/'.$ticketId);
    resolutionCheck($clientPrompt['status']===200 && str_contains($clientPrompt['body'],'Is your issue resolved?') && str_contains($clientPrompt['body'],'Yes, issue resolved') && str_contains($clientPrompt['body'],'No, issue continues'), 'Client sees explicit Yes and No resolution controls');

    $clientHttp->post('/portal/tickets/'.$ticketId.'/confirmation',['resolved'=>'no','client_note'=>'The final step is still failing.']);
    $ticket=$db->query('SELECT * FROM tickets WHERE id='.$ticketId)->fetch();
    resolutionCheck($ticket['status']==='Reopened' && $ticket['client_confirmation_status']==='Rejected' && $ticket['resolved_at']===null, 'Client No response reopens the ticket');
    resolutionCheck((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=".(int)$concernedStaff['id']." AND type='ticket_client_rejected' AND link='".$link."'")->fetchColumn()===1, 'Concerned project staff is notified when the client says No');

    $adminHttp = new ResolutionHttp($base);
    resolutionCheck($adminHttp->login('admin@rankersmind.local','Admin@12345')===302, 'Admin login succeeds');
    $adminPage=$adminHttp->get('/tickets/'.$ticketId);
    resolutionCheck($adminPage['status']===200 && str_contains($adminPage['body'],'Mark resolved & ask client'), 'Admin sees the resolve-and-confirm option');
    $adminHttp->post('/tickets/'.$ticketId.'/resolve',['resolution_note'=>'Admin completed the remaining final step and retested successfully.']);
    $ticket=$db->query('SELECT * FROM tickets WHERE id='.$ticketId)->fetch();
    $adminId=(int)$db->query("SELECT id FROM users WHERE email='admin@rankersmind.local'")->fetchColumn();
    resolutionCheck($ticket['status']==='Resolved' && (int)$ticket['resolved_by']===$adminId, 'Admin can resolve the reopened ticket and request confirmation again');

    $clientHttp->post('/portal/tickets/'.$ticketId.'/confirmation',['resolved'=>'yes']);
    $ticket=$db->query('SELECT * FROM tickets WHERE id='.$ticketId)->fetch();
    resolutionCheck($ticket['status']==='Closed' && $ticket['client_confirmation_status']==='Confirmed' && $ticket['closed_at']!==null, 'Ticket closes only after the client clicks Yes');
    resolutionCheck((int)$db->query("SELECT COUNT(*) FROM notifications WHERE user_id=".(int)$concernedStaff['id']." AND type='ticket_client_confirmed' AND link='".$link."'")->fetchColumn()===1, 'Concerned project staff is notified of client-confirmed closure');

    $messageCount=(int)$db->query('SELECT COUNT(*) FROM ticket_messages WHERE ticket_id='.$ticketId)->fetchColumn();
    $staffHttp->post('/tickets/'.$ticketId.'/reply',['message'=>'Closed ticket write must be rejected.','status'=>'Reopened']);
    resolutionCheck((int)$db->query('SELECT COUNT(*) FROM ticket_messages WHERE ticket_id='.$ticketId)->fetchColumn()===$messageCount && (string)$db->query('SELECT status FROM tickets WHERE id='.$ticketId)->fetchColumn()==='Closed', 'A client-confirmed closed ticket cannot be changed by a reply');

    $closedPage=$clientHttp->get('/portal/tickets/'.$ticketId);
    resolutionCheck(str_contains($closedPage['body'],'You confirmed that this issue was resolved.'), 'Client sees the confirmed closed state');
    echo "\n{$passed} ticket resolution checks passed.\n";
} finally {
    if($link!=='')$db->prepare('DELETE FROM notifications WHERE link=? OR link=?')->execute([$link,'/portal/tickets/'.$ticketId]);
    if($ticketId)$db->prepare('DELETE FROM tickets WHERE id=?')->execute([$ticketId]);
}
