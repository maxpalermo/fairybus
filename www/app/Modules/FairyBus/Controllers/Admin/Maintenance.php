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
use FairyBus\Models\FbMaintenanceModel;

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

        $model = new FbMaintenanceModel();
        $maintenance = $model->findById($id);
        if ($maintenance === null) {
            return $this->response->setStatusCode(404)->setBody('Manutenzione non trovata.');
        }

        $maintenance['tasks'] = (new \FairyBus\Models\FbMaintenanceTaskModel())->listByMaintenance($id);
        $maintenance['details'] = (new \FairyBus\Models\FbMaintenanceDetailModel())->listByMaintenance($id);
        $maintenance['invoices'] = (new \FairyBus\Models\FbMaintenanceInvoiceModel())->listByMaintenance($id);

        $pdf = PdfHelper::generate('print/maintenance_single.twig', [
            'title' => 'Manutenzione ' . $maintenance['id_maintenance'],
            'maintenance' => $maintenance,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'manutenzione_' . $id);
    }
}
