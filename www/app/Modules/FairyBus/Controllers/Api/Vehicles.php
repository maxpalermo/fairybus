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
use CodeIgniter\Files\File;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Models\FbBrandModel;
use FairyBus\Models\FbConfigurationModel;
use FairyBus\Models\FbFeatureModel;
use FairyBus\Models\FbVehicleDocumentModel;
use FairyBus\Models\FbVehicleFeatureModel;
use FairyBus\Models\FbVehicleImageModel;
use FairyBus\Models\FbVehicleKmModel;
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
                ['value' => 'retired', 'label' => 'Dismesso'],
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
        if (!in_array($status, ['active', 'retired'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Stato non valido.'], 422);
        }

        $model = new FbVehicleModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo non trovato.'], 404);
        }
        $model->update($id, ['status' => $status]);

        return $this->jsonResponse(['success' => true, 'status' => $status]);
    }

    /**
     * Aggiorna il chilometraggio del veicolo (edit inline dallo scadenzario).
     * Il nuovo valore non puo' essere inferiore ai km attuali; le variazioni
     * sono registrate in fb_vehicle_km con reason_class 'Manual'.
     */
    public function updateKm(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbVehicleModel();
        $vehicle = $model->find($id);
        if ($vehicle === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo non trovato.'], 404);
        }

        $km = $this->request->getPost('km');
        if ($km === null || $km === '' || filter_var($km, FILTER_VALIDATE_INT) === false || (int) $km < 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Chilometraggio non valido.'], 422);
        }
        $km = (int) $km;

        $current = (int) ($vehicle['current_km'] ?? 0);
        if ($km < $current) {
            return $this->jsonResponse([
                'success' => false,
                'error' => sprintf(
                    'Chilometraggio non valido: %s km è inferiore al valore attuale del veicolo (%s km).',
                    number_format($km, 0, ',', '.'),
                    number_format($current, 0, ',', '.')
                ),
            ], 422);
        }

        if ($km > $current) {
            (new FbVehicleKmModel())->register($id, $km, null, null, 'Manual');
        }

        return $this->jsonResponse(['success' => true, 'km' => $km]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbVehicleModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo non trovato.'], 404);
        }

        // Blocca se il veicolo e' referenziato da documenti o movimenti;
        // in cascata vanno invece solo i dati propri (scadenze, km,
        // caratteristiche, immagini e documenti con relativi file).
        $db = \Config\Database::connect();
        $reasons = [];
        foreach ([
            'fb_document' => 'documenti',
            'fb_invoice' => 'fatture',
            'fb_maintenance' => 'manutenzioni',
            'fb_refuelling' => 'rifornimenti',
        ] as $table => $label) {
            $n = (int) $db->table($table)->where('id_vehicle', $id)->countAllResults();
            if ($n > 0) {
                $reasons[] = "{$n} {$label}";
            }
        }
        if ($reasons !== []) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo non eliminabile: presente in ' . implode(', ', $reasons) . '.'], 422);
        }

        $expIds = array_column(
            $db->table('fb_expiration')->select('id_expiration')->where('id_vehicle', $id)->get()->getResultArray(),
            'id_expiration'
        );
        $files = array_merge(
            $db->table('fb_vehicle_image')->select('image AS f')->where('id_vehicle', $id)->get()->getResultArray(),
            $db->table('fb_vehicle_document')->select('document AS f')->where('id_vehicle', $id)->get()->getResultArray()
        );

        $db->transStart();
        if ($expIds !== []) {
            $db->table('fb_expiration_occurrence')->whereIn('id_expiration', $expIds)->delete();
            $db->table('fb_expiration')->where('id_vehicle', $id)->delete();
        }
        $db->table('fb_vehicle_feature')->where('id_vehicle', $id)->delete();
        $db->table('fb_vehicle_km')->where('id_vehicle', $id)->delete();
        $db->table('fb_vehicle_image')->where('id_vehicle', $id)->delete();
        $db->table('fb_vehicle_document')->where('id_vehicle', $id)->delete();
        $model->delete($id);
        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->jsonResponse(['success' => false, 'error' => "Errore durante l'eliminazione."], 500);
        }

        foreach ($files as $row) {
            $fullPath = FCPATH . ltrim((string) $row['f'], '/');
            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }

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
