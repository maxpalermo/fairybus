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
     * Importa le giacenze dalla tabella legacy `inventory`,
     * incrociandole con `fb_product` tramite il legacy_id.
     */
    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $legacyTables = LegacyDatabase::listTables($db);
        if (!in_array('inventory', $legacyTables, true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "inventory" non trovata.'], 422);
        }

        $hasProduct = in_array('product', $legacyTables, true);

        $rows = LegacyDatabase::withoutPrefix(
            $db,
            static function (BaseConnection $db) use ($hasProduct): array {
                $builder = $db->table('inventory i')
                    ->select('i.id, i.product_id, i.inputs, i.notification_limit, i.outputs, i.quantity, i.note, i.created_date, i.last_modified_date');

                if ($hasProduct) {
                    $builder->select('p.unit')
                        ->join('product p', 'p.id = i.product_id', 'left');
                }

                return $builder->orderBy('i.id', 'ASC')->get()->getResultArray();
            }
        );

        // Mappa legacy product.id => fb_product.id_product
        $productMap = [];
        foreach ($db->table('fb_product')->select('id_product, legacy_id')->where('legacy_id IS NOT NULL', null, false)->get()->getResultArray() as $p) {
            $productMap[(int) $p['legacy_id']] = (int) $p['id_product'];
        }

        $db->table('fb_stock')->truncate();

        $imported = 0;
        $skipped = 0;
        $batch = [];

        $flush = static function () use ($db, &$batch, &$imported): void {
            if ($batch === []) {
                return;
            }
            $db->table('fb_stock')->insertBatch($batch);
            $imported += count($batch);
            $batch = [];
        };

        foreach ($rows as $row) {
            $legacyProductId = (int) ($row['product_id'] ?? 0);
            $idProduct = $productMap[$legacyProductId] ?? null;

            if ($idProduct === null) {
                $skipped++;
                continue;
            }

            $limit = $row['notification_limit'] !== null ? (float) $row['notification_limit'] : null;
            // Nel legacy valori negativi (es. -1) disattivano la notifica
            if ($limit !== null && $limit < 0) {
                $limit = null;
            }

            $batch[] = [
                'legacy_id' => (int) $row['id'],
                'id_product' => $idProduct,
                'unit' => isset($row['unit']) && $row['unit'] !== null ? (int) $row['unit'] : 0,
                'quantity' => (float) ($row['quantity'] ?? 0),
                'inputs' => (float) ($row['inputs'] ?? 0),
                'outputs' => (float) ($row['outputs'] ?? 0),
                'notification_limit' => $limit,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];

            if (count($batch) >= 500) {
                $flush();
            }
        }
        $flush();

        $message = "Importate {$imported} giacenze dalla tabella legacy inventory.";
        if ($skipped > 0) {
            $message .= " Ignorate {$skipped} righe senza prodotto corrispondente.";
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => $message,
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
    }
}
