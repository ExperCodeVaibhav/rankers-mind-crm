<?php

declare(strict_types=1);

require __DIR__ . '/require-test-env.php';

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Config;
use App\Core\Database;

final class TicketTestHttp
{
    private string $cookieFile;
    private string $csrf = '';

    public function __construct(private string $base)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'rmticket_') ?: throw new RuntimeException('Cookie file failed.');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    public function login(string $email, string $password): int
    {
        $login = $this->request('GET', '/login');
        preg_match('/name="_token" value="([^"]+)"/', $login['body'], $matches);
        $this->csrf = $matches[1] ?? '';
        return $this->request('POST', '/login', ['_token'=>$this->csrf, 'email'=>$email, 'password'=>$password])['status'];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $data): array
    {
        $data['_token'] = $this->csrf;
        return $this->request('POST', $path, $data);
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $curl = curl_init($this->base.$path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HEADER=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_COOKIEJAR=>$this->cookieFile,
            CURLOPT_COOKIEFILE=>$this->cookieFile,
            CURLOPT_TIMEOUT=>15,
        ]);
        if ($method === 'POST') {
            curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($data)]);
        }
        $raw = curl_exec($curl);
        if ($raw === false) {
            throw new RuntimeException(curl_error($curl));
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        return ['status'=>$status, 'headers'=>substr($raw, 0, $headerSize), 'body'=>substr($raw, $headerSize)];
    }
}

$passed = 0;
function ticketCheck(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('Ticket assignment check failed: '.$label);
    }
    $passed++;
    echo "PASS  {$label}\n";
}

$db = Database::connection();
$client = $db->query("SELECT c.*,pm.email project_manager_email,ss.email support_staff_email FROM clients c JOIN users pm ON pm.id=c.project_manager_id JOIN users ss ON ss.id=c.support_staff_id WHERE c.portal_user_id=(SELECT id FROM users WHERE email='client@rankersmind.local') AND c.deleted_at IS NULL")->fetch();
ticketCheck((bool) $client, 'Seed client has a project manager and support staff assignment');

$ticketId = 0;
$link = '';
try {
    $clientHttp = new TicketTestHttp(rtrim((string) Config::get('app.url'), '/'));
    ticketCheck($clientHttp->login('client@rankersmind.local', 'Client@12345') === 302, 'Client login succeeds');
    $portal = $clientHttp->get('/portal/client');
    ticketCheck($portal['status'] === 200 && str_contains($portal['body'], 'data-ticket-form') && str_contains($portal['body'], 'project manager and support staff'), 'Support ticket dialog renders without blocking the portal');

    $subject = 'Assignment notification test '.date('His').random_int(100, 999);
    $created = $clientHttp->post('/portal/tickets', [
        'subject'=>$subject,
        'description'=>'Verify the assigned project manager and support staff receive this CRM message.',
        'project_id'=>'',
        'category'=>'Technical',
        'priority'=>'High',
    ]);
    $query = $db->prepare('SELECT * FROM tickets WHERE client_id=? AND subject=? ORDER BY id DESC LIMIT 1');
    $query->execute([$client['id'], $subject]);
    $ticket = $query->fetch();
    $ticketId = (int) ($ticket['id'] ?? 0);
    $link = '/tickets/'.$ticketId;
    ticketCheck($created['status'] === 302 && str_contains($created['headers'], '/portal/tickets/'.$ticketId), 'Ticket create returns immediately to the new ticket page');
    ticketCheck($ticketId > 0 && (int) $ticket['assigned_to'] === (int) $client['support_staff_id'] && $ticket['status'] === 'Assigned', 'New ticket is assigned to the client support staff');

    $notification = $db->prepare("SELECT COUNT(*) FROM notifications WHERE type='ticket_created' AND link=? AND user_id IN (?,?)");
    $notification->execute([$link, $client['project_manager_id'], $client['support_staff_id']]);
    ticketCheck((int) $notification->fetchColumn() === count(array_unique([(int) $client['project_manager_id'], (int) $client['support_staff_id']])), 'Project manager and support staff each receive one CRM notification');

    $detailStarted = microtime(true);
    $detail = $clientHttp->get('/portal/tickets/'.$ticketId);
    ticketCheck($detail['status'] === 200 && str_contains($detail['body'], $subject) && microtime(true) - $detailStarted < 3, 'Client support-ticket click opens a responsive ticket page');

    $supportHttp = new TicketTestHttp(rtrim((string) Config::get('app.url'), '/'));
    ticketCheck($supportHttp->login((string) $client['support_staff_email'], 'Staff@12345') === 302, 'Assigned support staff login succeeds');
    $supportTicket = $supportHttp->get('/tickets/'.$ticketId);
    $supportFeed = json_decode($supportHttp->get('/notifications/feed')['body'], true);
    ticketCheck($supportTicket['status'] === 200 && str_contains($supportTicket['body'], $subject), 'Assigned support staff can open the notified ticket');
    ticketCheck((bool) array_filter($supportFeed['items'] ?? [], fn(array $item): bool => ($item['link'] ?? '') === $link), 'Assigned support staff sees the ticket message in the CRM notification feed');

    $managerHttp = new TicketTestHttp(rtrim((string) Config::get('app.url'), '/'));
    ticketCheck($managerHttp->login((string) $client['project_manager_email'], 'Admin@12345') === 302, 'Assigned project manager login succeeds');
    $managerTicket = $managerHttp->get('/tickets/'.$ticketId);
    $managerFeed = json_decode($managerHttp->get('/notifications/feed')['body'], true);
    ticketCheck($managerTicket['status'] === 200 && str_contains($managerTicket['body'], $subject), 'Assigned project manager can open the notified ticket');
    ticketCheck((bool) array_filter($managerFeed['items'] ?? [], fn(array $item): bool => ($item['link'] ?? '') === $link), 'Assigned project manager sees the ticket message in the CRM notification feed');
    $clientEdit = $managerHttp->get('/clients/'.$client['id'].'/edit');
    ticketCheck(
        $clientEdit['status'] === 200
        && (bool) preg_match('/name="project_manager_id"[^>]*>.*?<option value="'.(int) $client['project_manager_id'].'" selected/s', $clientEdit['body'])
        && (bool) preg_match('/name="support_staff_id"[^>]*>.*?<option value="'.(int) $client['support_staff_id'].'" selected/s', $clientEdit['body']),
        'Client edit screen retains the assigned project manager and support staff'
    );

    echo "\n{$passed} ticket assignment checks passed.\n";
} finally {
    if ($link !== '') {
        $db->prepare('DELETE FROM notifications WHERE link=?')->execute([$link]);
    }
    if ($ticketId) {
        $db->prepare('DELETE FROM tickets WHERE id=?')->execute([$ticketId]);
    }
}
