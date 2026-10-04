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

namespace FairyBus\Models;

use CodeIgniter\Model;

class FbUserModel extends Model
{
    protected $table = 'fb_users';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'email',
        'password_hash',
        'first_name',
        'last_name',
        'is_active',
        'force_password_change',
        'last_login',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByEmail(string $email): ?array
    {
        return $this->where('email', $email)->where('is_active', 1)->first();
    }

    public function findWithRoles(): array
    {
        $users = $this->orderBy('last_name', 'asc')->orderBy('first_name', 'asc')->findAll();

        foreach ($users as &$user) {
            $user['roles'] = $this->getRoles((int) $user['id']);
        }

        return $users;
    }

    public function getRoles(int $userId): array
    {
        return $this->db->table('fb_user_roles ur')
            ->select('r.id, r.name')
            ->join('fb_roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)
            ->get()
            ->getResultArray();
    }

    public function getPermissions(int $userId): array
    {
        $rolePermissions = $this->db->table('fb_user_roles ur')
            ->select('p.id, p.name, p.description')
            ->join('fb_role_permissions rp', 'rp.role_id = ur.role_id')
            ->join('fb_permissions p', 'p.id = rp.permission_id')
            ->where('ur.user_id', $userId)
            ->get()
            ->getResultArray();

        $directPermissions = $this->db->table('fb_user_permissions up')
            ->select('p.id, p.name, p.description')
            ->join('fb_permissions p', 'p.id = up.permission_id')
            ->where('up.user_id', $userId)
            ->get()
            ->getResultArray();

        $merged = array_merge($rolePermissions, $directPermissions);
        $unique = [];

        foreach ($merged as $perm) {
            $unique[$perm['name']] = $perm;
        }

        return array_values($unique);
    }

    public function setPassword(int $userId, string $password): bool
    {
        return $this->update($userId, [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'force_password_change' => 1,
        ]);
    }

    public function toggleActive(int $userId): bool
    {
        $user = $this->find($userId);

        if ($user === null) {
            return false;
        }

        return (bool) $this->update($userId, [
            'is_active' => ((int) $user['is_active']) === 1 ? 0 : 1,
        ]);
    }

    public function setRoles(int $userId, array $roleIds): void
    {
        $this->db->table('fb_user_roles')->where('user_id', $userId)->delete();

        foreach ($roleIds as $roleId) {
            $this->db->table('fb_user_roles')->insert([
                'user_id' => $userId,
                'role_id' => (int) $roleId,
            ]);
        }
    }
}
