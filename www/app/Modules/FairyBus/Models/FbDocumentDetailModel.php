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
            ->select('dd.*, p.sku, p.name AS product_name')
            ->join('fb_product p', 'p.id_product = dd.id_product', 'left')
            ->where('dd.id_document', $idDocument)
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()
            ->getResultArray();
    }
}
