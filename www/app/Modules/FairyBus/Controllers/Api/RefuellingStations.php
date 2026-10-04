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

class RefuellingStations extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new \FairyBus\Models\FbRefuellingStationModel())->listAll(),
        ]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $data = $this->validateStation();
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        $id = (int) (new \FairyBus\Models\FbRefuellingStationModel())->insert($data + ['date_add' => date('Y-m-d H:i:s')]);

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbRefuellingStationModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Punto di rifornimento non trovato.'], 404);
        }

        $data = $this->validateStation();
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        $model->update($id, $data + ['date_upd' => date('Y-m-d H:i:s')]);

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbRefuellingStationModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Punto di rifornimento non trovato.'], 404);
        }
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    private function validateStation(): array|ResponseInterface
    {
        $rules = [
            'name' => 'required|max_length[255]',
            'province' => 'permit_empty|max_length[4]',
            'town' => 'permit_empty|max_length[255]',
            'post_code' => 'permit_empty|max_length[16]',
            'street' => 'permit_empty|max_length[255]',
            'suburb' => 'permit_empty|max_length[255]',
            'lat' => 'permit_empty|decimal',
            'lon' => 'permit_empty|decimal',
            'epsg_code' => 'permit_empty|integer',
            'note' => 'permit_empty|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $post = fn(string $k) => $this->request->getPost($k);

        return [
            'name' => (string) $post('name'),
            'province' => $post('province') !== '' ? strtoupper((string) $post('province')) : null,
            'town' => $post('town') !== '' ? $post('town') : null,
            'post_code' => $post('post_code') !== '' ? $post('post_code') : null,
            'street' => $post('street') !== '' ? $post('street') : null,
            'suburb' => $post('suburb') !== '' ? $post('suburb') : null,
            'lat' => $post('lat') !== '' ? (float) $post('lat') : null,
            'lon' => $post('lon') !== '' ? (float) $post('lon') : null,
            'epsg_code' => $post('epsg_code') !== '' ? (int) $post('epsg_code') : null,
            'note' => $post('note') !== '' ? $post('note') : null,
        ];
    }
}
