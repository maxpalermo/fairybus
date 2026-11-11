<?php

/**
 * Copyright since 2026 Massimiliano Palermo
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to maxx.palermo@gmail.com so we can send you a copy immediately.
 *
 * @author    Massimiliano Palermo <maxx.palermo@gmail.com>
 * @copyright Since 2026 Massimiliano Palermo
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace FairyBus\Controllers\Auth;

use App\Controllers\BaseController;
use App\Libraries\Twig;
use CodeIgniter\Database\Config as DatabaseConfig;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Models\FbUserModel;

class Login extends BaseController
{
    public function index(): ResponseInterface
    {
        if ($this->isLoggedIn()) {
            return redirect()->to('/admin/dashboard');
        }

        return $this->response->setBody(Twig::render('auth/login.twig', [
            'page_title' => 'Accedi',
            'error' => session('login_error'),
            'csrf' => [
                'name' => csrf_token(),
                'value' => csrf_hash(),
            ],
        ]));
    }

    public function attempt(): ResponseInterface
    {
        $rules = [
            'email' => 'required|valid_email',
            'password' => 'required',
        ];

        if (!$this->validate($rules)) {
            return redirect()->back()->with('login_error', 'Inserisci email e password.');
        }

        $email = $this->request->getPost('email');
        $password = $this->request->getPost('password');

        $model = new FbUserModel();
        $user = $model->findByEmail($email);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return redirect()->back()->with('login_error', 'Credenziali non valide.');
        }

        $permissions = $this->loadUserPermissions((int) $user['id']);

        session()->set([
            'fb_user_id' => $user['id'],
            'fb_user_email' => $user['email'],
            'fb_user_name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'fb_force_password_change' => (bool) $user['force_password_change'],
            'fb_user_permissions' => $permissions,
        ]);

        $model->update($user['id'], ['last_login' => date('Y-m-d H:i:s')]);

        if ($user['force_password_change']) {
            return redirect()->to('/change-password');
        }

        return redirect()->to('/admin/dashboard');
    }

    public function logout(): ResponseInterface
    {
        session()->destroy();

        return redirect()->to('/login');
    }

    private function isLoggedIn(): bool
    {
        return (bool) session('fb_user_id');
    }

    private function loadUserPermissions(int $userId): array
    {
        $db = DatabaseConfig::connect();

        return $db->table('fb_user_roles ur')
            ->select('p.name')
            ->join('fb_role_permissions rp', 'rp.role_id = ur.role_id')
            ->join('fb_permissions p', 'p.id = rp.permission_id')
            ->where('ur.user_id', $userId)
            ->get()
            ->getResultArray();
    }
}
