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

class FbVehicleFeatureModel extends Model
{
    protected $table            = 'fb_vehicle_feature';
    protected $primaryKey       = 'id_vehicle_feature';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $allowedFields    = ['id_vehicle', 'id_feature', 'value'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    /**
     * @return list<array<string, mixed>>
     */
    public function getForVehicle(int $idVehicle): array
    {
        $builder = $this->db->table("{$this->table} AS vf");
        $builder->select([
            'vf.id_vehicle_feature',
            'vf.id_vehicle',
            'vf.id_feature',
            'vf.value',
            'f.type',
            'f.name',
            'f.label',
        ]);
        $builder->join('fb_feature AS f', 'f.id_feature = vf.id_feature', 'INNER');
        $builder->where('vf.id_vehicle', $idVehicle);

        return $builder->get()->getResultArray();
    }

    public function setForVehicle(int $idVehicle, array $values): void
    {
        $this->where('id_vehicle', $idVehicle)->delete();

        foreach ($values as $idFeature => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $this->insert([
                'id_vehicle' => $idVehicle,
                'id_feature' => (int) $idFeature,
                'value'      => (string) $value,
            ]);
        }
    }
}
