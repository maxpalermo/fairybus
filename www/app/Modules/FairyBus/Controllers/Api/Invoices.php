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
use FairyBus\Models\FbDocumentDetailModel;
use FairyBus\Models\FbDocumentModel;
use FairyBus\Models\FbInvoiceModel;

class Invoices extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new FbInvoiceModel())->listAll(),
        ]);
    }

    public function get(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $invoice = (new FbInvoiceModel())->findById($id);
        if ($invoice === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fattura non trovata.'], 404);
        }

        $invoice['documents'] = $this->invoiceDocuments($id);
        $invoice['maintenances'] = \FairyBus\Models\FbMaintenanceInvoiceModel::blocksByInvoice($id);

        return $this->jsonResponse(['success' => true, 'invoice' => $invoice]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $validation = $this->validateInvoice();
        if ($validation instanceof ResponseInterface) {
            return $validation;
        }

        $model = new FbInvoiceModel();
        $id = (int) $model->insert($validation + ['date_add' => date('Y-m-d H:i:s')]);

        $this->syncDocuments($id, $this->documentIdsFromPost(), (int) ($validation['id_supplier'] ?? 0), (int) ($validation['id_customer'] ?? 0));
        $this->syncMaintenances($id, $this->maintenanceIdsFromPost());

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbInvoiceModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fattura non trovata.'], 404);
        }

        $validation = $this->validateInvoice();
        if ($validation instanceof ResponseInterface) {
            return $validation;
        }

        $model->update($id, $validation + ['date_upd' => date('Y-m-d H:i:s')]);

        $this->syncDocuments($id, $this->documentIdsFromPost(), (int) ($validation['id_supplier'] ?? 0), (int) ($validation['id_customer'] ?? 0));
        $this->syncMaintenances($id, $this->maintenanceIdsFromPost());

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbInvoiceModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fattura non trovata.'], 404);
        }

        $db = \Config\Database::connect();
        $db->table('fb_document')->where('id_invoice', $id)->set('id_invoice', null)->update();
        $db->table('fb_maintenance_invoice')->where('id_invoice', $id)->delete();
        $db->table('fb_maintenance')->where('id_invoice', $id)->set('id_invoice', null)->update();
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Documenti collegati alla fattura, con righe dettaglio.
     *
     * @return list<array<string, mixed>>
     */
    private function invoiceDocuments(int $idInvoice): array
    {
        $db = \Config\Database::connect();
        $docs = $db->table('fb_document d')
            ->select('d.*, s.company AS supplier_name')
            ->join('fb_supplier s', 's.id_supplier = d.id_supplier', 'left')
            ->where('d.id_invoice', $idInvoice)
            ->orderBy('d.date', 'ASC')
            ->get()
            ->getResultArray();

        $detailModel = new FbDocumentDetailModel();
        foreach ($docs as &$doc) {
            $doc['details'] = $detailModel->listByDocument((int) $doc['id_document']);
        }

        return $docs;
    }

    /**
     * @return list<int>
     */
    private function documentIdsFromPost(): array
    {
        $raw = (string) $this->request->getPost('document_ids');
        $ids = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = array_values(array_filter(array_map('intval', $decoded), static fn(int $i): bool => $i > 0));
            }
        }

        return $ids;
    }

    /**
     * Collega/scollega una singola scheda manutenzione a una fattura cliente.
     * POST: id_maintenance (obbligatorio), remove=1 per scollegare.
     */
    public function assignMaintenance(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbInvoiceModel();
        $invoice = $model->find($id);
        if ($invoice === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Fattura non trovata.'], 404);
        }
        if (empty($invoice['id_customer'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Le schede manutenzione si associano solo alle fatture cliente.'], 422);
        }

        $idMaintenance = (int) $this->request->getPost('id_maintenance');
        $remove = (string) $this->request->getPost('remove') === '1';
        if ($idMaintenance <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Scheda manutenzione non valida.'], 422);
        }

        $db = \Config\Database::connect();
        $mnt = $db->table('fb_maintenance')->select('id_maintenance, id_invoice')->where('id_maintenance', $idMaintenance)->get()->getRowArray();
        if ($mnt === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Scheda manutenzione non trovata.'], 404);
        }

        if ($remove) {
            $db->table('fb_maintenance_invoice')
                ->where('id_invoice', $id)
                ->where('id_maintenance', $idMaintenance)
                ->delete();
            $remaining = $db->table('fb_maintenance_invoice')->where('id_maintenance', $idMaintenance)->countAllResults();
            if ($remaining === 0 && (int) ($mnt['id_invoice'] ?? 0) === $id) {
                $db->table('fb_maintenance')->where('id_maintenance', $idMaintenance)->update(['id_invoice' => null]);
            }

            return $this->jsonResponse(['success' => true, 'linked' => false]);
        }

        if ((int) ($mnt['id_invoice'] ?? 0) > 0 && (int) $mnt['id_invoice'] !== $id) {
            return $this->jsonResponse(['success' => false, 'error' => 'La scheda è già registrata su un\'altra fattura.'], 422);
        }
        $exists = $db->table('fb_maintenance_invoice')
            ->where('id_maintenance', $idMaintenance)
            ->where('id_invoice', $id)
            ->countAllResults() > 0;
        if (!$exists) {
            $db->table('fb_maintenance_invoice')->insert([
                'id_maintenance' => $idMaintenance,
                'id_invoice' => $id,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }
        $db->table('fb_maintenance')->where('id_maintenance', $idMaintenance)->update(['id_invoice' => $id]);

        return $this->jsonResponse(['success' => true, 'linked' => true]);
    }

    /**
     * @return list<int>
     */
    private function maintenanceIdsFromPost(): array
    {
        $raw = (string) $this->request->getPost('maintenance_ids');
        $ids = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = array_values(array_filter(array_map('intval', $decoded), static fn(int $i): bool => $i > 0));
            }
        }

        return $ids;
    }

    /**
     * Collega le schede manutenzione selezionate alla fattura cliente e
     * scollega quelle deselezionate (fb_maintenance_invoice). Una scheda gia'
     * presente su un'altra fattura non viene mai spostata.
     *
     * @param list<int> $maintenanceIds
     */
    private function syncMaintenances(int $idInvoice, array $maintenanceIds): void
    {
        $db = \Config\Database::connect();
        $current = array_map('intval', array_column(
            $db->table('fb_maintenance_invoice')->select('id_maintenance')->where('id_invoice', $idInvoice)->get()->getResultArray(),
            'id_maintenance'
        ));

        $toRemove = array_diff($current, $maintenanceIds);
        $toAdd = array_diff($maintenanceIds, $current);

        if ($toRemove !== []) {
            $db->table('fb_maintenance_invoice')
                ->where('id_invoice', $idInvoice)
                ->whereIn('id_maintenance', array_values($toRemove))
                ->delete();
            // azzera il riferimento diretto solo se non restano altri link
            $db->table('fb_maintenance')
                ->where('id_invoice', $idInvoice)
                ->whereIn('id_maintenance', array_values($toRemove))
                ->where("NOT EXISTS (SELECT 1 FROM fb_maintenance_invoice mi WHERE mi.id_maintenance = fb_maintenance.id_maintenance)", null, false)
                ->update(['id_invoice' => null]);
        }

        foreach ($toAdd as $idMaintenance) {
            $mnt = $db->table('fb_maintenance')->select('id_maintenance, id_invoice')->where('id_maintenance', $idMaintenance)->get()->getRowArray();
            if ($mnt === null) {
                continue;
            }
            $invoicedElsewhere = ((int) ($mnt['id_invoice'] ?? 0) > 0 && (int) $mnt['id_invoice'] !== $idInvoice)
                || $db->table('fb_maintenance_invoice')
                    ->where('id_maintenance', $idMaintenance)
                    ->where('id_invoice !=', $idInvoice)
                    ->countAllResults() > 0;
            if ($invoicedElsewhere) {
                continue;
            }
            $db->table('fb_maintenance_invoice')->insert([
                'id_maintenance' => $idMaintenance,
                'id_invoice' => $idInvoice,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
            $db->table('fb_maintenance')->where('id_maintenance', $idMaintenance)->update(['id_invoice' => $idInvoice]);
        }
    }

    /**
     * Collega i documenti selezionati alla fattura e scollega quelli deselezionati.
     * Solo documenti dello stesso fornitore possono essere collegati.
     *
     * @param list<int> $documentIds
     */
    private function syncDocuments(int $idInvoice, array $documentIds, int $idSupplier, int $idCustomer = 0): void
    {
        $db = \Config\Database::connect();
        $partnerField = $idCustomer > 0 ? 'id_customer' : 'id_supplier';
        $partnerId = $idCustomer > 0 ? $idCustomer : $idSupplier;

        // Documenti attualmente collegati (servono per propagare lo svincolo
        // alle schede manutenzione associate via scarico conto terzi)
        $previouslyLinked = array_map('intval', array_column(
            $db->table('fb_document')->select('id_document')->where('id_invoice', $idInvoice)->get()->getResultArray(),
            'id_document'
        ));

        // Scollega i documenti attualmente collegati ma non più selezionati
        $builder = $db->table('fb_document')->where('id_invoice', $idInvoice);
        if ($documentIds !== []) {
            $builder->whereNotIn('id_document', $documentIds);
        }
        $builder->set('id_invoice', null)->update();

        foreach ($previouslyLinked as $idDocument) {
            if (!in_array($idDocument, $documentIds, true)) {
                \FairyBus\Models\FbMaintenanceInvoiceModel::unlinkByDocument($idDocument, $idInvoice);
            }
        }

        // Collega i documenti selezionati (solo se dello stesso partner e liberi o già di questa fattura)
        if ($documentIds !== [] && $partnerId > 0) {
            $db->table('fb_document')
                ->whereIn('id_document', $documentIds)
                ->where($partnerField, $partnerId)
                ->groupStart()
                ->where('id_invoice IS NULL', null, false)
                ->orWhere('id_invoice', $idInvoice)
                ->groupEnd()
                ->set('id_invoice', $idInvoice)
                ->update();

            foreach ($documentIds as $idDocument) {
                \FairyBus\Models\FbMaintenanceInvoiceModel::linkByDocument($idDocument, $idInvoice);
            }
        }
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    private function validateInvoice(): array|ResponseInterface
    {
        $rules = [
            'number' => 'permit_empty|max_length[255]',
            'date' => 'permit_empty|valid_date',
            'id_supplier' => 'permit_empty|integer',
            'id_customer' => 'permit_empty|integer',
            'id_vehicle' => 'permit_empty|integer',
            'collection_fee' => 'permit_empty|decimal',
            'deposit' => 'permit_empty|decimal',
            'transport_fee' => 'permit_empty|decimal',
            'note' => 'permit_empty|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        return [
            'number' => $this->request->getPost('number') !== '' ? $this->request->getPost('number') : null,
            'date' => $this->request->getPost('date') !== '' ? $this->request->getPost('date') : null,
            'id_supplier' => (int) $this->request->getPost('id_supplier') ?: null,
            'id_customer' => (int) $this->request->getPost('id_customer') ?: null,
            'id_vehicle' => (int) $this->request->getPost('id_vehicle') ?: null,
            'collection_fee' => (float) ($this->request->getPost('collection_fee') ?: 0),
            'deposit' => (float) ($this->request->getPost('deposit') ?: 0),
            'transport_fee' => (float) ($this->request->getPost('transport_fee') ?: 0),
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
        ];
    }
}
