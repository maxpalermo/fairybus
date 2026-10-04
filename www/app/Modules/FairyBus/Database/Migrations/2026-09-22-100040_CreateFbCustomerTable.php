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

class CreateFbCustomerTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_customer' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'legacy_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'company' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'vat_number' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'pec' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'contact_name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
        ]);

        $this->forge->addKey('id_customer', true);
        $this->forge->createTable('fb_customer');
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_customer', true);
    }
}
