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
use CodeIgniter\Files\File;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Libraries\LegacyDatabase;
use FairyBus\Models\FbBrandModel;
use FairyBus\Models\FbConfigurationModel;
use FairyBus\Models\FbFeatureModel;
use FairyBus\Models\FbVehicleDocumentModel;
use FairyBus\Models\FbVehicleFeatureModel;
use FairyBus\Models\FbVehicleImageModel;
use FairyBus\Models\FbVehicleModel;

class Vehicles extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rows = (new FbVehicleModel())->listWithBrand();
        if ($this->request->getGet('active') === '1') {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => ($row['status'] ?? 'active') !== 'retired'));
        }
        foreach ($rows as &$row) {
            $row['id_vehicle'] = (int) $row['id_vehicle'];
            $row['id_brand'] = $row['id_brand'] !== null ? (int) $row['id_brand'] : null;
            $row['current_km'] = (int) $row['current_km'];
        }

        return $this->jsonResponse(['success' => true, 'rows' => $rows]);
    }

    /**
     * Return form definitions: brands and feature fields.
     */
    public function definitions(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        return $this->jsonResponse([
            'success' => true,
            'brands' => (new FbBrandModel())->orderBy('name', 'ASC')->findAll(),
            'features' => $this->loadFeatureDefinitions(),
            'statuses' => [
                ['value' => 'active', 'label' => 'Attivo'],
                ['value' => 'maintenance', 'label' => 'In manutenzione'],
                ['value' => 'retired', 'label' => 'Ritirato'],
            ],
        ]);
    }

    public function detail(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $vehicle = (new FbVehicleModel())->findWithDetails($id);
        if ($vehicle === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo non trovato.'], 404);
        }

        $vehicle['id_vehicle'] = (int) $vehicle['id_vehicle'];
        $vehicle['id_brand'] = $vehicle['id_brand'] !== null ? (int) $vehicle['id_brand'] : null;
        $vehicle['current_km'] = (int) $vehicle['current_km'];

        return $this->jsonResponse([
            'success' => true,
            'vehicle' => $vehicle,
            'features' => $this->loadFeatureDefinitions(),
            'brands' => (new FbBrandModel())->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function create(): ResponseInterface
    {
        return $this->saveVehicle();
    }

    public function update(int $id): ResponseInterface
    {
        return $this->saveVehicle($id);
    }

    private function saveVehicle(?int $id = null): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'plate' => 'required|max_length[50]',
            'id_brand' => 'permit_empty|integer',
            'status' => 'required|max_length[50]',
            'current_km' => 'permit_empty|integer',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $data = [
            'plate' => $this->request->getPost('plate'),
            'id_brand' => $this->request->getPost('id_brand') ?: null,
            'status' => $this->request->getPost('status') ?: 'active',
            'chassis_number' => $this->request->getPost('chassis_number') ?: null,
            'current_km' => (int) ($this->request->getPost('current_km') ?: 0),
            'start_date' => $this->request->getPost('start_date') ?: null,
            'end_date' => $this->request->getPost('end_date') ?: null,
            'description' => $this->request->getPost('description') ?: null,
            'note' => $this->request->getPost('note') ?: null,
        ];

        $model = new FbVehicleModel();
        if ($id === null) {
            $id = (int) $model->insert($data);
        } else {
            $model->update($id, $data);
        }

        $featureValues = $this->request->getPost('features') ?: [];
        if (is_array($featureValues)) {
            (new FbVehicleFeatureModel())->setForVehicle($id, $featureValues);
        }

        return $this->jsonResponse(['success' => true, 'id_vehicle' => $id]);
    }

    public function setStatus(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $status = (string) $this->request->getPost('status');
        if (!in_array($status, ['active', 'maintenance', 'retired'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Stato non valido.'], 422);
        }

        $model = new FbVehicleModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo non trovato.'], 404);
        }
        $model->update($id, ['status' => $status]);

        return $this->jsonResponse(['success' => true, 'status' => $status]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbVehicleModel();
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    public function uploadImage(int $idVehicle): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $file = $this->request->getFile('image');
        if ($file === null || !$file->isValid()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Immagine non valida.'], 422);
        }

        $path = $this->storeUploadedFile($file, "vehicles/{$idVehicle}/images");
        (new FbVehicleImageModel())->add($idVehicle, $path);

        return $this->jsonResponse(['success' => true, 'path' => $path]);
    }

    public function deleteImage(int $idImage): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbVehicleImageModel();
        $row = $model->find($idImage);
        if ($row !== null) {
            $fullPath = FCPATH . ltrim($row['image'], '/');
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
            $model->delete($idImage);
        }

        return $this->jsonResponse(['success' => true]);
    }

    public function uploadDocument(int $idVehicle): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $file = $this->request->getFile('document');
        if ($file === null || !$file->isValid()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Documento non valido.'], 422);
        }

        $description = $this->request->getPost('description');
        $path = $this->storeUploadedFile($file, "vehicles/{$idVehicle}/documents");
        (new FbVehicleDocumentModel())->add($idVehicle, $path, is_string($description) ? $description : null);

        return $this->jsonResponse(['success' => true, 'path' => $path]);
    }

    public function deleteDocument(int $idDocument): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbVehicleDocumentModel();
        $row = $model->find($idDocument);
        if ($row !== null) {
            $fullPath = FCPATH . ltrim($row['document'], '/');
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
            $model->delete($idDocument);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Import vehicles from the legacy `vehicle` table and distribute
     * data into fb_vehicle, fb_vehicle_feature, fb_brand.
     */
    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        if (!in_array('vehicle', LegacyDatabase::listTables($db), true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "vehicle" non trovata.'], 422);
        }

        $availableColumns = LegacyDatabase::getFieldNames($db, 'vehicle');
        $desiredColumns = [
            'id',
            'organization_id',
            'brand',
            'chassis_number',
            'last_km_manually_registered',
            'plate',
            'start_date',
            'end_date',
            'vehicle_class',
            'note',
            'created_date',
            'last_modified_date',
            'air_conditioned',
            'antiparticle_filter',
            'engine_type',
            'fuel_type',
            'length_meters',
            'platform',
            'seats',
            'stand_place',
            'transport_on_demand',
            'registration_date',
            'euro',
            'kw',
            'engine_displacement',
        ];
        $selectColumns = array_values(array_intersect($availableColumns, $desiredColumns));
        if ($selectColumns === []) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "vehicle" non contiene colonne riconosciute.'], 422);
        }
        if (!in_array('id', $selectColumns, true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'La tabella legacy "vehicle" non contiene la colonna id.'], 422);
        }

        $db->table('fb_vehicle_feature')->truncate();
        $db->table('fb_vehicle_document')->truncate();
        $db->table('fb_vehicle_image')->truncate();
        $db->table('fb_vehicle')->truncate();

        $rows = LegacyDatabase::withoutPrefix(
            $db,
            static fn(BaseConnection $db): array => $db->table('vehicle')
                ->select(implode(',', $selectColumns))
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray()
        );

        $vehicleModel = new FbVehicleModel();
        $brandModel = new FbBrandModel();
        $featureModel = new FbFeatureModel();
        $vehicleFeatureModel = new FbVehicleFeatureModel();
        $features = $featureModel->indexedByName();

        $featureColumnMap = [
            'air_conditioned' => 'air_conditioned',
            'antiparticle_filter' => 'antiparticle_filter',
            'engine_type' => 'engine_type',
            'fuel_type' => 'fuel_type',
            'lenght_meters' => 'length_meters',
            'platform' => 'platform',
            'seats' => 'seats',
            'stand_place' => 'stand_place',
            'transportation_on_demand' => 'transport_on_demand',
            'registration_year' => 'registration_date',
            'euro' => 'euro',
            'kw' => 'kw',
            'engine_displacement' => 'engine_displacement',
        ];

        $imported = 0;
        foreach ($rows as $row) {
            if (empty($row['plate'])) {
                continue;
            }

            $existing = $vehicleModel->where('plate', (string) $row['plate'])->first();
            if ($existing !== null) {
                continue;
            }

            $idBrand = null;
            if (!empty($row['brand'])) {
                $idBrand = $brandModel->getOrCreate((string) $row['brand']);
            }

            $vehicleData = [
                'legacy_id' => (int) $row['id'],
                'id_organization' => !empty($row['organization_id']) ? (int) $row['organization_id'] : null,
                'id_brand' => $idBrand,
                'status' => 'active',
                'chassis_number' => !empty($row['chassis_number']) ? (string) $row['chassis_number'] : null,
                'current_km' => (int) ($row['last_km_manually_registered'] ?? 0),
                'plate' => !empty($row['plate']) ? (string) $row['plate'] : null,
                'start_date' => $this->parseDate($row['start_date'] ?? null),
                'end_date' => $this->parseDate($row['end_date'] ?? null),
                'description' => !empty($row['vehicle_class']) ? (string) $row['vehicle_class'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
            ];

            $idVehicle = (int) $vehicleModel->insert($vehicleData);

            $updateDates = [];
            $createdAt = $this->parseDateTime($row['created_date'] ?? null);
            $updatedAt = $this->parseDateTime($row['last_modified_date'] ?? null);
            if ($createdAt !== null) {
                $updateDates['created_at'] = $createdAt;
            }
            if ($updatedAt !== null) {
                $updateDates['updated_at'] = $updatedAt;
            }
            if ($updateDates !== []) {
                $vehicleModel->update($idVehicle, $updateDates);
            }

            foreach ($featureColumnMap as $featureName => $legacyColumn) {
                if (!isset($features[$featureName]) || !in_array($legacyColumn, $availableColumns, true)) {
                    continue;
                }

                $value = $row[$legacyColumn] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }

                $feature = $features[$featureName];

                if ($featureName === 'registration_year') {
                    $timestamp = strtotime((string) $value);
                    if ($timestamp !== false) {
                        $value = date('Y', $timestamp);
                    }
                }

                if ($feature['type'] === 'switch') {
                    $value = in_array(strtolower((string) $value), ['1', 'true', 'yes', 'si', 'sì', 'on'], true) ? '1' : '0';
                }

                $vehicleFeatureModel->insert([
                    'id_vehicle' => $idVehicle,
                    'id_feature' => (int) $feature['id_feature'],
                    'value' => (string) $value,
                ]);
            }

            $imported++;
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => "Importati {$imported} veicoli dalla tabella vehicle.",
            'imported' => $imported,
        ]);
    }

    private function parseDate(?string $value): ?string
    {
        if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function parseDateTime(?string $value): ?string
    {
        if (empty($value) || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function storeUploadedFile(File $file, string $relativeDir): string
    {
        $uploadPath = FCPATH . "uploads/{$relativeDir}/";
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        $newName = $file->getRandomName();
        $file->move($uploadPath, $newName);

        return "/uploads/{$relativeDir}/{$newName}";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFeatureDefinitions(): array
    {
        $features = (new FbFeatureModel())->listAll();
        $configModel = new FbConfigurationModel();

        foreach ($features as &$feature) {
            if ($feature['type'] === 'select') {
                $config = $configModel->get("select_{$feature['name']}");
                $feature['options'] = $config !== null
                    ? (json_decode($config['value'], true) ?? [])
                    : [];
            } else {
                $feature['options'] = [];
            }
        }

        return $features;
    }
}
