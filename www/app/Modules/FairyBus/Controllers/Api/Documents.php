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

class Documents extends AdminController
{
    private const BATCH_SIZE = 500;

    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $direction = $this->request->getGet('direction');
        $direction = in_array($direction, ['in', 'out'], true) ? $direction : 'all';

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new \FairyBus\Models\FbDocumentModel())->listAll([], $direction),
        ]);
    }

    public function get(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $document = (new \FairyBus\Models\FbDocumentModel())->findById($id);
        if ($document === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Documento non trovato.'], 404);
        }

        $document['details'] = (new \FairyBus\Models\FbDocumentDetailModel())->listByDocument($id);

        return $this->jsonResponse(['success' => true, 'document' => $document]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $validation = $this->validateDocument();
        if ($validation instanceof ResponseInterface) {
            return $validation;
        }

        // Scarichi verso clienti: il numero e' un progressivo di sistema
        // (contatore in fb_configuration), inizializzato a progressive+1.
        $config = null;
        $counter = 0;
        if (!empty($validation['id_customer'])) {
            $config = new \FairyBus\Models\FbConfigurationModel();
            $counter = (int) ($config->get('unload_doc_counter')['value'] ?? 0);
            if (empty($validation['number'])) {
                $validation['number'] = (string) ($counter + 1);
            }
        }

        $model = new \FairyBus\Models\FbDocumentModel();
        $id = (int) $model->insert($validation + [
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        if ($config !== null && $id > 0) {
            $used = is_numeric($validation['number']) ? (int) $validation['number'] : $counter + 1;
            $config->setValue('unload_doc_counter', (string) max($counter, $used));
        }

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    /**
     * Prossimo numero progressivo disponibile per i riepiloghi di
     * manutenzione terzi (scarichi verso clienti): contatore + 1.
     */
    public function nextNumber(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $counter = (int) ((new \FairyBus\Models\FbConfigurationModel())->get('unload_doc_counter')['value'] ?? 0);

        return $this->jsonResponse(['success' => true, 'next' => $counter + 1]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbDocumentModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Documento non trovato.'], 404);
        }

        $validation = $this->validateDocument();
        if ($validation instanceof ResponseInterface) {
            return $validation;
        }

        $model->update($id, $validation + ['date_upd' => date('Y-m-d H:i:s')]);

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbDocumentModel();
        $document = $model->find($id);
        if ($document === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Documento non trovato.'], 404);
        }

        $db = \Config\Database::connect();
        $detailModel = new \FairyBus\Models\FbDocumentDetailModel();
        $stock = new \FairyBus\Models\FbStockModel();

        // Storna a magazzino solo le righe create manualmente:
        // i movimenti importati dal legacy e quelli copiati dalle schede di
        // manutenzione hanno gia' movimentato lo stock a monte.
        $direction = $this->stockDirection($document ?? []);
        foreach ($detailModel->listByDocument($id) as $detail) {
            if (empty($detail['legacy_id']) && empty($detail['id_maintenance'])) {
                // Storno: eliminare un carico scarica, eliminare uno scarico carica
                $stock->moveStock((int) ($detail['id_product'] ?? 0), $direction > 0 ? 'out' : 'in', (float) $detail['quantity']);
            }
        }

        // schede manutenzione associate: svincola ed elimina eventuali
        // riferimenti a fattura generati tramite questo documento
        \FairyBus\Models\FbMaintenanceInvoiceModel::unlinkByDocument($id, (int) ($document['id_invoice'] ?? 0));
        $db->table('fb_maintenance')->where('id_document', $id)->set('id_document', null)->update();

        $db->table('fb_document_detail')->where('id_document', $id)->delete();
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Associa il documento a una fattura (id_invoice nel POST) o lo svincola (vuoto).
     */
    public function assignInvoice(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbDocumentModel();
        $document = $model->find($id);
        if ($document === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Documento non trovato.'], 404);
        }

        $idInvoice = (int) $this->request->getPost('id_invoice') ?: null;

        if ($idInvoice !== null) {
            $invoice = (new \FairyBus\Models\FbInvoiceModel())->find($idInvoice);
            if ($invoice === null) {
                return $this->jsonResponse(['success' => false, 'error' => 'Fattura non trovata.'], 404);
            }
            // La fattura deve riferirsi allo stesso partner (fornitore o cliente) del documento
            if (!empty($document['id_supplier']) && (int) $invoice['id_supplier'] !== (int) $document['id_supplier']) {
                return $this->jsonResponse(['success' => false, 'error' => 'La fattura appartiene a un fornitore diverso.'], 422);
            }
            if (!empty($document['id_customer']) && (int) $invoice['id_customer'] !== (int) $document['id_customer']) {
                return $this->jsonResponse(['success' => false, 'error' => 'La fattura appartiene a un cliente diverso.'], 422);
            }
        }

        $oldInvoice = (int) ($document['id_invoice'] ?? 0);
        $model->update($id, [
            'id_invoice' => $idInvoice,
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        // propaga il collegamento alle schede manutenzione dello scarico
        if ($oldInvoice > 0) {
            \FairyBus\Models\FbMaintenanceInvoiceModel::unlinkByDocument($id, $oldInvoice);
        }
        if ($idInvoice !== null) {
            \FairyBus\Models\FbMaintenanceInvoiceModel::linkByDocument($id, $idInvoice);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Sincronizza le schede manutenzione associate a un documento di scarico
     * (conto terzi): riceve l'elenco finale degli id selezionati, collega i
     * nuovi (copiando ricambi e manodopera nelle righe documento) e svincola
     * quelli deselezionati (eliminando le righe copiate).
     */
    public function syncMaintenances(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $document = (new \FairyBus\Models\FbDocumentModel())->find($id);
        if ($document === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Documento non trovato.'], 404);
        }
        if (empty($document['id_customer'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Le schede manutenzione si associano solo agli scarichi verso clienti.'], 422);
        }

        // maintenance_ids arriva come JSON (FormData) o array
        $raw = $this->request->getPost('maintenance_ids');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $wanted = array_values(array_unique(array_filter(array_map('intval', (array) $raw), static fn(int $i): bool => $i > 0)));

        $db = \Config\Database::connect();
        $current = array_map('intval', array_column(
            $db->table('fb_maintenance')->select('id_maintenance')->where('id_document', $id)->get()->getResultArray(),
            'id_maintenance'
        ));

        $toRemove = array_diff($current, $wanted);
        $toAdd = array_diff($wanted, $current);

        if ($toRemove !== []) {
            $db->table('fb_document_detail')
                ->where('id_document', $id)
                ->whereIn('id_maintenance', array_values($toRemove))
                ->delete();
            $db->table('fb_maintenance')
                ->whereIn('id_maintenance', array_values($toRemove))
                ->set('id_document', null)
                ->update();
        }

        $defaultVat = (float) ((new \FairyBus\Models\FbConfigurationModel())->get('default_tax_rate')['value'] ?? 0);
        $detailModel = new \FairyBus\Models\FbDocumentDetailModel();
        $linked = 0;
        $copied = 0;

        foreach ($toAdd as $idMaintenance) {
            $mnt = $db->table('fb_maintenance')->where('id_maintenance', $idMaintenance)->get()->getRowArray();
            if ($mnt === null) {
                return $this->jsonResponse(['success' => false, 'error' => "Manutenzione #{$idMaintenance} non trovata."], 422);
            }
            if (!empty($mnt['id_document'])) {
                return $this->jsonResponse(['success' => false, 'error' => "La manutenzione #{$idMaintenance} è già associata a un altro scarico."], 422);
            }
            $invoiced = $db->table('fb_maintenance_invoice')->where('id_maintenance', $idMaintenance)->countAllResults() > 0;
            if ($invoiced) {
                return $this->jsonResponse(['success' => false, 'error' => "La manutenzione #{$idMaintenance} è già associata a una fattura."], 422);
            }

            $db->table('fb_maintenance')->where('id_maintenance', $idMaintenance)->update(['id_document' => $id]);
            $linked++;

            // ricambi + manodopera della scheda -> righe documento (lo stock
            // era gia' stato scaricato: nessun movimento extra)
            $copied += $detailModel->importMaintenanceLines($id, $idMaintenance, (int) ($document['type'] ?? 0), $defaultVat);
        }

        // Registra sul documento il veicolo delle schede associate; se non
        // restano schede si mantiene il valore scelto nel form (id_vehicle).
        $idVehicle = $db->table('fb_maintenance')->select('id_vehicle')->where('id_document', $id)->limit(1)->get()->getRow('id_vehicle');
        if ($idVehicle !== null) {
            $db->table('fb_document')->where('id_document', $id)->update(['id_vehicle' => (int) $idVehicle]);
        }

        return $this->jsonResponse(['success' => true, 'linked' => $linked, 'unlinked' => count($toRemove), 'rows_copied' => $copied]);
    }

    /**
     * Documenti di un fornitore non ancora fatturati (più quelli già
     * collegati alla fattura indicata, per la modifica).
     */
    public function available(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idSupplier = (int) $this->request->getGet('supplier_id');
        $idCustomer = (int) $this->request->getGet('customer_id');
        if ($idSupplier <= 0 && $idCustomer <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Partner non valido.'], 422);
        }

        $idInvoice = (int) $this->request->getGet('invoice_id') ?: null;

        $model = new \FairyBus\Models\FbDocumentModel();
        $documents = $idCustomer > 0
            ? $model->listAvailableForInvoice($idCustomer, $idInvoice, 'customer')
            : $model->listAvailableForInvoice($idSupplier, $idInvoice, 'supplier');
        $detailModel = new \FairyBus\Models\FbDocumentDetailModel();
        foreach ($documents as &$doc) {
            $doc['details'] = $detailModel->listByDocument((int) $doc['id_document']);
        }

        return $this->jsonResponse(['success' => true, 'documents' => $documents]);
    }

    /**
     * Righe dettaglio di un documento.
     */
    public function details(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new \FairyBus\Models\FbDocumentDetailModel())->listByDocument($id),
        ]);
    }

    public function addDetail(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $document = (new \FairyBus\Models\FbDocumentModel())->find($id);
        if ($document === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Documento non trovato.'], 404);
        }

        $rules = [
            'id_product' => 'required|integer|greater_than[0]',
            'quantity' => 'required|decimal',
            'price' => 'permit_empty|decimal',
            'discount' => 'permit_empty|decimal',
            'vat_rate' => 'permit_empty|decimal',
            'lot' => 'permit_empty|max_length[255]',
            'note' => 'permit_empty|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new \FairyBus\Models\FbDocumentDetailModel();
        $newId = (int) $model->insert([
            'id_document' => $id,
            'id_product' => (int) $this->request->getPost('id_product'),
            'quantity' => $this->stockDirection($document) * abs((float) $this->request->getPost('quantity')),
            'price' => (float) ($this->request->getPost('price') ?: 0),
            'discount' => abs((float) ($this->request->getPost('discount') ?: 0)),
            'vat_rate' => $this->request->getPost('vat_rate') !== '' ? (float) $this->request->getPost('vat_rate') : null,
            'lot' => $this->request->getPost('lot') !== '' ? $this->request->getPost('lot') : null,
            'type' => (int) ($document['type'] ?? 0),
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        $row = $model->db->table('fb_document_detail dd')
            ->select('dd.*, p.sku, p.name AS product_name')
            ->join('fb_product p', 'p.id_product = dd.id_product', 'left')
            ->where('dd.id_document_detail', $newId)
            ->get()->getRowArray();

        // Riga creata manualmente: carico = +giacenza, scarico = -giacenza
        $direction = $this->stockDirection($document);
        (new \FairyBus\Models\FbStockModel())->moveStock((int) $row['id_product'], $direction > 0 ? 'in' : 'out', (float) $row['quantity']);

        $productModel = new \FairyBus\Models\FbProductModel();
        $product = $productModel->find((int) $row['id_product']);

        // Se il prodotto non ha un'aliquota IVA e nel movimento ne è stata
        // inserita una, la riporta sull'anagrafica del prodotto.
        $vatInput = $this->request->getPost('vat_rate');
        if ($vatInput !== null && $vatInput !== '' && $product !== null && (float) ($product['tax_rate'] ?? 0) <= 0) {
            $productModel->update((int) $row['id_product'], ['tax_rate' => (float) $vatInput]);
        }

        // Su ogni carico il prezzo d'acquisto del prodotto è allineato
        // all'ultimo prezzo di carico (al netto dello sconto riga).
        if ($direction > 0 && $product !== null) {
            $netPrice = (float) $row['price'] * (1 - abs((float) ($row['discount'] ?? 0)) / 100);
            if ($netPrice > 0) {
                $productModel->update((int) $row['id_product'], ['wholesale_price' => $netPrice]);
            }
        }

        return $this->jsonResponse(['success' => true, 'detail' => $row]);
    }

    public function deleteDetail(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbDocumentDetailModel();
        $detail = $model->find($id);
        if ($detail === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Riga non trovata.'], 404);
        }
        $model->delete($id);

        // se era una riga di carico, gli scarichi agganciati tornano anomali
        $model->db->table('fb_maintenance_detail')->where('id_load', $id)->update(['id_load' => null]);
        $model->db->table('fb_document_detail')->where('id_load', $id)->update(['id_load' => null]);

        // Le righe importate dal legacy e quelle copiate dalle schede di
        // manutenzione non toccano lo stock: lo scarico era gia' avvenuto.
        if (empty($detail['legacy_id']) && empty($detail['id_maintenance'])) {
            $document = (new \FairyBus\Models\FbDocumentModel())->find((int) $detail['id_document']);
            $direction = $this->stockDirection($document ?? []);
            // Storno: eliminare una riga di carico scarica, di scarico carica
            (new \FairyBus\Models\FbStockModel())->moveStock((int) ($detail['id_product'] ?? 0), $direction > 0 ? 'out' : 'in', (float) $detail['quantity']);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Aggiorna il prezzo di una riga documento (DDT arrivati senza prezzo).
     * Se la riga e' una copia conto terzi allinea anche la riga sorgente
     * della scheda manutenzione; sui carichi aggiorna il prezzo
     * d'acquisto dell'articolo (ultimo prezzo al netto dello sconto).
     */
    public function updateDetailPrice(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if (!$this->validate(['price' => 'required|decimal|greater_than_equal_to[0]'])) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $model = new \FairyBus\Models\FbDocumentDetailModel();
        $detail = $model->find($id);
        if ($detail === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Riga non trovata.'], 404);
        }

        $price = (float) $this->request->getPost('price');
        $document = (new \FairyBus\Models\FbDocumentModel())->find((int) $detail['id_document']);
        $isLoad = $this->stockDirection($document ?? []) > 0;
        $now = date('Y-m-d H:i:s');
        $cascaded = 0;

        $model->db->transStart();
        if ($isLoad) {
            $model->update($id, ['price' => $price]);

            // cascata: gli scarichi agganciati a questo carico (id_load)
            // prendono il nuovo prezzo — righe manutenzione + righe
            // scarico dei documenti cliente (non copie)
            $mntLinked = $model->db->table('fb_maintenance_detail')
                ->select('id_maintenance, id_product, price')
                ->where('id_load', $id)
                ->get()->getResultArray();
            foreach ($mntLinked as $mr) {
                // copie conto terzi delle righe manutenzione agganciate:
                // stesso prezzo della sorgente (matching sul vecchio prezzo)
                $model->db->table('fb_document_detail')
                    ->where('id_maintenance', (int) $mr['id_maintenance'])
                    ->where('id_product', (int) $mr['id_product'])
                    ->where('ABS(price - ' . (float) $mr['price'] . ') < 0.0001', null, false)
                    ->update(['price' => $price, 'date_upd' => $now]);
            }
            $model->db->table('fb_maintenance_detail')
                ->where('id_load', $id)
                ->update(['price' => $price, 'date_upd' => $now]);
            $model->db->query(
                'UPDATE fb_document_detail dd
                    JOIN fb_document d ON d.id_document = dd.id_document
                    SET dd.price = ?, dd.date_upd = ?
                    WHERE dd.id_load = ? AND d.id_customer IS NOT NULL AND dd.id_maintenance IS NULL',
                [$price, $now, $id]
            );
            $cascaded = count($mntLinked) + (int) $model->db->affectedRows();
        } else {
            // riga di scarico: il prezzo non segue piu' un carico ->
            // diventa anomala (id_load azzerato)
            $detached = $detail['id_load'] !== null;
            $model->update($id, ['price' => $price, 'id_load' => null]);
        }

        if (!empty($detail['id_maintenance'])) {
            // riga copia conto terzi: allinea la riga manutenzione sorgente,
            // che col nuovo prezzo non segue piu' il suo carico -> sganciata
            $model->db->table('fb_maintenance_detail')
                ->where('id_maintenance', (int) $detail['id_maintenance'])
                ->where('id_product', (int) $detail['id_product'])
                ->where('price', (float) $detail['price'])
                ->update(['price' => $price, 'id_load' => null]);
        }
        $model->db->transComplete();

        if (!$model->db->transStatus()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Errore durante il salvataggio del prezzo.'], 500);
        }

        if ($isLoad) {
            $netPrice = $price * (1 - abs((float) ($detail['discount'] ?? 0)) / 100);
            if ($netPrice > 0 && !empty($detail['id_product'])) {
                (new \FairyBus\Models\FbProductModel())->update((int) $detail['id_product'], ['wholesale_price' => $netPrice]);
            }
        }

        return $this->jsonResponse(['success' => true, 'price' => $price, 'cascaded' => $cascaded, 'detached' => $detached ?? false]);
    }

    /**
     * Direzione del movimento di magazzino: +1 per i carichi (fornitore),
     * -1 per gli scarichi (cliente).
     */
    private function stockDirection(array $document): int
    {
        return !empty($document['id_customer']) ? -1 : 1;
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    private function validateDocument(): array|ResponseInterface
    {
        $rules = [
            'number' => 'permit_empty|max_length[255]',
            'date' => 'permit_empty|valid_date',
            'id_supplier' => 'permit_empty|integer',
            'id_customer' => 'permit_empty|integer',
            'id_vehicle' => 'permit_empty|integer',
            'type' => 'permit_empty|is_natural|is_not_unique[fb_type_document.id]',
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
            'type' => $this->request->getPost('type') !== '' && $this->request->getPost('type') !== null
                ? (int) $this->request->getPost('type')
                : \FairyBus\Models\FbTypeDocumentModel::TYPE_DEFAULT,
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
        ];
    }

}
