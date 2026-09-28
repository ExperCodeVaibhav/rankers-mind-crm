<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;

final class SearchController extends Controller
{
    public function index(): void
    {
        if (auth()['user_type'] !== 'admin') { $this->redirect('dashboard'); }
        $term = trim($_GET['q'] ?? '');
        $results = [];
        if (mb_strlen($term) >= 2) {
            $db = Database::connection();
            $like = '%' . $term . '%';
            $sets = [
                ['Leads', 'leads', "SELECT id,lead_code code,CONCAT(person_name,IF(company_name IS NULL,'',CONCAT(' · ',company_name))) title FROM leads WHERE deleted_at IS NULL AND (person_name LIKE ? OR company_name LIKE ? OR lead_code LIKE ?) LIMIT 10"],
                ['Clients', 'clients', 'SELECT id,client_code code,company_name title FROM clients WHERE deleted_at IS NULL AND (company_name LIKE ? OR primary_contact LIKE ? OR client_code LIKE ?) LIMIT 10'],
                ['Projects', 'projects', 'SELECT id,project_code code,name title FROM projects WHERE deleted_at IS NULL AND (name LIKE ? OR project_code LIKE ? OR description LIKE ?) LIMIT 10'],
                ['Staff', 'staff', 'SELECT s.id,s.employee_code code,u.name title FROM staff_profiles s JOIN users u ON u.id=s.user_id WHERE s.deleted_at IS NULL AND (u.name LIKE ? OR s.employee_code LIKE ? OR u.email LIKE ?) LIMIT 10'],
                ['Invoices', 'invoices', "SELECT id,invoice_number code,CONCAT(invoice_number,' · INR ',FORMAT(grand_total,2)) title FROM invoices WHERE deleted_at IS NULL AND (invoice_number LIKE ? OR notes LIKE ? OR status LIKE ?) LIMIT 10"],
            ];
            foreach ($sets as [$label, $route, $sql]) {
                $query = $db->prepare($sql);
                $query->execute([$like, $like, $like]);
                $results[] = ['label' => $label, 'route' => $route, 'rows' => $query->fetchAll()];
            }
        }
        $this->render('search/index', compact('term', 'results') + ['title' => 'Global Search']);
    }
}
