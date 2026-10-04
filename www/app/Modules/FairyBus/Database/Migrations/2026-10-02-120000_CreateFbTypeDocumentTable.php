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
 * Tipologia del documento: fb_document.type e fb_document_detail.type
 * puntano a fb_type_document.id.
 * L'id 0 ("Default") e' sempre presente e non eliminabile.
 */
class CreateFbTypeDocumentTable extends Migration
{
    public function up(): void
    {
        if (!$this->db->tableExists('fb_type_document')) {
            $this->forge->addField([
                'id' => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => true],
                'name' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('fb_type_document');

            $this->db->table('fb_type_document')->insertBatch([
                ['id' => 0, 'name' => 'Default', 'description' => 'Documento generico'],
                ['id' => 1, 'name' => 'Magazzino', 'description' => 'Movimento di magazzino'],
                ['id' => 2, 'name' => 'Servizio', 'description' => 'Documento di servizio'],
                ['id' => 3, 'name' => 'Carburante', 'description' => 'Documento carburante'],
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_type_document', true);
    }
}
