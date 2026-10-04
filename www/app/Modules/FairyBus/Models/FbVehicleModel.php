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

class FbVehicleModel extends Model
{
    protected $table = 'fb_vehicle';
    protected $primaryKey = 'id_vehicle';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $allowedFields = [
        'legacy_id',
        'id_organization',
        'id_brand',
        'status',
        'chassis_number',
        'current_km',
        'plate',
        'start_date',
        'end_date',
        'description',
        'note',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * @param list<int> $ids Se valorizzato, filtra per id_vehicle
     * @return list<array<string, mixed>>
     */
    public function listWithBrand(array $ids = []): array
    {
        $builder = $this->db->table("{$this->table} AS v");
        $builder->select([
            'v.id_vehicle',
            'v.legacy_id',
            'v.id_organization',
            'v.id_brand',
            'v.status',
            'v.chassis_number',
            'v.current_km',
            'v.plate',
            'v.start_date',
            'v.end_date',
            'v.description',
            'v.note',
            'v.created_at',
            'v.updated_at',
            'b.name AS brand_name',
        ]);
        $builder->join('fb_brand AS b', 'b.id_brand = v.id_brand', 'LEFT');
        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('v.id_vehicle', $ids);
        }
        $builder->orderBy('v.plate', 'ASC');

        return $builder->get()->getResultArray();
    }

    public function findWithDetails(int $idVehicle): ?array
    {
        $vehicle = $this->find($idVehicle);
        if ($vehicle === null) {
            return null;
        }

        $brand = (new FbBrandModel())->find($vehicle['id_brand'] ?? 0);
        $vehicle['brand_name'] = $brand['name'] ?? null;

        $featureModel = new FbVehicleFeatureModel();
        $vehicle['features'] = $featureModel->getForVehicle($idVehicle);

        $vehicle['images'] = (new FbVehicleImageModel())->getForVehicle($idVehicle);
        $vehicle['documents'] = (new FbVehicleDocumentModel())->getForVehicle($idVehicle);

        return $vehicle;
    }
}
