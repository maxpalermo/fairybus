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

class CreateFbAddressTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_address' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_customer' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'id_supplier' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'id_country' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'id_state' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'address1' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'address2' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'postcode' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
                'null' => true,
            ],
            'city' => [
                'type' => 'VARCHAR',
                'constraint' => 128,
                'null' => true,
            ],
            'phone_number' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'mobile_number' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id_address', true);
        $this->forge->addKey('id_supplier');
        $this->forge->addKey('id_country');
        $this->forge->addKey('id_state');
        $this->forge->createTable('fb_address');
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_address', true);
    }
}
