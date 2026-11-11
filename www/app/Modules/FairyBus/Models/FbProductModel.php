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

class FbProductModel extends Model
{
    protected $table = 'fb_product';
    protected $primaryKey = 'id_product';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_category',
        'id_alias',
        'id_brand',
        'sku',
        'name',
        'active',
        'price',
        'wholesale_price',
        'tax_rate',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * @param list<int> $ids Se valorizzato, filtra per id_product
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = []): array
    {
        $builder = $this->db->table('fb_product p')
            ->select('p.*, b.name AS brand_name, c.name AS category_name, a.sku AS alias_sku, a.name AS alias_name, s.id_stock, s.unit, s.quantity, s.notification_limit, s.note AS stock_note, (SELECT COUNT(*) FROM fb_product pa WHERE pa.id_alias = p.id_product) AS alias_count')
            ->join('fb_brand b', 'b.id_brand = p.id_brand', 'left')
            ->join('fb_category c', 'c.id_category = p.id_category', 'left')
            ->join('fb_product a', 'a.id_product = p.id_alias', 'left')
            ->join('fb_stock s', 's.id_product = p.id_product', 'left')
            ->orderBy('p.name', 'ASC');

        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('p.id_product', $ids);
        }

        $rows = $builder->get()->getResultArray();
        $lastBuy = $this->lastPurchases(array_column($rows, 'id_product'));
        foreach ($rows as &$row) {
            $row['unit_label'] = $row['unit'] !== null ? FbStockModel::unitLabel($row['unit']) : null;
            $row['low_stock'] = $row['notification_limit'] !== null
                && $row['quantity'] !== null
                && (float) $row['quantity'] <= (float) $row['notification_limit'];
            $lp = $lastBuy[(int) $row['id_product']] ?? null;
            $row['last_buy_price'] = $lp['price'] ?? null;
            $row['last_buy_supplier'] = $lp['supplier'] ?? null;
            $row['last_buy_id_document'] = $lp['id_document'] ?? null;
        }

        return $rows;
    }

    /**
     * Ultimo prezzo d'acquisto per prodotto (netto dello sconto riga),
     * dai documenti di carico: mappa id_product => dati dell'ultima riga
     * di carico per data/id documento.
     *
     * @param list<int> $ids
     * @return array<int, array{price: float, id_document: int, supplier: string|null}>
     */
    private function lastPurchases(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('fb_document_detail dd')
            ->select('dd.id_product, dd.price, dd.discount, dd.id_document, s.company AS supplier_name')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->join('fb_supplier s', 's.id_supplier = d.id_supplier', 'left')
            ->where('d.id_customer IS NULL', null, false)
            ->where('dd.id_maintenance IS NULL', null, false)
            ->whereIn('dd.id_product', $ids)
            ->orderBy('d.date', 'DESC')
            ->orderBy('d.id_document', 'DESC')
            ->orderBy('dd.id_document_detail', 'DESC')
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $pid = (int) $row['id_product'];
            if (!isset($map[$pid])) {
                $map[$pid] = [
                    'price' => (float) $row['price'] * (1 - abs((float) ($row['discount'] ?? 0)) / 100),
                    'id_document' => (int) $row['id_document'],
                    'supplier' => $row['supplier_name'],
                ];
            }
        }

        return $map;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('fb_product p')
            ->select('p.*, b.name AS brand_name, c.name AS category_name, a.sku AS alias_sku, a.name AS alias_name')
            ->join('fb_brand b', 'b.id_brand = p.id_brand', 'left')
            ->join('fb_category c', 'c.id_category = p.id_category', 'left')
            ->join('fb_product a', 'a.id_product = p.id_alias', 'left')
            ->where('p.id_product', $id)
            ->get()
            ->getRowArray();
    }

    public function toggleActive(int $id): bool
    {
        $product = $this->find($id);
        if ($product === null) {
            return false;
        }

        return (bool) $this->update($id, ['active' => ((int) $product['active']) === 1 ? 0 : 1]);
    }
}
