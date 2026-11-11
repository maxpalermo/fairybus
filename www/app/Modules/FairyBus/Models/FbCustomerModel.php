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

class FbCustomerModel extends Model
{
    protected $table = 'fb_customer';
    protected $primaryKey = 'id_customer';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'legacy_id',
        'company',
        'vat_number',
        'email',
        'pec',
        'contact_name',
        'active',
    ];
    protected $useTimestamps = false;

    /**
     * @param list<int> $ids Se valorizzato, filtra per id_customer
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = []): array
    {
        $builder = $this->db->table('fb_customer c')
            ->select('c.*, a.address1, a.address2, a.postcode, a.city, a.id_country, a.id_state, co.name AS country_name, st.name AS state_name, a.phone_number, a.mobile_number')
            ->join('fb_address a', 'a.id_customer = c.id_customer', 'left')
            ->join('fb_country co', 'co.id_country = a.id_country', 'left')
            ->join('fb_state st', 'st.id_state = a.id_state', 'left')
            ->orderBy('c.company', 'ASC');

        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('c.id_customer', $ids);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->table('fb_customer c')
            ->select('c.*, a.id_address, a.address1, a.address2, a.postcode, a.city, a.id_country, a.id_state, a.phone_number, a.mobile_number')
            ->join('fb_address a', 'a.id_customer = c.id_customer', 'left')
            ->where('c.id_customer', $id)
            ->get()
            ->getRowArray();
    }
}
