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

namespace FairyBus\Controllers\Admin;

use App\Controllers\AdminController;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Libraries\PdfHelper;
use FairyBus\Models\FbMaintenanceDetailModel;
use FairyBus\Models\FbMaintenanceInvoiceModel;
use FairyBus\Models\FbMaintenanceModel;
use FairyBus\Models\FbMaintenanceTaskModel;

class Maintenance extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/maintenance.twig', [
            'page_title' => 'Manutenzione',
        ]);
    }

    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $rows = (new FbMaintenanceModel())->listAll($ids);

        $pdf = PdfHelper::generate('print/maintenance.twig', [
            'title' => 'Manutenzioni',
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'manutenzioni');
    }

    /**
     * Stampa della singola manutenzione con task e ricambi (anteprima inline).
     */
    public function printSingle(int $id): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        $maintenance = (new FbMaintenanceModel())->findById($id);
        if ($maintenance === null) {
            return $this->response->setStatusCode(404)->setBody('Manutenzione non trovata.');
        }

        $maintenance['tasks'] = (new FbMaintenanceTaskModel())->listByMaintenance($id);
        $maintenance['details'] = (new FbMaintenanceDetailModel())->listByMaintenance($id);
        $maintenance['invoices'] = (new FbMaintenanceInvoiceModel())->listByMaintenance($id);

        $pdf = PdfHelper::generate('print/maintenance_single.twig', [
            'title' => 'Manutenzione ' . $maintenance['id_maintenance'],
            'maintenance' => $maintenance,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'manutenzione_' . $id);
    }

    /**
     * Stampa dettagliata delle manutenzioni selezionate: un documento per
     * manutenzione seguito, se sono più di una, dal riepilogo ricambi.
     */
    public function printDetails(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $model = new FbMaintenanceModel();
        $taskModel = new FbMaintenanceTaskModel();
        $detailModel = new FbMaintenanceDetailModel();
        $invoiceModel = new FbMaintenanceInvoiceModel();

        $maintenances = [];
        $summary = [];
        foreach ($ids as $id) {
            $maintenance = $model->findById($id);
            if ($maintenance === null) {
                continue;
            }
            $maintenance['tasks'] = $taskModel->listByMaintenance($id);
            $maintenance['details'] = $detailModel->listByMaintenance($id);
            $maintenance['invoices'] = $invoiceModel->listByMaintenance($id);
            $maintenances[] = $maintenance;

            foreach ($maintenance['details'] as $d) {
                $key = (string) ($d['sku'] ?? '') . '|' . (string) ($d['product_name'] ?? '');
                if ($key === '|') {
                    $key = 'riga_' . (string) ($d['id_maintenance_detail'] ?? count($summary));
                }
                $qty = abs((float) ($d['quantity'] ?? 0));
                $importo = $qty * (float) ($d['price'] ?? 0) * (1 + (float) ($d['discount'] ?? 0) / 100);
                $rowTotal = $importo * (1 + (float) ($d['vat_rate'] ?? 0) / 100);
                if (!isset($summary[$key])) {
                    $summary[$key] = [
                        'sku' => $d['sku'] ?? '—',
                        'product_name' => $d['product_name'] ?? '—',
                        'unit_abbr' => $d['unit_abbr'] ?? 'pz',
                        'qty' => 0.0,
                        'amount' => 0.0,
                        'total' => 0.0,
                    ];
                }
                $summary[$key]['qty'] += $qty;
                $summary[$key]['amount'] += $importo;
                $summary[$key]['total'] += $rowTotal;
            }
        }

        uasort($summary, static fn(array $a, array $b): int => strcasecmp((string) $a['product_name'], (string) $b['product_name']));

        $pdf = PdfHelper::generate('print/maintenance_details.twig', [
            'title' => 'Dettaglio manutenzioni',
            'maintenances' => $maintenances,
            'summary' => $summary,
            'grand_total' => array_sum(array_column($summary, 'total')),
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'manutenzioni_dettagli');
    }
}
