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

class FbRoleModel extends Model
{
    protected $table = 'fb_roles';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $allowedFields = [
        'name',
        'description',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function getPermissions(int $roleId): array
    {
        return $this->db->table('fb_role_permissions rp')
            ->select('p.id, p.name')
            ->join('fb_permissions p', 'p.id = rp.permission_id')
            ->where('rp.role_id', $roleId)
            ->get()
            ->getResultArray();
    }

    public function setPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->table('fb_role_permissions')->where('role_id', $roleId)->delete();

        foreach ($permissionIds as $permissionId) {
            $this->db->table('fb_role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => (int) $permissionId,
            ]);
        }
    }
}
