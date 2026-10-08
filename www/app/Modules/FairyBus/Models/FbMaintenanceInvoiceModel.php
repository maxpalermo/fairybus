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

class FbMaintenanceInvoiceModel extends Model
{
    protected $table = 'fb_maintenance_invoice';
    protected $primaryKey = 'id_maintenance_invoice';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_maintenance',
        'id_invoice',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Fatture collegate a una manutenzione.
     *
     * @return list<array<string, mixed>>
     */
    public function listByMaintenance(int $idMaintenance): array
    {
        return $this->db->table('fb_maintenance_invoice mi')
            ->select('mi.*, i.number AS invoice_number, i.date AS invoice_date, s.company AS supplier_name, c.company AS customer_name')
            ->join('fb_invoice i', 'i.id_invoice = mi.id_invoice', 'left')
            ->join('fb_supplier s', 's.id_supplier = i.id_supplier', 'left')
            ->join('fb_customer c', 'c.id_customer = i.id_customer', 'left')
            ->where('mi.id_maintenance', $idMaintenance)
            ->orderBy('mi.id_maintenance_invoice', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Righe dettaglio normalizzate delle schede indicate: ricambi + manodopera
     * nello stesso formato delle righe documento (quantity/price/discount/vat_rate).
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array<string, mixed>>> mappa id_maintenance => righe
     */
    public static function detailLines(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }

        $db = \Config\Database::connect();
        $map = array_fill_keys($ids, []);

        $parts = $db->table('fb_maintenance_detail d')
            ->select('d.id_maintenance, d.quantity, d.price, d.discount, d.vat_rate, d.note, p.sku, p.name AS product_name')
            ->join('fb_product p', 'p.id_product = d.id_product', 'left')
            ->whereIn('d.id_maintenance', $ids)
            ->orderBy('d.id_maintenance_detail', 'ASC')
            ->get()->getResultArray();
        foreach ($parts as $p) {
            // le quantita' dei ricambi sono scarichi di magazzino (negative):
            // in fatturazione si mostrano sempre positive
            $map[(int) $p['id_maintenance']][] = [
                'sku' => $p['sku'],
                'product_name' => $p['product_name'] ?? ($p['note'] ?: 'Articolo'),
                'quantity' => abs((float) $p['quantity']),
                'price' => (float) $p['price'],
                'discount' => (float) ($p['discount'] ?? 0),
                'vat_rate' => $p['vat_rate'] !== null ? (float) $p['vat_rate'] : null,
            ];
        }

        $tasks = $db->table('fb_maintenance_task')
            ->select('id_maintenance, description, hours, price_per_hour, manpower')
            ->whereIn('id_maintenance', $ids)
            ->orderBy('id_maintenance_task', 'ASC')
            ->get()->getResultArray();
        foreach ($tasks as $t) {
            $hours = (float) ($t['hours'] ?? 0);
            $priceHour = (float) ($t['price_per_hour'] ?? 0);
            $manpower = (float) ($t['manpower'] ?? 0);
            $desc = trim((string) ($t['description'] ?? ''));
            if ($hours <= 0 && $manpower <= 0 && $priceHour <= 0 && $desc === '') {
                continue;
            }
            $map[(int) $t['id_maintenance']][] = [
                'sku' => null,
                'product_name' => 'Manodopera' . ($desc !== '' ? ': ' . $desc : ''),
                'quantity' => $hours > 0 ? $hours : 1,
                'price' => $priceHour > 0 ? $priceHour : $manpower,
                'discount' => 0,
                'vat_rate' => null,
            ];
        }

        return $map;
    }

    /**
     * Schede manutenzione collegate a una fattura cliente, complete di righe
     * dettaglio normalizzate (per la visualizzazione e la stampa).
     *
     * @return list<array<string, mixed>>
     */
    public static function blocksByInvoice(int $idInvoice): array
    {
        if ($idInvoice <= 0) {
            return [];
        }
        $db = \Config\Database::connect();
        $rows = $db->table('fb_maintenance_invoice mi')
            ->select('m.id_maintenance, m.date, m.km, m.note, m.id_document, v.plate')
            ->join('fb_maintenance m', 'm.id_maintenance = mi.id_maintenance')
            ->join('fb_vehicle v', 'v.id_vehicle = m.id_vehicle', 'left')
            ->where('mi.id_invoice', $idInvoice)
            ->orderBy('m.date', 'ASC')
            ->orderBy('m.id_maintenance', 'ASC')
            ->get()->getResultArray();

        $lines = self::detailLines(array_column($rows, 'id_maintenance'));
        foreach ($rows as &$row) {
            $row['details'] = $lines[(int) $row['id_maintenance']] ?? [];
        }

        return $rows;
    }

    /**
     * Propaga il collegamento alla fattura sulle schede manutenzione
     * associate al documento di scarico (conto terzi).
     */
    public static function linkByDocument(int $idDocument, int $idInvoice): void
    {
        if ($idDocument <= 0 || $idInvoice <= 0) {
            return;
        }
        $db = \Config\Database::connect();
        $ids = array_column(
            $db->table('fb_maintenance')->select('id_maintenance')->where('id_document', $idDocument)->get()->getResultArray(),
            'id_maintenance'
        );
        foreach ($ids as $idMaintenance) {
            $exists = $db->table('fb_maintenance_invoice')
                ->where('id_maintenance', (int) $idMaintenance)
                ->where('id_invoice', $idInvoice)
                ->countAllResults() > 0;
            if (!$exists) {
                $db->table('fb_maintenance_invoice')->insert([
                    'id_maintenance' => (int) $idMaintenance,
                    'id_invoice' => $idInvoice,
                    'date_add' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // riferimento diretto alla fattura sulla scheda (registrata)
        $db->table('fb_maintenance')->where('id_document', $idDocument)->update(['id_invoice' => $idInvoice]);
    }

    /**
     * Rimuove i riferimenti manutenzione<->fattura generati tramite il
     * documento di scarico (solo quelli verso la fattura indicata).
     */
    public static function unlinkByDocument(int $idDocument, int $idInvoice): void
    {
        if ($idDocument <= 0 || $idInvoice <= 0) {
            return;
        }
        $db = \Config\Database::connect();
        $ids = array_map('intval', array_column(
            $db->table('fb_maintenance')->select('id_maintenance')->where('id_document', $idDocument)->get()->getResultArray(),
            'id_maintenance'
        ));
        if ($ids === []) {
            return;
        }
        $db->table('fb_maintenance_invoice')
            ->where('id_invoice', $idInvoice)
            ->whereIn('id_maintenance', $ids)
            ->delete();

        // azzera il riferimento diretto solo sulle schede senza altri link fattura
        $db->table('fb_maintenance')
            ->where('id_invoice', $idInvoice)
            ->whereIn('id_maintenance', $ids)
            ->where("NOT EXISTS (SELECT 1 FROM fb_maintenance_invoice mi WHERE mi.id_maintenance = fb_maintenance.id_maintenance)", null, false)
            ->update(['id_invoice' => null]);
    }
}
