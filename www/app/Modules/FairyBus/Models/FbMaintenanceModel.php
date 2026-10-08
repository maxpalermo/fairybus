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

class FbMaintenanceModel extends Model
{
    protected $table = 'fb_maintenance';
    protected $primaryKey = 'id_maintenance';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_vehicle',
        'id_document',
        'id_invoice',
        'date',
        'km',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Manutenzioni con targa veicolo e flag fattura.
     *
     * @param list<int> $ids Se valorizzato, filtra per id_maintenance
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = [], ?int $idVehicle = null): array
    {
        $builder = $this->db->table('fb_maintenance m')
            ->select('m.*, v.plate AS vehicle_plate, b.name AS brand_name,
                (SELECT COUNT(*) FROM fb_maintenance_invoice mi WHERE mi.id_maintenance = m.id_maintenance) AS invoices_count')
            ->join('fb_vehicle v', 'v.id_vehicle = m.id_vehicle', 'left')
            ->join('fb_brand b', 'b.id_brand = v.id_brand', 'left')
            ->orderBy('m.date', 'DESC')
            ->orderBy('m.id_maintenance', 'DESC');

        if ($idVehicle !== null && $idVehicle > 0) {
            // dettaglio veicolo: mostra anche le manutenzioni di mezzi ritirati
            $builder->where('m.id_vehicle', $idVehicle);
        } else {
            $builder->where("v.status !=", 'retired');
        }

        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('m.id_maintenance', $ids);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('fb_maintenance m')
            ->select('m.*, v.plate AS vehicle_plate, b.name AS brand_name')
            ->join('fb_vehicle v', 'v.id_vehicle = m.id_vehicle', 'left')
            ->join('fb_brand b', 'b.id_brand = v.id_brand', 'left')
            ->where('m.id_maintenance', $id)
            ->get()
            ->getRowArray();
    }
}
