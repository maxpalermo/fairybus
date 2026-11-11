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

/**
 * Menu personalizzato dell'applicazione.
 * Struttura a 2 livelli: voce principale (id_parent NULL) -> sottomenu.
 * Le voci con figli devono avere route NULL.
 */
class CreateFbMenuTable extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('fb_menu')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'id_parent' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'title' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
                'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'icon' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'color_bg' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'color_fg' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'position' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'route' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('id_parent');
            $this->forge->createTable('fb_menu', true);
        }

        if (!$this->db->table('fb_configuration')->where('name', 'menu_custom')->countAllResults()) {
            $this->db->table('fb_configuration')->insert([
                'name' => 'menu_custom',
                'value' => '0',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_menu', true);
        $this->db->table('fb_configuration')->where('name', 'menu_custom')->delete();
    }
}
