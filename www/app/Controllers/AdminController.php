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

namespace App\Controllers;

use App\Libraries\Twig;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Libraries\PdfHelper;

abstract class AdminController extends BaseController
{
    protected string $moduleName = 'FairyBus';

    protected function renderAdmin(string $template, array $data = []): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        if (session('fb_force_password_change') && uri_string() !== 'change-password') {
            return redirect()->to('/change-password');
        }

        $csrf = $this->getCsrf();

        return $this->response->setBody(Twig::render($template, array_merge($data, [
            'app_name' => 'Fairy Bus',
            'page_title' => $data['page_title'] ?? 'Dashboard',
            'module_name' => $this->moduleName,
            'menu' => $this->buildMenu(),
            'user' => $this->getCurrentUser(),
            'csrf' => $csrf,
            'toast_prefs' => $this->getToastPrefs(),
        ])));
    }

    /**
     * Preferenze globali di visualizzazione dei toast (fb_configuration).
     *
     * @return array{position: string, duration: int, banner: bool}
     */
    protected function getToastPrefs(): array
    {
        $config = new \FairyBus\Models\FbConfigurationModel();
        $position = (string) ($config->get('toast_position')['value'] ?? 'center');
        $duration = (int) ($config->get('toast_duration')['value'] ?? 5);
        $style = (string) ($config->get('toast_style')['value'] ?? 'default');

        return [
            'position' => $position,
            'duration' => $duration * 1000,
            'banner' => $style === 'banner',
        ];
    }

    protected function isLoggedIn(): bool
    {
        return (bool) session('fb_user_id');
    }

    protected function can(string $permission): bool
    {
        $permissions = session('fb_user_permissions') ?: [];

        foreach ($permissions as $p) {
            if (in_array($p['name'], ['*', $permission], true)) {
                return true;
            }
        }

        return false;
    }

    protected function requirePermission(string $permission): ?ResponseInterface
    {
        if (!$this->can($permission)) {
            return $this->response
                ->setStatusCode(403)
                ->setBody('Accesso negato: non hai i permessi necessari.');
        }

        return null;
    }

    protected function jsonResponse(array $data, int $status = 200): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setJSON($data);
    }

    protected function getCsrf(): array
    {
        return [
            'name' => csrf_token(),
            'value' => csrf_hash(),
        ];
    }

    protected function buildMenu(): array
    {
        $menuModel = new \FairyBus\Models\FbMenuModel();
        $config = new \FairyBus\Models\FbConfigurationModel();
        $useCustom = ($config->get('menu_custom')['value'] ?? '0') === '1' && $menuModel->hasSavedMenu();
        $tree = $useCustom ? $menuModel->tree() : \FairyBus\Models\FbMenuModel::defaultTree();

        $canAll = $this->can('*');
        $toItem = function (array $node) use (&$toItem, $canAll): ?array {
            // La voce Impostazioni resta riservata agli admin, anche nel menu custom
            if (!$canAll && ($node['route'] ?? null) === 'admin/settings') {
                return null;
            }
            $children = [];
            foreach ($node['children'] ?? [] as $child) {
                $item = $toItem($child);
                if ($item !== null) {
                    $children[] = $item;
                }
            }
            $route = $children !== [] ? null : ($node['route'] ?? null);

            return [
                'label' => (string) ($node['title'] ?? ''),
                'icon' => ($node['icon'] ?? '') !== '' ? $node['icon'] : null,
                'url' => $route !== null && $route !== '' ? base_url($route) : '#',
                'active' => $route !== null && uri_string() === $route,
                'children' => $children,
                'color_bg' => $node['color_bg'] ?? null,
                'color_fg' => $node['color_fg'] ?? null,
            ];
        };

        $menu = [];
        foreach ($tree as $node) {
            $item = $toItem($node);
            if ($item !== null) {
                $menu[] = $item;
            }
        }

        // Se un figlio è attivo, espandi e attiva anche il padre
        foreach ($menu as &$item) {
            if (!empty($item['children'])) {
                $hasActiveChild = false;
                foreach ($item['children'] as $child) {
                    if (!empty($child['active'])) {
                        $hasActiveChild = true;
                        break;
                    }
                }
                if ($hasActiveChild) {
                    $item['active'] = true;
                }
            }
        }

        return $menu;
    }

    /**
     * Legge il parametro POST `ids` (JSON array di ID selezionati per la stampa).
     * Restituisce l'array di ID oppure una Response di errore/redirect.
     *
     * @return list<int>|ResponseInterface
     */
    protected function getPrintIds(int $maxRows = 500): array|ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        $ids = [];
        $raw = (string) $this->request->getPost('ids');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = array_values(array_filter(array_map('intval', $decoded), static fn(int $i): bool => $i > 0));
            }
        }

        if ($ids === []) {
            return $this->response->setStatusCode(422)->setBody('Seleziona almeno una riga da stampare.');
        }

        if (count($ids) > $maxRows) {
            return $this->response->setStatusCode(422)->setBody("Puoi stampare al massimo {$maxRows} righe alla volta.");
        }

        return $ids;
    }

    /**
     * Risposta HTTP per un PDF generato (anteprima inline).
     */
    protected function pdfResponse(string $pdf, string $name): ResponseInterface
    {
        $filename = PdfHelper::filename($name . '_' . date('Ymd_His'));

        return $this->response
            ->setContentType('application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->setBody($pdf);
    }

    protected function getCurrentUser(): array
    {
        return [
            'name' => session('fb_user_name') ?: 'Admin',
            'email' => session('fb_user_email') ?: '',
            'role' => 'Amministratore',
        ];
    }
}
