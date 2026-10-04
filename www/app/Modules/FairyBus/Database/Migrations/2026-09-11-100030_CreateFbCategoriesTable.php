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

class CreateFbCategoriesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id_category' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_parent' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'default' => null,
            ],
            'level_depth' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
            'position' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
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

        $this->forge->addKey('id_category', true);
        $this->forge->addKey('id_parent');
        $this->forge->addKey('active');
        $this->forge->createTable('fb_category');

        // Root category
        $this->db->table('fb_category')->insert([
            'id_category' => 1,
            'id_parent' => 0,
            'level_depth' => 0,
            'active' => 1,
            'position' => 0,
            'name' => 'Root',
            'description' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_category', true);
    }
}
