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

class Maintenance extends AdminController
{
    private const BATCH_SIZE = 500;

    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idVehicle = (int) $this->request->getGet('vehicle_id');

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new \FairyBus\Models\FbMaintenanceModel())->listAll([], $idVehicle > 0 ? $idVehicle : null),
        ]);
    }

    /**
     * Righe dettaglio (ricambi + manodopera) di tutte le manutenzioni di un
     * veicolo, appiattite con data e id scheda — usate dalla tabella
     * Riparazioni del dettaglio veicolo.
     * GET api/maintenance/lines?vehicle_id=N
     */
    public function lines(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idVehicle = (int) $this->request->getGet('vehicle_id');
        if ($idVehicle <= 0) {
            return $this->jsonResponse(['success' => true, 'rows' => []]);
        }

        $db = \Config\Database::connect();
        $rows = $db->table('fb_maintenance_detail d')
            ->select('d.*, m.date AS maintenance_date, p.sku, p.name AS product_name,
                t.hours AS labor_hours, t.price_per_hour AS labor_price_per_hour, t.manpower AS labor_manpower')
            ->join('fb_maintenance m', 'm.id_maintenance = d.id_maintenance')
            ->join('fb_product p', 'p.id_product = d.id_product', 'left')
            ->join('fb_maintenance_task t', 't.id_maintenance_detail = d.id_maintenance_detail', 'left')
            ->where('m.id_vehicle', $idVehicle)
            ->orderBy('m.date', 'DESC')
            ->orderBy('m.id_maintenance', 'DESC')
            ->orderBy('d.id_maintenance_detail', 'ASC')
            ->get()->getResultArray();

        return $this->jsonResponse(['success' => true, 'rows' => $rows]);
    }

    public function get(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $maintenance = (new \FairyBus\Models\FbMaintenanceModel())->findById($id);
        if ($maintenance === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Manutenzione non trovata.'], 404);
        }

        $maintenance['tasks'] = (new \FairyBus\Models\FbMaintenanceTaskModel())->listByMaintenance($id);
        $maintenance['details'] = (new \FairyBus\Models\FbMaintenanceDetailModel())->listByMaintenance($id);
        $maintenance['invoices'] = (new \FairyBus\Models\FbMaintenanceInvoiceModel())->listByMaintenance($id);

        return $this->jsonResponse(['success' => true, 'maintenance' => $maintenance]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $validation = $this->validateMaintenance();
        if ($validation instanceof ResponseInterface) {
            return $validation;
        }

        $model = new \FairyBus\Models\FbMaintenanceModel();
        $id = (int) $model->insert($validation['maintenance'] + ['date_add' => date('Y-m-d H:i:s')]);

        $this->syncTask($id, $validation['task']);
        $this->registerKm($id, (int) ($validation['maintenance']['id_vehicle'] ?? 0), (int) ($validation['maintenance']['km'] ?? 0), $validation['maintenance']['date'] ?? null);

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbMaintenanceModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Manutenzione non trovata.'], 404);
        }

        $validation = $this->validateMaintenance();
        if ($validation instanceof ResponseInterface) {
            return $validation;
        }

        $model->update($id, $validation['maintenance'] + ['date_upd' => date('Y-m-d H:i:s')]);
        $this->syncTask($id, $validation['task']);
        $this->registerKm($id, (int) ($validation['maintenance']['id_vehicle'] ?? 0), (int) ($validation['maintenance']['km'] ?? 0), $validation['maintenance']['date'] ?? null);

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbMaintenanceModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Manutenzione non trovata.'], 404);
        }

        $db = \Config\Database::connect();
        $stock = new \FairyBus\Models\FbStockModel();

        // I ricambi usati in manutenzione rientrano in giacenza solo se la riga
        // e' stata aggiunta manualmente (le righe legacy erano gia' conteggiate).
        foreach ((new \FairyBus\Models\FbMaintenanceDetailModel())->listByMaintenance($id) as $detail) {
            if (empty($detail['legacy_id'])) {
                $stock->loadStock((int) ($detail['id_product'] ?? 0), (float) $detail['quantity']);
            }
        }

        $db->table('fb_maintenance_detail')->where('id_maintenance', $id)->delete();
        $db->table('fb_maintenance_task')->where('id_maintenance', $id)->delete();
        $db->table('fb_maintenance_invoice')->where('id_maintenance', $id)->delete();
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /* ---------- Dettagli (ricambi usati: stock -) ---------- */

    /**
     * Schede manutenzione di un veicolo fatturabili tramite documento di
     * scarico: non associate ad alcuna fattura cliente (fb_maintenance_invoice)
     * e non gia' collegate ad un altro scarico (id_document). Con document_id
     * vengono restituite anche le schede gia' collegate a quel documento
     * (modifica dello scarico).
     */
    public function available(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idVehicle = (int) $this->request->getGet('vehicle_id');
        $idDocument = (int) $this->request->getGet('document_id');
        $idInvoice = (int) $this->request->getGet('invoice_id');
        $withLines = (string) $this->request->getGet('with_lines') === '1';
        if ($idVehicle <= 0 && $idDocument <= 0 && $idInvoice <= 0) {
            return $this->jsonResponse(['success' => true, 'rows' => []]);
        }

        $db = \Config\Database::connect();
        $b = $db->table('fb_maintenance m')
            ->select('m.id_maintenance, m.id_vehicle, m.date, m.km, m.note, m.id_document, v.plate')
            ->select('(SELECT COUNT(*) FROM fb_maintenance_detail d WHERE d.id_maintenance = m.id_maintenance) AS parts_count', false)
            ->select('(SELECT COUNT(*) FROM fb_maintenance_task t WHERE t.id_maintenance = m.id_maintenance) AS tasks_count', false)
            ->select('(SELECT COALESCE(SUM(ABS(d.quantity) * d.price * (1 + COALESCE(d.discount, 0) / 100)), 0) FROM fb_maintenance_detail d WHERE d.id_maintenance = m.id_maintenance) AS parts_amount', false)
            ->select('(SELECT COALESCE(SUM(t.manpower), 0) FROM fb_maintenance_task t WHERE t.id_maintenance = m.id_maintenance) AS labor_amount', false)
            ->join('fb_vehicle v', 'v.id_vehicle = m.id_vehicle', 'left');

        if ($idInvoice > 0) {
            // fattura cliente: sono escluse solo le schede registrate su
            // ALTRE fatture; quelle gia' su questa tornano marcate linked
            $b->groupStart()
                ->where('m.id_invoice IS NULL', null, false)
                ->orWhere('m.id_invoice', $idInvoice)
                ->groupEnd();
        } else {
            // scarico: solo schede mai fatturate
            $b->where('m.id_invoice IS NULL', null, false)
                ->where("NOT EXISTS (SELECT 1 FROM fb_maintenance_invoice mi WHERE mi.id_maintenance = m.id_maintenance)", null, false);
        }

        if ($idVehicle > 0) {
            if ($idInvoice > 0) {
                // fattura cliente: veicolo selezionato + schede gia' sulla
                // fattura (anche se di altro veicolo, per non perderle)
                $b->groupStart()
                    ->where('m.id_vehicle', $idVehicle)
                    ->orWhere('m.id_invoice', $idInvoice)
                    ->groupEnd();
            } else {
                // schede libere + quelle gia' collegate al documento in modifica
                $b->where('m.id_vehicle', $idVehicle)
                    ->groupStart()
                    ->where('m.id_document IS NULL', null, false)
                    ->orWhere('m.id_document', $idDocument > 0 ? $idDocument : 0)
                    ->groupEnd();
            }
        } elseif ($idDocument > 0) {
            // solo document_id: in modifica serve per ritrovare le schede
            // gia' collegate e da li' la targa del veicolo
            $b->where('m.id_document', $idDocument);
        } else {
            // solo invoice_id: schede gia' collegate a questa fattura
            $b->where('m.id_invoice', $idInvoice);
        }

        $rows = $b->orderBy('m.date', 'DESC')
            ->orderBy('m.id_maintenance', 'DESC')
            ->get()
            ->getResultArray();

        $linkedIds = [];
        if ($idInvoice > 0) {
            $linkedIds = array_map('intval', array_column(
                $db->table('fb_maintenance')->select('id_maintenance')->where('id_invoice', $idInvoice)->get()->getResultArray(),
                'id_maintenance'
            ));
        }

        $lines = $withLines ? \FairyBus\Models\FbMaintenanceInvoiceModel::detailLines(array_column($rows, 'id_maintenance')) : [];

        foreach ($rows as &$row) {
            $row['linked'] = $idInvoice > 0
                ? in_array((int) $row['id_maintenance'], $linkedIds, true)
                : ($idDocument > 0 && (int) ($row['id_document'] ?? 0) === $idDocument);
            $row['amount'] = (float) ($row['parts_amount'] ?? 0) + (float) ($row['labor_amount'] ?? 0);
            if ($withLines) {
                $row['lines'] = $lines[(int) $row['id_maintenance']] ?? [];
            }
        }

        return $this->jsonResponse(['success' => true, 'rows' => $rows]);
    }

    public function details(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $labor = \Config\Database::connect()->table('fb_maintenance_task')
            ->selectSum('hours')->selectSum('manpower')
            ->where('id_maintenance', $id)->get()->getRowArray() ?: [];

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new \FairyBus\Models\FbMaintenanceDetailModel())->listByMaintenance($id),
            'labor' => [
                'hours' => (float) ($labor['hours'] ?? 0),
                'manpower' => (float) ($labor['manpower'] ?? 0),
            ],
        ]);
    }

    public function addDetail(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if ((new \FairyBus\Models\FbMaintenanceModel())->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Manutenzione non trovata.'], 404);
        }

        $rules = [
            'id_product' => 'required|integer|greater_than[0]',
            'quantity' => 'required|decimal',
            'price' => 'permit_empty|decimal',
            'discount' => 'permit_empty|decimal',
            'vat_rate' => 'permit_empty|decimal',
            'lot' => 'permit_empty|max_length[255]',
            'note' => 'permit_empty|max_length[255]',
            'hours' => 'permit_empty|decimal',
            'price_per_hour' => 'permit_empty|decimal',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new \FairyBus\Models\FbMaintenanceDetailModel();
        $newId = (int) $model->insert([
            'id_maintenance' => $id,
            'id_product' => (int) $this->request->getPost('id_product'),
            'quantity' => (float) $this->request->getPost('quantity'),
            'price' => (float) ($this->request->getPost('price') ?: 0),
            'discount' => (float) ($this->request->getPost('discount') ?: 0),
            'vat_rate' => $this->request->getPost('vat_rate') !== '' ? (float) $this->request->getPost('vat_rate') : null,
            'lot' => $this->request->getPost('lot') !== '' ? $this->request->getPost('lot') : null,
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        $row = $model->db->table('fb_maintenance_detail dd')
            ->select('dd.*, p.sku, p.name AS product_name')
            ->join('fb_product p', 'p.id_product = dd.id_product', 'left')
            ->where('dd.id_maintenance_detail', $newId)
            ->get()->getRowArray();

        // Ricambio usato in officina: la giacenza scende
        (new \FairyBus\Models\FbStockModel())->unloadStock((int) $row['id_product'], (float) $row['quantity']);

        // Manodopera associata al ricambio (task con id_maintenance_detail = riga)
        $hours = (int) (float) ($this->request->getPost('hours') ?: 0);
        $pricePerHour = (float) ($this->request->getPost('price_per_hour') ?: 0);
        if ($hours > 0 || $pricePerHour > 0) {
            (new \FairyBus\Models\FbMaintenanceTaskModel())->insert([
                'id_maintenance' => $id,
                'id_maintenance_detail' => $newId,
                'hours' => $hours,
                'price_per_hour' => $pricePerHour,
                'manpower' => $hours * $pricePerHour,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }

        // Il costo orario inserito diventa il default globale se non
        // configurato o diverso da fb_configuration.hourly_cost.
        if ($pricePerHour > 0) {
            $config = new \FairyBus\Models\FbConfigurationModel();
            if ((float) ($config->get('hourly_cost')['value'] ?? 0) !== $pricePerHour) {
                $config->setValue('hourly_cost', (string) $pricePerHour);
            }
        }

        // Se il prodotto non ha un'aliquota IVA e nel movimento ne è stata
        // inserita una, la riporta sull'anagrafica del prodotto.
        $vatInput = $this->request->getPost('vat_rate');
        if ($vatInput !== null && $vatInput !== '') {
            $productModel = new \FairyBus\Models\FbProductModel();
            $product = $productModel->find((int) $row['id_product']);
            if ($product !== null && (float) ($product['tax_rate'] ?? 0) <= 0) {
                $productModel->update((int) $row['id_product'], ['tax_rate' => (float) $vatInput]);
            }
        }

        return $this->jsonResponse(['success' => true, 'detail' => $row]);
    }

    public function deleteDetail(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbMaintenanceDetailModel();
        $detail = $model->find($id);
        if ($detail === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Riga non trovata.'], 404);
        }
        $model->delete($id);

        // Rimuove anche la manodopera associata a questo ricambio
        \Config\Database::connect()->table('fb_maintenance_task')
            ->where('id_maintenance_detail', $id)->delete();

        if (empty($detail['legacy_id'])) {
            (new \FairyBus\Models\FbStockModel())->loadStock((int) ($detail['id_product'] ?? 0), (float) $detail['quantity']);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /* ---------- Fatture collegate ---------- */

    public function linkInvoice(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if ((new \FairyBus\Models\FbMaintenanceModel())->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Manutenzione non trovata.'], 404);
        }

        $idInvoice = (int) $this->request->getPost('id_invoice');
        if ((new \FairyBus\Models\FbInvoiceModel())->find($idInvoice) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fattura non trovata.'], 404);
        }

        $model = new \FairyBus\Models\FbMaintenanceInvoiceModel();
        $exists = $model->where('id_maintenance', $id)->where('id_invoice', $idInvoice)->first();
        if ($exists === null) {
            $model->insert(['id_maintenance' => $id, 'id_invoice' => $idInvoice, 'date_add' => date('Y-m-d H:i:s')]);
        }

        return $this->jsonResponse(['success' => true]);
    }

    public function unlinkInvoice(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idInvoice = (int) $this->request->getPost('id_invoice');
        \Config\Database::connect()->table('fb_maintenance_invoice')
            ->where('id_maintenance', $id)->where('id_invoice', $idInvoice)->delete();

        return $this->jsonResponse(['success' => true]);
    }

    /* ---------- Helpers ---------- */

    /**
     * Upsert del task generale della manutenzione (id_maintenance_detail = 0):
     * il form gestisce solo la descrizione del lavoro, mentre ore/costi
     * sono associati ai singoli ricambi.
     *
     * @param array<string, mixed> $task
     */
    private function syncTask(int $idMaintenance, array $task): void
    {
        $model = new \FairyBus\Models\FbMaintenanceTaskModel();
        $existing = $model->where('id_maintenance', $idMaintenance)
            ->where('id_maintenance_detail', 0)
            ->orderBy('id_maintenance_task', 'ASC')->first();

        if ($existing !== null) {
            $model->update((int) $existing['id_maintenance_task'], $task + ['date_upd' => date('Y-m-d H:i:s')]);
        } elseif ($task['description'] !== null) {
            $model->insert($task + ['id_maintenance' => $idMaintenance, 'id_maintenance_detail' => 0, 'date_add' => date('Y-m-d H:i:s')]);
        }
    }

    private function registerKm(int $idMaintenance, int $idVehicle, int $km, ?string $date): void
    {
        if ($idVehicle <= 0 || $km <= 0) {
            return;
        }
        (new \FairyBus\Models\FbVehicleKmModel())->register($idVehicle, $km, $date ? $date . ' 00:00:00' : null, $idMaintenance, 'Maintenance');
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    private function validateMaintenance(): array|ResponseInterface
    {
        $rules = [
            'id_vehicle' => 'required|integer|greater_than[0]',
            'date' => 'permit_empty|valid_date',
            'km' => 'permit_empty|integer',
            'note' => 'permit_empty|max_length[255]',
            'task_description' => 'permit_empty|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        return [
            'maintenance' => [
                'id_vehicle' => (int) $this->request->getPost('id_vehicle'),
                'date' => $this->request->getPost('date') !== '' ? $this->request->getPost('date') : null,
                'km' => (int) $this->request->getPost('km') ?: null,
                'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
            ],
            'task' => [
                'description' => $this->request->getPost('task_description') !== '' ? $this->request->getPost('task_description') : null,
            ],
        ];
    }

    /* ---------- Import legacy ---------- */

    /**
     * Importa manutenzioni e scadenze:
     *  - expiration_tag            -> fb_expiration_tag (extra "20000" = km, "6months" = data)
     *  - expiration                -> fb_expiration (company_asset_id -> veicolo)
     *  - expiration_occurrence     -> fb_expiration_occurrence
     *  - maintenance               -> fb_maintenance
     *  - maintenance_task          -> fb_maintenance_task
     *  - trade (maintenance) -> movement -> fb_maintenance_detail
     *  - maintenance.invoice_id    -> fb_maintenance_invoice
     *  - car_service / car_service_task -> fb_car_service / fb_car_service_task
     *  - vehiclekmregistration     -> fb_vehicle_km
     */
    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $legacyTables = \FairyBus\Libraries\LegacyDatabase::listTables($db);

        foreach (['maintenance', 'maintenance_task', 'movement', 'trade', 'expiration', 'expiration_occurrence', 'expiration_tag', 'car_service', 'car_service_task', 'vehiclekmregistration'] as $required) {
            if (!in_array($required, $legacyTables, true)) {
                return $this->jsonResponse(['success' => false, 'error' => "Tabella legacy \"{$required}\" non trovata."], 422);
            }
        }

        $vehicleMap = $this->legacyMap($db, 'fb_vehicle', 'id_vehicle');
        $productMap = $this->legacyMap($db, 'fb_product', 'id_product');
        $invoiceMap = $this->legacyMap($db, 'fb_invoice', 'id_invoice');

        $report = [
            'tags' => 0,
            'expirations' => 0,
            'occurrences' => 0,
            'maintenance' => 0,
            'tasks' => 0,
            'details' => 0,
            'invoices' => 0,
            'services' => 0,
            'service_tasks' => 0,
            'km' => 0,
            'skipped' => 0,
        ];

        // --- Etichette scadenze ----------------------------------------------------

        $db->table('fb_expiration_occurrence')->truncate();
        $db->table('fb_expiration')->truncate();
        $db->table('fb_expiration_tag')->truncate();

        $tags = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('expiration_tag')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_expiration_tag', $tags, static function (array $row): ?array {
            $extra = trim((string) ($row['extra'] ?? ''));
            $kind = 'date';
            $value = null;
            $unit = null;
            if (preg_match('/^(\d+)(days|months|years)?$/', $extra, $m)) {
                $value = (int) $m[1];
                $unit = $m[2] ?? 'km';
                if ($unit === 'km') {
                    $kind = 'km';
                }
            }

            return [
                'legacy_id' => (int) $row['id'],
                'name' => (string) $row['tag_name'],
                'applies_to' => $row['expiration_class'] === 'VehicleExpiration' ? 'Scadenza per veicolo' : $row['expiration_class'],
                'kind' => $kind,
                'interval_value' => $value,
                'interval_unit' => $unit,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['tags']);

        $tagMap = $this->legacyMap($db, 'fb_expiration_tag', 'id_expiration_tag');

        // --- Scadenze --------------------------------------------------------------

        $expirations = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('expiration')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $tagKinds = [];
        foreach ($db->table('fb_expiration_tag')->select('id_expiration_tag, kind')->get()->getResultArray() as $t) {
            $tagKinds[(int) $t['id_expiration_tag']] = $t['kind'];
        }

        $this->insertBatches($db, 'fb_expiration', $expirations, static function (array $row) use ($vehicleMap, $tagMap, $tagKinds): ?array {
            $idTag = $tagMap[(int) ($row['tag_id'] ?? 0)] ?? null;
            $ptype = $row['periodicity_type'] !== null ? (int) $row['periodicity_type'] : null;
            $expiresAtKm = $row['expires_atkm'] !== null ? (int) $row['expires_atkm'] : null;
            $expiresAtKm = $expiresAtKm > 0 ? $expiresAtKm : null;
            // Tipo scadenza: km se c'e' una soglia km o l'etichetta e' chilometrica
            $kind = ($expiresAtKm !== null || ($idTag !== null && ($tagKinds[$idTag] ?? 'date') === 'km')) ? 'km' : 'date';
            $periodicity = 'once';
            if ($kind === 'km') {
                $periodicity = 'km';
            } elseif ((int) ($row['everyxdays'] ?? 0) > 0) {
                $periodicity = 'days';
            } elseif ($ptype !== null && $ptype !== 0) {
                $periodicity = 'yearly';
            }

            $date = !empty($row['expiration_date']) ? $row['expiration_date'] : null;
            if ($date === '1970-01-01') {
                $date = null;
            }
            $until = !empty($row['until_date']) ? $row['until_date'] : null;
            if ($until === '1970-01-01') {
                $until = null;
            }

            return [
                'legacy_id' => (int) $row['id'],
                'id_vehicle' => $vehicleMap[(int) ($row['company_asset_id'] ?? 0)] ?? null,
                'id_expiration_tag' => $idTag,
                'description' => !empty($row['description']) ? (string) $row['description'] : null,
                'expiration_date' => $date,
                'until_date' => $until,
                'expires_atkm' => $expiresAtKm,
                'periodicity' => $periodicity,
                'periodicity_type' => $ptype,
                'everyxdays' => (int) ($row['everyxdays'] ?? 0) ?: null,
                'yearly_month' => (int) ($row['yearly_periodicity_month'] ?? 0) ?: null,
                'yearly_day' => (int) ($row['yearly_periodicity_day'] ?? 0) ?: null,
                'kind' => $kind,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['expirations']);

        $expirationMap = $this->legacyMap($db, 'fb_expiration', 'id_expiration');

        // --- Occorrenze --------------------------------------------------------------

        $occurrences = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('expiration_occurrence')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_expiration_occurrence', $occurrences, static function (array $row) use ($expirationMap, &$report): ?array {
            $idExp = $expirationMap[(int) ($row['expiration_id'] ?? 0)] ?? null;
            if ($idExp === null) {
                return null;
            }

            return [
                'legacy_id' => (int) $row['id'],
                'id_expiration' => $idExp,
                'expiration_date' => !empty($row['expiration_date']) ? $row['expiration_date'] : null,
                'km' => $row['parameter'] !== null ? (int) $row['parameter'] : null,
                'state' => $row['state'] !== null ? (int) $row['state'] : 0,
            ];
        }, $report['occurrences'], $report['skipped']);

        // --- Manutenzioni ------------------------------------------------------------

        $db->table('fb_maintenance_detail')->truncate();
        $db->table('fb_maintenance_invoice')->truncate();
        $db->table('fb_maintenance_task')->truncate();
        $db->table('fb_maintenance')->truncate();

        $maintenances = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('maintenance')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_maintenance', $maintenances, static function (array $row) use ($vehicleMap): ?array {
            return [
                'legacy_id' => (int) $row['id'],
                'id_vehicle' => $vehicleMap[(int) ($row['vehicle_id'] ?? 0)] ?? null,
                'date' => !empty($row['date']) ? $row['date'] : null,
                'km' => $row['km'] !== null ? (int) $row['km'] : null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['maintenance']);

        $maintenanceMap = $this->legacyMap($db, 'fb_maintenance', 'id_maintenance');

        // Collegamento fatture (maintenance.invoice_id -> fb_invoice)
        $this->insertBatches($db, 'fb_maintenance_invoice', $maintenances, static function (array $row) use ($maintenanceMap, $invoiceMap): ?array {
            $idMaintenance = $maintenanceMap[(int) $row['id']] ?? null;
            $idInvoice = $invoiceMap[(int) ($row['invoice_id'] ?? 0)] ?? null;
            if ($idMaintenance === null || $idInvoice === null) {
                return null;
            }

            return [
                'id_maintenance' => $idMaintenance,
                'id_invoice' => $idInvoice,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
            ];
        }, $report['invoices']);

        // --- Task di manutenzione ------------------------------------------------------

        $maintenanceTasks = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('maintenance_task')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_maintenance_task', $maintenanceTasks, static function (array $row) use ($maintenanceMap): ?array {
            $idMaintenance = $maintenanceMap[(int) ($row['maintenance_id'] ?? 0)] ?? null;
            if ($idMaintenance === null) {
                return null;
            }

            return [
                'legacy_id' => (int) $row['id'],
                'id_maintenance' => $idMaintenance,
                'description' => !empty($row['description']) ? (string) $row['description'] : null,
                'hours' => (int) ($row['hours'] ?? 0),
                'manpower' => (float) ($row['manpower'] ?? 0),
                'price_per_hour' => (float) ($row['price_per_hour'] ?? 0),
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['tasks'], $report['skipped']);

        // --- Dettagli (ricambi): movement dei trade reference_class='maintenance' -------

        // trade.id => fb_maintenance.id  (via trade.reference_id = maintenance.id)
        $tradeToMaintenance = [];
        foreach (\FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('trade')->select('id, reference_id')->where('reference_class', 'maintenance')->get()->getResultArray();
        }) as $t) {
            $idMaintenance = $maintenanceMap[(int) ($t['reference_id'] ?? 0)] ?? null;
            if ($idMaintenance !== null) {
                $tradeToMaintenance[(int) $t['id']] = $idMaintenance;
            }
        }

        // product_list.id => product_id
        $productListMap = [];
        if (in_array('product_list', $legacyTables, true)) {
            foreach (\FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
                return $db->table('product_list')->select('id, product_id')->get()->getResultArray();
            }) as $r) {
                $productListMap[(int) $r['id']] = (int) $r['product_id'];
            }
        }

        $vatMap = [];
        if (in_array('vatrate', $legacyTables, true)) {
            foreach (\FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
                return $db->table('vatrate')->select('id, code, standard_rate')->get()->getResultArray();
            }) as $r) {
                $vatMap[(int) $r['id']] = ['code' => (string) $r['code'], 'rate' => (float) $r['standard_rate']];
            }
        }

        $movements = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('movement')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_maintenance_detail', $movements, static function (array $row) use ($tradeToMaintenance, $productListMap, $productMap, $vatMap): ?array {
            $idMaintenance = $tradeToMaintenance[(int) ($row['trade_id'] ?? 0)] ?? null;
            if ($idMaintenance === null) {
                return null; // movimento non legato a una manutenzione
            }

            $legacyProductId = $productListMap[(int) ($row['product_list_id'] ?? 0)] ?? null;
            $vat = $vatMap[(int) ($row['vat_id'] ?? 0)] ?? null;

            return [
                'legacy_id' => (int) $row['id'],
                'id_maintenance' => $idMaintenance,
                'id_product' => $legacyProductId !== null ? ($productMap[$legacyProductId] ?? null) : null,
                'quantity' => (float) ($row['quantity'] ?? 0),
                'price' => (float) ($row['price'] ?? 0),
                'discount' => (float) ($row['discount_or_inflation'] ?? 0),
                'lot' => !empty($row['lot']) ? (string) $row['lot'] : null,
                'vat_code' => $vat['code'] ?? null,
                'vat_rate' => $vat['rate'] ?? null,
                'warehouse_id' => (int) ($row['warehouse_id'] ?? 0) ?: null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['details'], $report['skipped']);

        // --- Tagliandi -----------------------------------------------------------------

        $db->table('fb_car_service_task')->truncate();
        $db->table('fb_car_service')->truncate();

        $services = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('car_service')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_car_service', $services, static function (array $row) use ($maintenanceMap): ?array {
            return [
                'legacy_id' => (int) $row['id'],
                'id_maintenance' => $maintenanceMap[(int) ($row['maintenance_id'] ?? 0)] ?? null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['services']);

        $serviceMap = $this->legacyMap($db, 'fb_car_service', 'id_car_service');
        $occurrenceMap = $this->legacyMap($db, 'fb_expiration_occurrence', 'id_expiration_occurrence');

        $serviceTasks = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('car_service_task')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_car_service_task', $serviceTasks, static function (array $row) use ($serviceMap, $expirationMap, $tagMap, $occurrenceMap): ?array {
            $idService = $serviceMap[(int) ($row['car_service_id'] ?? 0)] ?? null;
            if ($idService === null) {
                return null;
            }

            return [
                'legacy_id' => (int) $row['id'],
                'id_car_service' => $idService,
                'id_expiration' => $expirationMap[(int) ($row['expiration_id'] ?? 0)] ?? null,
                'id_expiration_tag' => $tagMap[(int) ($row['expiration_tag_id'] ?? 0)] ?? null,
                'id_expiration_occurrence' => $occurrenceMap[(int) ($row['expiration_occurrence_id'] ?? 0)] ?? null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['service_tasks'], $report['skipped']);

        // --- Registro km veicoli ---------------------------------------------------------

        $db->table('fb_vehicle_km')->truncate();

        $kmRows = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('vehiclekmregistration')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_vehicle_km', $kmRows, static function (array $row) use ($vehicleMap, $maintenanceMap): ?array {
            $idVehicle = $vehicleMap[(int) ($row['vehicle_id'] ?? 0)] ?? null;
            if ($idVehicle === null) {
                return null;
            }
            $reasonClass = $row['registration_reason_class'] ?? null;

            return [
                'legacy_id' => (int) $row['id'],
                'id_vehicle' => $idVehicle,
                'amount' => (int) ($row['amount'] ?? 0),
                'registration_date' => !empty($row['registration_date']) ? $row['registration_date'] : null,
                'reason_class' => $reasonClass,
                'reason_id' => (int) ($row['registration_reasonid'] ?? 0) ?: null,
                'id_maintenance' => $reasonClass === 'Maintenance' ? ($maintenanceMap[(int) ($row['registration_reasonid'] ?? 0)] ?? null) : null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['km'], $report['skipped']);

        return $this->jsonResponse([
            'success' => true,
            'message' => sprintf(
                'Manutenzioni: %d, lavori: %d, ricambi: %d, fatture: %d, tagliandi: %d, voci tagliando: %d, reg. km: %d, etichette: %d, scadenze: %d, occorrenze: %d (scartate %d righe).',
                $report['maintenance'],
                $report['tasks'],
                $report['details'],
                $report['invoices'],
                $report['services'],
                $report['service_tasks'],
                $report['km'],
                $report['tags'],
                $report['expirations'],
                $report['occurrences'],
                $report['skipped']
            ),
        ]);
    }

    /**
     * Mappa legacy_id => chiave primaria per una tabella fb_*.
     *
     * @return array<int, int>
     */
    private function legacyMap(BaseConnection $db, string $table, string $pk): array
    {
        $map = [];
        foreach ($db->table($table)->select("{$pk}, legacy_id")->where('legacy_id IS NOT NULL', null, false)->get()->getResultArray() as $r) {
            $map[(int) $r['legacy_id']] = (int) $r[$pk];
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function insertBatches(BaseConnection $db, string $table, array $rows, callable $mapper, int &$count, ?int &$skipped = null): void
    {
        $batch = [];
        foreach ($rows as $row) {
            $mapped = $mapper($row);
            if ($mapped === null) {
                if ($skipped !== null) {
                    $skipped++;
                }
                continue;
            }
            $batch[] = $mapped;
            if (count($batch) >= self::BATCH_SIZE) {
                $db->table($table)->insertBatch($batch);
                $count += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $db->table($table)->insertBatch($batch);
            $count += count($batch);
        }
    }
}
