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

class CreateFbDocumentTables extends Migration
{
    public function up(): void
    {
        // Tipi di documento (chiavi fisse, non auto-increment)
        $this->forge->addField([
            'id' => [
                'type' => 'TINYINT',
                'constraint' => 3,
                'unsigned' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => false,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('fb_document_type');

        $this->db->table('fb_document_type')->insertBatch([
            ['id' => 0, 'name' => 'DDT'],
            ['id' => 1, 'name' => 'INVOICE'],
        ]);

        // Fatture (legacy invoice)
        $this->forge->addField([
            'id_invoice' => [
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
            'number' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'date' => [
                'type' => 'DATE',
                'null' => true,
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
            'collection_fee' => [
                'type' => 'DECIMAL',
                'constraint' => '14,4',
                'null' => false,
                'default' => 0,
            ],
            'deposit' => [
                'type' => 'DECIMAL',
                'constraint' => '14,4',
                'null' => false,
                'default' => 0,
            ],
            'transport_fee' => [
                'type' => 'DECIMAL',
                'constraint' => '14,4',
                'null' => false,
                'default' => 0,
            ],
            'type' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'status' => [
                'type' => 'INT',
                'constraint' => 11,
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
        $this->forge->addKey('id_invoice', true);
        $this->forge->addKey('legacy_id');
        $this->forge->addKey('id_customer');
        $this->forge->addKey('id_supplier');
        $this->forge->createTable('fb_invoice');

        // Documenti (legacy trade, esclusi i riferimenti a maintenance)
        $this->forge->addField([
            'id_document' => [
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
            'type' => [
                'type' => 'TINYINT',
                'constraint' => 3,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'number' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'date' => [
                'type' => 'DATE',
                'null' => true,
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
            'id_invoice' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'id_ddt' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'reference_class' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'reference_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'status' => [
                'type' => 'INT',
                'constraint' => 11,
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
        $this->forge->addKey('id_document', true);
        $this->forge->addKey('legacy_id');
        $this->forge->addKey('type');
        $this->forge->addKey('id_customer');
        $this->forge->addKey('id_supplier');
        $this->forge->addKey('id_invoice');
        $this->forge->createTable('fb_document');

        // Righe dettaglio documenti (legacy movement collegati a trade)
        $this->forge->addField([
            'id_document_detail' => [
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
            'id_document' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
            ],
            'id_product' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'quantity' => [
                'type' => 'DECIMAL',
                'constraint' => '14,3',
                'null' => false,
                'default' => 0,
            ],
            'price' => [
                'type' => 'DECIMAL',
                'constraint' => '14,4',
                'null' => false,
                'default' => 0,
            ],
            'discount' => [
                'type' => 'DECIMAL',
                'constraint' => '14,4',
                'null' => false,
                'default' => 0,
            ],
            'lot' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'vat_code' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'vat_rate' => [
                'type' => 'DECIMAL',
                'constraint' => '5,2',
                'null' => true,
            ],
            'type' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'warehouse_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'status' => [
                'type' => 'INT',
                'constraint' => 11,
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
        $this->forge->addKey('id_document_detail', true);
        $this->forge->addKey('legacy_id');
        $this->forge->addKey('id_document');
        $this->forge->addKey('id_product');
        $this->forge->createTable('fb_document_detail');
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_document_detail', true);
        $this->forge->dropTable('fb_document', true);
        $this->forge->dropTable('fb_invoice', true);
        $this->forge->dropTable('fb_document_type', true);
    }
}
