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
 * Tabelle Manutenzione e Scadenze.
 *
 * Mappatura legacy:
 *  - maintenance              -> fb_maintenance        (intervento: data, km, veicolo)
 *  - maintenance_task         -> fb_maintenance_task   (lavoro: ore, prezzo orario, manodopera)
 *  - movement (trade maint.)  -> fb_maintenance_detail (pezzi/ricambi usati)
 *  - maintenance.invoice_id   -> fb_maintenance_invoice(riferimenti fattura)
 *  - car_service              -> fb_car_service        (tagliando/appuntamento)
 *  - car_service_task         -> fb_car_service_task   (voci scadenza nel tagliando)
 *  - expiration_tag           -> fb_expiration_tag     (etichetta scadenza: tipo Km/Data + intervallo)
 *  - expiration               -> fb_expiration         (scadenza su veicolo, periodica o una tantum)
 *  - expiration_occurrence    -> fb_expiration_occurrence (istanza concreta: data o km, stato)
 *  - vehiclekmregistration    -> fb_vehicle_km         (registro km veicolo)
 */
class CreateFbMaintenanceTables extends Migration
{
    private function idField(string $name): array
    {
        return [$name => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true]];
    }

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
        $this->forge->addField($this->idField($pk) + $fields);
        $this->forge->addKey($pk, true);
        $this->forge->addKey('legacy_id');
        foreach ($keys as $key) {
            $this->forge->addKey($key);
        }
        $this->forge->createTable($table, true);
    }

    public function up(): void
    {
        // Manutenzione (intervento su veicolo)
        $this->create('fb_maintenance', 'id_maintenance', $this->baseFields() + [
            'id_vehicle' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'date' => ['type' => 'DATE', 'null' => true],
            'km' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ], ['id_vehicle', 'date']);

        // Lavoro eseguito (ore, prezzo orario, manodopera)
        $this->create('fb_maintenance_task', 'id_maintenance_task', $this->baseFields() + [
            'id_maintenance' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'hours' => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
            'manpower' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'price_per_hour' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
        ], ['id_maintenance']);

        // Ricambi/materiali usati (da movement)
        $this->create('fb_maintenance_detail', 'id_maintenance_detail', $this->baseFields() + [
            'id_maintenance' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'id_product' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'quantity' => ['type' => 'DECIMAL', 'constraint' => '12,3', 'default' => 0],
            'price' => ['type' => 'DECIMAL', 'constraint' => '12,4', 'default' => 0],
            'discount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0],
            'lot' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'vat_code' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'vat_rate' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
            'warehouse_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ], ['id_maintenance', 'id_product']);

        // Riferimenti fattura della manutenzione (link N:N)
        $this->create('fb_maintenance_invoice', 'id_maintenance_invoice', $this->baseFields() + [
            'id_maintenance' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'id_invoice' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ], ['id_maintenance', 'id_invoice']);

        // Tagliando / appuntamento di servizio
        $this->create('fb_car_service', 'id_car_service', $this->baseFields() + [
            'id_maintenance' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ], ['id_maintenance']);

        // Voci di scadenza aggregate nel tagliando
        $this->create('fb_car_service_task', 'id_car_service_task', $this->baseFields() + [
            'id_car_service' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'id_expiration' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'id_expiration_tag' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'id_expiration_occurrence' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ], ['id_car_service']);

        // Registro chilometrico veicoli
        $this->create('fb_vehicle_km', 'id_vehicle_km', $this->baseFields() + [
            'id_vehicle' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'amount' => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0],
            'registration_date' => ['type' => 'DATETIME', 'null' => true],
            'reason_class' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'reason_id' => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
            'id_maintenance' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ], ['id_vehicle', 'id_maintenance']);

        // Etichette scadenza (Olio motore, Revisione, Estintore, ...)
        $this->create('fb_expiration_tag', 'id_expiration_tag', $this->baseFields() + [
            'name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => false],
            'applies_to' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'kind' => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'date'], // km | date
            'interval_value' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'interval_unit' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true], // km | days | months | years
        ]);

        // Scadenza su veicolo (periodica o una tantum)
        $this->create('fb_expiration', 'id_expiration', $this->baseFields() + [
            'id_vehicle' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'id_expiration_tag' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'expiration_date' => ['type' => 'DATE', 'null' => true],
            'until_date' => ['type' => 'DATE', 'null' => true],
            'expires_atkm' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'periodicity' => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'once'], // once | yearly | km | days
            'periodicity_type' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'everyxdays' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'yearly_month' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'yearly_day' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
            'kind' => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'date'], // km | date
        ], ['id_vehicle', 'id_expiration_tag', 'expiration_date']);

        // Occorrenza concreta della scadenza
        $this->create('fb_expiration_occurrence', 'id_expiration_occurrence', $this->baseFields() + [
            'id_expiration' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => false],
            'expiration_date' => ['type' => 'DATE', 'null' => true],
            'km' => ['type' => 'BIGINT', 'constraint' => 20, 'null' => true],
            'state' => ['type' => 'INT', 'constraint' => 11, 'null' => true],
        ], ['id_expiration', 'expiration_date']);
    }

    public function down(): void
    {
        foreach ([
            'fb_expiration_occurrence',
            'fb_expiration',
            'fb_expiration_tag',
            'fb_vehicle_km',
            'fb_car_service_task',
            'fb_car_service',
            'fb_maintenance_invoice',
            'fb_maintenance_detail',
            'fb_maintenance_task',
            'fb_maintenance',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }
}
