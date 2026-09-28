<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Services\EntityService;
use App\Services\LeadConversionService;
use App\Services\AuditService;

final class LeadController extends Controller
{
    public function importForm():void
    {
        if(!can('leads.create')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        $this->render('leads/import',['title'=>'Import Leads']);
    }
    public function import():void
    {
        if(!can('leads.create')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$file=$_FILES['csv']??null;if(!$file||$file['error']!==UPLOAD_ERR_OK){flash('error','Select a valid CSV file.');$this->redirect('leads/import');}if($file['size']>5*1024*1024){flash('error','CSV file must not exceed 5 MB.');$this->redirect('leads/import');}$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);if(!in_array($mime,['text/plain','text/csv','application/csv','application/vnd.ms-excel'],true)){flash('error','Only CSV files are allowed.');$this->redirect('leads/import');}$handle=fopen($file['tmp_name'],'rb');$header=array_map(fn($v)=>strtolower(trim((string)$v)),fgetcsv($handle)?:[]);$required=['person_name','phone','service_interest'];if(array_diff($required,$header)){fclose($handle);flash('error','CSV headers must include person_name, phone and service_interest.');$this->redirect('leads/import');}$created=0;$skipped=0;$config=Config::get('entities.leads');$db=Database::connection();while(($values=fgetcsv($handle))!==false){$row=array_combine($header,array_pad($values,count($header),''));if(!$row||trim($row['person_name']??'')===''||trim($row['phone']??'')===''){$skipped++;continue;}$dupe=$db->prepare("SELECT id FROM leads WHERE deleted_at IS NULL AND (phone=? OR (email IS NOT NULL AND email<>'' AND email=?)) LIMIT 1");$dupe->execute([trim($row['phone']),trim($row['email']??'')]);if($dupe->fetch()){$skipped++;continue;}$data=['person_name'=>trim($row['person_name']),'company_name'=>trim($row['company_name']??'')?:null,'phone'=>trim($row['phone']),'alternative_phone'=>trim($row['alternative_phone']??'')?:null,'email'=>trim($row['email']??'')?:null,'city'=>trim($row['city']??'')?:null,'state'=>trim($row['state']??'')?:null,'country'=>trim($row['country']??'India')?:'India','service_interest'=>trim($row['service_interest']),'estimated_budget'=>is_numeric($row['estimated_budget']??null)?$row['estimated_budget']:null,'expected_value'=>is_numeric($row['expected_value']??null)?$row['expected_value']:null,'source'=>trim($row['source']??'CSV Import')?:'CSV Import','assigned_to'=>auth()['id'],'stage'=>in_array($row['stage']??'', $config['fields']['stage']['options'],true)?$row['stage']:'New','classification'=>in_array($row['classification']??'', $config['fields']['classification']['options'],true)?$row['classification']:'Warm','score'=>is_numeric($row['score']??null)?max(0,min(100,(int)$row['score'])):0,'conversion_probability'=>0,'priority'=>in_array($row['priority']??'', $config['fields']['priority']['options'],true)?$row['priority']:'Medium','notes'=>trim($row['notes']??'')?:null];EntityService::create($config,$data);$created++;}fclose($handle);flash('success',"Imported {$created} leads; skipped {$skipped} invalid or duplicate rows.");$this->redirect('leads');
    }
    public function convert(string $id):void
    {
        if(!can('leads.edit')||!can('clients.create')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}
        try{$result=LeadConversionService::convert((int)$id,isset($_POST['portal_enabled']),$_POST['portal_password']??null);flash('success','Lead converted to client '.$result['client_code'].'.');$this->redirect('clients/'.$result['client_id']);}catch(\Throwable $e){flash('error',$e->getMessage());$this->redirect('leads/'.$id);}
    }
    public function bulk():void
    {
        if(!can('leads.edit')){http_response_code(403);view('errors/403',['title'=>'Access denied']);return;}$ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['ids']??[])))));if(!$ids||count($ids)>100){flash('error','Select between 1 and 100 leads.');$this->redirect('leads');}$action=$_POST['bulk_action']??'';$value=trim((string)($_POST['bulk_value']??''));$config=Config::get('entities.leads');$field=null;$allowed=[];if($action==='stage'){$field='stage';$allowed=array_values($config['fields']['stage']['options']);}elseif($action==='classification'){$field='classification';$allowed=array_values($config['fields']['classification']['options']);}elseif($action==='assign'&&can('leads.assign')){$field='assigned_to';$q=Database::connection()->prepare("SELECT id FROM users WHERE id=? AND status='active'");$q->execute([(int)$value]);if($q->fetchColumn())$allowed=[(string)(int)$value];}if(!$field||!in_array($value,$allowed,true)){flash('error','Choose a valid bulk action and value.');$this->redirect('leads');}$placeholders=implode(',',array_fill(0,count($ids),'?'));$db=Database::connection();$db->prepare("UPDATE leads SET `{$field}`=?,updated_by=? WHERE id IN ({$placeholders}) AND deleted_at IS NULL")->execute([$field==='assigned_to'?(int)$value:$value,auth()['id'],...$ids]);if($field==='stage'){$insert=$db->prepare("INSERT INTO lead_activities(lead_id,user_id,activity_type,subject,details) VALUES(?,?,'stage_change','Bulk stage update',?)");foreach($ids as $leadId)$insert->execute([$leadId,auth()['id'],'Stage changed to '.$value]);}AuditService::log('bulk_updated','leads',null,[],['ids'=>$ids,'field'=>$field,'value'=>$value]);flash('success',count($ids).' leads updated.');$this->redirect('leads');
    }
}
