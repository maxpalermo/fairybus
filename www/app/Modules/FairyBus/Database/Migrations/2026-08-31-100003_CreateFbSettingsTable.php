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

class CreateFbSettingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'scope' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => false,
                'default'    => 'global',
            ],
            'key' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => false,
            ],
            'value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => false,
                'default'    => 'string',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['scope', 'key']);
        $this->forge->createTable('fb_settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_settings', true);
    }
}
