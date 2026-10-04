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

class SeedFbFeaturesAndConfigurations extends Migration
{
    public function up(): void
    {
        $features = [
            ['type' => 'switch', 'name' => 'air_conditioned', 'label' => 'Aria condizionata'],
            ['type' => 'switch', 'name' => 'antiparticle_filter', 'label' => 'Filtro antiparticolato'],
            ['type' => 'select', 'name' => 'engine_type', 'label' => 'Tipo motore'],
            ['type' => 'select', 'name' => 'fuel_type', 'label' => 'Tipo alimentazione'],
            ['type' => 'value', 'name' => 'lenght_meters', 'label' => 'Lunghezza (mt)'],
            ['type' => 'switch', 'name' => 'platform', 'label' => 'Piattaforma'],
            ['type' => 'value', 'name' => 'seats', 'label' => 'Posti a sedere'],
            ['type' => 'value', 'name' => 'stand_place', 'label' => 'Posti in piedi'],
            ['type' => 'switch', 'name' => 'transportation_on_demand', 'label' => 'Trasporto su richiesta'],
            ['type' => 'value', 'name' => 'registration_year', 'label' => 'Anno immatricolazione'],
            ['type' => 'select', 'name' => 'euro', 'label' => 'Euro'],
            ['type' => 'value', 'name' => 'kw', 'label' => 'Kw'],
            ['type' => 'value', 'name' => 'engine_displacement', 'label' => 'Cilindrata'],
        ];

        $this->db->table('fb_feature')->emptyTable();
        foreach ($features as $feature) {
            $this->db->table('fb_feature')->insert($feature);
        }

        $configurations = [
            [
                'name'  => 'select_engine_type',
                'value' => json_encode([
                    ['value' => 'diesel', 'label' => 'Diesel'],
                    ['value' => 'electric', 'label' => 'Elettrico'],
                    ['value' => 'hybrid', 'label' => 'Ibrido'],
                    ['value' => 'gpl', 'label' => 'GPL'],
                ]),
            ],
            [
                'name'  => 'select_fuel_type',
                'value' => json_encode([
                    ['value' => 'diesel', 'label' => 'Diesel'],
                    ['value' => 'electric', 'label' => 'Elettrico'],
                    ['value' => 'gpl', 'label' => 'GPL'],
                    ['value' => 'methane', 'label' => 'Metano'],
                ]),
            ],
            [
                'name'  => 'select_euro',
                'value' => json_encode([
                    ['value' => 'euro_3', 'label' => 'Euro 3'],
                    ['value' => 'euro_4', 'label' => 'Euro 4'],
                    ['value' => 'euro_5', 'label' => 'Euro 5'],
                    ['value' => 'euro_6', 'label' => 'Euro 6'],
                    ['value' => 'euro_7', 'label' => 'Euro 7'],
                ]),
            ],
        ];

        $this->db->table('fb_configuration')->emptyTable();
        foreach ($configurations as $configuration) {
            $this->db->table('fb_configuration')->insert($configuration);
        }
    }

    public function down(): void
    {
        $this->db->table('fb_configuration')->emptyTable();
        $this->db->table('fb_feature')->emptyTable();
    }
}
