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

class FbExpirationTagModel extends Model
{
    protected $table = 'fb_expiration_tag';
    protected $primaryKey = 'id_expiration_tag';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'name',
        'applies_to',
        'kind',
        'interval_value',
        'interval_unit',
        'status',
        'note',
        'date_add',
        'date_upd',
    ];
    protected $useTimestamps = false;

    /**
     * Intervallo in formato leggibile ("20.000 km", "6 mesi", "1 anno", "60 giorni").
     */
    public static function intervalLabel(array $tag): string
    {
        if ($tag['kind'] === 'km' || $tag['interval_unit'] === 'km') {
            return number_format((float) $tag['interval_value'], 0, ',', '.') . ' km';
        }
        $units = ['days' => 'giorni', 'months' => 'mesi', 'years' => 'anni', 'day' => 'giorni', 'month' => 'mesi', 'year' => 'anni'];
        $unit = $units[$tag['interval_unit'] ?? ''] ?? ($tag['interval_unit'] ?? '');
        $n = (int) $tag['interval_value'];
        if ($unit === 'mesi' && $n === 1) {
            return '1 mese';
        }
        if ($unit === 'anni' && $n === 1) {
            return '1 anno';
        }

        return trim($n . ' ' . $unit);
    }

    /**
     * Etichette con intervallo formattato.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = []): array
    {
        $builder = $this->orderBy('name', 'ASC');
        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('id_expiration_tag', $ids);
        }
        $rows = $builder->findAll();
        foreach ($rows as &$row) {
            $row['interval_label'] = self::intervalLabel($row);
        }

        return $rows;
    }
}
