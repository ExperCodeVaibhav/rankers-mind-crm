<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class EstimateService
{
    public static function create(array $data, array $items): int
    {
        return Database::transaction(function (PDO $db) use ($data, $items): int {
            if (!$items) {
                throw new \RuntimeException('Add at least one estimate line item.');
            }
            $subtotal = 0.0;
            $tax = 0.0;
            $calculated = [];
            foreach ($items as $item) {
                $quantity = (float) ($item['quantity'] ?? 0);
                $rate = (float) ($item['rate'] ?? 0);
                $taxPercent = (float) ($item['tax_percent'] ?? 0);
                if ($quantity <= 0 || $rate < 0 || $taxPercent < 0 || $taxPercent > 100) {
                    throw new \RuntimeException('Line item quantity, rate and tax must be valid.');
                }
                $base = round($quantity * $rate, 2);
                $taxAmount = round($base * $taxPercent / 100, 2);
                $subtotal += $base;
                $tax += $taxAmount;
                $calculated[] = [
                    'description' => trim((string) $item['description']),
                    'quantity' => $quantity,
                    'rate' => $rate,
                    'tax_percent' => $taxPercent,
                    'amount' => $base + $taxAmount,
                ];
            }
            $discount = max(0, min((float) ($data['discount_total'] ?? 0), $subtotal + $tax));
            $grand = round($subtotal + $tax - $discount, 2);
            $next = (int) $db->query('SELECT COALESCE(MAX(id),0)+1 FROM estimates')->fetchColumn();
            $number = 'RM-EST-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $db->prepare("INSERT INTO estimates(estimate_number,estimate_type,client_id,project_id,estimate_date,valid_until,subtotal,tax_total,discount_total,grand_total,status,notes,terms,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'Draft',?,?,?)")
                ->execute([$number, $data['estimate_type'], $data['client_id'], $data['project_id'] ?: null, $data['estimate_date'], $data['valid_until'] ?: null, $subtotal, $tax, $discount, $grand, $data['notes'] ?: null, $data['terms'] ?: null, auth()['id']]);
            $id = (int) $db->lastInsertId();
            $insert = $db->prepare('INSERT INTO estimate_items(estimate_id,description,quantity,rate,tax_percent,amount) VALUES(?,?,?,?,?,?)');
            foreach ($calculated as $item) {
                $insert->execute([$id, $item['description'], $item['quantity'], $item['rate'], $item['tax_percent'], $item['amount']]);
            }
            AuditService::log('created', 'estimates', $id, [], ['estimate_number' => $number, 'grand_total' => $grand]);
            return $id;
        });
    }

    public static function convertToInvoice(int $estimateId): int
    {
        return Database::transaction(function (PDO $db) use ($estimateId): int {
            $q = $db->prepare('SELECT * FROM estimates WHERE id=? AND deleted_at IS NULL FOR UPDATE');
            $q->execute([$estimateId]);
            $estimate = $q->fetch();
            if (!$estimate) {
                throw new \RuntimeException('Estimate not found.');
            }
            if (!in_array($estimate['status'], ['Accepted', 'Sent'], true)) {
                throw new \RuntimeException('Only a sent or accepted estimate can be converted.');
            }
            $existing = $db->prepare("SELECT id FROM invoices WHERE notes LIKE ? AND deleted_at IS NULL LIMIT 1");
            $existing->execute(['Converted from ' . $estimate['estimate_number'] . '%']);
            if ($invoiceId = $existing->fetchColumn()) {
                return (int) $invoiceId;
            }
            $q = $db->prepare('SELECT * FROM estimate_items WHERE estimate_id=?');
            $q->execute([$estimateId]);
            $items = $q->fetchAll();
            $next = (int) $db->query('SELECT COALESCE(MAX(id),0)+1 FROM invoices')->fetchColumn();
            $number = 'RM-INV-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $invoiceType = $estimate['estimate_type'] === 'Proforma' ? 'Proforma Invoice' : 'Tax Invoice';
            $gstType = (float) $estimate['tax_total'] > 0 ? 'CGST_SGST' : 'No Tax';
            $notes = 'Converted from ' . $estimate['estimate_number'] . ($estimate['notes'] ? "\n" . $estimate['notes'] : '');
            $db->prepare("INSERT INTO invoices(invoice_number,invoice_type,client_id,project_id,invoice_date,due_date,gst_type,subtotal,tax_total,discount_total,additional_charges,grand_total,paid_amount,pending_amount,status,notes,terms,created_by) VALUES(?,?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 30 DAY),?,?,?,?,0,?,0,?,'Draft',?,?,?)")
                ->execute([$number, $invoiceType, $estimate['client_id'], $estimate['project_id'], $gstType, $estimate['subtotal'], $estimate['tax_total'], $estimate['discount_total'], $estimate['grand_total'], $estimate['grand_total'], $notes, $estimate['terms'], auth()['id']]);
            $invoiceId = (int) $db->lastInsertId();
            $insert = $db->prepare('INSERT INTO invoice_items(invoice_id,description,hsn_sac,quantity,rate,tax_percent,discount,taxable_amount,tax_amount,amount) VALUES(?,?,NULL,?,?,?,0,?,?,?)');
            foreach ($items as $item) {
                $taxable = round((float) $item['quantity'] * (float) $item['rate'], 2);
                $taxAmount = round($taxable * (float) $item['tax_percent'] / 100, 2);
                $insert->execute([$invoiceId, $item['description'], $item['quantity'], $item['rate'], $item['tax_percent'], $taxable, $taxAmount, $item['amount']]);
            }
            $db->prepare("UPDATE estimates SET status='Converted' WHERE id=?")->execute([$estimateId]);
            AuditService::log('converted', 'estimates', $estimateId, $estimate, ['invoice_id' => $invoiceId]);
            return $invoiceId;
        });
    }
}
