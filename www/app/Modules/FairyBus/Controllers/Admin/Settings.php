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

namespace FairyBus\Controllers\Admin;

use App\Controllers\AdminController;
use App\Libraries\Twig;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Models\FbPermissionModel;
use FairyBus\Models\FbUserModel;

class Settings extends AdminController
{
    public function index(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $userModel = new FbUserModel();
        $permissionModel = new FbPermissionModel();
        $config = new \FairyBus\Models\FbConfigurationModel();

        return $this->renderAdmin('admin/settings.twig', [
            'page_title' => 'Impostazioni',
            'users' => $userModel->findWithRoles(),
            'permissions' => $permissionModel->listAll(),
            'toast_settings' => [
                'position' => $config->get('toast_position')['value'] ?? 'center',
                'duration' => (int) ($config->get('toast_duration')['value'] ?? 5),
                'style' => $config->get('toast_style')['value'] ?? 'default',
            ],
            'hourly_cost' => (float) ($config->get('hourly_cost')['value'] ?? 0),
            'default_tax_rate' => (float) ($config->get('default_tax_rate')['value'] ?? 22),
            'expiration_alert_days' => (int) ($config->get('expiration_alert_days')['value'] ?? 30),
            'expiration_alert_km' => (int) ($config->get('expiration_alert_km')['value'] ?? 2000),
        ]);
    }
}
