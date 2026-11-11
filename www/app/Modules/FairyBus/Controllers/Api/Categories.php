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
use CodeIgniter\HTTP\ResponseInterface;
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

        $products = (int) \Config\Database::connect()->table('fb_product')->where('id_category', $id)->countAllResults();
        if ($products > 0) {
            return $this->jsonResponse(['success' => false, 'error' => "La categoria è usata da {$products} prodotti: non può essere eliminata."], 422);
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

}
