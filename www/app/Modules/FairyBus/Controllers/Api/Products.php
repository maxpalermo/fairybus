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

        $db = \Config\Database::connect();
        $rows = $db->table('fb_document_detail dd')
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

        // Residuo per singola riga di carico: caricato meno gli scarichi
        // gia' agganciati via id_load (stessa logica di lots-detail).
        $loadRows = $this->lotLoadRows($id); // ASC per data
        $linkedQty = [];
        foreach ($this->lotOutRows($id) as $o) {
            if ($o['id_load'] !== null) {
                $lid = (int) $o['id_load'];
                $linkedQty[$lid] = ($linkedQty[$lid] ?? 0) + $o['qty'];
            }
        }
        $residuoByLoad = [];
        foreach ($loadRows as $l) {
            $residuoByLoad[(int) $l['id_document_detail']] = abs((float) $l['quantity']) - ($linkedQty[(int) $l['id_document_detail']] ?? 0);
        }
        foreach ($rows as &$r) {
            $r['residuo'] = $residuoByLoad[(int) $r['id_document_detail']] ?? null;
        }
        unset($r);

        $bySupplier = [];
        foreach ($rows as $row) {
            $sid = (int) ($row['id_supplier'] ?? 0);
            if (!isset($bySupplier[$sid])) {
                $bySupplier[$sid] = $row;
            }
        }

        // Lotti residui per prezzo (stessa logica del picker "per prezzo
        // d'acquisto"): a ogni bucket si aggancia il carico FIFO con
        // residuo > 0 a quel prezzo — e' il carico a cui lo scarico verra'
        // collegato (id_load). Fallback: il carico piu' recente a quel prezzo.
        $stockQty = (float) ($db->table('fb_stock')->select('quantity')->where('id_product', $id)->get()->getRowArray()['quantity'] ?? 0);
        $loadsAsc = array_map(
            static fn(array $r): array => ['price' => $r['price'], 'qty' => abs((float) $r['quantity'])],
            array_reverse($rows),
        );
        $lots = [];
        foreach ($this->priceLots($loadsAsc, $this->stockOutMoves($id)[$id] ?? [], $stockQty) as $lot) {
            if ($lot['qty'] <= 0.0001) {
                continue;
            }
            $target = null;
            foreach ($loadRows as $l) {
                if (abs((float) $l['price'] - $lot['price']) < 0.0001 && ($residuoByLoad[(int) $l['id_document_detail']] ?? 0) > 0.0001) {
                    $target = $l;
                    break;
                }
            }
            if ($target === null) {
                foreach ($rows as $r) {
                    if (abs((float) $r['price'] - $lot['price']) < 0.0001) {
                        $target = $r;
                        break;
                    }
                }
            }
            if ($target !== null) {
                $lot['id_load'] = (int) $target['id_document_detail'];
                $lot['supplier_name'] = $target['supplier_name'] ?? null;
                $lot['date'] = $target['date'] ?? null;
                $lot['doc_number'] = $target['doc_number'] ?? null;
                $lot['discount'] = (float) ($target['discount'] ?? 0);
                $lot['vat_rate'] = isset($target['vat_rate']) ? (float) $target['vat_rate'] : null;
                $lot['load_qty'] = abs((float) ($target['quantity'] ?? 0));
                $lot['load_residuo'] = $residuoByLoad[(int) $target['id_document_detail']] ?? null;
            }
            $lots[] = $lot;
        }

        return $this->jsonResponse([
            'success' => true,
            'last' => $rows[0] ?? null,
            'suppliers' => array_values($bySupplier),
            'history' => $rows,
            'lots' => $lots,
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
            // copie conto terzi di righe manutenzione: non sono movimenti stock
            ->where('dd.id_maintenance IS NULL', null, false)
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
        if ($dupError = $this->rejectDuplicateSku()) {
            return $dupError;
        }

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

        if ($dupError = $this->rejectDuplicateSku($id)) {
            return $dupError;
        }

        $data = [
            'id_category' => $this->nullIfEmpty('id_category'),
            'id_brand' => $this->nullIfEmpty('id_brand'),
            'sku' => $this->request->getPost('sku'),
            'name' => $this->request->getPost('name'),
            'active' => (int) ($this->request->getPost('active') ?? 1),
            'price' => (float) ($this->request->getPost('price') ?? 0),
            'wholesale_price' => (float) ($this->request->getPost('wholesale_price') ?? 0),
            'tax_rate' => (float) ($this->request->getPost('tax_rate') ?? 0),
        ];

        // id_alias viene aggiornato solo se il campo e' presente nel POST:
        // il form di modifica di un alias non lo include e non deve
        // azzerare il collegamento al prodotto principale.
        if ($this->request->getPost('id_alias') !== null) {
            $data['id_alias'] = $this->nullIfEmpty('id_alias');
        }

        $model->update($id, $data);

        $unit = $this->request->getPost('unit');
        if ($unit !== null && $unit !== '') {
            (new FbStockModel())->setUnit($id, (int) $unit);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Rifiuta un SKU già assegnato a un altro prodotto (confronto case-insensitive,
     * garantito dalla collation utf8mb4_general_ci). SKU vuoto ammesso.
     */
    private function rejectDuplicateSku(?int $excludeId = null): ?ResponseInterface
    {
        $sku = trim((string) $this->request->getPost('sku'));
        if ($sku === '') {
            return null;
        }

        $builder = \Config\Database::connect()->table('fb_product')->where('sku', $sku);
        if ($excludeId !== null) {
            $builder->where('id_product !=', $excludeId);
        }
        if ($builder->countAllResults() === 0) {
            return null;
        }

        return $this->jsonResponse([
            'success' => false,
            'error' => "Il codice '{$sku}' è già assegnato a un altro articolo.",
        ], 422);
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

        // Eliminazione bloccata se il prodotto e' referenziato: righe
        // documento/manutenzione, alias di altri prodotti o giacenza con
        // dati. Una riga fb_stock completamente a zero viene invece
        // eliminata in cascata insieme al prodotto.
        $db = \Config\Database::connect();
        $stock = $db->table('fb_stock')->where('id_product', $id)->get()->getRowArray();
        $docRows = (int) $db->table('fb_document_detail')->where('id_product', $id)->countAllResults();
        $maintRows = (int) $db->table('fb_maintenance_detail')->where('id_product', $id)->countAllResults();
        $aliases = (int) $db->table('fb_product')->where('id_alias', $id)->countAllResults();

        $reasons = [];
        if ($docRows > 0) {
            $reasons[] = "{$docRows} righe documento";
        }
        if ($maintRows > 0) {
            $reasons[] = "{$maintRows} righe manutenzione";
        }
        if ($aliases > 0) {
            $reasons[] = "alias di {$aliases} prodotti";
        }

        $emptyStock = false;
        if ($stock !== null) {
            $qty = (float) $stock['quantity'];
            if ($qty != 0.0 || (float) $stock['inputs'] != 0.0 || (float) $stock['outputs'] != 0.0) {
                $reasons[] = 'giacenza ' . rtrim(rtrim(number_format($qty, 3, ',', ''), '0'), ',');
            } else {
                $emptyStock = true;
            }
        }

        if ($reasons !== []) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Prodotto non eliminabile: presente in ' . implode(', ', $reasons) . '. Disattivalo invece di eliminarlo.',
            ], 422);
        }

        $db->transStart();
        if ($emptyStock) {
            $db->table('fb_stock')->where('id_stock', (int) $stock['id_stock'])->delete();
        }
        $model->delete($id);
        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->jsonResponse(['success' => false, 'error' => "Errore durante l'eliminazione."], 500);
        }

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

    /**
     * Collega un prodotto esistente non associato come alias di un
     * prodotto radice (usato dal tab "Non associati" del dialog).
     */
    public function attachAlias(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbProductModel();
        $product = $model->find($id);
        if ($product === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        $rootId = (int) $this->request->getPost('id_root');
        $root = $model->find($rootId);
        if ($root === null || !empty($root['id_alias'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto principale non valido.'], 422);
        }

        if ($id === $rootId) {
            return $this->jsonResponse(['success' => false, 'error' => 'Un prodotto non può essere alias di se stesso.'], 422);
        }

        if (!empty($product['id_alias'])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Il prodotto è già un alias.'], 422);
        }

        $db = \Config\Database::connect();
        $children = (int) $db->table('fb_product')->where('id_alias', $id)->countAllResults();
        if ($children > 0) {
            return $this->jsonResponse(['success' => false, 'error' => "Il prodotto è già principale di {$children} alias."], 422);
        }

        $model->update($id, ['id_alias' => $rootId]);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Allinea i nomi dei prodotti alias a quello del prodotto principale.
     * Esegue un batch di `limit` righe per chiamata: il frontend la invoca
     * in loop finche' updated non torna 0.
     */
    public function alignAliasNames(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $limit = max(1, min(2000, (int) ($this->request->getPost('limit') ?: 500)));
        $db = \Config\Database::connect();
        $join = 'JOIN fb_product m ON m.id_product = a.id_alias WHERE a.name <> m.name';

        $pending = (int) $db->query("SELECT COUNT(*) AS c FROM fb_product a {$join}")->getRow('c');

        $updated = 0;
        if ($pending > 0) {
            $ids = array_column(
                $db->query("SELECT a.id_product FROM fb_product a {$join} LIMIT ?", [$limit])->getResultArray(),
                'id_product'
            );
            if ($ids !== []) {
                $in = implode(',', array_map('intval', $ids));
                $db->query(
                    "UPDATE fb_product a
                     JOIN fb_product m ON m.id_product = a.id_alias
                        SET a.name = m.name
                      WHERE a.id_product IN ({$in})"
                );
                $updated = $db->affectedRows();
            }
        }

        return $this->jsonResponse(['success' => true, 'pending' => $pending, 'updated' => $updated]);
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
            ->where('dd.id_product IS NOT NULL', null, false)
            // righe copiate da schede manutenzione (conto terzi): lo scarico
            // e' gia' conteggiato da fb_maintenance_detail, qui non muove stock
            ->where('dd.id_maintenance IS NULL', null, false);
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

        // la giacenza non coperta dai movimenti va nel lotto all'ultimo
        // prezzo di carico: si somma al bucket esistente, non lo duplica
        $uncovered = $stockQty - array_sum($residual);
        if ($uncovered > 0.0001) {
            $add($lastPrice, $uncovered);
        }

        $result = [];
        foreach ($order as $key) {
            if (abs($residual[$key]) > 0.0001) {
                $result[] = ['price' => (float) $key, 'qty' => $residual[$key]];
            }
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
            $net = (float) $r['price'] * (1 - abs((float) ($r['discount'] ?? 0)) / 100);
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

    /**
     * Carichi e scarichi di un prodotto con residuo per carico e stato di
     * associazione: ogni scarico e' agganciato in modo persistente al suo
     * carico tramite id_load; il residuo del carico e'
     * quantita' caricata meno la somma degli scarichi agganciati.
     * Uno scarico senza id_load e' "non associato" (anomalo da riallineare).
     * GET api/products/{id}/lots-detail
     */
    public function lotDetail(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $product = $db->table('fb_product')
            ->select('id_product, sku, name')->where('id_product', $id)->get()->getRowArray();
        if ($product === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        $loads = $this->lotLoadRows($id);
        $outs = $this->lotOutRows($id);

        // residuo esatto per carico e associazione solo verso i carichi
        // di questo prodotto (un id_load orfano conta come non associato)
        $loadIds = [];
        foreach ($loads as $l) {
            $loadIds[(int) $l['id_document_detail']] = true;
        }
        $linkedQty = [];
        $associated = [];
        $unassociated = [];
        foreach ($outs as $o) {
            unset($o['id_maintenance']);
            if ($o['id_load'] !== null && isset($loadIds[$o['id_load']])) {
                $linkedQty[$o['id_load']] = ($linkedQty[$o['id_load']] ?? 0) + $o['qty'];
                $o['associated'] = true;
                $associated[] = $o;
            } else {
                $o['associated'] = false;
                $unassociated[] = $o;
            }
        }
        foreach ($loads as &$l) {
            $l['qty'] = abs((float) $l['quantity']);
            unset($l['quantity']);
            $l['residuo'] = $l['qty'] - ($linkedQty[(int) $l['id_document_detail']] ?? 0);
        }
        unset($l);

        return $this->jsonResponse([
            'success' => true,
            'product' => $product,
            'loads' => $loads,
            'outs_associated' => $associated,
            'outs_unassociated' => $unassociated,
        ]);
    }

    /**
     * Riallinea gli scarichi selezionati al carico scelto: riscrive price
     * e id_load su fb_maintenance_detail (piu' il prezzo delle eventuali
     * righe copiate nei documenti conto terzi) o su fb_document_detail.
     * POST api/products/{id}/realign — body: moves JSON [{source,id_row}], load_id
     * detach=1 imposta price=0.00 e id_load NULL (sgancio).
     */
    public function realign(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        if ($db->table('fb_product')->where('id_product', $id)->countAllResults() === 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        // detach=1: sgancia gli scarichi dal carico impostando prezzo 0.00
        $detach = $this->request->getPost('detach') === '1';
        $idLoad = (int) $this->request->getPost('load_id');
        $moves = json_decode((string) $this->request->getPost('moves'), true);
        if (!is_array($moves) || $moves === []) {
            return $this->jsonResponse(['success' => false, 'error' => 'Nessun movimento selezionato.'], 422);
        }

        $price = 0.0;
        if (!$detach) {
            // il carico target deve essere una riga di carico reale del prodotto
            $load = $db->table('fb_document_detail dd')
                ->select('dd.price')
                ->join('fb_document d', 'd.id_document = dd.id_document')
                ->where('d.id_customer IS NULL', null, false)
                ->where('dd.id_maintenance IS NULL', null, false)
                ->where('dd.id_product', $id)
                ->where('dd.id_document_detail', $idLoad)
                ->get()->getRowArray();
            if ($load === null) {
                return $this->jsonResponse(['success' => false, 'error' => 'Il carico indicato non appartiene a questo prodotto.'], 422);
            }
            $price = (float) $load['price'];
        }
        $idLoadValue = $detach ? null : $idLoad;

        $now = date('Y-m-d H:i:s');
        $updated = 0;
        $db->transStart();
        foreach ($moves as $m) {
            $idRow = (int) ($m['id_row'] ?? 0);
            $source = (string) ($m['source'] ?? '');
            if ($idRow <= 0) {
                continue;
            }

            if ($source === 'maintenance') {
                $row = $db->table('fb_maintenance_detail')
                    ->where('id_maintenance_detail', $idRow)->where('id_product', $id)
                    ->get()->getRowArray();
                if ($row === null) {
                    continue;
                }
                $db->table('fb_maintenance_detail')->where('id_maintenance_detail', $idRow)
                    ->update(['price' => $price, 'id_load' => $idLoadValue, 'date_upd' => $now]);
                // righe copiate nello scarico conto terzi: stesso prezzo
                $db->table('fb_document_detail')
                    ->where('id_maintenance', (int) $row['id_maintenance'])
                    ->where('id_product', $id)
                    ->where('ABS(price - ' . (float) $row['price'] . ') < 0.0001', null, false)
                    ->update(['price' => $price, 'date_upd' => $now]);
                $updated++;
            } elseif ($source === 'document') {
                $db->table('fb_document_detail')
                    ->where('id_document_detail', $idRow)->where('id_product', $id)
                    ->where('id_maintenance IS NULL', null, false)
                    ->update(['price' => $price, 'id_load' => $idLoadValue, 'date_upd' => $now]);
                $updated++;
            }
        }
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Errore durante il riallineamento.'], 500);
        }

        return $this->jsonResponse(['success' => true, 'updated' => $updated]);
    }

    /**
     * Aggiorna la quantita' di uno scarico (tool riallineo): riscrive
     * quantity conservando il segno originale, allinea le eventuali righe
     * copiate nei documenti conto terzi e storna la differenza a magazzino
     * (solo per le righe non legacy, che hanno gia' movimentato lo stock).
     * POST api/products/{id}/out-qty — source, id_row, qty
     */
    public function updateOutQty(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        if ($db->table('fb_product')->where('id_product', $id)->countAllResults() === 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        $source = (string) $this->request->getPost('source');
        $idRow = (int) $this->request->getPost('id_row');
        $qty = abs((float) $this->request->getPost('qty'));
        if ($idRow <= 0 || !in_array($source, ['maintenance', 'document'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Movimento non valido.'], 422);
        }
        if ($qty <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'La quantità deve essere maggiore di zero. Per azzerare elimina il movimento.'], 422);
        }

        $row = $this->findOutRow($db, $source, $idRow, $id);
        if ($row === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Movimento non trovato.'], 404);
        }

        $signed = (float) $row['quantity'] < 0 ? -$qty : $qty;
        $now = date('Y-m-d H:i:s');
        $db->transStart();

        if ($source === 'maintenance') {
            $db->table('fb_maintenance_detail')->where('id_maintenance_detail', $idRow)
                ->update(['quantity' => $signed, 'date_upd' => $now]);
            // righe copiate nello scarico conto terzi: stessa quantita'
            $db->table('fb_document_detail')
                ->where('id_maintenance', (int) $row['id_maintenance'])
                ->where('id_product', $id)
                ->update(['quantity' => $signed, 'date_upd' => $now]);
        } else {
            $db->table('fb_document_detail')->where('id_document_detail', $idRow)
                ->update(['quantity' => $signed, 'date_upd' => $now]);
        }

        // storno differenza: lo scarico e' sempre 'out' -> torna in giacenza
        if (empty($row['legacy_id'])) {
            $diff = abs((float) $row['quantity']) - $qty;
            $stock = new \FairyBus\Models\FbStockModel();
            if ($diff > 0) {
                $stock->loadStock($id, $diff);
            } elseif ($diff < 0) {
                $stock->unloadStock($id, -$diff);
            }
        }
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Errore durante l\'aggiornamento.'], 500);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Elimina uno scarico (tool riallineo): rimuove la riga di movimento,
     * la manodopera collegata (fb_maintenance_task) e le righe copiate nei
     * documenti conto terzi. La quantita' rientra in giacenza solo per le
     * righe non legacy (quelle legacy erano gia' conteggiate).
     * POST api/products/{id}/out-delete — source, id_row
     */
    public function deleteOut(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $source = (string) $this->request->getPost('source');
        $idRow = (int) $this->request->getPost('id_row');
        if ($idRow <= 0 || !in_array($source, ['maintenance', 'document'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Movimento non valido.'], 422);
        }

        $row = $this->findOutRow($db, $source, $idRow, $id);
        if ($row === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Movimento non trovato.'], 404);
        }

        $db->transStart();
        if ($source === 'maintenance') {
            // manodopera associata al ricambio
            $db->table('fb_maintenance_task')->where('id_maintenance_detail', $idRow)->delete();
            $db->table('fb_maintenance_detail')->where('id_maintenance_detail', $idRow)->delete();
            // righe copiate nello scarico conto terzi
            $db->table('fb_document_detail')
                ->where('id_maintenance', (int) $row['id_maintenance'])
                ->where('id_product', $id)
                ->delete();
        } else {
            $db->table('fb_document_detail')->where('id_document_detail', $idRow)->delete();
        }

        if (empty($row['legacy_id'])) {
            (new \FairyBus\Models\FbStockModel())->loadStock($id, (float) $row['quantity']);
        }
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->jsonResponse(['success' => false, 'error' => 'Errore durante l\'eliminazione.'], 500);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Riga di scarico per il tool riallineo: maintenance_detail o
     * document_detail (solo righe cliente non copiate da manutenzione).
     *
     * @return array<string, mixed>|null
     */
    private function findOutRow(\CodeIgniter\Database\BaseConnection $db, string $source, int $idRow, int $idProduct): ?array
    {
        if ($source === 'maintenance') {
            $row = $db->table('fb_maintenance_detail')
                ->where('id_maintenance_detail', $idRow)->where('id_product', $idProduct)
                ->get()->getRowArray();
        } else {
            $row = $db->table('fb_document_detail')
                ->where('id_document_detail', $idRow)->where('id_product', $idProduct)
                ->where('id_maintenance IS NULL', null, false)
                ->get()->getRowArray();
        }

        return $row !== null ? $row : null;
    }

    /**
     * Righe di carico del prodotto (documenti di fornitura) in ordine
     * cronologico, con residuo inizializzato alla quantita' caricata.
     *
     * @return list<array<string, mixed>>
     */
    private function lotLoadRows(int $id): array
    {
        return \Config\Database::connect()->table('fb_document_detail dd')
            ->select('dd.id_document_detail, dd.quantity, dd.price, dd.discount,
                d.date, d.number AS doc_number, s.company AS supplier_name')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->join('fb_supplier s', 's.id_supplier = d.id_supplier', 'left')
            ->where('d.id_customer IS NULL', null, false)
            ->where('dd.id_maintenance IS NULL', null, false)
            ->where('dd.id_product', $id)
            ->orderBy('d.date', 'ASC')
            ->orderBy('d.id_document', 'ASC')
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()->getResultArray();
    }

    /**
     * Scarichi reali del prodotto in ordine cronologico: righe di documenti
     * cliente (escluse le copie conto terzi) e righe manutenzione.
     *
     * @return list<array<string, mixed>>
     */
    private function lotOutRows(int $id): array
    {
        $db = \Config\Database::connect();
        $outs = [];

        $docs = $db->table('fb_document_detail dd')
            ->select('dd.id_document_detail AS id_row, dd.quantity, dd.price, dd.id_load, d.date, d.number AS doc_number, d.id_document, c.company AS subject')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->join('fb_customer c', 'c.id_customer = d.id_customer', 'left')
            ->where('d.id_customer IS NOT NULL', null, false)
            ->where('dd.id_maintenance IS NULL', null, false)
            ->where('dd.id_product', $id)
            ->orderBy('d.date', 'ASC')
            ->orderBy('d.id_document', 'ASC')
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()->getResultArray();
        foreach ($docs as $r) {
            $outs[] = [
                'source' => 'document',
                'id_row' => (int) $r['id_row'],
                'ref_id' => (int) $r['id_document'],
                'subject' => $r['subject'],
                'date' => $r['date'],
                'qty' => abs((float) $r['quantity']),
                'price' => (float) $r['price'],
                'id_load' => $r['id_load'] !== null ? (int) $r['id_load'] : null,
                'doc_label' => $r['doc_number'] ?: 'documento',
            ];
        }

        $maint = $db->table('fb_maintenance_detail md')
            ->select('md.id_maintenance_detail AS id_row, md.id_maintenance, md.quantity, md.price, md.id_load, m.date, v.plate AS subject')
            ->join('fb_maintenance m', 'm.id_maintenance = md.id_maintenance')
            ->join('fb_vehicle v', 'v.id_vehicle = m.id_vehicle', 'left')
            ->where('md.id_product', $id)
            ->orderBy('m.date', 'ASC')
            ->orderBy('md.id_maintenance_detail', 'ASC')
            ->get()->getResultArray();
        foreach ($maint as $r) {
            $outs[] = [
                'source' => 'maintenance',
                'id_row' => (int) $r['id_row'],
                'ref_id' => (int) $r['id_maintenance'],
                'id_maintenance' => (int) $r['id_maintenance'],
                'subject' => $r['subject'],
                'date' => $r['date'],
                'qty' => abs((float) $r['quantity']),
                'price' => (float) $r['price'],
                'id_load' => $r['id_load'] !== null ? (int) $r['id_load'] : null,
                'doc_label' => '#' . $r['id_maintenance'],
            ];
        }

        usort($outs, static fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $outs;
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

        if ($dupError = $this->rejectDuplicateSku()) {
            return $dupError;
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


    private function nullIfEmpty(string $field): ?int
    {
        $value = $this->request->getPost($field);

        return ($value === null || $value === '' || $value === '0') ? null : (int) $value;
    }
}
