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

        $model = new \FairyBus\Models\FbDocumentModel();
        $id = (int) $model->insert($validation + [
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonResponse(['success' => true, 'id' => $id]);
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
        // i movimenti importati dal legacy sono già compresi nella giacenza iniziale.
        $direction = $this->stockDirection($document ?? []);
        foreach ($detailModel->listByDocument($id) as $detail) {
            if (empty($detail['legacy_id'])) {
                $stock->adjustStock((int) ($detail['id_product'] ?? 0), -$direction * (float) $detail['quantity']);
            }
        }

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

        $model->update($id, [
            'id_invoice' => $idInvoice,
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonResponse(['success' => true]);
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
            'quantity' => (float) $this->request->getPost('quantity'),
            'price' => (float) ($this->request->getPost('price') ?: 0),
            'discount' => (float) ($this->request->getPost('discount') ?: 0),
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
        (new \FairyBus\Models\FbStockModel())->adjustStock((int) $row['id_product'], $direction * (float) $row['quantity']);

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

        // Le righe importate dal legacy non toccano lo stock: erano già
        // conteggiate nella giacenza iniziale importata da inventory.
        if (empty($detail['legacy_id'])) {
            $document = (new \FairyBus\Models\FbDocumentModel())->find((int) $detail['id_document']);
            $direction = $this->stockDirection($document ?? []);
            (new \FairyBus\Models\FbStockModel())->adjustStock((int) ($detail['id_product'] ?? 0), -$direction * (float) $detail['quantity']);
        }

        return $this->jsonResponse(['success' => true]);
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
            'type' => $this->request->getPost('type') !== '' && $this->request->getPost('type') !== null
                ? (int) $this->request->getPost('type')
                : \FairyBus\Models\FbTypeDocumentModel::TYPE_DEFAULT,
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
        ];
    }

    /**
     * Importa documenti da legacy:
     *  - invoice  -> fb_invoice
     *  - trade    -> fb_document (esclusi reference_class = 'maintenance', destinati a fb_maintenance)
     *  - movement -> fb_document_detail (solo movimenti dei documenti importati)
     */
    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $legacyTables = \FairyBus\Libraries\LegacyDatabase::listTables($db);

        foreach (['trade', 'movement', 'invoice', 'business_partner'] as $required) {
            if (!in_array($required, $legacyTables, true)) {
                return $this->jsonResponse(['success' => false, 'error' => "Tabella legacy \"{$required}\" non trovata."], 422);
            }
        }

        // --- Mappe di riferimento -------------------------------------------------

        // business_partner.id => role (1 = customer, 0 = supplier)
        $bpRoles = [];
        foreach (\FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('business_partner')->select('id, role')->get()->getResultArray();
        }) as $r) {
            $bpRoles[(int) $r['id']] = (int) $r['role'];
        }

        // fb_customer.legacy_id => id_customer, fb_supplier.legacy_id => id_supplier
        $customerMap = $this->legacyMap($db, 'fb_customer', 'id_customer');
        $supplierMap = $this->legacyMap($db, 'fb_supplier', 'id_supplier');

        $resolvePartner = static function (?int $bpId) use ($bpRoles, $customerMap, $supplierMap): array {
            if ($bpId === null || $bpId <= 0 || !isset($bpRoles[$bpId])) {
                return [null, null, false];
            }
            if ($bpRoles[$bpId] === 1) {
                $id = $customerMap[$bpId] ?? null;

                return [$id, null, $id !== null];
            }
            $id = $supplierMap[$bpId] ?? null;

            return [null, $id, $id !== null];
        };

        $db->table('fb_document_detail')->truncate();
        $db->table('fb_document')->truncate();
        $db->table('fb_invoice')->truncate();

        $report = ['invoices' => 0, 'documents' => 0, 'details' => 0, 'skipped' => 0];

        // --- Fatture (invoice -> fb_invoice) --------------------------------------

        $invoices = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('invoice')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $this->insertBatches($db, 'fb_invoice', $invoices, static function (array $row) use ($resolvePartner, &$report): ?array {
            [$idCustomer, $idSupplier] = $resolvePartner((int) ($row['business_partner_id'] ?? 0) ?: null);

            return [
                'legacy_id' => (int) $row['id'],
                'number' => $row['document_number'] !== '' && $row['document_number'] !== null ? (string) $row['document_number'] : null,
                'date' => !empty($row['date']) ? $row['date'] : null,
                'id_customer' => $idCustomer,
                'id_supplier' => $idSupplier,
                'collection_fee' => (float) ($row['collection_fee'] ?? 0),
                'deposit' => (float) ($row['deposit'] ?? 0),
                'transport_fee' => (float) ($row['transport_fee'] ?? 0),
                'type' => $row['type'] !== null ? (int) $row['type'] : null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['invoices']);

        // fb_invoice.legacy_id => id_invoice
        $invoiceMap = $this->legacyMap($db, 'fb_invoice', 'id_invoice');

        // --- Documenti (trade -> fb_document) -------------------------------------

        $trades = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('trade')
                ->where("(reference_class IS NULL OR reference_class != 'maintenance')", null, false)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
        });

        $this->insertBatches($db, 'fb_document', $trades, static function (array $row) use ($resolvePartner, $invoiceMap): ?array {
            [$idCustomer, $idSupplier] = $resolvePartner((int) ($row['business_partner_id'] ?? 0) ?: null);

            $legacyInvoiceId = (int) ($row['invoice_id'] ?? 0) ?: null;

            return [
                'legacy_id' => (int) $row['id'],
                'type' => 0, // fb_document_type: 0 = DDT
                'number' => $row['number'] !== '' && $row['number'] !== null ? (string) $row['number'] : null,
                'date' => !empty($row['date']) ? $row['date'] : null,
                'id_customer' => $idCustomer,
                'id_supplier' => $idSupplier,
                'id_invoice' => $legacyInvoiceId !== null ? ($invoiceMap[$legacyInvoiceId] ?? null) : null,
                'id_ddt' => null, // ddt_id ignorato
                'reference_class' => null,
                'reference_id' => null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['documents']);

        // fb_document.legacy_id (trade.id) => id_document
        $documentMap = $this->legacyMap($db, 'fb_document', 'id_document');

        // --- Dettagli (movement -> fb_document_detail) ----------------------------

        // product_list.id => product_id
        $productListMap = [];
        if (in_array('product_list', $legacyTables, true)) {
            foreach (\FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
                return $db->table('product_list')->select('id, product_id')->get()->getResultArray();
            }) as $r) {
                $productListMap[(int) $r['id']] = (int) $r['product_id'];
            }
        }

        // fb_product.legacy_id => id_product
        $productMap = $this->legacyMap($db, 'fb_product', 'id_product');

        // vatrate.id => [code, standard_rate]
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

        $this->insertBatches($db, 'fb_document_detail', $movements, static function (array $row) use ($documentMap, $productListMap, $productMap, $vatMap, &$report): ?array {
            $tradeId = (int) ($row['trade_id'] ?? 0);
            $idDocument = $documentMap[$tradeId] ?? null;
            if ($idDocument === null) {
                return null; // movimento di un trade maintenance/non importato
            }

            $legacyProductId = $productListMap[(int) ($row['product_list_id'] ?? 0)] ?? null;
            $idProduct = $legacyProductId !== null ? ($productMap[$legacyProductId] ?? null) : null;

            $vat = $vatMap[(int) ($row['vat_id'] ?? 0)] ?? null;

            return [
                'legacy_id' => (int) $row['id'],
                'id_document' => $idDocument,
                'id_product' => $idProduct,
                'quantity' => (float) ($row['quantity'] ?? 0),
                'price' => (float) ($row['price'] ?? 0),
                'discount' => (float) ($row['discount_or_inflation'] ?? 0),
                'lot' => !empty($row['lot']) ? (string) $row['lot'] : null,
                'vat_code' => $vat['code'] ?? null,
                'vat_rate' => $vat['rate'] ?? null,
                'type' => $row['type'] !== null ? (int) $row['type'] : null,
                'warehouse_id' => (int) ($row['warehouse_id'] ?? 0) ?: null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }, $report['details'], $report['skipped']);

        return $this->jsonResponse([
            'success' => true,
            'message' => sprintf(
                'Importate %d fatture, %d documenti e %d righe dettaglio (saltate %d righe maintenance/non collegate).',
                $report['invoices'],
                $report['documents'],
                $report['details'],
                $report['skipped'],
            ),
            'imported' => $report,
        ]);
    }

    /**
     * Mappa legacy_id => chiave primaria su una tabella fb_*.
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
     * Inserisce le righe in batch da 500, scartando quelle per cui $mapRow restituisce null.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function insertBatches(BaseConnection $db, string $table, array $rows, callable $mapRow, int &$imported, int &$skipped = 0): void
    {
        $batch = [];

        $flush = static function () use ($db, $table, &$batch, &$imported): void {
            if ($batch === []) {
                return;
            }
            $db->table($table)->insertBatch($batch);
            $imported += count($batch);
            $batch = [];
        };

        foreach ($rows as $row) {
            $mapped = $mapRow($row);
            if ($mapped === null) {
                $skipped++;
                continue;
            }
            $batch[] = $mapped;
            if (count($batch) >= self::BATCH_SIZE) {
                $flush();
            }
        }
        $flush();
    }
}
