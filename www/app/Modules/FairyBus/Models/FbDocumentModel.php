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

class FbDocumentModel extends Model
{
    protected $table = 'fb_document';
    protected $primaryKey = 'id_document';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'type',
        'number',
        'date',
        'id_customer',
        'id_supplier',
        'id_invoice',
        'id_ddt',
        'reference_class',
        'reference_id',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Documenti con fornitore/cliente e numero fattura collegata.
     *
     * @param list<int> $ids Se valorizzato, filtra per id_document
     * @param string $direction 'in' = carichi (fornitore o senza partner), 'out' = scarichi (cliente), 'all' = tutti
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = [], string $direction = 'all'): array
    {
        $builder = $this->db->table('fb_document d')
            ->select('d.*, s.company AS supplier_name, c.company AS customer_name, i.number AS invoice_number, t.name AS type_name')
            ->join('fb_supplier s', 's.id_supplier = d.id_supplier', 'left')
            ->join('fb_customer c', 'c.id_customer = d.id_customer', 'left')
            ->join('fb_invoice i', 'i.id_invoice = d.id_invoice', 'left')
            ->join('fb_type_document t', 't.id = d.type', 'left')
            ->orderBy('d.date', 'DESC')
            ->orderBy('d.id_document', 'DESC');

        if ($direction === 'in') {
            $builder->where('d.id_customer IS NULL', null, false);
        } elseif ($direction === 'out') {
            $builder->where('d.id_customer IS NOT NULL', null, false);
        }

        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('d.id_document', $ids);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('fb_document d')
            ->select('d.*, s.company AS supplier_name, c.company AS customer_name, i.number AS invoice_number, t.name AS type_name')
            ->join('fb_supplier s', 's.id_supplier = d.id_supplier', 'left')
            ->join('fb_customer c', 'c.id_customer = d.id_customer', 'left')
            ->join('fb_invoice i', 'i.id_invoice = d.id_invoice', 'left')
            ->join('fb_type_document t', 't.id = d.type', 'left')
            ->where('d.id_document', $id)
            ->get()
            ->getRowArray();
    }

    /**
     * Documenti di un partner (fornitore o cliente) non ancora collegati a una fattura.
     *
     * @param string $partner 'supplier' oppure 'customer'
     * @return list<array<string, mixed>>
     */
    public function listAvailableForInvoice(int $idPartner, ?int $idInvoice = null, string $partner = 'supplier'): array
    {
        $field = $partner === 'customer' ? 'd.id_customer' : 'd.id_supplier';

        return $this->db->table('fb_document d')
            ->select('d.*')
            ->where($field, $idPartner)
            ->groupStart()
            ->where('d.id_invoice IS NULL', null, false)
            ->orWhere('d.id_invoice', $idInvoice ?? 0)
            ->groupEnd()
            ->orderBy('d.date', 'DESC')
            ->orderBy('d.id_document', 'DESC')
            ->get()
            ->getResultArray();
    }
}
