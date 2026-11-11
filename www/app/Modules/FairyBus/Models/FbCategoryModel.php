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

class FbCategoryModel extends Model
{
    protected $table = 'fb_category';
    protected $primaryKey = 'id_category';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'id_parent',
        'level_depth',
        'active',
        'position',
        'name',
        'description',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * @param list<int> $ids Se valorizzato, filtra per id_category
     * @return list<array<string, mixed>>
     */
    public function listAll(array $ids = []): array
    {
        $builder = $this->orderBy('id_parent', 'ASC')
            ->orderBy('position', 'ASC')
            ->orderBy('name', 'ASC');

        if ($ids !== []) {
            $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('id_category', $ids);
        }

        return $builder->findAll();
    }

    /**
     * Categorie selezionate con il nome della categoria padre (per le stampe).
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

        return $this->db->table('fb_category c')
            ->select('c.*, p.name AS parent_name')
            ->join('fb_category p', 'p.id_category = c.id_parent', 'left')
            ->whereIn('c.id_category', $ids)
            ->orderBy('c.id_parent', 'ASC')
            ->orderBy('c.position', 'ASC')
            ->orderBy('c.name', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        return $this->where('active', 1)
            ->orderBy('id_parent', 'ASC')
            ->orderBy('position', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findChildren(int $idParent): array
    {
        return $this->where('id_parent', $idParent)
            ->orderBy('position', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->find($id);
    }

    public function hasChildren(int $id): bool
    {
        return $this->where('id_parent', $id)->countAllResults() > 0;
    }

    /**
     * @return list<int>
     */
    public function getChildrenIds(int $id): array
    {
        $rows = $this->select('id_category')->where('id_parent', $id)->findAll();

        return array_column($rows, 'id_category');
    }

    public function getNextPosition(int $idParent): int
    {
        $row = $this->selectMax('position')
            ->where('id_parent', $idParent)
            ->first();

        return ((int) ($row['position'] ?? 0)) + 1;
    }

    public function calculateDepth(int $idParent): int
    {
        if ($idParent <= 0) {
            return 0;
        }

        $parent = $this->find($idParent);

        return $parent !== null ? ((int) $parent['level_depth']) + 1 : 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createCategory(array $data): int
    {
        $idParent = (int) ($data['id_parent'] ?? 0);
        if ($idParent === 0) {
            $idParent = 1;
            $data['id_parent'] = 1;
        }

        $data['level_depth'] = $this->calculateDepth($idParent);
        $data['position'] = $this->getNextPosition($idParent);
        $data['active'] = isset($data['active']) ? (int) $data['active'] : 1;

        return (int) $this->insert($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateCategory(int $id, array $data): bool
    {
        $category = $this->find($id);
        if ($category === null) {
            return false;
        }

        $newParent = array_key_exists('id_parent', $data) ? (int) $data['id_parent'] : (int) $category['id_parent'];
        if ($newParent === $id) {
            $newParent = (int) $category['id_parent'];
            $data['id_parent'] = $newParent;
        }

        $data['level_depth'] = $this->calculateDepth($newParent);
        $data['active'] = isset($data['active']) ? (int) $data['active'] : (int) $category['active'];

        return $this->update($id, $data);
    }

    public function deleteCategory(int $id): bool
    {
        if ($id === 1) {
            return false;
        }

        if ($this->hasChildren($id)) {
            return false;
        }

        return $this->delete($id);
    }

    public function toggleActive(int $id): bool
    {
        $category = $this->find($id);
        if ($category === null || $id === 1) {
            return false;
        }

        return (bool) $this->update($id, ['active' => ((int) $category['active']) === 1 ? 0 : 1]);
    }
    /**
     * @return list<array<string, mixed>>
     */
    public function getOptions(): array
    {
        $rows = $this->orderBy('id_parent', 'ASC')
            ->orderBy('position', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id_category']] = $row;
        }

        $options = [];
        foreach ($rows as $row) {
            $depth = (int) $row['level_depth'];
            $prefix = $depth > 0 ? str_repeat('— ', $depth) : '';
            $options[] = [
                'id_category' => (int) $row['id_category'],
                'name' => $prefix . $row['name'],
                'depth' => $depth,
            ];
        }

        return $options;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTree(): array
    {
        $rows = $this->orderBy('id_parent', 'ASC')
            ->orderBy('position', 'ASC')
            ->orderBy('name', 'ASC')
            ->findAll();

        $indexed = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $indexed[(int) $row['id_category']] = $row;
        }

        $tree = [];
        foreach ($indexed as $id => $row) {
            $parentId = (int) $row['id_parent'];
            if ($parentId > 0 && isset($indexed[$parentId])) {
                $indexed[$parentId]['children'][] = &$indexed[$id];
            } else {
                $tree[] = &$indexed[$id];
            }
        }

        return $tree;
    }
}
