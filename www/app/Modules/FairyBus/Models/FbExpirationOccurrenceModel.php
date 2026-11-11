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

class FbExpirationOccurrenceModel extends Model
{
    /** Stati importati dal legacy */
    public const STATE_OPEN = 0;      // generata / in attesa
    public const STATE_NOTIFIED = 1;  // notificata
    public const STATE_SCHEDULED = 2; // pianificata
    public const STATE_EXPIRED = 3;   // scaduta
    public const STATE_DONE = 4;      // eseguita (collegata a un tagliando)

    public const STATE_LABELS = [
        0 => 'In attesa',
        1 => 'Notificata',
        2 => 'Pianificata',
        3 => 'Scaduta',
        4 => 'Eseguita',
    ];

    protected $table = 'fb_expiration_occurrence';
    protected $primaryKey = 'id_expiration_occurrence';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_expiration',
        'expiration_date',
        'km',
        'state',
        'done_date',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Occorrenze con veicolo/etichetta, per calendario e allerte.
     *
     * @param list<int> $states Stati da includere (default tutti)
     * @return list<array<string, mixed>>
     */
    public function listAll(array $states = []): array
    {
        $builder = $this->db->table('fb_expiration_occurrence o')
            ->select('o.*, e.id_vehicle, e.id_expiration_tag, e.description, e.kind AS expiration_kind, e.hidden,
                v.plate AS vehicle_plate, v.current_km, t.name AS tag_name, t.kind AS tag_kind, t.interval_value, t.interval_unit')
            ->join('fb_expiration e', 'e.id_expiration = o.id_expiration', 'left')
            ->join('fb_vehicle v', 'v.id_vehicle = e.id_vehicle', 'left')
            ->join('fb_expiration_tag t', 't.id_expiration_tag = e.id_expiration_tag', 'left')
            ->where("v.status !=", 'retired')
            ->orderBy('o.expiration_date', 'ASC');

        if ($states !== []) {
            $builder->whereIn('o.state', $states);
        }

        $rows = $builder->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['state_label'] = self::STATE_LABELS[(int) $row['state']] ?? '—';
        }

        return $rows;
    }

    /**
     * Occorrenze per id (stampa selezionate).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('fb_expiration_occurrence o')
            ->select('o.*, e.id_vehicle, e.id_expiration_tag, e.description, e.kind AS expiration_kind, e.hidden,
                v.plate AS vehicle_plate, v.current_km, t.name AS tag_name, t.kind AS tag_kind, t.interval_value, t.interval_unit')
            ->join('fb_expiration e', 'e.id_expiration = o.id_expiration', 'left')
            ->join('fb_vehicle v', 'v.id_vehicle = e.id_vehicle', 'left')
            ->join('fb_expiration_tag t', 't.id_expiration_tag = e.id_expiration_tag', 'left')
            ->where("v.status !=", 'retired')
            ->whereIn('o.id_expiration_occurrence', $ids)
            ->orderBy('o.expiration_date', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['state_label'] = self::STATE_LABELS[(int) $row['state']] ?? '—';
            $row['is_km_deadline'] = $row['expiration_date'] === null || ($row['tag_kind'] === 'km');
        }

        return $rows;
    }

    /**
     * Occorrenze "aperte" imminenti: entro $days giorni (scadenze a data)
     * oppure entro $kmMargin km dal chilometraggio attuale del veicolo.
     *
     * @return list<array<string, mixed>>
     */
    public function listAlerts(int $days = 30, int $kmMargin = 2000): array
    {
        $today = date('Y-m-d');
        $limit = date('Y-m-d', strtotime("+{$days} days"));

        $rows = $this->db->table('fb_expiration_occurrence o')
            ->select('o.*, e.id_vehicle, e.id_expiration_tag, e.description, e.kind AS expiration_kind, e.hidden,
                v.plate AS vehicle_plate, v.current_km, t.name AS tag_name, t.kind AS tag_kind, t.interval_value, t.interval_unit')
            ->join('fb_expiration e', 'e.id_expiration = o.id_expiration', 'left')
            ->join('fb_vehicle v', 'v.id_vehicle = e.id_vehicle', 'left')
            ->join('fb_expiration_tag t', 't.id_expiration_tag = e.id_expiration_tag', 'left')
            ->groupStart()
            ->groupStart()
            ->where('o.expiration_date IS NOT NULL', null, false)
            ->where('o.expiration_date <=', $limit)
            ->groupEnd()
            ->orGroupStart()
            ->where('o.km IS NOT NULL', null, false)
            ->where('o.km <= v.current_km + ' . (int) $kmMargin, null, false)
            ->groupEnd()
            ->groupEnd()
            ->whereNotIn('COALESCE(o.state,0)', [self::STATE_DONE])
            ->where('COALESCE(e.hidden,0)', 0)
            ->where("v.status !=", 'retired')
            ->orderBy('o.expiration_date', 'ASC')
            ->orderBy('o.km', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['state_label'] = self::STATE_LABELS[(int) $row['state']] ?? '—';
            $row['is_km_deadline'] = $row['expiration_date'] === null || ($row['tag_kind'] === 'km');
        }

        return $rows;
    }
}
