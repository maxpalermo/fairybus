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

class FbRefuellingStationModel extends Model
{
    protected $table = 'fb_refuelling_station';
    protected $primaryKey = 'id_refuelling_station';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'name',
        'province',
        'town',
        'post_code',
        'street',
        'suburb',
        'lat',
        'lon',
        'epsg_code',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = []): array
    {
        $builder = $this->orderBy('name', 'ASC');
        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('id_refuelling_station', $ids);
        }

        $rows = $builder->findAll();
        foreach ($rows as &$row) {
            $row['address'] = implode(', ', array_filter([
                $row['street'],
                trim(($row['post_code'] ?? '') . ' ' . ($row['town'] ?? '')),
                $row['province'] ? '(' . $row['province'] . ')' : null,
            ])) ?: '—';
        }

        return $rows;
    }
}
