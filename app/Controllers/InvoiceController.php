<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\InvoiceService;

final class InvoiceController extends Controller
{
    private function guard(string $action='view'):void{if(!can('invoices.'.$action)){http_response_code(403);view('errors/403',['title'=>'Access denied']);exit;}}
    public function index():void
    {
        if(auth()['user_type']==='client'){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        $this->guard();$status=$_GET['status']??'';$q=trim($_GET['q']??'');$where='i.deleted_at IS NULL';$params=[];if($status!==''){$where.=' AND i.status=?';$params[]=$status;}if($q!==''){$where.=' AND (i.invoice_number LIKE ? OR c.company_name LIKE ?)';$params[]='%'.$q.'%';$params[]='%'.$q.'%';}$st=Database::connection()->prepare("SELECT i.*,c.company_name,p.name project_name FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN projects p ON p.id=i.project_id WHERE {$where} ORDER BY i.invoice_date DESC,i.id DESC");$st->execute($params);$rows=$st->fetchAll();$totals=Database::connection()->query("SELECT COALESCE(SUM(grand_total),0) invoiced,COALESCE(SUM(paid_amount),0) paid,COALESCE(SUM(pending_amount),0) pending FROM invoices WHERE deleted_at IS NULL AND status<>'Cancelled'")->fetch();$this->render('invoices/index',compact('rows','totals','status','q')+['title'=>'Invoices']);
    }
    public function create():void{if(auth()['user_type']==='client'){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$this->guard('create');$db=Database::connection();$clients=$db->query("SELECT id,company_name FROM clients WHERE status='active' AND deleted_at IS NULL ORDER BY company_name")->fetchAll();$projects=$db->query("SELECT id,name,client_id FROM projects WHERE deleted_at IS NULL ORDER BY name")->fetchAll();$this->render('invoices/form',compact('clients','projects')+['title'=>'Create Invoice']);}
    public function store():void
    {
        if(auth()['user_type']==='client'){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        $this->guard('create');$required=['client_id','invoice_date','due_date','invoice_type','gst_type'];foreach($required as $field)if(empty($_POST[$field])){flash('error','Complete all required invoice fields.');$this->redirect('invoices/create');}
        $items=[];foreach((array)($_POST['items']??[]) as $item)if(trim($item['description']??'')!=='')$items[]=$item;
        try{$id=InvoiceService::create($_POST,$items);flash('success','Invoice created and totals calculated.');$this->redirect('invoices/'.$id);}catch(\Throwable $e){flash('error',$e->getMessage());$this->redirect('invoices/create');}
    }
    public function show(string $id):void
    {
        $this->guard();$data=$this->invoice((int)$id);$this->render('invoices/show',$data+['title'=>'Invoice '.$data['invoice']['invoice_number']]);
    }
    public function payment(string $id):void
    {
        if(auth()['user_type']==='client'){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        if(!can('payments.create')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        try{InvoiceService::recordPayment((int)$id,$_POST);flash('success','Payment recorded and pending balance updated.');}catch(\Throwable $e){flash('error',$e->getMessage());}$this->redirect('invoices/'.$id);
    }
    public function print(string $id):void
    {
        $this->guard();$data=$this->invoice((int)$id);$html=$this->documentHtml($data);if(($_GET['format']??'')==='pdf'&&class_exists('Dompdf\\Dompdf')){$pdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);$pdf->loadHtml($html);$pdf->setPaper('A4');$pdf->render();$pdf->stream($data['invoice']['invoice_number'].'.pdf',['Attachment'=>true]);exit;}header('Content-Type:text/html;charset=utf-8');echo $html;exit;
    }
    public function receipt(string $id):void
    {
        $this->guard();$db=Database::connection();$sql='SELECT pay.*,r.receipt_number,i.invoice_number,i.client_id,c.company_name,c.primary_contact,c.email,c.phone,c.billing_address FROM payments pay JOIN invoices i ON i.id=pay.invoice_id JOIN clients c ON c.id=i.client_id LEFT JOIN payment_receipts r ON r.payment_id=pay.id WHERE pay.id=? AND pay.deleted_at IS NULL AND i.deleted_at IS NULL';$params=[(int)$id];if(auth()['user_type']==='client'){$sql.=' AND c.portal_user_id=? AND c.portal_enabled=1';$params[]=auth()['id'];}$q=$db->prepare($sql);$q->execute($params);$payment=$q->fetch();if(!$payment){http_response_code(404);view('errors/404',['title'=>'Receipt not found']);return;}if(!$payment['receipt_number']){$number='RM-RCT-'.str_pad((string)$payment['id'],4,'0',STR_PAD_LEFT);$db->prepare('INSERT IGNORE INTO payment_receipts(payment_id,receipt_number) VALUES(?,?)')->execute([$payment['id'],$number]);$payment['receipt_number']=$number;}ob_start();require root_path('app/Views/documents/payment-receipt.php');$html=(string)ob_get_clean();if(($_GET['format']??'')==='pdf'&&class_exists('Dompdf\\Dompdf')){$pdf=new \Dompdf\Dompdf();$pdf->loadHtml($html);$pdf->setPaper('A4');$pdf->render();$pdf->stream($payment['receipt_number'].'.pdf',['Attachment'=>true]);exit;}header('Content-Type:text/html;charset=utf-8');echo $html;exit;
    }
    private function invoice(int $id):array{$db=Database::connection();$sql='SELECT i.*,c.company_name,c.primary_contact,c.email,c.phone,c.gstin,c.billing_address,c.city,c.state,p.name project_name FROM invoices i JOIN clients c ON c.id=i.client_id LEFT JOIN projects p ON p.id=i.project_id WHERE i.id=? AND i.deleted_at IS NULL';$params=[$id];if(auth()['user_type']==='client'){$sql.=' AND c.portal_user_id=? AND c.portal_enabled=1';$params[]=auth()['id'];}$q=$db->prepare($sql);$q->execute($params);$invoice=$q->fetch();if(!$invoice){http_response_code(404);view('errors/404',['title'=>'Invoice not found']);exit;}$q=$db->prepare('SELECT * FROM invoice_items WHERE invoice_id=?');$q->execute([$id]);$items=$q->fetchAll();$q=$db->prepare('SELECT p.*,r.receipt_number FROM payments p LEFT JOIN payment_receipts r ON r.payment_id=p.id WHERE p.invoice_id=? AND p.deleted_at IS NULL ORDER BY payment_date DESC');$q->execute([$id]);return ['invoice'=>$invoice,'items'=>$items,'payments'=>$q->fetchAll()];}
    private function documentHtml(array $data):string{ob_start();$invoice=$data['invoice'];$items=$data['items'];$payments=$data['payments'];require root_path('app/Views/documents/invoice.php');return (string)ob_get_clean();}
}
