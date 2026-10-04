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

namespace FairyBus\Models;

use CodeIgniter\Model;

class FbVehicleKmModel extends Model
{
    protected $table = 'fb_vehicle_km';
    protected $primaryKey = 'id_vehicle_km';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_vehicle',
        'amount',
        'registration_date',
        'reason_class',
        'reason_id',
        'id_maintenance',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Registra una rilevazione km e allinea fb_vehicle.current_km se più recente/alto.
     */
    public function register(int $idVehicle, int $km, ?string $date = null, ?int $idMaintenance = null, ?string $reasonClass = 'Maintenance'): int
    {
        $id = (int) $this->insert([
            'id_vehicle' => $idVehicle,
            'amount' => $km,
            'registration_date' => $date ?? date('Y-m-d H:i:s'),
            'reason_class' => $reasonClass,
            'reason_id' => $idMaintenance,
            'id_maintenance' => $reasonClass === 'Maintenance' ? $idMaintenance : null,
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        $vehicle = $this->db->table('fb_vehicle')->where('id_vehicle', $idVehicle)->get()->getRowArray();
        if ($vehicle !== null && (int) $vehicle['current_km'] < $km) {
            $this->db->table('fb_vehicle')->where('id_vehicle', $idVehicle)->update(['current_km' => $km]);
        }

        return $id;
    }
}
