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

        $rows = (new \FairyBus\Models\FbMaintenanceDetailModel())->linesForVehicle($idVehicle);

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
            ->select('(SELECT COUNT(*) FROM fb_maintenance_task t WHERE t.id_maintenance = m.id_maintenance AND (t.hours > 0 OR t.price_per_hour > 0 OR t.manpower > 0)) AS tasks_count', false)
            ->select('(SELECT COALESCE(SUM(ABS(d.quantity) * d.price * (1 - ABS(COALESCE(d.discount, 0)) / 100)), 0) FROM fb_maintenance_detail d WHERE d.id_maintenance = m.id_maintenance) AS parts_amount', false)
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
            'id_load' => 'permit_empty|integer',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new \FairyBus\Models\FbMaintenanceDetailModel();

        // scarico oltre giacenza non consentito: la q.tà non puo' superare lo stock
        $idProduct = (int) $this->request->getPost('id_product');
        $qtyToUnload = (float) $this->request->getPost('quantity');
        $stock = (float) ($model->db->table('fb_stock')
            ->select('quantity')
            ->where('id_product', $idProduct)
            ->get()
            ->getRow()->quantity ?? 0);
        if ($qtyToUnload - $stock > 0.0001) {
            $prod = $model->db->table('fb_product')
                ->select('sku, name')
                ->where('id_product', $idProduct)
                ->get()
                ->getRowArray();
            return $this->jsonResponse([
                'success' => false,
                'error' => sprintf(
                    'Giacenza insufficiente%s: disponibili %s, richiesti %s.',
                    $prod !== null ? " per \"{$prod['sku']} {$prod['name']}\"" : '',
                    number_format($stock, 3, ',', '.'),
                    number_format($qtyToUnload, 3, ',', '.')
                ),
            ], 422);
        }

        // Carico a cui agganciare lo scarico: quello scelto nel dialog,
        // altrimenti il piu' vecchio con residuo > 0 allo stesso prezzo
        $idLoad = (int) $this->request->getPost('id_load');
        $price = (float) ($this->request->getPost('price') ?: 0);
        if ($idLoad > 0) {
            $valid = $model->db->table('fb_document_detail dd')
                ->join('fb_document d', 'd.id_document = dd.id_document')
                ->where('dd.id_document_detail', $idLoad)
                ->where('dd.id_product', $idProduct)
                ->where('d.id_customer IS NULL', null, false)
                ->where('dd.id_maintenance IS NULL', null, false)
                ->countAllResults() > 0;
            if (!$valid) {
                return $this->jsonResponse(['success' => false, 'error' => 'Il carico indicato non appartiene a questo prodotto.'], 422);
            }
        } else {
            $idLoad = $this->findFifoLoad($model->db, $idProduct, $price) ?? 0;
        }

        // Sconto non inviato ma riga agganciata a un carico: eredita lo
        // sconto originale del carico (prezzo finale = lordo - sconto).
        $discount = $this->request->getPost('discount');
        if (($discount === null || $discount === '') && $idLoad > 0) {
            $discount = $model->db->table('fb_document_detail')
                ->select('discount')
                ->where('id_document_detail', $idLoad)
                ->get()->getRowArray()['discount'] ?? 0;
        }

        $newId = (int) $model->insert([
            'id_maintenance' => $id,
            'id_product' => (int) $this->request->getPost('id_product'),
            'quantity' => -abs((float) $this->request->getPost('quantity')),
            'price' => $price,
            'id_load' => $idLoad > 0 ? $idLoad : null,
            'discount' => abs((float) ($discount ?: 0)),
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

    /**
     * Carico FIFO a cui agganciare uno scarico senza id_load esplicito:
     * il piu' vecchio documento fornitore del prodotto allo stesso prezzo
     * che ha ancora residuo (caricato - scarichi gia' agganciati).
     */
    private function findFifoLoad(\CodeIgniter\Database\BaseConnection $db, int $idProduct, float $price): ?int
    {
        if ($price <= 0) {
            return null;
        }
        $loads = $db->table('fb_document_detail dd')
            ->select('dd.id_document_detail, ABS(dd.quantity) AS qty')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->where('d.id_customer IS NULL', null, false)
            ->where('dd.id_maintenance IS NULL', null, false)
            ->where('dd.id_product', $idProduct)
            ->where('ABS(dd.price - ' . $price . ') < 0.0001', null, false)
            ->orderBy('d.date', 'ASC')
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()->getResultArray();

        $linked = [];
        foreach ($db->table('fb_maintenance_detail md')->select('md.id_load, ABS(md.quantity) AS qty')->where('md.id_product', $idProduct)->where('md.id_load IS NOT NULL', null, false)->get()->getResultArray() as $o) {
            $linked[(int) $o['id_load']] = ($linked[(int) $o['id_load']] ?? 0) + (float) $o['qty'];
        }
        foreach ($db->table('fb_document_detail dd')->select('dd.id_load, ABS(dd.quantity) AS qty')->join('fb_document d', 'd.id_document = dd.id_document')->where('d.id_customer IS NOT NULL', null, false)->where('dd.id_product', $idProduct)->where('dd.id_load IS NOT NULL', null, false)->get()->getResultArray() as $o) {
            $linked[(int) $o['id_load']] = ($linked[(int) $o['id_load']] ?? 0) + (float) $o['qty'];
        }

        foreach ($loads as $l) {
            $lid = (int) $l['id_document_detail'];
            if ((float) $l['qty'] - ($linked[$lid] ?? 0) > 0.0001) {
                return $lid;
            }
        }
        return null;
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

        $mnt = (new \FairyBus\Models\FbMaintenanceModel())->find($id);
        if ($mnt === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Manutenzione non trovata.'], 404);
        }

        $idInvoice = (int) $this->request->getPost('id_invoice');
        $invoice = (new \FairyBus\Models\FbInvoiceModel())->find($idInvoice);
        if ($invoice === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fattura non trovata.'], 404);
        }
        if (empty($invoice['id_customer'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Le schede manutenzione si associano solo alle fatture cliente.'], 422);
        }
        if ((int) ($mnt['id_invoice'] ?? 0) > 0 && (int) $mnt['id_invoice'] !== $idInvoice) {
            return $this->jsonResponse(['success' => false, 'error' => 'La scheda è già registrata su un\'altra fattura.'], 422);
        }

        $db = \Config\Database::connect();
        $exists = $db->table('fb_maintenance_invoice')
            ->where('id_maintenance', $id)->where('id_invoice', $idInvoice)
            ->countAllResults() > 0;
        if (!$exists) {
            $db->table('fb_maintenance_invoice')->insert([
                'id_maintenance' => $id,
                'id_invoice' => $idInvoice,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }
        // riferimento diretto alla fattura (come assignMaintenance lato fatture)
        $db->table('fb_maintenance')->where('id_maintenance', $id)->update(['id_invoice' => $idInvoice]);

        return $this->jsonResponse(['success' => true]);
    }

    public function unlinkInvoice(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idInvoice = (int) $this->request->getPost('id_invoice');
        $db = \Config\Database::connect();
        $db->table('fb_maintenance_invoice')
            ->where('id_maintenance', $id)->where('id_invoice', $idInvoice)->delete();

        // azzera il riferimento diretto solo se non restano altri link fattura
        $remaining = $db->table('fb_maintenance_invoice')->where('id_maintenance', $id)->countAllResults();
        if ($remaining === 0) {
            $db->table('fb_maintenance')
                ->where('id_maintenance', $id)->where('id_invoice', $idInvoice)
                ->update(['id_invoice' => null]);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Associa una scheda libera a un documento di riepilogo esistente:
     * flagga fb_maintenance.id_document e copia ricambi+manodopera nelle
     * righe del documento (nessun movimento di magazzino).
     */
    public function linkDocument(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $mnt = (new \FairyBus\Models\FbMaintenanceModel())->find($id);
        if ($mnt === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Manutenzione non trovata.'], 404);
        }

        $idDocument = (int) $this->request->getPost('id_document');
        $document = (new \FairyBus\Models\FbDocumentModel())->find($idDocument);
        if ($document === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Documento non trovato.'], 404);
        }
        if (empty($document['id_customer'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Le schede manutenzione si associano solo ai documenti di riepilogo.'], 422);
        }
        if (!empty($mnt['id_document'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'La scheda è già associata a un riepilogo.'], 422);
        }
        if ((int) ($document['id_vehicle'] ?? 0) > 0 && (int) $document['id_vehicle'] !== (int) ($mnt['id_vehicle'] ?? 0)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Il riepilogo è associato a un altro automezzo.'], 422);
        }

        $db = \Config\Database::connect();
        $invoiced = $db->table('fb_maintenance_invoice')->where('id_maintenance', $id)->countAllResults() > 0;
        if ($invoiced) {
            return $this->jsonResponse(['success' => false, 'error' => 'La scheda è già associata a una fattura.'], 422);
        }

        $db->table('fb_maintenance')->where('id_maintenance', $id)->update(['id_document' => $idDocument]);

        // il riepilogo eredita la targa della scheda se non ne ha una
        if ((int) ($document['id_vehicle'] ?? 0) <= 0 && (int) ($mnt['id_vehicle'] ?? 0) > 0) {
            $db->table('fb_document')->where('id_document', $idDocument)->update(['id_vehicle' => (int) $mnt['id_vehicle']]);
        }

        $defaultVat = (float) ((new \FairyBus\Models\FbConfigurationModel())->get('default_tax_rate')['value'] ?? 0);
        $copied = (new \FairyBus\Models\FbDocumentDetailModel())->importMaintenanceLines($idDocument, $id, (int) ($document['type'] ?? 0), $defaultVat);

        return $this->jsonResponse(['success' => true, 'rows_copied' => $copied]);
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

}
