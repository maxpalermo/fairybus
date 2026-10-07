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
use FairyBus\Models\FbCategoryModel;
use FairyBus\Models\FbProductModel;
use FairyBus\Models\FbStockModel;

class Products extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();

        return $this->jsonResponse([
            'success' => true,
            'rows' => $model->listAll(),
        ]);
    }

    /** Statistiche globali prodotti per la toolbar KPI. */
    public function summary(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $row = $db->table('fb_product')
            ->select('COUNT(*) AS total', false)
            ->select('SUM(id_alias IS NULL) AS roots', false)
            ->select('SUM(id_alias IS NOT NULL) AS aliases', false)
            ->select('SUM(active = 1) AS active', false)
            ->select('SUM(active = 0) AS inactive', false)
            ->select('SUM(id_category IS NULL) AS no_category', false)
            ->select('COUNT(DISTINCT id_brand) AS brands', false)
            ->select('COUNT(DISTINCT id_alias) AS with_alias', false)
            ->select('SUM(s.notification_limit IS NOT NULL) AS alerts', false)
            ->select('SUM(s.notification_limit IS NOT NULL AND s.quantity <= s.notification_limit) AS low_stock', false)
            ->join('fb_stock s', 's.id_product = fb_product.id_product', 'left')
            ->get()
            ->getRowArray() ?? [];

        return $this->jsonResponse(['success' => true, 'summary' => array_map('intval', $row)]);
    }

    /**
     * Storico acquisti di un prodotto: ultimo acquisto complessivo e
     * ultimo acquisto per ciascun fornitore (dai documenti di carico).
     */
    public function purchases(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rows = \Config\Database::connect()->table('fb_document_detail dd')
            ->select('dd.id_document_detail, dd.quantity, dd.price, dd.discount, dd.vat_rate,
                d.number AS doc_number, d.date, d.id_supplier, s.company AS supplier_name')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->join('fb_supplier s', 's.id_supplier = d.id_supplier', 'left')
            ->where('dd.id_product', $id)
            ->where('d.id_supplier IS NOT NULL')
            ->orderBy('d.date', 'DESC')
            ->orderBy('d.id_document', 'DESC')
            ->get()
            ->getResultArray();

        $bySupplier = [];
        foreach ($rows as $row) {
            $sid = (int) ($row['id_supplier'] ?? 0);
            if (!isset($bySupplier[$sid])) {
                $bySupplier[$sid] = $row;
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'last' => $rows[0] ?? null,
            'suppliers' => array_values($bySupplier),
            'history' => $rows,
        ]);
    }

    /**
     * Storico movimenti di magazzino di un prodotto: carichi e scarichi da
     * documenti (fornitore/cliente) + scarichi verso veicoli (manutenzioni).
     */
    public function movements(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $rows = [];

        $docs = $db->table('fb_document_detail dd')
            ->select('d.date, d.number AS doc_number, d.id_supplier, d.id_document,
                dd.quantity, dd.price, dd.discount, dd.vat_rate,
                s.company AS supplier_name, c.company AS customer_name')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->join('fb_supplier s', 's.id_supplier = d.id_supplier', 'left')
            ->join('fb_customer c', 'c.id_customer = d.id_customer', 'left')
            ->where('dd.id_product', $id)
            ->get()
            ->getResultArray();

        foreach ($docs as $r) {
            $isIn = $r['id_supplier'] !== null;
            $rows[] = [
                'date' => $r['date'],
                'kind' => $isIn ? 'in' : 'out',
                'subject_type' => $isIn ? 'Fornitore' : 'Cliente',
                'subject_name' => $isIn ? $r['supplier_name'] : $r['customer_name'],
                'doc_label' => $r['doc_number'],
                'source' => 'document',
                'id_ref' => (int) $r['id_document'],
                'quantity' => (float) $r['quantity'],
                'price' => (float) $r['price'],
                'discount' => (float) $r['discount'],
                'vat_rate' => $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null,
            ];
        }

        $maint = $db->table('fb_maintenance_detail md')
            ->select('m.date, m.id_maintenance, md.quantity, md.price, md.discount, md.vat_rate, v.plate')
            ->join('fb_maintenance m', 'm.id_maintenance = md.id_maintenance')
            ->join('fb_vehicle v', 'v.id_vehicle = m.id_vehicle', 'left')
            ->where('md.id_product', $id)
            ->get()
            ->getResultArray();

        foreach ($maint as $r) {
            $rows[] = [
                'date' => $r['date'],
                'kind' => 'out',
                'subject_type' => 'Veicolo',
                'subject_name' => $r['plate'],
                'doc_label' => '#' . $r['id_maintenance'],
                'source' => 'maintenance',
                'id_ref' => (int) $r['id_maintenance'],
                'quantity' => (float) $r['quantity'],
                'price' => (float) $r['price'],
                'discount' => (float) $r['discount'],
                'vat_rate' => $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null,
            ];
        }

        usort($rows, static fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        return $this->jsonResponse(['success' => true, 'rows' => $rows]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|max_length[255]',
            'sku' => 'max_length[255]',
            'id_category' => 'permit_empty|integer',
            'id_brand' => 'permit_empty|integer',
            'id_alias' => 'permit_empty|integer',
            'active' => 'integer',
            'price' => 'decimal',
            'wholesale_price' => 'decimal',
            'tax_rate' => 'decimal',
            'unit' => 'permit_empty|integer',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbProductModel();
        $id = $model->insert([
            'id_category' => $this->nullIfEmpty('id_category'),
            'id_alias' => $this->nullIfEmpty('id_alias'),
            'id_brand' => $this->nullIfEmpty('id_brand'),
            'sku' => $this->request->getPost('sku'),
            'name' => $this->request->getPost('name'),
            'active' => (int) ($this->request->getPost('active') ?? 1),
            'price' => (float) ($this->request->getPost('price') ?? 0),
            'wholesale_price' => (float) ($this->request->getPost('wholesale_price') ?? 0),
            'tax_rate' => (float) ($this->request->getPost('tax_rate') ?? 0),
        ]);

        $unit = $this->request->getPost('unit');
        if ($unit !== null && $unit !== '') {
            (new FbStockModel())->setUnit((int) $id, (int) $unit);
        }

        return $this->jsonResponse(['success' => true, 'id_product' => (int) $id], 201);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'name' => 'required|max_length[255]',
            'sku' => 'max_length[255]',
            'id_category' => 'permit_empty|integer',
            'id_brand' => 'permit_empty|integer',
            'id_alias' => 'permit_empty|integer',
            'active' => 'integer',
            'price' => 'decimal',
            'wholesale_price' => 'decimal',
            'tax_rate' => 'decimal',
            'unit' => 'permit_empty|integer',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $model = new FbProductModel();
        $product = $model->find($id);
        if ($product === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        $model->update($id, [
            'id_category' => $this->nullIfEmpty('id_category'),
            'id_alias' => $this->nullIfEmpty('id_alias'),
            'id_brand' => $this->nullIfEmpty('id_brand'),
            'sku' => $this->request->getPost('sku'),
            'name' => $this->request->getPost('name'),
            'active' => (int) ($this->request->getPost('active') ?? 1),
            'price' => (float) ($this->request->getPost('price') ?? 0),
            'wholesale_price' => (float) ($this->request->getPost('wholesale_price') ?? 0),
            'tax_rate' => (float) ($this->request->getPost('tax_rate') ?? 0),
        ]);

        $unit = $this->request->getPost('unit');
        if ($unit !== null && $unit !== '') {
            (new FbStockModel())->setUnit($id, (int) $unit);
        }

        return $this->jsonResponse(['success' => true]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    public function detachAlias(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        $product = $model->find($id);
        if ($product === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        if (empty($product['id_alias'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Il prodotto non è un alias.'], 422);
        }

        $model->update($id, ['id_alias' => null]);

        return $this->jsonResponse(['success' => true]);
    }

    public function toggleActive(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        if (!$model->toggleActive($id)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 422);
        }

        return $this->jsonResponse(['success' => true]);
    }

    public function options(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        $products = $model
            ->select("id_product, name, sku, tax_rate, price, wholesale_price, (SELECT GROUP_CONCAT(a.sku SEPARATOR ' ') FROM fb_product a WHERE a.id_alias = fb_product.id_product) AS alias_skus, (SELECT s.quantity FROM fb_stock s WHERE s.id_product = fb_product.id_product LIMIT 1) AS stock_qty", false)
            ->where('id_alias', null)
            ->orderBy('name', 'ASC')
            ->findAll();
        foreach ($products as &$product) {
            $product['label'] = trim(($product['sku'] ? '[' . $product['sku'] . '] ' : '') . $product['name']);
        }

        // Prodotti alias (id_alias = prodotto radice): restituiti a parte così
        // l'autocomplete può mostrare tutta la famiglia e selezionare anche l'alias.
        $aliasProducts = $model
            ->select('id_product, name, sku, tax_rate, price, wholesale_price, id_alias, (SELECT s.quantity FROM fb_stock s WHERE s.id_product = fb_product.id_product LIMIT 1) AS stock_qty', false)
            ->where('id_alias IS NOT NULL')
            ->orderBy('name', 'ASC')
            ->findAll();
        foreach ($aliasProducts as &$product) {
            $product['label'] = trim(($product['sku'] ? '[' . $product['sku'] . '] ' : '') . $product['name']);
        }

        $response = [
            'success' => true,
            'products' => $products,
            'alias_products' => $aliasProducts,
            'brands' => (new FbBrandModel())->orderBy('name', 'ASC')->findAll(),
            'categories' => (new FbCategoryModel())->getOptions(),
        ];

        if ($this->request->getGet('lots') === '1') {
            $response['lot_products'] = $this->buildLotProducts($products, $aliasProducts);
        }

        return $this->jsonResponse($response);
    }

    /**
     * Espande i prodotti in "lotti" per prezzo d'acquisto: i carichi
     * (documenti senza cliente) si sommano per prezzo, gli scarichi si
     * sottraggono al prezzo registrato sul movimento. Giacenza residua
     * senza storico carichi → lotto sintetico all'ultimo prezzo noto.
     *
     * @param list<array<string,mixed>> $products
     * @param list<array<string,mixed>> $aliasProducts
     * @return list<array<string,mixed>>
     */
    private function buildLotProducts(array $products, array $aliasProducts): array
    {
        $db = \Config\Database::connect();

        $loads = $db->table('fb_document_detail dd')
            ->select('dd.id_product, dd.price, ABS(dd.quantity) AS qty')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->where('d.id_customer IS NULL', null, false)
            ->where('dd.id_product IS NOT NULL', null, false)
            ->orderBy('dd.id_product', 'ASC')
            ->orderBy('d.date', 'ASC')
            ->orderBy('d.id_document', 'ASC')
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()
            ->getResultArray();

        $qtyByProduct = [];
        foreach ($db->table('fb_stock')->select('id_product, quantity')->get()->getResultArray() as $s) {
            $qtyByProduct[(int) $s['id_product']] = (float) $s['quantity'];
        }

        $loadsByProduct = [];
        foreach ($loads as $l) {
            $loadsByProduct[(int) $l['id_product']][] = $l;
        }

        $outMovesByProduct = $this->stockOutMoves();
        $lotsByProduct = [];
        foreach ($loadsByProduct as $pid => $rows) {
            $lotsByProduct[$pid] = $this->priceLots($rows, $outMovesByProduct[$pid] ?? [], $qtyByProduct[$pid] ?? 0.0);
        }
        // Prodotti con scarichi ma senza carichi: emergono i lotti negativi
        foreach ($outMovesByProduct as $pid => $moves) {
            if (!isset($lotsByProduct[$pid])) {
                $lotsByProduct[$pid] = $this->priceLots([], $moves, $qtyByProduct[$pid] ?? 0.0);
            }
        }

        $out = [];
        foreach (array_merge($products, $aliasProducts) as $p) {
            $pid = (int) $p['id_product'];
            $lots = $lotsByProduct[$pid] ?? [];
            // Giacenza presente ma nessun carico registrato (es. legacy): lotto al listino
            if ($lots === [] && (float) ($p['stock_qty'] ?? 0) > 0) {
                $lots = [['price' => (float) ($p['price'] ?? 0), 'qty' => (float) $p['stock_qty']]];
            }
            foreach ($lots as $lot) {
                // Lotti azzerati o negativi: visibili nella scheda giacenza,
                // non selezionabili nel picker ricambi
                if ($lot['qty'] <= 0.0001) {
                    continue;
                }
                $entry = $p;
                $entry['lot_price'] = $lot['price'];
                $entry['lot_qty'] = $lot['qty'];
                $entry['stock_qty'] = $lot['qty'];
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * Scarichi reali di magazzino raggruppati per prodotto, ordinati per
     * data: scarichi verso clienti (documenti con id_customer) e scarichi
     * manutenzione — ognuno col prezzo registrato sulla riga.
     *
     * @return array<int, list<array{qty:float,price:float}>>
     */
    private function stockOutMoves(?int $idProduct = null): array
    {
        $db = \Config\Database::connect();
        $moves = [];

        $docs = $db->table('fb_document_detail dd')
            ->select('dd.id_product, ABS(dd.quantity) AS qty, dd.price, d.date')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->where('d.id_customer IS NOT NULL', null, false)
            ->where('dd.id_product IS NOT NULL', null, false);
        if ($idProduct !== null) {
            $docs->where('dd.id_product', $idProduct);
        }
        foreach ($docs->get()->getResultArray() as $r) {
            $moves[] = ['id_product' => (int) $r['id_product'], 'qty' => (float) $r['qty'], 'price' => (float) $r['price'], 'date' => (string) $r['date']];
        }

        $maint = $db->table('fb_maintenance_detail md')
            ->select('md.id_product, ABS(md.quantity) AS qty, md.price, m.date')
            ->join('fb_maintenance m', 'm.id_maintenance = md.id_maintenance')
            ->where('md.id_product IS NOT NULL', null, false);
        if ($idProduct !== null) {
            $maint->where('md.id_product', $idProduct);
        }
        foreach ($maint->get()->getResultArray() as $r) {
            $moves[] = ['id_product' => (int) $r['id_product'], 'qty' => (float) $r['qty'], 'price' => (float) $r['price'], 'date' => (string) $r['date']];
        }

        usort($moves, static fn($a, $b) => strcmp($a['date'], $b['date']));

        $byProduct = [];
        foreach ($moves as $m) {
            $byProduct[$m['id_product']][] = ['qty' => $m['qty'], 'price' => $m['price']];
        }
        return $byProduct;
    }

    /**
     * Giacenza per prezzo: i carichi si sommano per prezzo, gli scarichi
     * si sottraggono al prezzo con cui sono stati registrati. Uno scarico
     * a un prezzo mai caricato produce una voce negativa (segnala dati
     * incoerenti). La giacenza non coperta dai movimenti diventa un lotto
     * all'ultimo prezzo di carico.
     *
     * @param list<array{price:mixed,qty:mixed}> $loads carichi cronologici
     * @param list<array{qty:mixed,price:mixed}> $outMoves scarichi reali
     * @return list<array{price:float,qty:float}>
     */
    private function priceLots(array $loads, array $outMoves, float $stockQty): array
    {
        $residual = [];
        $order = [];
        $lastPrice = 0.0;
        $add = static function (float $price, float $qty) use (&$residual, &$order): void {
            $key = number_format($price, 4, '.', '');
            if (!array_key_exists($key, $residual)) {
                $order[] = $key;
                $residual[$key] = 0.0;
            }
            $residual[$key] += $qty;
        };

        foreach ($loads as $r) {
            $lastPrice = (float) $r['price'];
            $add($lastPrice, (float) $r['qty']);
        }
        foreach ($outMoves as $m) {
            $add((float) ($m['price'] ?? 0), -abs((float) $m['qty']));
        }

        $result = [];
        foreach ($order as $key) {
            if (abs($residual[$key]) > 0.0001) {
                $result[] = ['price' => (float) $key, 'qty' => $residual[$key]];
            }
        }
        $uncovered = $stockQty - array_sum(array_column($result, 'qty'));
        if ($uncovered > 0.0001) {
            $result[] = ['price' => $lastPrice, 'qty' => $uncovered];
        }
        return $result;
    }

    /**
     * Giacenza residua per prezzo d'acquisto di un singolo prodotto
     * (stessa logica FIFO di buildLotProducts, usata dalla scheda giacenza).
     */
    public function lots(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $product = $db->table('fb_product')->select('id_product, price')->where('id_product', $id)->get()->getRowArray();
        if (!$product) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato'], 404);
        }

        $loads = $db->table('fb_document_detail dd')
            ->select('dd.price, ABS(dd.quantity) AS qty, dd.discount, d.date, d.number AS doc_number, s.company AS supplier_name')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->join('fb_supplier s', 's.id_supplier = d.id_supplier', 'left')
            ->where('d.id_customer IS NULL', null, false)
            ->where('dd.id_product', $id)
            ->orderBy('d.date', 'ASC')
            ->orderBy('d.id_document', 'ASC')
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()
            ->getResultArray();

        $stock = $db->table('fb_stock')->select('quantity')->where('id_product', $id)->get()->getRowArray() ?: [];
        $lots = $this->priceLots($loads, $this->stockOutMoves($id)[$id] ?? [], (float) ($stock['quantity'] ?? 0));
        if ($lots === [] && (float) ($stock['quantity'] ?? 0) > 0) {
            $lots = [['price' => (float) $product['price'], 'qty' => (float) $stock['quantity']]];
        }

        // Ultimo e miglior prezzo d'acquisto (netto dello sconto riga)
        $lastPurchase = null;
        $bestPurchase = null;
        foreach ($loads as $r) {
            $net = (float) $r['price'] * (1 - (float) ($r['discount'] ?? 0) / 100);
            $entry = [
                'price' => $net,
                'list_price' => (float) $r['price'],
                'discount' => (float) ($r['discount'] ?? 0),
                'doc_number' => $r['doc_number'],
                'date' => $r['date'],
                'supplier' => $r['supplier_name'],
            ];
            $lastPurchase = $entry; // $loads è ordinato per data ASC
            if ($bestPurchase === null || $net < $bestPurchase['price']) {
                $bestPurchase = $entry;
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'stock_qty' => (float) ($stock['quantity'] ?? 0),
            'lots' => $lots,
            'last_purchase' => $lastPurchase,
            'best_purchase' => $bestPurchase,
        ]);
    }

    public function aliases(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();

        return $this->jsonResponse([
            'success' => true,
            'aliases' => $model->where('id_alias', $id)->orderBy('sku', 'ASC')->findAll(),
        ]);
    }

    public function createAlias(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        $root = $model->find($id);
        if ($root === null || $root['id_alias'] !== null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto originale non valido.'], 422);
        }

        $rules = [
            'id_brand' => 'permit_empty|integer',
            'sku' => 'required|max_length[255]',
            'price' => 'permit_empty|decimal',
            'wholesale_price' => 'permit_empty|decimal',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $price = $this->request->getPost('price');
        $wholesalePrice = $this->request->getPost('wholesale_price');

        $idAlias = $model->insert([
            'id_category' => $root['id_category'] ? (int) $root['id_category'] : null,
            'id_alias' => $id,
            'id_brand' => $this->nullIfEmpty('id_brand'),
            'sku' => $this->request->getPost('sku'),
            'name' => $root['name'],
            'active' => 1,
            'price' => (float) (($price !== null && $price !== '') ? $price : $root['price']),
            'wholesale_price' => (float) (($wholesalePrice !== null && $wholesalePrice !== '') ? $wholesalePrice : $root['wholesale_price']),
            'tax_rate' => (float) $root['tax_rate'],
        ]);

        return $this->jsonResponse(['success' => true, 'id_product' => (int) $idAlias], 201);
    }

    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        if (!in_array('product', LegacyDatabase::listTables($db), true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "product" non trovata.'], 422);
        }

        $availableColumns = LegacyDatabase::getFieldNames($db, 'product');
        $desiredColumns = [
            'id',
            'name',
            'sku',
            'brand',
            'alias_id',
            'category_id',
            'status',
            'created_date',
            'last_modified_date',
        ];
        $selectColumns = array_values(array_intersect($availableColumns, $desiredColumns));
        if ($selectColumns === [] || !in_array('id', $selectColumns, true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "product" non contiene colonne riconosciute.'], 422);
        }

        $rows = LegacyDatabase::withoutPrefix(
            $db,
            static fn(BaseConnection $db): array => $db->table('product')
                ->select(implode(',', $selectColumns))
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray()
        );

        $db->table('fb_product')->truncate();

        $brandModel = new FbBrandModel();
        $brandCache = [];

        // Mappatura legacy_id => nuovo id_product
        $idMap = [];

        $imported = 0;
        foreach ($rows as $row) {
            $idBrand = null;
            if (!empty($row['brand'])) {
                $brandName = (string) $row['brand'];
                if (!isset($brandCache[$brandName])) {
                    $brandCache[$brandName] = $brandModel->getOrCreate($brandName);
                }
                $idBrand = $brandCache[$brandName];
            }

            $idCategory = null;
            if (!empty($row['category_id'])) {
                $idCategory = (int) $row['category_id'];
            }

            $legacyId = (int) $row['id'];
            $data = [
                'legacy_id' => $legacyId,
                'id_category' => $idCategory,
                'id_alias' => null, // verrà aggiornato dopo
                'id_brand' => $idBrand,
                'sku' => !empty($row['sku']) ? (string) $row['sku'] : null,
                'name' => (string) ($row['name'] ?? ''),
                'active' => isset($row['status']) ? ((int) $row['status'] === 1 ? 1 : 0) : 1,
                'price' => 0.000000,
                'wholesale_price' => 0.000000,
                'tax_rate' => 0.00,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];

            $db->table('fb_product')->insert($data);
            $newId = (int) $db->insertID();
            $idMap[$legacyId] = $newId;
            $imported++;
        }

        // Rimappa id_alias usando legacy_id: legacy alias_id -> nuovo id_product
        $updated = 0;
        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $legacyAliasId = !empty($row['alias_id']) ? (int) $row['alias_id'] : 0;
            if ($legacyAliasId <= 0 || !isset($idMap[$legacyId]) || !isset($idMap[$legacyAliasId])) {
                continue;
            }

            $newAliasId = $idMap[$legacyAliasId];
            $db->table('fb_product')
                ->where('legacy_id', $legacyId)
                ->update(['id_alias' => $newAliasId]);
            $updated++;
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => "Importati {$imported} prodotti dalla tabella legacy product. Aggiornati {$updated} alias.",
            'imported' => $imported,
            'aliases_updated' => $updated,
        ]);
    }

    private function nullIfEmpty(string $field): ?int
    {
        $value = $this->request->getPost($field);

        return ($value === null || $value === '' || $value === '0') ? null : (int) $value;
    }
}
