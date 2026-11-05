<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Session;
use App\Core\View;

final class AuthController extends Controller
{
    public function loginForm(): void
    {
        if (Auth::check()) {
            $this->redirect('/admin');
        }
        View::display('Admin::auth/login', [
            'version' => APP_VERSION,
            'attempts' => (int) Session::get('login_attempts', 0),
        ]);
    }

    public function login(): never
    {
        Csrf::assert($this->request->string('_token'));
        $attempts = (int) Session::get('login_attempts', 0);
        $lastAttempt = (int) Session::get('login_last_attempt', 0);
        if ($attempts >= 6 && time() - $lastAttempt < 300) {
            flash('error', '尝试次数过多，请 5 分钟后再试。');
            $this->redirect('/admin/login');
        }

        $username = $this->request->string('username');
        $password = (string) $this->request->input('password', '');
        if ($username === '' || $password === '') {
            flash('error', '请输入账号和密码。');
            $this->redirect('/admin/login');
        }
        if (!Auth::attempt($username, $password)) {
            Session::put('login_attempts', $attempts + 1);
            Session::put('login_last_attempt', time());
            flash('error', '账号或密码不正确。');
            $this->redirect('/admin/login');
        }
        Session::forget('login_attempts');
        flash('success', '欢迎回来，' . ($username) . '。');
        $this->redirect('/admin');
    }

    public function logout(): never
    {
        Csrf::assert($this->request->string('_token'));
        Auth::logout();
        flash('success', '已安全退出。');
        $this->redirect('/admin/login');
    }
}
