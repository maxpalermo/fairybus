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
use FairyBus\Models\FbBrandModel;

class Brands extends AdminController
{
    private BaseConnection $db;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->db = \Config\Database::connect();
    }

    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbBrandModel();
        $rows = $model->orderBy('name', 'ASC')->findAll();

        foreach ($rows as &$row) {
            $row['id_brand'] = (int) $row['id_brand'];
        }

        return $this->jsonResponse([
            'success' => true,
            'rows' => $rows,
        ]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|max_length[255]|is_unique[fb_brand.name]',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new FbBrandModel();
        $id = $model->insert(['name' => $this->request->getPost('name')]);

        return $this->jsonResponse(['success' => true, 'id_brand' => (int) $id]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => "required|max_length[255]|is_unique[fb_brand.name,id_brand,{$id}]",
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new FbBrandModel();
        $model->update($id, ['name' => $this->request->getPost('name')]);

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbBrandModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Marca non trovata.'], 404);
        }

        $db = \Config\Database::connect();
        $reasons = [];
        $products = (int) $db->table('fb_product')->where('id_brand', $id)->countAllResults();
        if ($products > 0) {
            $reasons[] = "{$products} prodotti";
        }
        $vehicles = (int) $db->table('fb_vehicle')->where('id_brand', $id)->countAllResults();
        if ($vehicles > 0) {
            $reasons[] = "{$vehicles} veicoli";
        }
        if ($reasons !== []) {
            return $this->jsonResponse(['success' => false, 'error' => 'Marca non eliminabile: usata da ' . implode(' e ', $reasons) . '.'], 422);
        }

        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

}
