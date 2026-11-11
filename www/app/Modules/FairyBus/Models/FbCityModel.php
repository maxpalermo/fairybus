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

class FbCityModel extends Model
{
    protected $table            = 'fb_city';
    protected $primaryKey       = 'id_city';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = ['postcode', 'city', 'state', 'iso_code', 'status', 'date_add', 'date_upd'];
    protected $useTimestamps    = false;

    /**
     * @return list<array<string, mixed>>
     */
    public function listFiltered(?string $isoCode = null, ?string $search = null, int $limit = 50, int $offset = 0): array
    {
        $builder = $this->db->table('fb_city c')
            ->select('c.*, s.name AS state_name, s.id_country')
            ->join('fb_state s', 's.iso_code = c.iso_code', 'left')
            ->orderBy('c.city', 'ASC')
            ->orderBy('c.postcode', 'ASC')
            ->limit($limit, $offset);

        if ($isoCode !== null && $isoCode !== '') {
            $builder->where('c.iso_code', $isoCode);
        }

        if ($search !== null && $search !== '') {
            $builder->groupStart()
                ->like('c.city', $search, 'both', true, true)
                ->orLike('c.postcode', $search, 'both', true, true)
                ->groupEnd();
        }

        return $builder->get()->getResultArray();
    }

    public function countFiltered(?string $isoCode = null, ?string $search = null): int
    {
        $builder = $this->db->table('fb_city c')
            ->join('fb_state s', 's.iso_code = c.iso_code', 'left');

        if ($isoCode !== null && $isoCode !== '') {
            $builder->where('c.iso_code', $isoCode);
        }

        if ($search !== null && $search !== '') {
            $builder->groupStart()
                ->like('c.city', $search, 'both', true, true)
                ->orLike('c.postcode', $search, 'both', true, true)
                ->groupEnd();
        }

        return (int) $builder->countAllResults();
    }
}
