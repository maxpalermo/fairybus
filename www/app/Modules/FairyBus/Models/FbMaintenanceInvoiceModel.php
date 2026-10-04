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
}
