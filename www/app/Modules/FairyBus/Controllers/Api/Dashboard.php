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

namespace FairyBus\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class Dashboard extends BaseController
{
    public function widgets(): ResponseInterface
    {
        $widgets = [
            [
                'id'    => 'calendar',
                'title' => 'Calendario',
                'type'  => 'calendar',
                'data'  => [
                    ['date' => '2026-08-31', 'label' => 'Tagliando bus 12'],
                ],
            ],
            [
                'id'    => 'expirations',
                'title' => 'Scadenze',
                'type'  => 'list',
                'data'  => [
                    ['label' => 'Assicurazione CS458930', 'due' => '2026-09-15', 'status' => 'warning'],
                ],
            ],
            [
                'id'    => 'reminders',
                'title' => 'Promemoria',
                'type'  => 'list',
                'data'  => [
                    ['label' => 'Ordine olio motore', 'status' => 'info'],
                ],
            ],
        ];

        return $this->response->setJSON([
            'success' => true,
            'widgets' => $widgets,
        ]);
    }
}
