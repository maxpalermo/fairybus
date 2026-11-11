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

class FbStateModel extends Model
{
    protected $table            = 'fb_state';
    protected $primaryKey       = 'id_state';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = ['id_country', 'id_zone', 'name', 'iso_code', 'tax_behavior', 'active'];
    protected $useTimestamps    = false;

    /**
     * @return list<array<string, mixed>>
     */
    public function listByCountry(?int $idCountry = null): array
    {
        $builder = $this->orderBy('name', 'ASC');
        if ($idCountry !== null) {
            $builder->where('id_country', $idCountry);
        }

        return $builder->findAll();
    }

    public function findByIsoCode(string $isoCode): ?array
    {
        return $this->where('iso_code', $isoCode)->first();
    }
}
