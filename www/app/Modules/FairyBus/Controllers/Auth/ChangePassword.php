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

use App\Controllers\AdminController;
use App\Libraries\Twig;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Models\FbUserModel;

class ChangePassword extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('auth/change_password.twig', [
            'page_title' => 'Cambia password',
            'error'      => session('change_password_error'),
            'success'    => session('change_password_success'),
        ]);
    }

    public function update(): ResponseInterface
    {
        if (! session('fb_user_id')) {
            return redirect()->to('/login');
        }

        $rules = [
            'current_password' => 'required',
            'new_password'     => 'required|min_length[6]',
            'confirm_password' => 'required|matches[new_password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->with('change_password_error', implode(' ', $this->validator->getErrors()));
        }

        $model = new FbUserModel();
        $user  = $model->find(session('fb_user_id'));

        if ($user === null || ! password_verify($this->request->getPost('current_password'), $user['password_hash'])) {
            return redirect()->back()->with('change_password_error', 'Password attuale non corretta.');
        }

        $model->update($user['id'], [
            'password_hash'         => password_hash($this->request->getPost('new_password'), PASSWORD_DEFAULT),
            'force_password_change' => 0,
        ]);

        session()->set('fb_force_password_change', false);

        return redirect()->to('/admin/dashboard')->with('change_password_success', 'Password aggiornata con successo.');
    }
}
