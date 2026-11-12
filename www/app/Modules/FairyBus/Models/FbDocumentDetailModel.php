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

class FbDocumentDetailModel extends Model
{
    protected $table = 'fb_document_detail';
    protected $primaryKey = 'id_document_detail';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_document',
        'id_maintenance',
        'id_load',
        'id_product',
        'quantity',
        'price',
        'discount',
        'lot',
        'vat_code',
        'vat_rate',
        'type',
        'warehouse_id',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Righe dettaglio di un documento con dati prodotto.
     *
     * @return list<array<string, mixed>>
     */
    public function listByDocument(int $idDocument): array
    {
        return $this->db->table('fb_document_detail dd')
            ->select('dd.*, p.sku, p.name AS product_name, m.date AS maintenance_date')
            ->join('fb_product p', 'p.id_product = dd.id_product', 'left')
            ->join('fb_maintenance m', 'm.id_maintenance = dd.id_maintenance', 'left')
            ->where('dd.id_document', $idDocument)
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Dettagli di piu' documenti in una sola query (stampe massive).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listByDocuments(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }

        return $this->db->table('fb_document_detail dd')
            ->select('dd.*, p.sku, p.name AS product_name, m.date AS maintenance_date')
            ->join('fb_product p', 'p.id_product = dd.id_product', 'left')
            ->join('fb_maintenance m', 'm.id_maintenance = dd.id_maintenance', 'left')
            ->whereIn('dd.id_document', $ids)
            ->orderBy('dd.id_document', 'ASC')
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Copia le righe di una scheda manutenzione (ricambi + manodopera
     * valorizzata) in un documento di riepilogo: quantita' positive,
     * nessun movimento di magazzino (righe con id_maintenance).
     *
     * @return int numero di righe copiate
     */
    public function importMaintenanceLines(int $idDocument, int $idMaintenance, int $docType, float $defaultVat): int
    {
        $copied = 0;

        $parts = $this->db->table('fb_maintenance_detail')->where('id_maintenance', $idMaintenance)->get()->getResultArray();
        foreach ($parts as $part) {
            $this->insert([
                'id_document' => $idDocument,
                'id_maintenance' => $idMaintenance,
                'id_product' => $part['id_product'] !== null ? (int) $part['id_product'] : null,
                'quantity' => abs((float) $part['quantity']),
                'price' => (float) ($part['price'] ?? 0),
                'discount' => abs((float) ($part['discount'] ?? 0)),
                'lot' => $part['lot'] ?? null,
                'vat_code' => $part['vat_code'] ?? null,
                'vat_rate' => $part['vat_rate'] !== null ? (float) $part['vat_rate'] : null,
                'type' => $docType,
                'note' => $part['note'] ?? null,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
            $copied++;
        }

        $tasks = $this->db->table('fb_maintenance_task')->where('id_maintenance', $idMaintenance)->get()->getResultArray();
        foreach ($tasks as $task) {
            $hours = (float) ($task['hours'] ?? 0);
            $priceHour = (float) ($task['price_per_hour'] ?? 0);
            $manpower = (float) ($task['manpower'] ?? 0);
            $desc = trim((string) ($task['description'] ?? ''));
            if ($hours <= 0 && $manpower <= 0 && $priceHour <= 0) {
                continue;
            }
            $this->insert([
                'id_document' => $idDocument,
                'id_maintenance' => $idMaintenance,
                'id_product' => null,
                'quantity' => $hours > 0 ? $hours : 1,
                'price' => $priceHour > 0 ? $priceHour : $manpower,
                'discount' => 0,
                'vat_rate' => $defaultVat > 0 ? $defaultVat : null,
                'type' => $docType,
                'note' => trim('Manodopera' . ($desc !== '' ? ': ' . $desc : '') . " (sc. #{$idMaintenance})"),
                'date_add' => date('Y-m-d H:i:s'),
            ]);
            $copied++;
        }

        return $copied;
    }
}
