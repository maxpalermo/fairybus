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
use FairyBus\Models\FbAddressModel;
use FairyBus\Models\FbStateModel;
use FairyBus\Models\FbSupplierModel;

class Suppliers extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbSupplierModel();

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

        $countries = (new \FairyBus\Models\FbCountryModel())->orderBy('name', 'ASC')->findAll();
        $states = (new FbStateModel())->orderBy('name', 'ASC')->findAll();

        return $this->jsonResponse([
            'success' => true,
            'countries' => $countries,
            'states' => $states,
        ]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $data = $this->validateSupplier();
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        $id = (int) (new FbSupplierModel())->insert($data['supplier']);
        $this->saveAddress($id, $data['address']);

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbSupplierModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fornitore non trovato.'], 404);
        }

        $data = $this->validateSupplier();
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        $model->update($id, $data['supplier']);
        $this->saveAddress($id, $data['address']);

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbSupplierModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fornitore non trovato.'], 404);
        }

        $db = \Config\Database::connect();
        $reasons = [];
        foreach ([
            'fb_document' => 'documenti',
            'fb_invoice' => 'fatture',
            'fb_refuelling' => 'rifornimenti',
        ] as $table => $label) {
            $n = (int) $db->table($table)->where('id_supplier', $id)->countAllResults();
            if ($n > 0) {
                $reasons[] = "{$n} {$label}";
            }
        }
        if ($reasons !== []) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fornitore non eliminabile: presente in ' . implode(', ', $reasons) . '.'], 422);
        }

        (new FbAddressModel())->deleteBySupplier($id);
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * @return array{supplier: array<string, mixed>, address: array<string, mixed>}|ResponseInterface
     */
    private function validateSupplier(): array|ResponseInterface
    {
        $rules = [
            'company' => 'required|max_length[255]',
            'vat_number' => 'permit_empty|max_length[64]',
            'email' => 'permit_empty|valid_email|max_length[255]',
            'pec' => 'permit_empty|valid_email|max_length[255]',
            'contact_name' => 'permit_empty|max_length[255]',
            'address1' => 'permit_empty|max_length[255]',
            'address2' => 'permit_empty|max_length[255]',
            'postcode' => 'permit_empty|max_length[16]',
            'city' => 'permit_empty|max_length[255]',
            'phone_number' => 'permit_empty|max_length[64]',
            'mobile_number' => 'permit_empty|max_length[64]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $post = fn(string $k) => $this->request->getPost($k);
        $str = fn(string $k) => ($v = $post($k)) !== null && $v !== '' ? (string) $v : null;

        return [
            'supplier' => [
                'company' => (string) $post('company'),
                'vat_number' => $str('vat_number'),
                'email' => $str('email'),
                'pec' => $str('pec'),
                'contact_name' => $str('contact_name'),
                'active' => $post('active') ? 1 : 0,
                'fuel' => $post('fuel') ? 1 : 0,
            ],
            'address' => [
                'address1' => $str('address1'),
                'address2' => $str('address2'),
                'postcode' => $str('postcode'),
                'city' => $str('city'),
                'phone_number' => $str('phone_number'),
                'mobile_number' => $str('mobile_number'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $address
     */
    private function saveAddress(int $idSupplier, array $address): void
    {
        if (implode('', array_map('strval', $address)) === '') {
            return;
        }

        $model = new FbAddressModel();
        $existing = $model->where('id_supplier', $idSupplier)->first();
        if ($existing !== null) {
            $model->update((int) $existing['id_address'], $address);
        } else {
            $model->insert($address + ['id_supplier' => $idSupplier]);
        }
    }

}
