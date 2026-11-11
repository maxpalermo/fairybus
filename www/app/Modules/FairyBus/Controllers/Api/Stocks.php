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
use FairyBus\Models\FbStockModel;

class Stocks extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbStockModel();

        return $this->jsonResponse([
            'success' => true,
            'rows' => $model->listAll(),
        ]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbStockModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Giacenza non trovata.'], 404);
        }

        $rules = [
            'quantity' => 'permit_empty|decimal',
            'unit' => 'permit_empty|integer|in_list[0,1,2]',
            'notification_limit' => 'permit_empty|decimal',
            'note' => 'permit_empty|max_length[255]',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $limit = $this->request->getPost('notification_limit');
        $unit = $this->request->getPost('unit');
        $quantity = $this->request->getPost('quantity');

        $model->update($id, [
            'quantity' => ($quantity === null || $quantity === '') ? 0 : (float) $quantity,
            'unit' => ($unit === null || $unit === '') ? 0 : (int) $unit,
            'notification_limit' => ($limit === null || $limit === '') ? null : (float) $limit,
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Upsert della giacenza per id_product: aggiorna la riga esistente o la
     * crea se il prodotto non ha ancora una giacenza.
     */
    public function updateByProduct(int $idProduct): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        if ($idProduct <= 0 || $db->table('fb_product')->where('id_product', $idProduct)->countAllResults() === 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Prodotto non trovato.'], 404);
        }

        $rules = [
            'quantity' => 'permit_empty|decimal',
            'unit' => 'permit_empty|integer|in_list[0,1,2]',
            'notification_limit' => 'permit_empty|decimal',
            'note' => 'permit_empty|max_length[255]',
        ];

        if (!$this->validate($rules)) {
            return $this->jsonResponse([
                'success' => false,
                'errors' => $this->validator->getErrors(),
            ], 422);
        }

        $limit = $this->request->getPost('notification_limit');
        $unit = $this->request->getPost('unit');
        $quantity = $this->request->getPost('quantity');

        $model = new FbStockModel();
        $data = [
            'quantity' => ($quantity === null || $quantity === '') ? 0 : (float) $quantity,
            'unit' => ($unit === null || $unit === '') ? 0 : (int) $unit,
            'notification_limit' => ($limit === null || $limit === '') ? null : (float) $limit,
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        $row = $model->where('id_product', $idProduct)->first();
        if ($row !== null) {
            $model->update((int) $row['id_stock'], $data);
            $idStock = (int) $row['id_stock'];
        } else {
            $idStock = (int) $model->insert($data + [
                'id_product' => $idProduct,
                'inputs' => $data['quantity'],
                'outputs' => 0,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->jsonResponse(['success' => true, 'id_stock' => $idStock]);
    }


    /**
     * Allineamento quantita' (tool Giacenze): step 'signs' normalizza il
     * segno dei movimenti (carichi positivi, scarichi negativi); step
     * 'count' restituisce i prodotti da ricalcolare; step 'stock'
     * riallinea fb_stock dai movimenti a blocchi (offset/limit) per il
     * progress del dialog.
     * POST api/stocks/align — step, offset, limit
     */
    public function align(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $step = (string) $this->request->getPost('step');

        if ($step === 'signs') {
            $db->transStart();
            // ricambi manutenzione: sempre scarico -> quantita' negativa
            $db->query('UPDATE fb_maintenance_detail SET quantity = -ABS(quantity) WHERE quantity > 0');
            $fixedMaint = $db->affectedRows();
            // documenti fornitore: carico -> positivo
            $db->query('UPDATE fb_document_detail dd JOIN fb_document d ON d.id_document = dd.id_document
                SET dd.quantity = ABS(dd.quantity)
                WHERE d.id_customer IS NULL AND dd.id_product IS NOT NULL AND dd.quantity < 0');
            $fixedIn = $db->affectedRows();
            // documenti cliente: scarico -> negativo (le righe senza
            // articolo, es. manodopera, non sono movimenti di magazzino)
            $db->query('UPDATE fb_document_detail dd JOIN fb_document d ON d.id_document = dd.id_document
                SET dd.quantity = -ABS(dd.quantity)
                WHERE d.id_customer IS NOT NULL AND dd.id_product IS NOT NULL AND dd.quantity > 0');
            $fixedOut = $db->affectedRows();
            $db->transComplete();

            if (!$db->transStatus()) {
                return $this->jsonResponse(['success' => false, 'error' => 'Errore durante la normalizzazione dei segni.'], 500);
            }

            return $this->jsonResponse([
                'success' => true,
                'maintenance' => $fixedMaint,
                'loads' => $fixedIn,
                'outs' => $fixedOut,
            ]);
        }

        // prodotti con almeno un movimento, in ordine
        $productIds = static function () use ($db): array {
            $rows = $db->query('SELECT DISTINCT id_product FROM (
                    SELECT id_product FROM fb_document_detail WHERE id_product IS NOT NULL
                    UNION
                    SELECT id_product FROM fb_maintenance_detail WHERE id_product IS NOT NULL
                ) t ORDER BY id_product')->getResultArray();

            return array_map(static fn(array $r): int => (int) $r['id_product'], $rows);
        };

        if ($step === 'count') {
            return $this->jsonResponse(['success' => true, 'total' => count($productIds())]);
        }

        if ($step === 'links') {
            $offset = max(0, (int) $this->request->getPost('offset'));
            $limit = max(1, (int) ($this->request->getPost('limit') ?: 100));
            $all = $productIds();
            $total = count($all);
            $slice = array_slice($all, $offset, $limit);
            $linked = 0;

            $db->transStart();
            foreach ($slice as $pid) {
                $linked += $this->linkOutsToLoads($db, $pid);
            }
            $db->transComplete();

            if (!$db->transStatus()) {
                return $this->jsonResponse(['success' => false, 'error' => 'Errore durante l\'aggancio degli scarichi.'], 500);
            }

            return $this->jsonResponse([
                'success' => true,
                'processed' => $offset + count($slice),
                'total' => $total,
                'done' => $offset + count($slice) >= $total,
                'linked' => $linked,
            ]);
        }

        if ($step === 'stock') {
            $offset = max(0, (int) $this->request->getPost('offset'));
            $limit = max(1, (int) ($this->request->getPost('limit') ?: 100));
            $all = $productIds();
            $total = count($all);
            $slice = array_slice($all, $offset, $limit);
            $updated = 0;
            $created = 0;
            $now = date('Y-m-d H:i:s');

            $db->transStart();
            foreach ($slice as $pid) {
                $in = (float) $db->query('SELECT COALESCE(SUM(dd.quantity), 0) q FROM fb_document_detail dd
                    JOIN fb_document d ON d.id_document = dd.id_document
                    WHERE dd.id_product = ? AND d.id_customer IS NULL AND dd.id_maintenance IS NULL', [$pid])->getRow()->q;
                $outDocs = (float) $db->query('SELECT COALESCE(SUM(ABS(dd.quantity)), 0) q FROM fb_document_detail dd
                    JOIN fb_document d ON d.id_document = dd.id_document
                    WHERE dd.id_product = ? AND d.id_customer IS NOT NULL AND dd.id_maintenance IS NULL', [$pid])->getRow()->q;
                $outMaint = (float) $db->query('SELECT COALESCE(SUM(ABS(md.quantity)), 0) q FROM fb_maintenance_detail md
                    WHERE md.id_product = ?', [$pid])->getRow()->q;

                $outputs = $outDocs + $outMaint;
                $quantity = $in - $outputs;

                $stock = $db->table('fb_stock')->where('id_product', $pid)->get()->getRowArray();
                if ($stock === null) {
                    $db->table('fb_stock')->insert([
                        'id_product' => $pid,
                        'quantity' => $quantity,
                        'inputs' => $in,
                        'outputs' => $outputs,
                        'date_add' => $now,
                    ]);
                    $created++;
                } elseif (
                    abs((float) $stock['quantity'] - $quantity) > 0.0001
                    || abs((float) $stock['inputs'] - $in) > 0.0001
                    || abs((float) $stock['outputs'] - $outputs) > 0.0001
                ) {
                    $db->table('fb_stock')->where('id_stock', (int) $stock['id_stock'])
                        ->update(['quantity' => $quantity, 'inputs' => $in, 'outputs' => $outputs, 'date_upd' => $now]);
                    $updated++;
                }
            }
            $db->transComplete();

            if (!$db->transStatus()) {
                return $this->jsonResponse(['success' => false, 'error' => 'Errore durante il ricalcolo delle giacenze.'], 500);
            }

            return $this->jsonResponse([
                'success' => true,
                'total' => $total,
                'processed' => min($offset + $limit, $total),
                'updated' => $updated,
                'created' => $created,
                'done' => $offset + $limit >= $total,
            ]);
        }

        return $this->jsonResponse(['success' => false, 'error' => 'Step non valido.'], 422);
    }

    /**
     * Aggancio scarichi ai carichi per un prodotto (step "links" di align):
     * ogni carico, in ordine cronologico, viene riempito con gli scarichi
     * dello STESSO prezzo non ancora agganciati (id_load NULL) — prima
     * quelli da data >= data carico, poi in mancanza quelli precedenti.
     * Uno scarico si aggancia solo se entra nel residuo del carico
     * (niente superamento); gli scarichi già agganciati a mano o da un
     * giro precedente riducono la capacità e non vengono toccati.
     *
     * @return int numero di scarichi agganciati
     */
    private function linkOutsToLoads(\CodeIgniter\Database\BaseConnection $db, int $idProduct): int
    {
        // carichi: righe documento fornitore, in ordine di data documento
        $loads = $db->table('fb_document_detail dd')
            ->select('dd.id_document_detail, ABS(dd.quantity) AS qty, dd.price, d.date')
            ->join('fb_document d', 'd.id_document = dd.id_document')
            ->where('d.id_customer IS NULL', null, false)
            ->where('dd.id_maintenance IS NULL', null, false)
            ->where('dd.id_product', $idProduct)
            ->orderBy('d.date', 'ASC')
            ->orderBy('dd.id_document_detail', 'ASC')
            ->get()->getResultArray();
        if ($loads === []) {
            return 0;
        }

        // scarichi: righe manutenzione + righe documenti cliente non copiate
        $outs = [];
        foreach (
            $db->table('fb_maintenance_detail md')
                ->select('md.id_maintenance_detail AS id_row, md.id_load, ABS(md.quantity) AS qty, md.price, m.date')
                ->join('fb_maintenance m', 'm.id_maintenance = md.id_maintenance')
                ->where('md.id_product', $idProduct)
                ->get()->getResultArray() as $r
        ) {
            $outs[] = ['source' => 'maintenance', 'id_row' => (int) $r['id_row'], 'id_load' => $r['id_load'], 'qty' => (float) $r['qty'], 'price' => (float) $r['price'], 'date' => (string) $r['date']];
        }
        foreach (
            $db->table('fb_document_detail dd')
                ->select('dd.id_document_detail AS id_row, dd.id_load, ABS(dd.quantity) AS qty, dd.price, d.date')
                ->join('fb_document d', 'd.id_document = dd.id_document')
                ->where('d.id_customer IS NOT NULL', null, false)
                ->where('dd.id_maintenance IS NULL', null, false)
                ->where('dd.id_product', $idProduct)
                ->get()->getResultArray() as $r
        ) {
            $outs[] = ['source' => 'document', 'id_row' => (int) $r['id_row'], 'id_load' => $r['id_load'], 'qty' => (float) $r['qty'], 'price' => (float) $r['price'], 'date' => (string) $r['date']];
        }
        if ($outs === []) {
            return 0;
        }

        // capacita' residua per carico al netto degli scarichi gia' agganciati;
        // gli scarichi agganciati restano immutati (scelte manuali incluse)
        $residuo = [];
        foreach ($loads as $l) {
            $residuo[(int) $l['id_document_detail']] = abs((float) $l['qty']);
        }
        $byPrice = [];
        foreach ($outs as $i => $o) {
            if ($o['id_load'] !== null) {
                $lid = (int) $o['id_load'];
                if (isset($residuo[$lid])) {
                    $residuo[$lid] -= $o['qty'];
                }
                continue;
            }
            $byPrice[number_format($o['price'], 4, '.', '')][] = $i;
        }

        $now = date('Y-m-d H:i:s');
        $linked = 0;
        foreach ($loads as $l) {
            $lid = (int) $l['id_document_detail'];
            $remaining = $residuo[$lid];
            if ($remaining <= 0.0001) {
                continue;
            }
            $candidates = $byPrice[number_format((float) $l['price'], 4, '.', '')] ?? [];
            // prima gli scarichi da data >= data carico, poi i precedenti
            $used = [];
            foreach ([true, false] as $preferred) {
                foreach ($candidates as $ci => $oi) {
                    $o = $outs[$oi];
                    $isAfter = strcmp($o['date'], (string) $l['date']) >= 0;
                    if ($isAfter !== $preferred) {
                        continue;
                    }
                    if ($o['qty'] <= $remaining + 0.0001) {
                        if ($o['source'] === 'maintenance') {
                            $db->table('fb_maintenance_detail')->where('id_maintenance_detail', $o['id_row'])
                                ->update(['id_load' => $lid, 'date_upd' => $now]);
                        } else {
                            $db->table('fb_document_detail')->where('id_document_detail', $o['id_row'])
                                ->update(['id_load' => $lid, 'date_upd' => $now]);
                        }
                        $remaining -= $o['qty'];
                        $linked++;
                        $used[] = $ci;
                    }
                }
            }
            foreach ($used as $ci) {
                unset($candidates[$ci]);
            }
            $byPrice[number_format((float) $l['price'], 4, '.', '')] = $candidates;
            $residuo[$lid] = $remaining;
        }

        return $linked;
    }
}
