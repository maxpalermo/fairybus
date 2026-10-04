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
        foreach ($rows as &$row) {
            $row['unit_label'] = $row['unit'] !== null ? FbStockModel::unitLabel($row['unit']) : null;
            $row['low_stock'] = $row['notification_limit'] !== null
                && $row['quantity'] !== null
                && (float) $row['quantity'] <= (float) $row['notification_limit'];
        }

        return $rows;
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
