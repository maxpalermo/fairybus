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
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Import unique brand names from the legacy `vehicle` table.
     */
    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $legacyTables = LegacyDatabase::listTables($this->db);
        if (!in_array('vehicle', $legacyTables, true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "vehicle" non trovata.'], 422);
        }

        $brands = LegacyDatabase::withoutPrefix(
            $this->db,
            fn(BaseConnection $db) => $db
                ->table('vehicle')
                ->select('brand')
                ->where('brand IS NOT NULL')
                ->where('brand !=', '')
                ->groupBy('brand')
                ->orderBy('brand', 'ASC')
                ->get()
                ->getResultArray()
        );

        $model = new FbBrandModel();
        $created = 0;
        foreach ($brands as $brand) {
            $existing = $model->getByName($brand['brand']);
            if ($existing !== null) {
                continue;
            }

            $model->insert(['name' => $brand['brand']]);
            $created++;
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => "Importate {$created} nuove marche dalla tabella vehicle.",
            'created' => $created,
        ]);
    }
}
