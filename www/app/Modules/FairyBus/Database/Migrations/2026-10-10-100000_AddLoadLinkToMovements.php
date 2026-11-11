<?php

/*
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
 */

declare(strict_types=1);

namespace FairyBus\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Link persistente scarico -> carico:
 *  - fb_maintenance_detail.id_load: riga di carico (fb_document_detail)
 *    che copre lo scarico. Stessa cosa su fb_document_detail per le
 *    righe di scarico dei documenti cliente.
 * Le righe copiate conto terzi (id_maintenance NOT NULL) non usano il
 * link: seguono la riga sorgente di manutenzione.
 */
class AddLoadLinkToMovements extends Migration
{
    public function up(): void
    {
        $col = [
            'type' => 'INT',
            'constraint' => 11,
            'unsigned' => true,
            'null' => true,
            'comment' => 'Riga carico (fb_document_detail) che copre lo scarico',
        ];

        $this->forge->addColumn('fb_maintenance_detail', ['id_load' => $col]);
        $this->db->query('ALTER TABLE fb_maintenance_detail ADD INDEX idx_mnt_load (id_load)');

        $this->forge->addColumn('fb_document_detail', ['id_load' => $col]);
        $this->db->query('ALTER TABLE fb_document_detail ADD INDEX idx_doc_load (id_load)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE fb_maintenance_detail DROP INDEX idx_mnt_load');
        $this->forge->dropColumn('fb_maintenance_detail', 'id_load');

        $this->db->query('ALTER TABLE fb_document_detail DROP INDEX idx_doc_load');
        $this->forge->dropColumn('fb_document_detail', 'id_load');
    }
}
