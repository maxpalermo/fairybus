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

namespace FairyBus\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedFbAdminRoleAndPermissions extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Ruolo Amministratore
        $this->db->table('fb_roles')->insert([
            'name'        => 'Amministratore',
            'description' => 'Accesso completo a tutte le funzionalità',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $roleId = $this->db->insertID();

        // Permesso wildcard
        $this->db->table('fb_permissions')->insert([
            'name'        => '*',
            'description' => 'Permesso universale',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $permissionId = $this->db->insertID();

        // Associa permesso wildcard al ruolo Amministratore
        $this->db->table('fb_role_permissions')->insert([
            'role_id'       => $roleId,
            'permission_id' => $permissionId,
            'created_at'    => $now,
        ]);

        // Assegna il ruolo Amministratore all'utente admin
        $admin = $this->db->table('fb_users')
            ->where('email', 'admin@fairybus.local')
            ->get()
            ->getRow();

        if ($admin !== null) {
            $this->db->table('fb_user_roles')->insert([
                'user_id'    => $admin->id,
                'role_id'    => $roleId,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->db->table('fb_permissions')->where('name', '*')->delete();
        $this->db->table('fb_roles')->where('name', 'Amministratore')->delete();
    }
}
