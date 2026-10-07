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

class AddLegacyIdToFbVehicleTable extends Migration
{
    public function up(): void
    {
        $fields = [
            'legacy_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'id_vehicle',
            ],
        ];

        $this->forge->addColumn('fb_vehicle', $fields);
        $this->forge->addKey('legacy_id', false, false, 'idx_vehicle_legacy_id');
    }

    public function down(): void
    {
        if ($this->db->fieldExists('legacy_id', 'fb_vehicle')) {
            $this->forge->dropColumn('fb_vehicle', 'legacy_id');
        }
    }
}
