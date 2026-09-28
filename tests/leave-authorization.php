<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Config\Config;
use App\Core\Database;

final class LeaveAuthorizationHttp
{
    private string $cookieFile;
    private string $csrf = '';

    public function __construct(private string $base)
    {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'rmleave_') ?: throw new RuntimeException('Cookie file failed.');
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    public function login(string $email, string $password): int
    {
        $page = $this->request('GET', '/login');
        preg_match('/name="_token" value="([^"]+)"/', $page['body'], $match);
        $this->csrf = $match[1] ?? '';
        return $this->request('POST', '/login', [
            '_token'=>$this->csrf,
            'email'=>$email,
            'password'=>$password,
        ])['status'];
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
        if ($raw === false) throw new RuntimeException(curl_error($curl));
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        return ['status'=>$status, 'headers'=>substr($raw, 0, $headerSize), 'body'=>substr($raw, $headerSize)];
    }
}

$passes = 0;
function leaveAuthorizationCheck(bool $condition, string $label): void
{
    global $passes;
    if (!$condition) throw new RuntimeException('Leave authorization check failed: '.$label);
    $passes++;
    echo "PASS  {$label}\n";
}

$db = Database::connection();
$suffix = date('His').random_int(100, 999);
$password = 'LeaveAuth@12345';
$staffEmail = "leave.staff.{$suffix}@rankersmind.local";
$hrEmail = "leave.hr.{$suffix}@rankersmind.local";
$staffUserId = 0;
$staffProfileId = 0;
$hrUserId = 0;
$leaveId = 0;
$auditStart = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM audit_logs')->fetchColumn();
$activityStart = (int) $db->query('SELECT COALESCE(MAX(id),0) FROM activity_logs')->fetchColumn();

try {
    $branchId = (int) $db->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
    $leaveTypeId = (int) $db->query('SELECT id FROM leave_types WHERE is_active=1 ORDER BY id LIMIT 1')->fetchColumn();
    leaveAuthorizationCheck($branchId > 0 && $leaveTypeId > 0, 'Required branch and leave type exist');

    $insertUser = $db->prepare("INSERT INTO users(branch_id,name,email,password,user_type,status,must_change_password) VALUES(?,?,?,?,?,'active',0)");
    $insertUser->execute([$branchId, 'Leave Staff '.$suffix, $staffEmail, password_hash($password, PASSWORD_DEFAULT), 'staff']);
    $staffUserId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE slug='staff'")->execute([$staffUserId]);
    $db->prepare("INSERT INTO staff_profiles(user_id,employee_code,joining_date,employment_type,employment_status) VALUES(?,?,CURDATE(),'full_time','active')")
        ->execute([$staffUserId, 'RM-LV-'.$suffix]);
    $staffProfileId = (int) $db->lastInsertId();

    $insertUser->execute([$branchId, 'Leave HR '.$suffix, $hrEmail, password_hash($password, PASSWORD_DEFAULT), 'admin']);
    $hrUserId = (int) $db->lastInsertId();
    $db->prepare("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE slug='hr-manager'")->execute([$hrUserId]);

    $base = rtrim((string) Config::get('app.url'), '/');
    $staff = new LeaveAuthorizationHttp($base);
    leaveAuthorizationCheck($staff->login($staffEmail, $password) === 302, 'Staff login succeeds');

    $createForm = $staff->get('/leave/create');
    leaveAuthorizationCheck(
        $createForm['status'] === 200
        && !str_contains($createForm['body'], 'name="staff_id"')
        && !str_contains($createForm['body'], 'name="status"')
        && !str_contains($createForm['body'], 'name="rejection_reason"'),
        'Staff leave form does not expose staff ownership or approval fields'
    );

    $reason = 'Authorization test '.$suffix;
    $created = $staff->post('/leave', [
        'staff_id'=>999999,
        'leave_type_id'=>$leaveTypeId,
        'start_date'=>date('Y-m-d', strtotime('+7 days')),
        'end_date'=>date('Y-m-d', strtotime('+7 days')),
        'days'=>'1',
        'reason'=>$reason,
        'status'=>'Approved',
        'rejection_reason'=>'Tampered approval',
    ]);
    $query = $db->prepare('SELECT * FROM leave_requests WHERE staff_id=? AND reason=? ORDER BY id DESC LIMIT 1');
    $query->execute([$staffProfileId, $reason]);
    $leave = $query->fetch();
    $leaveId = (int) ($leave['id'] ?? 0);
    leaveAuthorizationCheck(
        $created['status'] === 302
        && $leaveId > 0
        && $leave['status'] === 'Pending Manager'
        && $leave['rejection_reason'] === null,
        'Tampered staff submission remains pending and owned by the logged-in staff member'
    );

    $list = $staff->get('/leave');
    $detail = $staff->get('/leave/'.$leaveId);
    $editHref = '/leave/'.$leaveId.'/edit';
    leaveAuthorizationCheck(
        $list['status'] === 200
        && $detail['status'] === 200
        && !str_contains($list['body'], $editHref)
        && !str_contains($detail['body'], $editHref),
        'Staff can view the request without receiving an Edit action'
    );
    leaveAuthorizationCheck($staff->get($editHref)['status'] === 403, 'Staff edit URL is blocked server-side');

    $blockedUpdate = $staff->post('/leave/'.$leaveId, [
        'leave_type_id'=>$leaveTypeId,
        'start_date'=>date('Y-m-d', strtotime('+10 days')),
        'end_date'=>date('Y-m-d', strtotime('+10 days')),
        'days'=>'1',
        'reason'=>'Staff tried to change this request',
        'status'=>'Approved',
    ]);
    $query->execute([$staffProfileId, $reason]);
    $unchanged = $query->fetch();
    leaveAuthorizationCheck(
        $blockedUpdate['status'] === 403 && $unchanged && $unchanged['status'] === 'Pending Manager',
        'Direct staff update and self-approval attempt is rejected'
    );

    $hr = new LeaveAuthorizationHttp($base);
    leaveAuthorizationCheck($hr->login($hrEmail, $password) === 302, 'HR login succeeds');
    $hrEdit = $hr->get($editHref);
    leaveAuthorizationCheck(
        $hrEdit['status'] === 200
        && str_contains($hrEdit['body'], 'name="status"')
        && str_contains($hrEdit['body'], 'name="rejection_reason"'),
        'Authorized HR receives leave approval controls'
    );

    $approved = $hr->post('/leave/'.$leaveId, [
        'staff_id'=>$staffProfileId,
        'leave_type_id'=>$leaveTypeId,
        'start_date'=>$leave['start_date'],
        'end_date'=>$leave['end_date'],
        'days'=>$leave['days'],
        'reason'=>$leave['reason'],
        'status'=>'Approved',
        'rejection_reason'=>'',
    ]);
    $approvedStatus = $db->query('SELECT status FROM leave_requests WHERE id='.$leaveId)->fetchColumn();
    leaveAuthorizationCheck($approved['status'] === 302 && $approvedStatus === 'Approved', 'Authorized HR can approve the leave request');

    echo "\n{$passes} leave authorization checks passed.\n";
} finally {
    if ($leaveId) $db->prepare('DELETE FROM leave_requests WHERE id=?')->execute([$leaveId]);
    $db->prepare('DELETE FROM audit_logs WHERE id>?')->execute([$auditStart]);
    $db->prepare('DELETE FROM activity_logs WHERE id>?')->execute([$activityStart]);
    if ($staffUserId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$staffUserId]);
    if ($hrUserId) $db->prepare('DELETE FROM users WHERE id=?')->execute([$hrUserId]);
}
