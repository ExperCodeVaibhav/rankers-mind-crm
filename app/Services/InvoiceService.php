<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class InvoiceService
{
    public static function create(array $data,array $items): int
    {
        return Database::transaction(function(PDO $db)use($data,$items):int{
            if(!$items)throw new \RuntimeException('Add at least one invoice line item.');$subtotal=$tax=0;$calculated=[];
            foreach($items as $item){$qty=(float)$item['quantity'];$rate=(float)$item['rate'];$discount=(float)($item['discount']??0);$taxPercent=(float)($item['tax_percent']??0);if($qty<=0||$rate<0)throw new \RuntimeException('Line item quantity and rate must be valid.');$taxable=max(0,$qty*$rate-$discount);$taxAmount=round($taxable*$taxPercent/100,2);$subtotal+=$taxable;$tax+=$taxAmount;$calculated[]=$item+['taxable_amount'=>$taxable,'tax_amount'=>$taxAmount,'amount'=>$taxable+$taxAmount];}
            $additional=(float)($data['additional_charges']??0);$grand=round($subtotal+$tax+$additional,2);$number=self::nextNumber($db);
            $db->prepare("INSERT INTO invoices(invoice_number,invoice_type,client_id,project_id,invoice_date,due_date,gst_type,subtotal,tax_total,discount_total,additional_charges,grand_total,paid_amount,pending_amount,status,notes,terms,created_by) VALUES(?,?,?,?,?,?,?,?,?,0,?,?,0,?,'Draft',?,?,?)")
                ->execute([$number,$data['invoice_type'],$data['client_id'],$data['project_id']?:null,$data['invoice_date'],$data['due_date'],$data['gst_type'],$subtotal,$tax,$additional,$grand,$grand,$data['notes']?:null,$data['terms']?:null,auth()['id']]);
            $id=(int)$db->lastInsertId();$insert=$db->prepare('INSERT INTO invoice_items(invoice_id,description,hsn_sac,quantity,rate,tax_percent,discount,taxable_amount,tax_amount,amount) VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach($calculated as $item)$insert->execute([$id,$item['description'],$item['hsn_sac']?:null,$item['quantity'],$item['rate'],$item['tax_percent'],$item['discount']??0,$item['taxable_amount'],$item['tax_amount'],$item['amount']]);
            AuditService::log('created','invoices',$id,[],['invoice_number'=>$number,'grand_total'=>$grand]);return $id;
        });
    }

    public static function recordPayment(int $invoiceId,array $data): int
    {
        return Database::transaction(function(PDO $db)use($invoiceId,$data):int{
            $q=$db->prepare('SELECT * FROM invoices WHERE id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$invoiceId]);$invoice=$q->fetch();if(!$invoice)throw new \RuntimeException('Invoice not found.');
            $amount=(float)$data['amount'];if($amount<=0||$amount>(float)$invoice['pending_amount'])throw new \RuntimeException('Payment must be greater than zero and cannot exceed the pending balance.');
            $number='RM-PAY-'.str_pad((string)((int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM payments')->fetchColumn()),4,'0',STR_PAD_LEFT);
            $db->prepare('INSERT INTO payments(payment_number,invoice_id,amount,payment_date,payment_mode,reference_number,bank_account,notes,recorded_by) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$number,$invoiceId,$amount,$data['payment_date'],$data['payment_mode'],$data['reference_number']?:null,$data['bank_account']?:null,$data['notes']?:null,auth()['id']]);$paymentId=(int)$db->lastInsertId();
            $paid=(float)$invoice['paid_amount']+$amount;$pending=max(0,(float)$invoice['grand_total']-$paid);$status=$pending<=0?'Paid':'Partially Paid';$db->prepare('UPDATE invoices SET paid_amount=?,pending_amount=?,status=?,updated_by=? WHERE id=?')->execute([$paid,$pending,$status,auth()['id'],$invoiceId]);
            $db->prepare('INSERT INTO payment_receipts(payment_id,receipt_number) VALUES(?,?)')->execute([$paymentId,'RM-RCT-'.str_pad((string)$paymentId,4,'0',STR_PAD_LEFT)]);
            if($invoice['client_id']){$u=$db->prepare('SELECT portal_user_id FROM clients WHERE id=?');$u->execute([$invoice['client_id']]);if($uid=$u->fetchColumn())NotificationService::send((int)$uid,'payment_received','Payment received','Payment '.$number.' was recorded against '.$invoice['invoice_number'].'.','/portal/client#invoices');}
            return $paymentId;
        });
    }

    private static function nextNumber(PDO $db):string{$next=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM invoices')->fetchColumn();return 'RM-INV-'.str_pad((string)$next,4,'0',STR_PAD_LEFT);}
}
