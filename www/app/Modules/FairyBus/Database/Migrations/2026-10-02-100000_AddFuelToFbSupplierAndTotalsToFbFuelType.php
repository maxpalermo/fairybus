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
 * Aggiunge:
 *  - fb_supplier.fuel (bool): il fornitore e' un fornitore di carburante
 *  - fb_fuel_type.total_load / total_unload / amount DECIMAL(20,4) NULL:
 *    totali carico/scarico/rimanenza per tipo carburante (statistiche globali).
 *    I campi non fanno parte dell'importazione legacy: restano NULL di default.
 */
class AddFuelToFbSupplierAndTotalsToFbFuelType extends Migration
{
    public function up(): void
    {
        if (!$this->db->fieldExists('fuel', 'fb_supplier')) {
            $this->forge->addColumn('fb_supplier', [
                'fuel' => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'unsigned' => true,
                    'null' => false,
                    'default' => 0,
                    'after' => 'active',
                ],
            ]);
        }

        $totals = [
            'total_load' => ['type' => 'DECIMAL', 'constraint' => '20,4', 'null' => true, 'default' => null],
            'total_unload' => ['type' => 'DECIMAL', 'constraint' => '20,4', 'null' => true, 'default' => null],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '20,4', 'null' => true, 'default' => null],
        ];
        $toAdd = [];
        foreach ($totals as $name => $def) {
            if (!$this->db->fieldExists($name, 'fb_fuel_type')) {
                $toAdd[$name] = $def;
            }
        }
        if ($toAdd !== []) {
            $this->forge->addColumn('fb_fuel_type', $toAdd);
        }
    }

    public function down(): void
    {
        if ($this->db->fieldExists('fuel', 'fb_supplier')) {
            $this->forge->dropColumn('fb_supplier', 'fuel');
        }
        foreach (['total_load', 'total_unload', 'amount'] as $name) {
            if ($this->db->fieldExists($name, 'fb_fuel_type')) {
                $this->forge->dropColumn('fb_fuel_type', $name);
            }
        }
    }
}
