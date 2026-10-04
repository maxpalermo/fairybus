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
use FairyBus\Models\FbInvoiceModel;

class Invoices extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/invoices.twig', [
            'page_title' => 'Fatture',
        ]);
    }

    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $rows = (new FbInvoiceModel())->listAll($ids);

        $pdf = PdfHelper::generate('print/invoices.twig', [
            'title' => 'Elenco fatture',
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'fatture');
    }

    /**
     * Stampa della singola fattura con i DDT collegati (anteprima inline).
     */
    public function printSingle(int $id): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        $model = new FbInvoiceModel();
        $invoice = $model->findById($id);
        if ($invoice === null) {
            return $this->response->setStatusCode(404)->setBody('Fattura non trovata.');
        }

        $db = \Config\Database::connect();
        $invoice['documents'] = $db->table('fb_document d')
            ->select('d.*')
            ->where('d.id_invoice', $id)
            ->orderBy('d.date', 'ASC')
            ->get()->getResultArray();

        $detailModel = new \FairyBus\Models\FbDocumentDetailModel();
        foreach ($invoice['documents'] as &$doc) {
            $doc['details'] = $detailModel->listByDocument((int) $doc['id_document']);
        }

        $pdf = PdfHelper::generate('print/invoice.twig', [
            'title' => 'Fattura ' . ($invoice['number'] ?? $invoice['id_invoice']),
            'invoice' => $invoice,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'fattura_' . ($invoice['number'] ?? $id));
    }
}
