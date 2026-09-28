<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\AuditService;
use App\Services\EstimateService;

final class EstimateController extends Controller
{
    private function guard(string $action = 'view'): void
    {
        if (auth()['user_type'] === 'client' || !can('invoices.' . $action)) {
            http_response_code(403);
            view('errors/403', ['title' => 'Access denied']);
            exit;
        }
    }

    public function index(): void
    {
        $this->guard();
        $status = $_GET['status'] ?? '';
        $q = trim($_GET['q'] ?? '');
        $where = 'e.deleted_at IS NULL';
        $params = [];
        if ($status !== '') {
            $where .= ' AND e.status=?';
            $params[] = $status;
        }
        if ($q !== '') {
            $where .= ' AND (e.estimate_number LIKE ? OR c.company_name LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        $statement = Database::connection()->prepare("SELECT e.*,c.company_name,p.name project_name FROM estimates e JOIN clients c ON c.id=e.client_id LEFT JOIN projects p ON p.id=e.project_id WHERE {$where} ORDER BY e.estimate_date DESC,e.id DESC");
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $this->render('estimates/index', compact('rows', 'status', 'q') + ['title' => 'Estimates & Quotations']);
    }

    public function create(): void
    {
        $this->guard('create');
        $db = Database::connection();
        $clients = $db->query("SELECT id,company_name FROM clients WHERE status='active' AND deleted_at IS NULL ORDER BY company_name")->fetchAll();
        $projects = $db->query('SELECT id,name,client_id FROM projects WHERE deleted_at IS NULL ORDER BY name')->fetchAll();
        $this->render('estimates/form', compact('clients', 'projects') + ['title' => 'Create Estimate']);
    }

    public function store(): void
    {
        $this->guard('create');
        foreach (['client_id', 'estimate_type', 'estimate_date'] as $field) {
            if (empty($_POST[$field])) {
                flash('error', 'Complete all required estimate fields.');
                $this->redirect('estimates/create');
            }
        }
        $items = [];
        foreach ((array) ($_POST['items'] ?? []) as $item) {
            if (trim($item['description'] ?? '') !== '') {
                $items[] = $item;
            }
        }
        try {
            $id = EstimateService::create($_POST, $items);
            flash('success', 'Estimate created and totals calculated.');
            $this->redirect('estimates/' . $id);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            $this->redirect('estimates/create');
        }
    }

    public function show(string $id): void
    {
        $this->guard();
        $data = $this->estimate((int) $id);
        $this->render('estimates/show', $data + ['title' => $data['estimate']['estimate_number']]);
    }

    public function status(string $id): void
    {
        $this->guard('edit');
        $allowed = ['Draft', 'Sent', 'Accepted', 'Rejected', 'Expired'];
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $allowed, true)) {
            flash('error', 'Choose a valid estimate status.');
            $this->redirect('estimates/' . $id);
        }
        Database::connection()->prepare('UPDATE estimates SET status=? WHERE id=? AND deleted_at IS NULL')->execute([$status, (int) $id]);
        AuditService::log('status_changed', 'estimates', (int) $id, [], ['status' => $status]);
        flash('success', 'Estimate status updated.');
        $this->redirect('estimates/' . $id);
    }

    public function convert(string $id): void
    {
        $this->guard('create');
        try {
            $invoiceId = EstimateService::convertToInvoice((int) $id);
            flash('success', 'Estimate converted to a draft invoice.');
            $this->redirect('invoices/' . $invoiceId);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            $this->redirect('estimates/' . $id);
        }
    }

    public function print(string $id): void
    {
        $this->guard();
        $data = $this->estimate((int) $id);
        $estimate = $data['estimate'];
        $items = $data['items'];
        ob_start();
        require root_path('app/Views/documents/estimate.php');
        $html = (string) ob_get_clean();
        if (($_GET['format'] ?? '') === 'pdf' && class_exists('Dompdf\\Dompdf')) {
            $pdf = new \Dompdf\Dompdf();
            $pdf->loadHtml($html);
            $pdf->setPaper('A4');
            $pdf->render();
            $pdf->stream($estimate['estimate_number'] . '.pdf', ['Attachment' => true]);
            exit;
        }
        header('Content-Type:text/html;charset=utf-8');
        echo $html;
        exit;
    }

    private function estimate(int $id): array
    {
        $db = Database::connection();
        $q = $db->prepare('SELECT e.*,c.company_name,c.primary_contact,c.email,c.phone,c.gstin,c.billing_address,c.city,c.state,p.name project_name FROM estimates e JOIN clients c ON c.id=e.client_id LEFT JOIN projects p ON p.id=e.project_id WHERE e.id=? AND e.deleted_at IS NULL');
        $q->execute([$id]);
        $estimate = $q->fetch();
        if (!$estimate) {
            http_response_code(404);
            view('errors/404', ['title' => 'Estimate not found']);
            exit;
        }
        $q = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id=?');
        $q->execute([$id]);
        return ['estimate' => $estimate, 'items' => $q->fetchAll()];
    }
}
