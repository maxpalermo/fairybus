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

class FbStockModel extends Model
{
    /**
     * Unità di misura legacy: 0 = Pezzi, 1 = Metri, 2 = Litri.
     */
    public const UNIT_LABELS = [
        0 => 'Pezzi',
        1 => 'Metri',
        2 => 'Litri',
    ];

    protected $table = 'fb_stock';
    protected $primaryKey = 'id_stock';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'id_product',
        'unit',
        'quantity',
        'inputs',
        'outputs',
        'notification_limit',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Registra un movimento di magazzino su un prodotto:
     * delta positivo -> incrementa inputs, delta negativo -> incrementa outputs.
     * Se il prodotto non ha una riga stock e il delta è positivo, la crea.
     */
    public function adjustStock(int $idProduct, float $delta): void
    {
        if ($idProduct <= 0 || $delta == 0.0) {
            return;
        }

        $row = $this->where('id_product', $idProduct)->first();
        if ($row === null) {
            if ($delta > 0) {
                $this->insert([
                    'id_product' => $idProduct,
                    'quantity' => $delta,
                    'inputs' => $delta,
                    'outputs' => 0,
                    'unit' => 0,
                    'date_add' => date('Y-m-d H:i:s'),
                ]);
            }

            return;
        }

        $update = [
            'quantity' => (float) $row['quantity'] + $delta,
            'date_upd' => date('Y-m-d H:i:s'),
        ];
        if ($delta > 0) {
            $update['inputs'] = (float) $row['inputs'] + $delta;
        } else {
            $update['outputs'] = (float) $row['outputs'] + abs($delta);
        }

        $this->update((int) $row['id_stock'], $update);
    }

    public static function unitLabel($unit): string
    {
        $unit = $unit === null ? 0 : (int) $unit;

        return self::UNIT_LABELS[$unit] ?? 'Pezzi';
    }

    /**
     * Giacenze incrociate con i dati del prodotto.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $rows = $this->db->table('fb_stock s')
            ->select('s.*, p.sku, p.name AS product_name, p.active AS product_active, b.name AS brand_name, c.name AS category_name')
            ->join('fb_product p', 'p.id_product = s.id_product', 'left')
            ->join('fb_brand b', 'b.id_brand = p.id_brand', 'left')
            ->join('fb_category c', 'c.id_category = p.id_category', 'left')
            ->orderBy('p.name', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['unit_label'] = self::unitLabel($row['unit']);
            $row['low_stock'] = $row['notification_limit'] !== null
                && (float) $row['quantity'] <= (float) $row['notification_limit'];
        }

        return $rows;
    }

    /**
     * Giacenze per una lista di id_stock (es. selezione per la stampa).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listByIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('fb_stock s')
            ->select('s.*, p.sku, p.name AS product_name, p.active AS product_active, b.name AS brand_name, c.name AS category_name')
            ->join('fb_product p', 'p.id_product = s.id_product', 'left')
            ->join('fb_brand b', 'b.id_brand = p.id_brand', 'left')
            ->join('fb_category c', 'c.id_category = p.id_category', 'left')
            ->whereIn('s.id_stock', $ids)
            ->orderBy('p.name', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['unit_label'] = self::unitLabel($row['unit']);
            $row['low_stock'] = $row['notification_limit'] !== null
                && (float) $row['quantity'] <= (float) $row['notification_limit'];
        }

        return $rows;
    }
}
