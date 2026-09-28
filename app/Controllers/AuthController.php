<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\AuthService;

final class AuthController extends Controller
{
    public function login():void{$this->render('auth/login',['title'=>'Sign in']);}
    public function authenticate():void
    {
        $email=trim($_POST['email']??'');$password=$_POST['password']??'';
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password===''){$_SESSION['_old']=['email'=>$email];flash('error','Enter a valid email and password.');$this->redirect('login');}
        $result=AuthService::attempt($email,$password,isset($_POST['remember']));
        if(!$result['ok']){$_SESSION['_old']=['email'=>$email];flash('error',$result['message']);$this->redirect('login');}
        flash('success','Welcome back, '.auth()['name'].'.');
        if($result['must_change_password'])$this->redirect('account/password');
        $this->redirect('dashboard');
    }
    public function logout():void{AuthService::logout();session_start();flash('success','You have signed out securely.');$this->redirect('login');}
    public function forgot():void{$this->render('auth/forgot',['title'=>'Forgot password']);}
    public function sendReset():void
    {
        $email=trim($_POST['email']??'');$token=filter_var($email,FILTER_VALIDATE_EMAIL)?AuthService::createResetToken($email):null;
        if($token&&\App\Config\Config::get('app.env')==='development')flash('dev_link',url('reset-password?token='.urlencode($token)));
        flash('success','If that account exists, a password reset link has been prepared.');$this->redirect('forgot-password');
    }
    public function reset():void{$this->render('auth/reset',['title'=>'Reset password','token'=>$_GET['token']??'']);}
    public function updatePassword():void
    {
        $password=$_POST['password']??'';$confirm=$_POST['password_confirmation']??'';$token=$_POST['token']??'';
        if(strlen($password)<10||$password!==$confirm){flash('error','Use at least 10 characters and enter matching passwords.');$this->redirect('reset-password?token='.urlencode($token));}
        if(!AuthService::resetPassword($token,$password)){flash('error','This reset link is invalid or expired.');$this->redirect('forgot-password');}
        flash('success','Password reset. You can now sign in.');$this->redirect('login');
    }
    public function passwordForm():void{$this->render('auth/change-password',['title'=>'Change password']);}
    public function changePassword():void
    {
        $new=$_POST['new_password']??'';
        if(strlen($new)<10||$new!==($_POST['new_password_confirmation']??'')){flash('error','The new password must be at least 10 characters and match its confirmation.');$this->redirect('account/password');}
        $result=AuthService::changePassword((int)auth()['id'],$_POST['current_password']??'',$new);flash($result['ok']?'success':'error',$result['message']);$this->redirect($result['ok']?'dashboard':'account/password');
    }
}
