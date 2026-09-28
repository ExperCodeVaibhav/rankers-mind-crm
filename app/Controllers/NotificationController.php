<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Services\NotificationService;

final class NotificationController extends Controller
{
    public function index():void{$q=Database::connection()->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 100');$q->execute([auth()['id']]);$rows=$q->fetchAll();$this->render('notifications/index',compact('rows')+['title'=>'Notifications']);}
    public function feed():void{$this->json(['ok'=>true,'count'=>NotificationService::unreadCount((int)auth()['id']),'items'=>NotificationService::unread((int)auth()['id'])]);}
    public function read(string $id):void{Database::connection()->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?')->execute([(int)$id,auth()['id']]);$this->json(['ok'=>true]);}
    public function readAll():void{Database::connection()->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([auth()['id']]);flash('success','All notifications marked as read.');$this->redirect('notifications');}
}
