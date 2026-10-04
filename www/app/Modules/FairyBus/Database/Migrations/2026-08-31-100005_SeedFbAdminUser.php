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

class SeedFbAdminUser extends Migration
{
    public function up(): void
    {
        $data = [
            'email'                 => 'admin@fairybus.local',
            'password_hash'         => password_hash('admin', PASSWORD_DEFAULT),
            'first_name'            => 'Amministratore',
            'last_name'             => '',
            'is_active'             => 1,
            'force_password_change' => 1,
            'created_at'            => date('Y-m-d H:i:s'),
            'updated_at'            => date('Y-m-d H:i:s'),
        ];

        $this->db->table('fb_users')->insert($data);
    }

    public function down(): void
    {
        $this->db->table('fb_users')->where('email', 'admin@fairybus.local')->delete();
    }
}
