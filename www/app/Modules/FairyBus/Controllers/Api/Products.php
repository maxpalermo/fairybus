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

namespace FairyBus\Controllers\Api;

use App\Controllers\AdminController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Libraries\LegacyDatabase;
use FairyBus\Models\FbBrandModel;
use FairyBus\Models\FbCategoryModel;
use FairyBus\Models\FbProductModel;

class Products extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();

        return $this->jsonResponse([
            'success' => true,
            'rows' => $model->listAll(),
        ]);
    }

    /** Statistiche globali prodotti per la toolbar KPI. */
    public function summary(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $row = $db->table('fb_product')
            ->select('COUNT(*) AS total', false)
            ->select('SUM(id_alias IS NULL) AS roots', false)
            ->select('SUM(id_alias IS NOT NULL) AS aliases', false)
            ->select('SUM(active = 1) AS active', false)
            ->select('SUM(active = 0) AS inactive', false)
            ->select('SUM(id_category IS NULL) AS no_category', false)
            ->select('COUNT(DISTINCT id_brand) AS brands', false)
            ->select('COUNT(DISTINCT id_alias) AS with_alias', false)
            ->select('SUM(s.notification_limit IS NOT NULL) AS alerts', false)
            ->select('SUM(s.notification_limit IS NOT NULL AND s.quantity <= s.notification_limit) AS low_stock', false)
            ->join('fb_stock s', 's.id_product = fb_product.id_product', 'left')
            ->get()
            ->getRowArray() ?? [];

        return $this->jsonResponse(['success' => true, 'summary' => array_map('intval', $row)]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|max_length[255]',
            'sku' => 'max_length[255]',
            'id_category' => 'permit_empty|integer',
            'id_brand' => 'permit_empty|integer',
            'id_alias' => 'permit_empty|integer',
            'active' => 'integer',
            'price' => 'decimal',
            'wholesale_price' => 'decimal',
            'tax_rate' => 'decimal',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbProductModel();
        $id = $model->insert([
            'id_category' => $this->nullIfEmpty('id_category'),
            'id_alias' => $this->nullIfEmpty('id_alias'),
            'id_brand' => $this->nullIfEmpty('id_brand'),
            'sku' => $this->request->getPost('sku'),
            'name' => $this->request->getPost('name'),
            'active' => (int) ($this->request->getPost('active') ?? 1),
            'price' => (float) ($this->request->getPost('price') ?? 0),
            'wholesale_price' => (float) ($this->request->getPost('wholesale_price') ?? 0),
            'tax_rate' => (float) ($this->request->getPost('tax_rate') ?? 0),
        ]);

        return $this->jsonResponse(['success' => true, 'id_product' => (int) $id], 201);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|max_length[255]',
            'sku' => 'max_length[255]',
            'id_category' => 'permit_empty|integer',
            'id_brand' => 'permit_empty|integer',
            'id_alias' => 'permit_empty|integer',
            'active' => 'integer',
            'price' => 'decimal',
            'wholesale_price' => 'decimal',
            'tax_rate' => 'decimal',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbProductModel();
        $product = $model->find($id);
        if ($product === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        $model->update($id, [
            'id_category' => $this->nullIfEmpty('id_category'),
            'id_alias' => $this->nullIfEmpty('id_alias'),
            'id_brand' => $this->nullIfEmpty('id_brand'),
            'sku' => $this->request->getPost('sku'),
            'name' => $this->request->getPost('name'),
            'active' => (int) ($this->request->getPost('active') ?? 1),
            'price' => (float) ($this->request->getPost('price') ?? 0),
            'wholesale_price' => (float) ($this->request->getPost('wholesale_price') ?? 0),
            'tax_rate' => (float) ($this->request->getPost('tax_rate') ?? 0),
        ]);

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    public function detachAlias(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        $product = $model->find($id);
        if ($product === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        if (empty($product['id_alias'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Il prodotto non è un alias.'], 422);
        }

        $model->update($id, ['id_alias' => null]);

        return $this->jsonResponse(['success' => true]);
    }

    public function toggleActive(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        if (!$model->toggleActive($id)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 422);
        }

        return $this->jsonResponse(['success' => true]);
    }

    public function options(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        $products = $model->select('id_product, name, sku')->where('id_alias', null)->orderBy('name', 'ASC')->findAll();
        foreach ($products as &$product) {
            $product['label'] = trim(($product['sku'] ? '[' . $product['sku'] . '] ' : '') . $product['name']);
        }

        return $this->jsonResponse([
            'success' => true,
            'products' => $products,
            'brands' => (new FbBrandModel())->orderBy('name', 'ASC')->findAll(),
            'categories' => (new FbCategoryModel())->getOptions(),
        ]);
    }

    public function aliases(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();

        return $this->jsonResponse([
            'success' => true,
            'aliases' => $model->where('id_alias', $id)->orderBy('sku', 'ASC')->findAll(),
        ]);
    }

    public function createAlias(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        $root = $model->find($id);
        if ($root === null || $root['id_alias'] !== null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto originale non valido.'], 422);
        }

        $rules = [
            'id_brand' => 'permit_empty|integer',
            'sku' => 'required|max_length[255]',
            'price' => 'permit_empty|decimal',
            'wholesale_price' => 'permit_empty|decimal',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $price = $this->request->getPost('price');
        $wholesalePrice = $this->request->getPost('wholesale_price');

        $idAlias = $model->insert([
            'id_category' => $root['id_category'] ? (int) $root['id_category'] : null,
            'id_alias' => $id,
            'id_brand' => $this->nullIfEmpty('id_brand'),
            'sku' => $this->request->getPost('sku'),
            'name' => $root['name'],
            'active' => 1,
            'price' => (float) (($price !== null && $price !== '') ? $price : $root['price']),
            'wholesale_price' => (float) (($wholesalePrice !== null && $wholesalePrice !== '') ? $wholesalePrice : $root['wholesale_price']),
            'tax_rate' => (float) $root['tax_rate'],
        ]);

        return $this->jsonResponse(['success' => true, 'id_product' => (int) $idAlias], 201);
    }

    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        if (!in_array('product', LegacyDatabase::listTables($db), true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "product" non trovata.'], 422);
        }

        $availableColumns = LegacyDatabase::getFieldNames($db, 'product');
        $desiredColumns = [
            'id',
            'name',
            'sku',
            'brand',
            'alias_id',
            'category_id',
            'status',
            'created_date',
            'last_modified_date',
        ];
        $selectColumns = array_values(array_intersect($availableColumns, $desiredColumns));
        if ($selectColumns === [] || !in_array('id', $selectColumns, true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "product" non contiene colonne riconosciute.'], 422);
        }

        $rows = LegacyDatabase::withoutPrefix(
            $db,
            static fn(BaseConnection $db): array => $db->table('product')
                ->select(implode(',', $selectColumns))
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray()
        );

        $db->table('fb_product')->truncate();

        $brandModel = new FbBrandModel();
        $brandCache = [];

        // Mappatura legacy_id => nuovo id_product
        $idMap = [];

        $imported = 0;
        foreach ($rows as $row) {
            $idBrand = null;
            if (!empty($row['brand'])) {
                $brandName = (string) $row['brand'];
                if (!isset($brandCache[$brandName])) {
                    $brandCache[$brandName] = $brandModel->getOrCreate($brandName);
                }
                $idBrand = $brandCache[$brandName];
            }

            $idCategory = null;
            if (!empty($row['category_id'])) {
                $idCategory = (int) $row['category_id'];
            }

            $legacyId = (int) $row['id'];
            $data = [
                'legacy_id' => $legacyId,
                'id_category' => $idCategory,
                'id_alias' => null, // verrà aggiornato dopo
                'id_brand' => $idBrand,
                'sku' => !empty($row['sku']) ? (string) $row['sku'] : null,
                'name' => (string) ($row['name'] ?? ''),
                'active' => isset($row['status']) ? (int) $row['status'] : 1,
                'price' => 0.000000,
                'wholesale_price' => 0.000000,
                'tax_rate' => 0.00,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];

            $db->table('fb_product')->insert($data);
            $newId = (int) $db->insertID();
            $idMap[$legacyId] = $newId;
            $imported++;
        }

        // Rimappa id_alias usando legacy_id: legacy alias_id -> nuovo id_product
        $updated = 0;
        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $legacyAliasId = !empty($row['alias_id']) ? (int) $row['alias_id'] : 0;
            if ($legacyAliasId <= 0 || !isset($idMap[$legacyId]) || !isset($idMap[$legacyAliasId])) {
                continue;
            }

            $newAliasId = $idMap[$legacyAliasId];
            $db->table('fb_product')
                ->where('legacy_id', $legacyId)
                ->update(['id_alias' => $newAliasId]);
            $updated++;
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => "Importati {$imported} prodotti dalla tabella legacy product. Aggiornati {$updated} alias.",
            'imported' => $imported,
            'aliases_updated' => $updated,
        ]);
    }

    private function nullIfEmpty(string $field): ?int
    {
        $value = $this->request->getPost($field);

        return ($value === null || $value === '' || $value === '0') ? null : (int) $value;
    }
}
