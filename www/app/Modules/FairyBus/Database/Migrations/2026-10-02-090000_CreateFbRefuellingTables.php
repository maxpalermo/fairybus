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
 * Tabelle Rifornimenti.
 *
 * Mappatura legacy:
 *  - refuelling_station -> fb_refuelling_station (punto di rifornimento + indirizzo/coordinate)
 *  - refuelling         -> fb_refuelling         (rifornimento: litri, veicolo, stazione, tipo)
 *  - (enum legacy)      -> fb_fuel_type          (tipi carburante, id fissi = ordinali legacy)
 *
 * Note legacy:
 *  - refuelling_station.city contiene la PROVINCIA (es. "CS"), town il comune.
 *  - refuelling.fuel_type e' l'ordinale enum: 0 Benzina, 1 Benzina senza piombo,
 *    2 Diesel, 3 HQ Diesel, 4 Elettricita', 5 GPL, 6 Metano.
 *  - refuelling.supplier_id -> business_partner (role=0) -> fb_supplier.legacy_id.
 *  - refuelling e' sempre uno scarico di carburante dal punto di rifornimento
 *    (direction='out'); 'in' resta disponibile per futuri carichi cisterna.
 */
class CreateFbRefuellingTables extends Migration
{
    private function baseFields(): array
    {
        return [
            'legacy_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'note' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'date_add' => ['type' => 'DATETIME', 'null' => true],
            'date_upd' => ['type' => 'DATETIME', 'null' => true],
        ];
    }

    private function create(string $table, string $pk, array $fields, array $keys = []): void
    {
        $this->forge->addField($fields);
        $this->forge->addKey($pk, true);
        $this->forge->addKey('legacy_id');
        foreach ($keys as $key) {
            $this->forge->addKey($key);
        }
        $this->forge->createTable($table, true);
    }

    public function up(): void
    {
        // Tipi di carburante (id fissi = ordinali enum legacy)
        $this->forge->addField([
            'id_fuel_type' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
        ]);
        $this->forge->addKey('id_fuel_type', true);
        $this->forge->createTable('fb_fuel_type', true);

        $fuelTypes = [
            'Benzina',
            'Benzina senza piombo',
            'Diesel',
            'HQ Diesel',
            'Elettricità',
            'GPL',
            'Metano',
        ];
        foreach ($fuelTypes as $i => $name) {
            $this->db->table('fb_fuel_type')->ignore()->insert(['id_fuel_type' => $i, 'name' => $name]);
        }

        // Punti di rifornimento
        $this->create('fb_refuelling_station', 'id_refuelling_station', [
            'id_refuelling_station' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            ...$this->baseFields(),
            'name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'province' => ['type' => 'VARCHAR', 'constraint' => 4, 'null' => true],
            'town' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'post_code' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'street' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'suburb' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'lat' => ['type' => 'DOUBLE', 'null' => true],
            'lon' => ['type' => 'DOUBLE', 'null' => true],
            'epsg_code' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ]);

        // Rifornimenti (scarico carburante verso veicolo)
        $this->create('fb_refuelling', 'id_refuelling', [
            'id_refuelling' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            ...$this->baseFields(),
            'id_vehicle' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'id_station' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'id_supplier' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'id_fuel_type' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'direction' => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'out'],
            'refuel_time' => ['type' => 'DATETIME', 'null' => true],
            'liters' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'price_per_liter' => ['type' => 'DECIMAL', 'constraint' => '10,4', 'default' => 0],
            'km_at_refuel' => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
            'km_since_last_refuel' => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
        ], ['id_vehicle', 'id_station', 'refuel_time']);
    }

    public function down(): void
    {
        $this->forge->dropTable('fb_refuelling', true);
        $this->forge->dropTable('fb_refuelling_station', true);
        $this->forge->dropTable('fb_fuel_type', true);
    }
}
