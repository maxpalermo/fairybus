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

class FbMaintenanceDetailModel extends Model
{
    protected $table = 'fb_maintenance_detail';
    protected $primaryKey = 'id_maintenance_detail';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_maintenance',
        'id_load',
        'id_product',
        'quantity',
        'price',
        'discount',
        'lot',
        'vat_code',
        'vat_rate',
        'warehouse_id',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Righe di una manutenzione con dati prodotto.
     *
     * @return list<array<string, mixed>>
     */
    public function listByMaintenance(int $idMaintenance): array
    {
        $rows = $this->db->table('fb_maintenance_detail d')
            ->select('d.*, p.sku, p.name AS product_name,
                (SELECT s.unit FROM fb_stock s WHERE s.id_product = p.id_product ORDER BY s.id_stock LIMIT 1) AS unit,
                t.hours AS labor_hours, t.price_per_hour AS labor_price_per_hour, t.manpower AS labor_manpower')
            ->join('fb_product p', 'p.id_product = d.id_product', 'left')
            ->join('fb_maintenance_task t', 't.id_maintenance_detail = d.id_maintenance_detail', 'left')
            ->where('d.id_maintenance', $idMaintenance)
            ->orderBy('d.id_maintenance_detail', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['unit_abbr'] = FbStockModel::unitAbbr($row['unit'] ?? 0);
        }

        return $rows;
    }

    /**
     * Righe di piu' manutenzioni in una sola query (stampe massive).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listByMaintenances(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('fb_maintenance_detail d')
            ->select('d.*, p.sku, p.name AS product_name,
                (SELECT s.unit FROM fb_stock s WHERE s.id_product = p.id_product ORDER BY s.id_stock LIMIT 1) AS unit,
                t.hours AS labor_hours, t.price_per_hour AS labor_price_per_hour, t.manpower AS labor_manpower')
            ->join('fb_product p', 'p.id_product = d.id_product', 'left')
            ->join('fb_maintenance_task t', 't.id_maintenance_detail = d.id_maintenance_detail', 'left')
            ->whereIn('d.id_maintenance', $ids)
            ->orderBy('d.id_maintenance_detail', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['unit_abbr'] = FbStockModel::unitAbbr($row['unit'] ?? 0);
        }

        return $rows;
    }

    /**
     * Righe dettaglio (ricambi + manodopera) di tutte le manutenzioni di un
     * veicolo, appiattite con data, km e note della scheda — usate dalla
     * tabella Riparazioni del dettaglio veicolo e dal report storico.
     *
     * @return list<array<string, mixed>>
     */
    public function linesForVehicle(int $idVehicle): array
    {
        return $this->db->table('fb_maintenance_detail d')
            ->select('d.*, m.date AS maintenance_date, m.km AS maintenance_km, m.note AS maintenance_note,
                p.sku, p.name AS product_name,
                ld.id_document AS load_document_id, doc.number AS load_number, s.company AS load_supplier,
                t.hours AS labor_hours, t.price_per_hour AS labor_price_per_hour, t.manpower AS labor_manpower')
            ->join('fb_maintenance m', 'm.id_maintenance = d.id_maintenance')
            ->join('fb_product p', 'p.id_product = d.id_product', 'left')
            ->join('fb_document_detail ld', 'ld.id_document_detail = d.id_load', 'left')
            ->join('fb_document doc', 'doc.id_document = ld.id_document', 'left')
            ->join('fb_supplier s', 's.id_supplier = doc.id_supplier', 'left')
            ->join('fb_maintenance_task t', 't.id_maintenance_detail = d.id_maintenance_detail', 'left')
            ->where('m.id_vehicle', $idVehicle)
            ->orderBy('m.date', 'DESC')
            ->orderBy('m.id_maintenance', 'DESC')
            ->orderBy('d.id_maintenance_detail', 'ASC')
            ->get()->getResultArray();
    }
}
