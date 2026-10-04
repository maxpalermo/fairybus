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
use FairyBus\Models\FbCategoryModel;

class Categories extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbCategoryModel();

        return $this->jsonResponse([
            'success' => true,
            'rows' => $model->listAll(),
        ]);
    }

    public function options(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbCategoryModel();

        return $this->jsonResponse([
            'success' => true,
            'options' => $model->getOptions(),
        ]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|max_length[255]',
            'id_parent' => 'required|integer',
            'description' => 'max_length[65535]',
            'active' => 'integer',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbCategoryModel();
        $idParent = (int) $this->request->getPost('id_parent');
        if ($idParent === 0) {
            $idParent = 1;
        }

        $id = $model->createCategory([
            'id_parent' => $idParent,
            'name' => $this->request->getPost('name'),
            'description' => $this->request->getPost('description'),
            'active' => $this->request->getPost('active') ?? 1,
        ]);

        return $this->jsonResponse(['success' => true, 'id_category' => $id], 201);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|max_length[255]',
            'id_parent' => 'required|integer',
            'description' => 'max_length[65535]',
            'active' => 'integer',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbCategoryModel();
        $category = $model->findById($id);
        if ($category === null || $id === 1) {
            return $this->jsonResponse(['success' => false, 'error' => 'Categoria non trovata.'], 404);
        }

        $idParent = (int) $this->request->getPost('id_parent');
        if ($idParent === 0) {
            $idParent = 1;
        }

        $model->updateCategory($id, [
            'id_parent' => $idParent,
            'name' => $this->request->getPost('name'),
            'description' => $this->request->getPost('description'),
            'active' => $this->request->getPost('active') ?? 1,
        ]);

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if ($id === 1) {
            return $this->jsonResponse(['success' => false, 'error' => 'La categoria radice non può essere eliminata.'], 422);
        }

        $model = new FbCategoryModel();
        if (!$model->deleteCategory($id)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Impossibile eliminare: la categoria ha sotto-categorie.'], 422);
        }

        return $this->jsonResponse(['success' => true]);
    }

    public function toggleActive(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbCategoryModel();
        if (!$model->toggleActive($id)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Categoria non trovata o non modificabile.'], 422);
        }

        return $this->jsonResponse(['success' => true]);
    }

    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        if (!in_array('category', LegacyDatabase::listTables($db), true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "category" non trovata.'], 422);
        }

        $availableColumns = LegacyDatabase::getFieldNames($db, 'category');
        $desiredColumns = [
            'id',
            'name',
            'note',
            'status',
            'organization_id',
            'created_date',
            'last_modified_date',
        ];
        $selectColumns = array_values(array_intersect($availableColumns, $desiredColumns));
        if ($selectColumns === [] || !in_array('id', $selectColumns, true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "category" non contiene colonne riconosciute.'], 422);
        }

        $rows = LegacyDatabase::withoutPrefix(
            $db,
            static fn(BaseConnection $db): array => $db->table('category')
                ->select(implode(',', $selectColumns))
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray()
        );

        $db->table('fb_category')->truncate();

        $model = new FbCategoryModel();
        $imported = 0;

        foreach ($rows as $row) {
            $data = [
                'id_category' => (int) $row['id'],
                'id_parent' => null,
                'level_depth' => 0,
                'active' => isset($row['status']) ? (int) $row['status'] : 1,
                'position' => $imported,
                'name' => (string) ($row['name'] ?? ''),
                'description' => !empty($row['note']) ? (string) $row['note'] : null,
            ];

            $db->table('fb_category')->insert($data);

            $updateDates = [];
            if (!empty($row['created_date'])) {
                $updateDates['created_at'] = $row['created_date'];
            }
            if (!empty($row['last_modified_date'])) {
                $updateDates['updated_at'] = $row['last_modified_date'];
            }
            if ($updateDates !== []) {
                $db->table('fb_category')->where('id_category', (int) $row['id'])->update($updateDates);
            }

            $imported++;
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => "Importate {$imported} categorie dalla tabella legacy category.",
            'imported' => $imported,
        ]);
    }
}
