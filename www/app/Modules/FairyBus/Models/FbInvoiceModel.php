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

class FbInvoiceModel extends Model
{
    protected $table = 'fb_invoice';
    protected $primaryKey = 'id_invoice';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'number',
        'date',
        'id_customer',
        'id_supplier',
        'collection_fee',
        'deposit',
        'transport_fee',
        'type',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Fatture con fornitore/cliente e numero di documenti collegati.
     *
     * @param list<int> $ids Se valorizzato, filtra per id_invoice
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = []): array
    {
        $builder = $this->db->table('fb_invoice i')
            ->select('i.*, s.company AS supplier_name, c.company AS customer_name, (SELECT COUNT(*) FROM fb_document fd WHERE fd.id_invoice = i.id_invoice) AS documents_count')
            ->join('fb_supplier s', 's.id_supplier = i.id_supplier', 'left')
            ->join('fb_customer c', 'c.id_customer = i.id_customer', 'left')
            ->orderBy('i.date', 'DESC')
            ->orderBy('i.id_invoice', 'DESC');

        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('i.id_invoice', $ids);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('fb_invoice i')
            ->select('i.*, s.company AS supplier_name, c.company AS customer_name')
            ->join('fb_supplier s', 's.id_supplier = i.id_supplier', 'left')
            ->join('fb_customer c', 'c.id_customer = i.id_customer', 'left')
            ->where('i.id_invoice', $id)
            ->get()
            ->getRowArray();
    }
}
