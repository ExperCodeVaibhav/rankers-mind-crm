<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Validator;
use App\Services\ClientAccountService;
use App\Services\EntityService;

final class ClientController extends Controller
{
    public function create(): void
    {
        $config = $this->guard('create');
        $relations = EntityService::relationOptions($config);
        $this->render('crud/form', compact('config', 'relations') + [
            'entity' => 'clients', 'record' => [], 'portalUser' => null, 'title' => 'Create Client & Portal Login',
        ]);
    }

    public function store(): void
    {
        $config = $this->guard('create');
        $data = EntityService::sanitize($config, $_POST);
        $portal = $this->portalInput();
        $this->validateInput($config, $data, $portal, null);
        try {
            $id = ClientAccountService::create($config, $data, $portal);
            flash('success', 'Client created'.($data['portal_enabled'] ? ' with a separate portal login.' : '.'));
            $this->redirect('clients/'.$id);
        } catch (\Throwable $error) {
            $_SESSION['_old'] = $_POST;
            flash('error', str_contains($error->getMessage(), 'Duplicate entry') ? 'That client login email is already in use.' : $error->getMessage());
            $this->redirect('clients/create');
        }
    }

    public function edit(string $id): void
    {
        $config = $this->guard('edit');
        $record = EntityService::find($config, (int) $id);
        if (!$record) {
            http_response_code(404);
            view('errors/404', ['title' => 'Client not found']);
            return;
        }
        $relations = EntityService::relationOptions($config);
        $portalUser = null;
        if ($record['portal_user_id']) {
            $query = Database::connection()->prepare('SELECT id,email,status,must_change_password,last_login_at FROM users WHERE id=? AND user_type=\'client\'');
            $query->execute([$record['portal_user_id']]);
            $portalUser = $query->fetch() ?: null;
        }
        $this->render('crud/form', compact('config', 'relations', 'record', 'portalUser') + [
            'entity' => 'clients', 'title' => 'Edit Client & Portal Login',
        ]);
    }

    public function update(string $id): void
    {
        $config = $this->guard('edit');
        $record = EntityService::find($config, (int) $id);
        if (!$record) {
            http_response_code(404);
            view('errors/404', ['title' => 'Client not found']);
            return;
        }
        $data = EntityService::sanitize($config, $_POST);
        $portal = $this->portalInput();
        $portalUserId = $record['portal_user_id'] ? (int) $record['portal_user_id'] : null;
        $this->validateInput($config, $data, $portal, $portalUserId);
        try {
            ClientAccountService::update((int) $id, $data, $portal);
            flash('success', 'Client details and portal access updated securely.');
            $this->redirect('clients/'.$id);
        } catch (\Throwable $error) {
            $_SESSION['_old'] = $_POST;
            flash('error', str_contains($error->getMessage(), 'Duplicate entry') ? 'That client login email is already in use.' : $error->getMessage());
            $this->redirect('clients/'.$id.'/edit');
        }
    }

    public function delete(string $id): void
    {
        $this->guard('delete');
        try {
            ClientAccountService::delete((int) $id);
            flash('success', 'Client archived and its portal login disabled.');
        } catch (\Throwable $error) {
            flash('error', $error->getMessage());
        }
        $this->redirect('clients');
    }

    private function guard(string $action): array
    {
        $config = (array) Config::get('entities.clients');
        if (auth()['user_type'] !== 'admin' || !can('clients.'.$action)) {
            http_response_code(403);
            view('errors/403', ['title' => 'Access denied']);
            exit;
        }
        return $config;
    }

    private function portalInput(): array
    {
        return [
            'email' => mb_strtolower(trim((string) ($_POST['portal_login_email'] ?? ''))),
            'password' => (string) ($_POST['portal_password'] ?? ''),
            'password_confirmation' => (string) ($_POST['portal_password_confirmation'] ?? ''),
            'status' => in_array($_POST['portal_account_status'] ?? '', ['active', 'inactive', 'locked'], true) ? $_POST['portal_account_status'] : 'active',
            'must_change_password' => isset($_POST['portal_must_change_password']) ? 1 : 0,
        ];
    }

    private function validateInput(array $config, array $data, array $portal, ?int $portalUserId): void
    {
        $rules = [];
        foreach ($config['fields'] as $field => $meta) {
            $rules[$field] = $meta['rules'] ?? 'nullable';
        }
        $errors = (new Validator())->validate($data, $rules);
        if (!empty($data['project_manager_id'])) {
            $query = Database::connection()->prepare("SELECT 1 FROM users u WHERE u.id=? AND u.status='active' AND u.deleted_at IS NULL AND (u.user_type='staff' OR EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.slug='project-manager'))");
            $query->execute([(int) $data['project_manager_id']]);
            if (!$query->fetchColumn()) {
                $errors['project_manager_id'][] = 'Select an active project manager.';
            }
        }
        if (!empty($data['support_staff_id'])) {
            $query = Database::connection()->prepare("SELECT 1 FROM users WHERE id=? AND user_type='staff' AND status='active' AND deleted_at IS NULL");
            $query->execute([(int) $data['support_staff_id']]);
            if (!$query->fetchColumn()) {
                $errors['support_staff_id'][] = 'Select an active staff member for support tickets.';
            }
        }
        if (!empty($data['portal_enabled'])) {
            if (!filter_var($portal['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['portal_login_email'][] = 'Enter a valid client login email address.';
            }
            if (!$portalUserId && strlen($portal['password']) < 10) {
                $errors['portal_password'][] = 'Set a temporary password with at least 10 characters.';
            }
            if ($portal['password'] !== '' && strlen($portal['password']) < 10) {
                $errors['portal_password'][] = 'The password must contain at least 10 characters.';
            }
            if ($portal['password'] !== $portal['password_confirmation']) {
                $errors['portal_password_confirmation'][] = 'The password confirmation does not match.';
            }
            if (filter_var($portal['email'], FILTER_VALIDATE_EMAIL)) {
                $query = Database::connection()->prepare('SELECT id FROM users WHERE email=? AND deleted_at IS NULL AND id<>? LIMIT 1');
                $query->execute([$portal['email'], $portalUserId ?? 0]);
                if ($query->fetchColumn()) {
                    $errors['portal_login_email'][] = 'This login email is already assigned to another account.';
                }
            }
        }
        if ($errors) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old'] = $_POST;
            flash('error', 'Please correct the highlighted client and portal fields.');
            $this->redirect($portalUserId || !empty($_POST['_client_id']) ? 'clients/'.((int) ($_POST['_client_id'] ?? 0)).'/edit' : 'clients/create');
        }
    }
}
