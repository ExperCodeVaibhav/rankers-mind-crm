<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class LeadConversionService
{
    public static function convert(int $leadId, bool $createPortal, ?string $password = null): array
    {
        return Database::transaction(function (PDO $db) use ($leadId,$createPortal,$password): array {
            $q=$db->prepare('SELECT * FROM leads WHERE id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$leadId]);$lead=$q->fetch();
            if(!$lead) throw new \RuntimeException('Lead not found.');
            if($lead['converted_client_id']) throw new \RuntimeException('This lead has already been converted.');
            $duplicate=$db->prepare('SELECT id FROM clients WHERE deleted_at IS NULL AND ((email IS NOT NULL AND email=?) OR phone=?) LIMIT 1');$duplicate->execute([$lead['email'],$lead['phone']]);
            if($duplicate->fetch()) throw new \RuntimeException('A client already exists with this email or phone number.');
            $portalUserId=null;
            if($createPortal&&$lead['email']){
                $existing=$db->prepare('SELECT id FROM users WHERE email=?');$existing->execute([$lead['email']]);$portalUserId=$existing->fetchColumn();
                if(!$portalUserId){$plain=$password?:'Client@'.random_int(1000,9999);$db->prepare("INSERT INTO users(branch_id,name,email,phone,password,user_type,status,must_change_password) VALUES(?,?,?,?,?,'client','active',1)")->execute([$lead['branch_id'],$lead['person_name'],$lead['email'],$lead['phone'],password_hash($plain,PASSWORD_DEFAULT)]);$portalUserId=(int)$db->lastInsertId();$db->prepare("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE slug='client'")->execute([$portalUserId]);}
            }
            $number=(int)$db->query('SELECT COALESCE(MAX(id),0)+1 FROM clients')->fetchColumn();$code='RM-CLI-'.str_pad((string)$number,3,'0',STR_PAD_LEFT);
            $db->prepare("INSERT INTO clients(branch_id,portal_user_id,client_code,company_name,primary_contact,phone,email,city,state,country,status,account_manager_id,notes,source_lead_id,portal_enabled,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'active',?,?,?,?,?)")
                ->execute([$lead['branch_id'],$portalUserId,$code,$lead['company_name']?:$lead['person_name'],$lead['person_name'],$lead['phone'],$lead['email'],$lead['city'],$lead['state'],$lead['country'],$lead['assigned_to'],$lead['notes'],$leadId,$createPortal?1:0,auth()['id']]);
            $clientId=(int)$db->lastInsertId();
            $db->prepare("UPDATE leads SET stage='Won',converted_client_id=?,converted_at=NOW(),updated_by=? WHERE id=?")->execute([$clientId,auth()['id'],$leadId]);
            $db->prepare("INSERT INTO lead_activities(lead_id,user_id,activity_type,subject,details) VALUES(?,?,'conversion','Converted to client',?)")->execute([$leadId,auth()['id'],'Created client '.$code]);
            AuditService::log('converted','leads',$leadId,$lead,['client_id'=>$clientId]);
            return ['client_id'=>$clientId,'client_code'=>$code];
        });
    }
}
