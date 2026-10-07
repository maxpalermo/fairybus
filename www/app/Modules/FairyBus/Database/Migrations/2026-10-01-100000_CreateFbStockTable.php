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

class CreateFbStockTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_stock' => [
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
            'id_product' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'unit' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'quantity' => [
                'type' => 'DECIMAL',
                'constraint' => '14,3',
                'null' => false,
                'default' => 0.000,
            ],
            'inputs' => [
                'type' => 'DECIMAL',
                'constraint' => '14,3',
                'null' => false,
                'default' => 0.000,
            ],
            'outputs' => [
                'type' => 'DECIMAL',
                'constraint' => '14,3',
                'null' => false,
                'default' => 0.000,
            ],
            'notification_limit' => [
                'type' => 'DECIMAL',
                'constraint' => '14,3',
                'null' => true,
            ],
            'note' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'date_add' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'date_upd' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id_stock', true);
        $this->forge->addKey('legacy_id');
        $this->forge->addKey('id_product');
        $this->forge->createTable('fb_stock', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_stock', true);
    }
}
