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

class FbExpirationModel extends Model
{
    protected $table = 'fb_expiration';
    protected $primaryKey = 'id_expiration';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_vehicle',
        'id_expiration_tag',
        'description',
        'expiration_date',
        'until_date',
        'expires_atkm',
        'periodicity',
        'periodicity_type',
        'everyxdays',
        'yearly_month',
        'yearly_day',
        'kind',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Scadenze con veicolo, etichetta e prossima occorrenza aperta.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = [], ?int $idVehicle = null): array
    {
        $builder = $this->db->table('fb_expiration e')
            ->select('e.*, v.plate AS vehicle_plate, v.current_km, t.name AS tag_name, t.kind AS tag_kind, t.interval_value, t.interval_unit')
            ->join('fb_vehicle v', 'v.id_vehicle = e.id_vehicle', 'left')
            ->join('fb_expiration_tag t', 't.id_expiration_tag = e.id_expiration_tag', 'left')
            ->orderBy('v.plate', 'ASC')
            ->orderBy('t.name', 'ASC')
            ->orderBy('e.expiration_date', 'DESC')
            ->orderBy('e.expires_atkm', 'DESC');

        if ($idVehicle !== null && $idVehicle > 0) {
            // dettaglio veicolo: mostra anche le scadenze di mezzi ritirati
            $builder->where('e.id_vehicle', $idVehicle);
        } else {
            $builder->where("v.status !=", 'retired');
        }

        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('e.id_expiration', $ids);
        }

        $rows = $builder->get()->getResultArray();
        if ($rows === []) {
            return $rows;
        }

        // Prima occorrenza aperta per ciascuna scadenza
        $open = $this->db->table('fb_expiration_occurrence')
            ->select('id_expiration_occurrence, id_expiration, expiration_date, km, state')
            ->whereIn('id_expiration', array_column($rows, 'id_expiration'))
            ->where('COALESCE(state,0) <>', 4)
            ->orderBy('expiration_date', 'ASC')
            ->orderBy('km', 'ASC')
            ->get()->getResultArray();

        $next = [];
        foreach ($open as $o) {
            $idExp = (int) $o['id_expiration'];
            if (!isset($next[$idExp])) {
                $next[$idExp] = $o;
            }
        }

        foreach ($rows as &$row) {
            $o = $next[(int) $row['id_expiration']] ?? null;
            $row['next_occurrence_id'] = $o['id_expiration_occurrence'] ?? null;
            $row['next_date'] = $o['expiration_date'] ?? null;
            $row['next_km'] = $o['km'] ?? null;
            $row['next_state'] = $o['state'] ?? null;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('fb_expiration e')
            ->select('e.*, v.plate AS vehicle_plate, t.name AS tag_name')
            ->join('fb_vehicle v', 'v.id_vehicle = e.id_vehicle', 'left')
            ->join('fb_expiration_tag t', 't.id_expiration_tag = e.id_expiration_tag', 'left')
            ->where('e.id_expiration', $id)
            ->get()
            ->getRowArray();
    }
}
