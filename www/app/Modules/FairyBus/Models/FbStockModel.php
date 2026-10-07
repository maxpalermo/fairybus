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
        3 => 'Centimetri',
        4 => 'Millimetri',
        5 => 'Millilitri',
        6 => 'Chilogrammi',
        7 => 'Grammi',
        8 => 'Metri quadri',
    ];

    public const UNIT_ABBRS = [
        0 => 'pz',
        1 => 'mt',
        2 => 'lt',
        3 => 'cm',
        4 => 'mm',
        5 => 'ml',
        6 => 'kg',
        7 => 'g',
        8 => 'mq',
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

    /** Movimenti di magazzino */
    public const MOVEMENT_IN = 'in';   // carico (+giacenza)
    public const MOVEMENT_OUT = 'out'; // scarico (-giacenza)

    /**
     * Movimenta lo stock di un prodotto.
     * $qty è sempre positiva: il segno lo decide il tipo di movimento
     * ('in'/'carico' -> loadStock, 'out'/'scarico' -> unloadStock).
     */
    public function moveStock(int $idProduct, string $movement, float $qty): void
    {
        if (in_array(strtolower($movement), [self::MOVEMENT_OUT, 'scarico', 'unload'], true)) {
            $this->unloadStock($idProduct, $qty);
        } else {
            $this->loadStock($idProduct, $qty);
        }
    }

    /**
     * Carico: incrementa quantity e inputs di $delta.
     * Se il prodotto non ha una riga stock, la crea.
     */
    public function loadStock(int $idProduct, float $delta): void
    {
        $this->applyDelta($idProduct, abs($delta));
    }

    /**
     * Scarico: decrementa quantity e incrementa outputs di $delta.
     * Se il prodotto non ha una riga stock, la crea con giacenza negativa.
     */
    public function unloadStock(int $idProduct, float $delta): void
    {
        $this->applyDelta($idProduct, -abs($delta));
    }

    /**
     * Inventario: imposta la giacenza a $qty senza toccare i contatori
     * inputs/outputs (su riga nuova inputs = qty per coerenza).
     */
    public function setStock(int $idProduct, float $qty): void
    {
        if ($idProduct <= 0) {
            return;
        }

        $row = $this->where('id_product', $idProduct)->first();
        if ($row === null) {
            $this->insert([
                'id_product' => $idProduct,
                'quantity' => $qty,
                'inputs' => $qty,
                'outputs' => 0,
                'unit' => 0,
                'date_add' => date('Y-m-d H:i:s'),
            ]);

            return;
        }

        $this->update((int) $row['id_stock'], [
            'quantity' => $qty,
            'date_upd' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Registra un movimento di magazzino su un prodotto:
     * delta positivo -> incrementa inputs, delta negativo -> incrementa outputs.
     * Se il prodotto non ha una riga stock la crea (anche con giacenza negativa).
     */
    private function applyDelta(int $idProduct, float $delta): void
    {
        if ($idProduct <= 0 || $delta == 0.0) {
            return;
        }

        $row = $this->where('id_product', $idProduct)->first();
        if ($row === null) {
            $this->insert([
                'id_product' => $idProduct,
                'quantity' => $delta,
                'inputs' => $delta > 0 ? $delta : 0,
                'outputs' => $delta < 0 ? abs($delta) : 0,
                'unit' => 0,
                'date_add' => date('Y-m-d H:i:s'),
            ]);

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

    /**
     * Imposta l'unità di misura della riga stock di un prodotto.
     * Se la riga non esiste la crea con giacenza zero.
     */
    public function setUnit(int $idProduct, int $unit): void
    {
        if ($idProduct <= 0) {
            return;
        }

        $row = $this->where('id_product', $idProduct)->first();
        if ($row === null) {
            $this->insert([
                'id_product' => $idProduct,
                'quantity' => 0,
                'inputs' => 0,
                'outputs' => 0,
                'unit' => $unit,
                'date_add' => date('Y-m-d H:i:s'),
            ]);

            return;
        }

        if ((int) $row['unit'] !== $unit) {
            $this->update((int) $row['id_stock'], [
                'unit' => $unit,
                'date_upd' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function unitLabel($unit): string
    {
        $unit = $unit === null ? 0 : (int) $unit;

        return self::UNIT_LABELS[$unit] ?? 'Pezzi';
    }

    public static function unitAbbr($unit): string
    {
        $unit = $unit === null ? 0 : (int) $unit;

        return self::UNIT_ABBRS[$unit] ?? 'pz';
    }

    /**
     * Giacenze incrociate con i dati del prodotto.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        $rows = $this->db->table('fb_stock s')
            ->select('s.*, p.sku, p.name AS product_name, p.active AS product_active, p.id_alias, b.name AS brand_name, c.name AS category_name, a.sku AS alias_sku, a.name AS alias_name')
            ->join('fb_product p', 'p.id_product = s.id_product', 'left')
            ->join('fb_product a', 'a.id_product = p.id_alias', 'left')
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
            ->select('s.*, p.sku, p.name AS product_name, p.active AS product_active, p.id_alias, b.name AS brand_name, c.name AS category_name, a.sku AS alias_sku, a.name AS alias_name')
            ->join('fb_product p', 'p.id_product = s.id_product', 'left')
            ->join('fb_product a', 'a.id_product = p.id_alias', 'left')
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
