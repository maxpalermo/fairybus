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

class FbRefuellingModel extends Model
{
    protected $table = 'fb_refuelling';
    protected $primaryKey = 'id_refuelling';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_vehicle',
        'id_station',
        'id_supplier',
        'id_fuel_type',
        'direction',
        'refuel_time',
        'liters',
        'price_per_liter',
        'km_at_refuel',
        'km_since_last_refuel',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Rifornimenti con targa veicolo, stazione, fornitore e tipo carburante.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = []): array
    {
        $builder = $this->db->table('fb_refuelling r')
            ->select('r.*, v.plate AS vehicle_plate, st.name AS station_name, s.company AS supplier_name, ft.name AS fuel_type_name')
            ->join('fb_vehicle v', 'v.id_vehicle = r.id_vehicle', 'left')
            ->join('fb_refuelling_station st', 'st.id_refuelling_station = r.id_station', 'left')
            ->join('fb_supplier s', 's.id_supplier = r.id_supplier', 'left')
            ->join('fb_fuel_type ft', 'ft.id_fuel_type = r.id_fuel_type', 'left')
            ->orderBy('r.refuel_time', 'DESC')
            ->orderBy('r.id_refuelling', 'DESC');

        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('r.id_refuelling', $ids);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * Rifornimenti filtrati (stesse colonne di listAll).
     * Filtri: supplier_id, vehicle_id, fuel_type_id, direction, date_from, date_to.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function listFiltered(array $filters = []): array
    {
        $builder = $this->db->table('fb_refuelling r')
            ->select('r.*, v.plate AS vehicle_plate, st.name AS station_name, s.company AS supplier_name, ft.name AS fuel_type_name')
            ->join('fb_vehicle v', 'v.id_vehicle = r.id_vehicle', 'left')
            ->join('fb_refuelling_station st', 'st.id_refuelling_station = r.id_station', 'left')
            ->join('fb_supplier s', 's.id_supplier = r.id_supplier', 'left')
            ->join('fb_fuel_type ft', 'ft.id_fuel_type = r.id_fuel_type', 'left')
            ->orderBy('r.refuel_time', 'DESC')
            ->orderBy('r.id_refuelling', 'DESC');

        self::applyFilters($builder, $filters);

        $rows = $builder->get()->getResultArray();

        if (($filters['direction'] ?? null) === 'out') {
            $rows = $this->decorateConsumption($rows);
        }

        return $rows;
    }

    /**
     * Arricchisce gli scarichi con km precedenti, differenza km e consumo km/l.
     * km_prev: km_since_last_refuel se valorizzato, altrimenti i km del
     * rifornimento 'out' precedente dello stesso veicolo.
     * km_per_liter = km_diff / litri caricati nel rifornimento precedente.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function decorateConsumption(array $rows): array
    {
        $vehicleIds = array_values(array_unique(array_filter(array_map(static fn(array $r): int => (int) ($r['id_vehicle'] ?? 0), $rows))));
        if ($rows === [] || $vehicleIds === []) {
            return $rows;
        }

        // storico scarichi dei veicoli coinvolti, ordinato per veicolo+data:
        // il precedente e' l'ultima riga con (data, id) precedenti alla corrente
        $history = $this->db->table('fb_refuelling')
            ->select('id_refuelling, id_vehicle, refuel_time, km_at_refuel, liters')
            ->where('direction', 'out')
            ->whereIn('id_vehicle', $vehicleIds)
            ->orderBy('id_vehicle', 'ASC')
            ->orderBy('refuel_time', 'ASC')
            ->orderBy('id_refuelling', 'ASC')
            ->get()
            ->getResultArray();

        $prevById = [];
        $lastByVehicle = [];
        foreach ($history as $h) {
            $vid = (int) $h['id_vehicle'];
            $prevById[(int) $h['id_refuelling']] = $lastByVehicle[$vid] ?? null;
            $lastByVehicle[$vid] = $h;
        }

        foreach ($rows as &$row) {
            $prev = $prevById[(int) $row['id_refuelling']] ?? null;

            // i dati legacy usano 0 invece di NULL: <=0 equivale a "non valorizzato"
            $stored = (int) ($row['km_since_last_refuel'] ?? 0);
            $prevKm = $prev !== null ? (int) $prev['km_at_refuel'] : 0;
            $kmPrev = $stored > 0 ? $stored : ($prevKm > 0 ? $prevKm : null);
            $kmAt = (int) ($row['km_at_refuel'] ?? 0);

            $kmDiff = $kmAt > 0 && $kmPrev !== null ? $kmAt - $kmPrev : null;
            $prevLiters = $prev !== null && (float) $prev['liters'] > 0 ? (float) $prev['liters'] : null;

            $kmPerLiter = $kmDiff !== null && $kmDiff > 0 && $prevLiters !== null
                ? round($kmDiff / $prevLiters, 2)
                : null;

            $row['km_prev'] = $kmPrev;
            $row['km_diff'] = $kmDiff;
            // oltre 100 km/l il dato e' palesemente errato (come nelle statistiche)
            $row['km_per_liter'] = $kmPerLiter !== null && $kmPerLiter <= 100 ? $kmPerLiter : null;
            // chilometraggio non congruo: differenza negativa o consumo implausibile
            $row['km_error'] = ($kmDiff !== null && $kmDiff < 0)
                || ($kmPerLiter !== null && $kmPerLiter > 100);
        }
        unset($row);

        return $rows;
    }

    /**
     * Applica i filtri comuni a un builder su fb_refuelling (alias r).
     *
     * @param array<string, mixed> $filters
     */
    public static function applyFilters($builder, array $filters): void
    {
        if (!empty($filters['supplier_id'])) {
            $builder->where('r.id_supplier', (int) $filters['supplier_id']);
        }
        if (!empty($filters['vehicle_id'])) {
            $builder->where('r.id_vehicle', (int) $filters['vehicle_id']);
        }
        if (($filters['fuel_type_id'] ?? null) !== null && $filters['fuel_type_id'] !== '') {
            $builder->where('r.id_fuel_type', (int) $filters['fuel_type_id']);
        }
        if (in_array($filters['direction'] ?? null, ['in', 'out'], true)) {
            $builder->where('r.direction', $filters['direction']);
        }
        if (!empty($filters['date_from'])) {
            $builder->where('r.refuel_time >=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to'])) {
            $builder->where('r.refuel_time <=', $filters['date_to'] . ' 23:59:59');
        }
    }

    /**
     * Ultimo rifornimento registrato per un veicolo (per data/id).
     * Con $excludeId/$before restituisce il rifornimento precedente a quello in modifica.
     *
     * @return array<string, mixed>|null
     */
    public function lastForVehicle(int $idVehicle, int $excludeId = 0, ?string $before = null): ?array
    {
        $builder = $this->where('id_vehicle', $idVehicle);
        if ($excludeId > 0) {
            $builder->where('id_refuelling !=', $excludeId);
        }
        if ($before !== null) {
            $builder->where('refuel_time <', $before);
        }

        return $builder
            ->orderBy('refuel_time', 'DESC')
            ->orderBy('id_refuelling', 'DESC')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('fb_refuelling r')
            ->select('r.*, v.plate AS vehicle_plate, st.name AS station_name, s.company AS supplier_name, ft.name AS fuel_type_name')
            ->join('fb_vehicle v', 'v.id_vehicle = r.id_vehicle', 'left')
            ->join('fb_refuelling_station st', 'st.id_refuelling_station = r.id_station', 'left')
            ->join('fb_supplier s', 's.id_supplier = r.id_supplier', 'left')
            ->join('fb_fuel_type ft', 'ft.id_fuel_type = r.id_fuel_type', 'left')
            ->where('r.id_refuelling', $id)
            ->get()
            ->getRowArray();
    }
}
