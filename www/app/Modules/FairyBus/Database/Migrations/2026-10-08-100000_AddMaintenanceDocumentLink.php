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
 * Manutenzione conto terzi -> documento di scarico cliente:
 *  - fb_maintenance.id_document: scarico che ha fatturato la scheda
 *  - fb_document_detail.id_maintenance: manutenzione sorgente della riga
 *    (le righe da manutenzione non muovono lo stock: gia' scaricate)
 */
class AddMaintenanceDocumentLink extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('fb_maintenance', [
            'id_document' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'id_vehicle',
                'comment' => 'Documento di scarico che ha fatturato la manutenzione',
            ],
        ]);
        $this->db->query('ALTER TABLE fb_maintenance ADD INDEX idx_mnt_document (id_document)');

        $this->forge->addColumn('fb_maintenance', [
            'id_invoice' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'id_document',
                'comment' => 'Fattura cliente che ha registrato la manutenzione',
            ],
        ]);
        $this->db->query('ALTER TABLE fb_maintenance ADD INDEX idx_mnt_invoice (id_invoice)');

        $this->forge->addColumn('fb_document_detail', [
            'id_maintenance' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'id_product',
                'comment' => 'Manutenzione sorgente: la riga non muove lo stock',
            ],
        ]);
        $this->db->query('ALTER TABLE fb_document_detail ADD INDEX idx_doc_detail_maintenance (id_maintenance)');

        $this->forge->addColumn('fb_document', [
            'id_vehicle' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'id_ddt',
                'comment' => 'Veicolo delle schede manutenzione associate (conto terzi)',
            ],
        ]);
        $this->db->query('ALTER TABLE fb_document ADD INDEX idx_doc_vehicle (id_vehicle)');

        $this->forge->addColumn('fb_invoice', [
            'id_vehicle' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'id_customer',
                'comment' => 'Automezzo delle schede manutenzione (fatture cliente conto terzi)',
            ],
        ]);
        $this->db->query('ALTER TABLE fb_invoice ADD INDEX idx_inv_vehicle (id_vehicle)');
    }

    public function down(): void
    {
        $this->forge->dropColumn('fb_maintenance', 'id_document');
        $this->forge->dropColumn('fb_maintenance', 'id_invoice');
        $this->forge->dropColumn('fb_document_detail', 'id_maintenance');
        $this->forge->dropColumn('fb_document', 'id_vehicle');
        $this->forge->dropColumn('fb_invoice', 'id_vehicle');
    }
}
