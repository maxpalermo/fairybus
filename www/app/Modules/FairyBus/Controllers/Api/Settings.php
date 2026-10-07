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

use App\Controllers\AdminController;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Models\FbPermissionModel;
use FairyBus\Models\FbRoleModel;
use FairyBus\Models\FbUserModel;
use FairyBus\Models\FbUserPermissionModel;
use FairyBus\Models\FbUserRoleModel;

class Settings extends AdminController
{
    public function users(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbUserModel();
        $rows = $model->findWithRoles();

        foreach ($rows as &$row) {
            unset($row['password_hash']);
            $row['id'] = (int) $row['id'];
            $row['is_active'] = (bool) $row['is_active'];
            $row['active'] = $row['is_active'];
            $row['role_ids'] = array_column($row['roles'] ?? [], 'id');
            $row['roles'] = array_column($row['roles'] ?? [], 'name');
            $row['full_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: $row['email'];
        }

        return $this->jsonResponse([
            'success' => true,
            'rows' => $rows,
            'all_roles' => (new FbRoleModel())->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function createUser(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'email' => 'required|valid_email|is_unique[fb_users.email]',
            'first_name' => 'required|max_length[100]',
            'last_name' => 'required|max_length[100]',
            'password' => 'required|min_length[6]',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbUserModel();
        $id = $model->insert([
            'email' => $this->request->getPost('email'),
            'first_name' => $this->request->getPost('first_name'),
            'last_name' => $this->request->getPost('last_name'),
            'password_hash' => password_hash($this->request->getPost('password'), PASSWORD_DEFAULT),
            'is_active' => 1,
            'force_password_change' => 1,
        ]);

        $roleIds = $this->parseIds($this->request->getPost('role_ids'));
        if ($roleIds !== []) {
            $model->setRoles((int) $id, $roleIds);
        }

        return $this->jsonResponse(['success' => true, 'id' => (int) $id], 201);
    }

    public function updateUser(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'email' => "required|valid_email|is_unique[fb_users.email,id,{$id}]",
            'first_name' => 'required|max_length[100]',
            'last_name' => 'required|max_length[100]',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbUserModel();
        $model->update($id, [
            'email' => $this->request->getPost('email'),
            'first_name' => $this->request->getPost('first_name'),
            'last_name' => $this->request->getPost('last_name'),
        ]);

        $roleIds = $this->parseIds($this->request->getPost('role_ids'));
        $model->setRoles($id, $roleIds);

        return $this->jsonResponse(['success' => true]);
    }

    private function parseIds($value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (!is_array($decoded)) {
            return [];
        }

        return array_filter(array_map('intval', $decoded));
    }

    public function deleteUser(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if ($id === (int) session('fb_user_id')) {
            return $this->jsonResponse(['success' => false, 'error' => 'Non puoi eliminare il tuo utente.'], 422);
        }

        (new FbUserModel())->delete($id);
        (new FbUserRoleModel())->where('user_id', $id)->delete();
        (new FbUserPermissionModel())->where('user_id', $id)->delete();

        return $this->jsonResponse(['success' => true]);
    }

    public function toggleUserActive(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbUserModel();
        $success = $model->toggleActive($id);

        return $this->jsonResponse(['success' => $success]);
    }

    public function resetPassword(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $password = $this->request->getPost('password');

        if (empty($password) || strlen($password) < 6) {
            return $this->jsonResponse(['success' => false, 'error' => 'La password deve essere di almeno 6 caratteri.'], 422);
        }

        (new FbUserModel())->setPassword($id, $password);

        return $this->jsonResponse(['success' => true]);
    }

    public function permissions(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $userModel = new FbUserModel();
        $permissionModel = new FbPermissionModel();
        $userPermModel = new FbUserPermissionModel();

        $users = $userModel->findWithRoles();
        $permissions = $permissionModel->listAll();

        foreach ($users as &$user) {
            $userId = (int) $user['id'];
            unset($user['password_hash']);
            $user['id'] = $userId;
            $user['is_active'] = (bool) $user['is_active'];
            $user['full_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['email'];
            $user['roles'] = array_column($user['roles'] ?? [], 'name');
            $user['permissions'] = array_column(
                array_merge(
                    $userModel->getPermissions($userId),
                    $userPermModel->where('user_id', $userId)->findAll()
                ),
                'name'
            );
            $user['direct_permission_ids'] = array_column($userPermModel->getForUser($userId), 'permission_id');
        }

        return $this->jsonResponse([
            'success' => true,
            'users' => $users,
            'permissions' => $permissions,
        ]);
    }

    public function updateUserPermissions(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $ids = $this->parseIds($this->request->getPost('permission_ids'));

        (new FbUserPermissionModel())->setForUser($id, $ids);

        return $this->jsonResponse(['success' => true]);
    }

    public function roles(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $roleModel = new FbRoleModel();
        $permissionModel = new FbPermissionModel();

        $roles = $roleModel->orderBy('name', 'ASC')->findAll();
        foreach ($roles as &$role) {
            $role['id'] = (int) $role['id'];
            $role['permissions'] = array_column($roleModel->getPermissions((int) $role['id']), 'name');
            $role['permission_ids'] = array_column($roleModel->getPermissions((int) $role['id']), 'id');
        }

        return $this->jsonResponse([
            'success' => true,
            'roles' => $roles,
            'permissions' => $permissionModel->listAll(),
        ]);
    }

    public function createRole(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|max_length[100]|is_unique[fb_roles.name]',
            'description' => 'max_length[255]',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbRoleModel();
        $id = $model->insert([
            'name' => $this->request->getPost('name'),
            'description' => $this->request->getPost('description'),
        ]);

        return $this->jsonResponse(['success' => true, 'id' => (int) $id], 201);
    }

    public function updateRolePermissions(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $roleModel = new FbRoleModel();
        $role = $roleModel->find($id);
        if ($role === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Ruolo non trovato.'], 404);
        }
        if (strtolower($role['name'] ?? '') === 'administrator') {
            return $this->jsonResponse(['success' => false, 'error' => 'Il ruolo Amministratore non può essere modificato.'], 422);
        }

        $ids = $this->parseIds($this->request->getPost('permission_ids'));

        $roleModel->setPermissions($id, $ids);

        return $this->jsonResponse(['success' => true]);
    }

    public function changeOwnPassword(): ResponseInterface
    {
        $rules = [
            'current_password' => 'required',
            'new_password' => 'required|min_length[6]',
            'confirm_password' => 'required|matches[new_password]',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbUserModel();
        $user = $model->find(session('fb_user_id'));

        if ($user === null || !password_verify($this->request->getPost('current_password'), $user['password_hash'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Password attuale non corretta.'], 422);
        }

        $model->update((int) $user['id'], [
            'password_hash' => password_hash($this->request->getPost('new_password'), PASSWORD_DEFAULT),
            'force_password_change' => 0,
        ]);

        session()->set('fb_force_password_change', false);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Salva le preferenze globali dei toast (posizione, durata, stile).
     */
    public function toastPrefs(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'position' => 'required|in_list[top-left,top-center,top-right,center,bottom-left,bottom-center,bottom-right]',
            'duration' => 'required|is_natural|less_than_equal_to[120]',
            'style' => 'required|in_list[default,banner]',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $config = new \FairyBus\Models\FbConfigurationModel();
        $config->setValue('toast_position', (string) $this->request->getPost('position'));
        $config->setValue('toast_duration', (string) (int) $this->request->getPost('duration'));
        $config->setValue('toast_style', (string) $this->request->getPost('style'));

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Configurazione globale (fb_configuration): costo orario manodopera
     * e altri valori globali leggibili da ogni pagina.
     */
    public function config(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $config = new \FairyBus\Models\FbConfigurationModel();

        return $this->jsonResponse([
            'success' => true,
            'hourly_cost' => (float) ($config->get('hourly_cost')['value'] ?? 0),
            'expiration_alert_days' => (int) ($config->get('expiration_alert_days')['value'] ?? 30),
            'expiration_alert_km' => (int) ($config->get('expiration_alert_km')['value'] ?? 2000),
        ]);
    }

    /**
     * Salva la configurazione globale (costo orario manodopera,
     * soglie di avviso scadenze). Salva solo le chiavi presenti nel POST.
     */
    public function saveConfig(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if (
            !$this->validate([
                'hourly_cost' => 'permit_empty|decimal|greater_than_equal_to[0]',
                'expiration_alert_days' => 'permit_empty|integer|greater_than_equal_to[0]',
                'expiration_alert_km' => 'permit_empty|integer|greater_than_equal_to[0]',
            ])
        ) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $config = new \FairyBus\Models\FbConfigurationModel();
        $post = $this->request->getPost();
        if (array_key_exists('hourly_cost', $post)) {
            $config->setValue('hourly_cost', (string) (float) ($post['hourly_cost'] ?: 0));
        }
        if (array_key_exists('expiration_alert_days', $post)) {
            $config->setValue('expiration_alert_days', (string) (int) ($post['expiration_alert_days'] ?: 30));
        }
        if (array_key_exists('expiration_alert_km', $post)) {
            $config->setValue('expiration_alert_km', (string) (int) ($post['expiration_alert_km'] ?: 2000));
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Dati per l'editor del menu: albero salvato (o replica del default se
     * fb_menu e' vuota), flag menu_custom e metadati (icone, colori, rotte).
     */
    public function menu(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbMenuModel();
        $tree = $model->tree();
        $isReplica = $tree === [];
        if ($isReplica || $this->request->getGet('source') === 'default') {
            $tree = \FairyBus\Models\FbMenuModel::defaultTree();
        }

        $config = new \FairyBus\Models\FbConfigurationModel();

        return $this->jsonResponse([
            'success' => true,
            'menu' => $tree,
            'is_replica' => $isReplica,
            'custom' => ($config->get('menu_custom')['value'] ?? '0') === '1',
            'meta' => self::menuMeta(),
        ]);
    }

    /**
     * Attiva/disattiva il menu custom (fb_configuration.menu_custom).
     */
    public function menuCustom(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $enabled = $this->request->getPost('enabled') ? '1' : '0';
        (new \FairyBus\Models\FbConfigurationModel())->setValue('menu_custom', $enabled);

        return $this->jsonResponse(['success' => true, 'enabled' => $enabled === '1']);
    }

    /**
     * Salva l'intera struttura del menu (JSON ad albero, max 2 livelli).
     */
    public function saveMenu(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $tree = json_decode((string) $this->request->getPost('tree'), true);
        if (!is_array($tree)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Struttura non valida.'], 422);
        }

        $meta = self::menuMeta();
        $routes = array_column($meta['routes'], 'value');
        $icons = array_column($meta['icons'], 'value');
        $colors = array_column($meta['colors'], 'value');

        $sanitize = function (array $node, int $level) use (&$sanitize, $routes, $icons, $colors): ?array {
            $title = trim((string) ($node['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 255) {
                return null;
            }
            $children = [];
            if ($level === 0 && !empty($node['children']) && is_array($node['children'])) {
                foreach ($node['children'] as $child) {
                    $c = $sanitize($child, 1);
                    if ($c !== null) {
                        $c['children'] = [];
                        $children[] = $c;
                    }
                }
            }
            $route = trim((string) ($node['route'] ?? ''));
            $icon = trim((string) ($node['icon'] ?? ''));
            $bg = trim((string) ($node['color_bg'] ?? ''));
            $fg = trim((string) ($node['color_fg'] ?? ''));

            return [
                'title' => $title,
                'description' => mb_substr(trim((string) ($node['description'] ?? '')), 0, 255),
                'icon' => in_array($icon, $icons, true) ? $icon : null,
                'color_bg' => in_array($bg, $colors, true) && $bg !== '' ? $bg : null,
                'color_fg' => in_array($fg, $colors, true) && $fg !== '' ? $fg : null,
                'route' => $children !== [] ? null : (in_array($route, $routes, true) ? $route : null),
                'children' => $children,
            ];
        };

        $clean = [];
        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }
            $n = $sanitize($node, 0);
            if ($n !== null) {
                $clean[] = $n;
            }
        }

        (new \FairyBus\Models\FbMenuModel())->saveTree($clean);

        return $this->jsonResponse(['success' => true]);
    }

    /* ---------- Tipi di documento (fb_type_document) ---------- */

    public function documentTypes(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new \FairyBus\Models\FbTypeDocumentModel())->listAll(),
        ]);
    }

    public function createDocumentType(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if (!$this->validate(['name' => 'required|max_length[64]', 'description' => 'permit_empty|max_length[255]'])) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new \FairyBus\Models\FbTypeDocumentModel();
        $id = $model->nextId();
        $model->insert([
            'id' => $id,
            'name' => trim((string) $this->request->getPost('name')),
            'description' => $this->request->getPost('description') !== '' ? trim((string) $this->request->getPost('description')) : null,
        ]);

        return $this->jsonResponse(['success' => true, 'id' => $id], 201);
    }

    public function updateDocumentType(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbTypeDocumentModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tipo documento non trovato.'], 404);
        }

        if (!$this->validate(['name' => 'required|max_length[64]', 'description' => 'permit_empty|max_length[255]'])) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $model->update($id, [
            'name' => trim((string) $this->request->getPost('name')),
            'description' => $this->request->getPost('description') !== '' ? trim((string) $this->request->getPost('description')) : null,
        ]);

        return $this->jsonResponse(['success' => true]);
    }

    public function deleteDocumentType(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbTypeDocumentModel();
        $type = $model->find($id);
        if ($type === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tipo documento non trovato.'], 404);
        }

        if ($id === \FairyBus\Models\FbTypeDocumentModel::TYPE_DEFAULT) {
            return $this->jsonResponse(['success' => false, 'error' => 'Il tipo "Default" non può essere eliminato.'], 422);
        }

        if ($model->isInUse($id)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tipo in uso da documenti esistenti: impossibile eliminarlo.'], 422);
        }

        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * @return array{icons: list<array{value: string, label: string}>, colors: list<array{value: string, label: string}>, routes: list<array{value: string, label: string}>}
     */
    private static function menuMeta(): array
    {
        $icons = [
            'dashboard' => 'Dashboard',
            'book' => 'Libro / anagrafiche',
            'wrench' => 'Chiave inglese / officina',
            'file' => 'Documento',
            'fuel' => 'Carburante',
            'box' => 'Magazzino',
            'truck' => 'Veicolo',
            'users' => 'Utenti',
            'tag' => 'Etichetta',
            'sliders' => 'Cursori / preferenze',
            'settings' => 'Impostazioni',
        ];

        $colors = [
            '' => 'Predefinito',
            '#2563eb' => 'Primario (blu)',
            '#0ea5e9' => 'Info (azzurro)',
            '#16a34a' => 'Successo (verde)',
            '#f59e0b' => 'Avviso (ambra)',
            '#dc2626' => 'Errore (rosso)',
            '#7c3aed' => 'Viola',
            '#db2777' => 'Rosa',
            '#0f172a' => 'Scuro',
            '#64748b' => 'Grigio',
            '#f8fafc' => 'Chiaro',
        ];

        $routes = [];
        $all = \Config\Services::routes()->getRoutes('GET');
        foreach ($all as $route => $handler) {
            if (!str_starts_with($route, 'admin/') || str_contains($route, '(')) {
                continue;
            }
            $routes[$route] = $route;
        }
        ksort($routes);

        return [
            'icons' => array_map(static fn(string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys($icons), $icons),
            'colors' => array_map(static fn(string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys($colors), $colors),
            'routes' => array_map(static fn(string $v): array => ['value' => $v, 'label' => $v], array_values($routes)),
        ];
    }
}
