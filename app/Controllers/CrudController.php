<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Validator;
use App\Services\EntityService;

final class CrudController extends Controller
{
    private function config(string $entity,array $actions=['view']):array
    {
        $config=Config::get('entities.'.$entity);if(!$config){http_response_code(404);view('errors/404',['title'=>'Module not found']);exit;}
        if (auth()['user_type'] === 'client') { http_response_code(403); view('errors/403',['title'=>'Access denied']); exit; }
        if (auth()['user_type'] === 'staff') {
            $allowed = ['projects','tasks','leave','salary-advances','attendance-corrections','tickets'];
            if (!in_array($entity, $allowed, true)) { http_response_code(403); view('errors/403',['title'=>'Access denied']); exit; }
            foreach ($actions as $action) {
                if ($action === 'create' && !in_array($entity, ['leave','salary-advances','attendance-corrections'], true)) { http_response_code(403); view('errors/403',['title'=>'Access denied']); exit; }
                if ($action === 'edit' && $entity === 'leave') { http_response_code(403); view('errors/403',['title'=>'Access denied']); exit; }
                if ($action === 'edit' && $entity === 'projects') { http_response_code(403); view('errors/403',['title'=>'Access denied']); exit; }
                if ($action === 'delete') { http_response_code(403); view('errors/403',['title'=>'Access denied']); exit; }
            }
        }
        foreach($actions as $action)if(!can($config['permission'].'.'.$action)){http_response_code(403);view('errors/403',['title'=>'Access denied']);exit;}
        if ($entity === 'leave' && in_array('edit', $actions, true) && !can('leave.approve')) {
            http_response_code(403); view('errors/403',['title'=>'Access denied']); exit;
        }
        if ($entity === 'leave' && in_array('create', $actions, true)) {
            unset($config['fields']['status'], $config['fields']['rejection_reason']);
            if (auth()['user_type'] === 'staff') unset($config['fields']['staff_id']);
        }
        if (auth()['user_type'] === 'staff' && $entity === 'tasks' && in_array('edit', $actions, true)) {
            $config['fields'] = array_intersect_key($config['fields'], array_flip(['status','progress','actual_hours','completion_note']));
        }
        return $config;
    }
    public function index(string $entity):void
    {
        $config=$this->config($entity);$viewMode=$_GET['view']??'list';$result=EntityService::paginate($config,$_GET,max(1,(int)($_GET['page']??1)),in_array($viewMode,['board','calendar'],true)?250:15);$relations=EntityService::relationOptions($config);$q=Database::connection()->prepare('SELECT * FROM saved_filters WHERE user_id=? AND module=? ORDER BY name');$q->execute([auth()['id'],$entity]);$savedFilters=$q->fetchAll();
        if($entity==='followups'&&($_GET['status']??'')==='overdue'){$this->redirect('followups?status=Scheduled');}
        $this->render('crud/index',compact('entity','config','result','relations','savedFilters')+['title'=>$config['title']]);
    }
    public function create(string $entity):void{$config=$this->config($entity,['create']);if($entity==='tickets')$config['fields']['status']['options']=['Open','Assigned','In Progress','Waiting for Client','Reopened'];$relations=EntityService::relationOptions($config);$this->limitStaffRelations($relations);$this->render('crud/form',compact('entity','config','relations')+['record'=>[],'title'=>'Create '.$config['singular']]);}
    public function store(string $entity):void
    {
        $config=$this->config($entity,['create']);$data=EntityService::sanitize($config,$_POST);if($entity==='tickets'&&in_array($data['status']??'', ['Resolved','Closed'],true))$data['status']='Open';$this->forceStaffOwnership($entity,$data);$this->validate($entity,$config,$data);
        try{$id=EntityService::create($config,$data);flash('success',$config['singular'].' created successfully.');$this->redirect($entity.'/'.$id);}catch(\Throwable $e){$_SESSION['_old']=$_POST;flash('error',$this->friendly($e));$this->redirect($entity.'/create');}
    }
    public function show(string $entity,string $id):void
    {
        $config=$this->config($entity);$record=EntityService::find($config,(int)$id);if(!$record||!$this->owns($entity,$record)){http_response_code(404);view('errors/404',['title'=>'Record not found']);return;}$relations=EntityService::relationOptions($config);$this->limitStaffRelations($relations);$related=$this->related($entity,(int)$id);$db=Database::connection();$db->prepare('INSERT INTO recent_records(user_id,record_type,record_id,viewed_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE viewed_at=NOW()')->execute([auth()['id'],$entity,(int)$id]);$q=$db->prepare('SELECT 1 FROM favourites WHERE user_id=? AND record_type=? AND record_id=?');$q->execute([auth()['id'],$entity,(int)$id]);$isFavourite=(bool)$q->fetchColumn();
        $this->render('crud/show',compact('entity','config','record','relations','related','isFavourite')+['title'=>$config['singular'].' details']);
    }
    public function edit(string $entity,string $id):void{$config=$this->config($entity,['edit']);$record=EntityService::find($config,(int)$id);if(!$record||!$this->owns($entity,$record)){http_response_code(404);view('errors/404',['title'=>'Record not found']);return;}$this->populateVirtual($entity,(int)$id,$record);$relations=EntityService::relationOptions($config);$this->limitStaffRelations($relations);$this->render('crud/form',compact('entity','config','record','relations')+['title'=>'Edit '.$config['singular']]);}
    public function update(string $entity,string $id):void
    {
        $config=$this->config($entity,['edit']);$existing=EntityService::find($config,(int)$id);if(!$existing||!$this->owns($entity,$existing)){http_response_code(404);view('errors/404',['title'=>'Record not found']);return;}$data=EntityService::sanitize($config,$_POST);if($entity==='tickets')$data['status']=$existing['status'];if(auth()['user_type']==='staff'&&$entity==='tasks'){$data=array_intersect_key($data,array_flip(['status','progress','actual_hours','completion_note']));if($existing['approval_status']==='Approved'){$data['status']='Completed';$data['progress']=100;}}$this->forceStaffOwnership($entity,$data);$this->validate($entity,$config,$data,$id);
        try{EntityService::update($config,(int)$id,$data);flash('success',$config['singular'].' updated successfully.');$this->redirect($entity.'/'.$id);}catch(\Throwable $e){$_SESSION['_old']=$_POST;flash('error',$this->friendly($e));$this->redirect($entity.'/'.$id.'/edit');}
    }
    public function delete(string $entity,string $id):void{$config=$this->config($entity,['delete']);try{EntityService::delete($config,(int)$id);flash('success',$config['singular'].' removed.');}catch(\Throwable $e){flash('error',$this->friendly($e));}$this->redirect($entity);}
    public function quickStatus(string $entity,string $id):void
    {
        $config=$this->config($entity,['edit']);$record=EntityService::find($config,(int)$id);if(!$record||!$this->owns($entity,$record)){http_response_code(404);view('errors/404',['title'=>'Record not found']);return;}$field=['leads'=>'stage','tasks'=>'status','projects'=>'status','followups'=>'status','tickets'=>'status'][$entity]??null;if(!$field){flash('error','Quick status is not supported for this module.');$this->redirect($entity);}$value=$_POST['value']??'';if($entity==='tickets'&&in_array($value,['Resolved','Closed'],true)){flash('error','Use the ticket resolution action. Only the client can close a resolved ticket.');$this->redirect('tickets/'.$id);}$options=$config['fields'][$field]['options']??[];$allowed=[];foreach($options as $key=>$label)$allowed[]=is_int($key)?$label:$key;if(!in_array($value,$allowed,true)){flash('error','Select a valid status.');$this->redirect($entity);}EntityService::update($config,(int)$id,[$field=>$value]);flash('success',$config['singular'].' status updated.');$this->redirect($_POST['_redirect']??$entity);
    }
    public function export(string $entity):void
    {
        $config=$this->config($entity,['export']);$result=EntityService::paginate($config,$_GET,1,10000);header('Content-Type:text/csv;charset=utf-8');header('Content-Disposition:attachment;filename="'.$entity.'_'.date('Ymd_His').'.csv"');$out=fopen('php://output','wb');fputcsv($out,array_merge(['ID'],$config['list']));foreach($result['rows'] as $row){$line=[$row['id']];foreach($config['list'] as $field)$line[]=$row[$field]??'';fputcsv($out,$line);}fclose($out);exit;
    }
    public function toggleFavourite(string $entity,string $id):void
    {
        $config=$this->config($entity);$record=EntityService::find($config,(int)$id);if(!$record||!$this->owns($entity,$record)){http_response_code(404);view('errors/404',['title'=>'Record not found']);return;}$db=Database::connection();$q=$db->prepare('SELECT 1 FROM favourites WHERE user_id=? AND record_type=? AND record_id=?');$q->execute([auth()['id'],$entity,(int)$id]);if($q->fetchColumn()){$db->prepare('DELETE FROM favourites WHERE user_id=? AND record_type=? AND record_id=?')->execute([auth()['id'],$entity,(int)$id]);flash('success','Removed from favourites.');}else{$db->prepare('INSERT INTO favourites(user_id,record_type,record_id) VALUES(?,?,?)')->execute([auth()['id'],$entity,(int)$id]);flash('success','Added to favourites.');}$this->redirect($entity.'/'.$id);
    }
    public function saveFilter(string $entity):void
    {
        $this->config($entity);$name=trim($_POST['name']??'');parse_str((string)($_POST['query']??''),$query);$allowed=array_intersect_key($query,array_flip(['q','sort','direction','view',...array_keys((array)Config::get('entities.'.$entity.'.fields',[]))]));unset($allowed['page']);if($name===''||!$allowed){flash('error','Enter a name after applying at least one filter.');$this->redirect($entity);}$q=Database::connection()->prepare('INSERT INTO saved_filters(user_id,module,name,filter_json) VALUES(?,?,?,?)');$q->execute([auth()['id'],$entity,mb_substr($name,0,100),json_encode($allowed,JSON_UNESCAPED_UNICODE)]);flash('success','Filter saved for your account.');$this->redirect($entity.'?'.http_build_query($allowed));
    }
    public function deleteFilter(string $entity,string $id):void
    {
        $this->config($entity);Database::connection()->prepare('DELETE FROM saved_filters WHERE id=? AND user_id=? AND module=?')->execute([(int)$id,auth()['id'],$entity]);flash('success','Saved filter removed.');$this->redirect($entity);
    }
    public function favourites():void
    {
        if(auth()['user_type']==='client'){$this->redirect('portal/client');}$db=Database::connection();$load=function(string $table,string $order)use($db):array{$q=$db->prepare("SELECT record_type,record_id,{$order} moment FROM {$table} WHERE user_id=? ORDER BY {$order} DESC LIMIT 40");$q->execute([auth()['id']]);$items=[];foreach($q->fetchAll() as $row){$config=Config::get('entities.'.$row['record_type']);if(!$config||!can($config['permission'].'.view'))continue;$record=EntityService::find($config,(int)$row['record_id']);if(!$record||!$this->owns($row['record_type'],$record))continue;$items[]=['entity'=>$row['record_type'],'id'=>$record['id'],'title'=>$record[$config['code']['field']??'']??$record['title']??$record['name']??$record['person_name']??$config['singular'].' #'.$record['id'],'module'=>$config['title'],'icon'=>$config['icon'],'moment'=>$row['moment']];}return $items;};$favourites=$load('favourites','created_at');$recent=$load('recent_records','viewed_at');$this->render('crud/favourites',compact('favourites','recent')+['title'=>'Favourites & Recent Records']);
    }
    private function validate(string $entity,array $config,array $data,?string $id=null):void
    {
        $rules=[];foreach($config['fields'] as $field=>$meta)$rules[$field]=$meta['rules']??'nullable';$errors=(new Validator())->validate($data,$rules);
        if($entity==='leads'){$sql='SELECT id FROM leads WHERE deleted_at IS NULL AND id<>? AND ((phone=? AND phone<>\'\') OR (email IS NOT NULL AND email=? AND email<>\'\')) LIMIT 1';$q=Database::connection()->prepare($sql);$q->execute([(int)$id,$data['phone']??'',$data['email']??'']);if($q->fetch())$errors['phone'][]='A lead already exists with this phone or email address.';}
        if($errors){$_SESSION['_errors']=$errors;$_SESSION['_old']=$_POST;flash('error','Please correct the highlighted fields.');$this->redirect($id?$entity.'/'.$id.'/edit':$entity.'/create');}
    }
    private function related(string $entity,int $id):array
    {
        $db=Database::connection();$data=[];
        if($entity==='leads'){$q=$db->prepare('SELECT a.*,u.name user_name FROM lead_activities a LEFT JOIN users u ON u.id=a.user_id WHERE a.lead_id=? ORDER BY a.happened_at DESC');$q->execute([$id]);$data['timeline']=$q->fetchAll();$q=$db->prepare('SELECT * FROM lead_followups WHERE lead_id=? AND deleted_at IS NULL ORDER BY scheduled_at DESC');$q->execute([$id]);$data['followups']=$q->fetchAll();$q=$db->prepare('SELECT * FROM lead_attachments WHERE lead_id=? AND deleted_at IS NULL ORDER BY created_at DESC');$q->execute([$id]);$data['attachments']=$q->fetchAll();$q=$db->prepare('SELECT t.* FROM tags t JOIN lead_tags lt ON lt.tag_id=t.id WHERE lt.lead_id=? ORDER BY t.name');$q->execute([$id]);$data['tags']=$q->fetchAll();}
        if($entity==='projects'){$q=$db->prepare('SELECT t.* FROM tasks t WHERE t.project_id=? AND t.deleted_at IS NULL ORDER BY due_date');$q->execute([$id]);$data['tasks']=$q->fetchAll();$q=$db->prepare('SELECT pu.*,u.name FROM project_updates pu JOIN users u ON u.id=pu.user_id WHERE project_id=? ORDER BY pu.created_at DESC');$q->execute([$id]);$data['updates']=$q->fetchAll();$q=$db->prepare('SELECT pm.*,u.name FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE project_id=? ORDER BY u.name');$q->execute([$id]);$data['members']=$q->fetchAll();$q=$db->prepare('SELECT * FROM project_milestones WHERE project_id=? ORDER BY due_date');$q->execute([$id]);$data['milestones']=$q->fetchAll();$q=$db->prepare('SELECT * FROM project_files WHERE project_id=? AND deleted_at IS NULL ORDER BY created_at DESC');$q->execute([$id]);$data['files']=$q->fetchAll();}
        if($entity==='clients'){$q=$db->prepare('SELECT u.id,u.email,u.status,u.must_change_password,u.last_login_at,u.created_at FROM clients c LEFT JOIN users u ON u.id=c.portal_user_id WHERE c.id=?');$q->execute([$id]);$data['portal_user']=$q->fetch()?:null;$q=$db->prepare("SELECT (SELECT COUNT(*) FROM projects WHERE client_id=? AND deleted_at IS NULL) projects,(SELECT COUNT(*) FROM invoices WHERE client_id=? AND deleted_at IS NULL) invoices,(SELECT COUNT(*) FROM tickets WHERE client_id=? AND deleted_at IS NULL) tickets,(SELECT COUNT(*) FROM client_documents WHERE client_id=? AND deleted_at IS NULL) documents");$q->execute([$id,$id,$id,$id]);$data['counts']=$q->fetch();}
        if($entity==='tasks'){$q=$db->prepare('SELECT c.*,u.name FROM task_comments c JOIN users u ON u.id=c.user_id WHERE task_id=? AND c.deleted_at IS NULL ORDER BY c.created_at');$q->execute([$id]);$data['comments']=$q->fetchAll();$q=$db->prepare('SELECT u.id,u.name FROM task_assignees ta JOIN users u ON u.id=ta.user_id WHERE task_id=?');$q->execute([$id]);$data['assignees']=$q->fetchAll();$q=$db->prepare('SELECT tu.*,u.name FROM task_updates tu JOIN users u ON u.id=tu.user_id WHERE tu.task_id=? ORDER BY tu.created_at DESC');$q->execute([$id]);$data['updates']=$q->fetchAll();$q=$db->prepare('SELECT * FROM task_checklists WHERE task_id=? ORDER BY id');$q->execute([$id]);$data['checklists']=$q->fetchAll();$q=$db->prepare('SELECT tl.*,u.name FROM task_time_logs tl JOIN users u ON u.id=tl.user_id WHERE tl.task_id=? ORDER BY tl.work_date DESC,tl.id DESC');$q->execute([$id]);$data['time_logs']=$q->fetchAll();$q=$db->prepare("SELECT * FROM file_uploads WHERE related_type='task' AND related_id=? AND deleted_at IS NULL ORDER BY created_at DESC");$q->execute([$id]);$data['files']=$q->fetchAll();}
        if($entity==='tickets'){$q=$db->prepare('SELECT m.*,u.name,u.user_type FROM ticket_messages m JOIN users u ON u.id=m.user_id WHERE ticket_id=? AND m.deleted_at IS NULL ORDER BY m.created_at');$q->execute([$id]);$data['messages']=$q->fetchAll();}
        return $data;
    }
    private function friendly(\Throwable $e):string{return str_contains($e->getMessage(),'Duplicate entry')?'A record with the same unique value already exists.':$e->getMessage();}
    private function owns(string $entity,array $record):bool
    {
        if(auth()['user_type']!=='staff')return true;$db=Database::connection();$userId=(int)auth()['id'];
        if($entity==='tasks'){$q=$db->prepare('SELECT 1 FROM task_assignees WHERE task_id=? AND user_id=?');$q->execute([$record['id'],$userId]);return(bool)$q->fetchColumn();}
        if($entity==='projects'){$q=$db->prepare('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?');$q->execute([$record['id'],$userId]);return(bool)$q->fetchColumn();}
        if(in_array($entity,['leave','salary-advances','attendance-corrections'],true)){$q=$db->prepare('SELECT 1 FROM staff_profiles WHERE id=? AND user_id=?');$q->execute([$record['staff_id'],$userId]);return(bool)$q->fetchColumn();}
        if($entity==='tickets'){
            if((int)$record['assigned_to']===$userId)return true;
            $q=$db->prepare('SELECT 1 FROM tickets t JOIN clients c ON c.id=t.client_id LEFT JOIN projects p ON p.id=t.project_id AND p.deleted_at IS NULL LEFT JOIN project_members pm ON pm.project_id=t.project_id AND pm.user_id=? WHERE t.id=? AND (c.project_manager_id=? OR p.project_manager_id=? OR pm.user_id=?)');
            $q->execute([$userId,$record['id'],$userId,$userId,$userId]);
            return(bool)$q->fetchColumn();
        }
        return false;
    }
    private function forceStaffOwnership(string $entity,array &$data):void
    {
        if(auth()['user_type']!=='staff'||!in_array($entity,['leave','salary-advances','attendance-corrections'],true))return;$q=Database::connection()->prepare('SELECT id FROM staff_profiles WHERE user_id=? AND deleted_at IS NULL');$q->execute([auth()['id']]);$data['staff_id']=(int)$q->fetchColumn();
        if($entity==='leave')$data['status']='Pending Manager';if($entity==='salary-advances')$data['status']='Pending';
        if($entity==='attendance-corrections')$data['status']='Pending';
    }
    private function limitStaffRelations(array &$relations):void
    {
        if(auth()['user_type']!=='staff'||empty($relations['staff_id']))return;$q=Database::connection()->prepare('SELECT id FROM staff_profiles WHERE user_id=? AND deleted_at IS NULL');$q->execute([auth()['id']]);$staffId=(int)$q->fetchColumn();$relations['staff_id']=array_values(array_filter($relations['staff_id'],fn($row)=>(int)$row['id']===$staffId));
    }
    private function populateVirtual(string $entity,int $id,array &$record):void
    {
        $db=Database::connection();
        if($entity==='projects'){$q=$db->prepare('SELECT user_id FROM project_members WHERE project_id=?');$q->execute([$id]);$record['member_ids']=array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN));}
        if($entity==='tasks'){$q=$db->prepare('SELECT user_id FROM task_assignees WHERE task_id=?');$q->execute([$id]);$record['assignee_ids']=array_map('intval',$q->fetchAll(\PDO::FETCH_COLUMN));}
    }
}
