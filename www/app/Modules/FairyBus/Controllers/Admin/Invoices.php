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
use FairyBus\Models\FbDocumentDetailModel;
use FairyBus\Models\FbInvoiceModel;
use FairyBus\Models\FbMaintenanceInvoiceModel;

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

        $detailModel = new FbDocumentDetailModel();
        foreach ($invoice['documents'] as &$doc) {
            $doc['details'] = $detailModel->listByDocument((int) $doc['id_document']);
        }

        // fatture cliente: schede manutenzione collegate (conto terzi)
        $invoice['maintenances'] = FbMaintenanceInvoiceModel::blocksByInvoice($id);

        $pdf = PdfHelper::generate('print/invoice.twig', [
            'title' => 'Fattura ' . ($invoice['number'] ?? $invoice['id_invoice']),
            'invoice' => $invoice,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'fattura_' . ($invoice['number'] ?? $id));
    }

    /**
     * Stampa dettagliata delle fatture selezionate: per ogni fattura il
     * documento completo (DDT collegati e/o schede manutenzione) e in coda
     * il riepilogo articoli aggregato su tutte le fatture.
     */
    public function printDetails(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $model = new FbInvoiceModel();
        $detailModel = new FbDocumentDetailModel();
        $db = \Config\Database::connect();

        $invoices = [];
        $summary = [];
        foreach ($ids as $id) {
            $invoice = $model->findById((int) $id);
            if ($invoice === null) {
                continue;
            }

            $invoice['documents'] = $db->table('fb_document d')
                ->select('d.*')
                ->where('d.id_invoice', (int) $id)
                ->orderBy('d.date', 'ASC')
                ->get()->getResultArray();
            foreach ($invoice['documents'] as &$doc) {
                $doc['details'] = $detailModel->listByDocument((int) $doc['id_document']);
            }
            unset($doc);

            // Le schede la cui copia righe e' gia' dentro un documento
            // collegato alla fattura non si ristampano come blocco a parte:
            // il documento e' la rappresentazione canonica del contenuto.
            $docIds = array_map('intval', array_column($invoice['documents'], 'id_document'));
            $invoice['maintenances'] = array_values(array_filter(
                FbMaintenanceInvoiceModel::blocksByInvoice((int) $id),
                static fn(array $m): bool => !in_array((int) ($m['id_document'] ?? 0), $docIds, true)
            ));

            // totale fattura + riepilogo articoli aggregato
            $lines = [];
            foreach ($invoice['documents'] as $doc) {
                foreach ($doc['details'] as $d) {
                    $lines[] = $d;
                }
            }
            foreach ($invoice['maintenances'] as $mnt) {
                foreach ($mnt['details'] as $d) {
                    $lines[] = $d;
                }
            }

            $invTotal = 0.0;
            foreach ($lines as $d) {
                $qty = abs((float) ($d['quantity'] ?? 0));
                $importo = $qty * (float) ($d['price'] ?? 0) * (1 + (float) ($d['discount'] ?? 0) / 100);
                $rowTotal = $importo * (1 + (float) ($d['vat_rate'] ?? 0) / 100);
                $invTotal += $rowTotal;

                $key = (string) ($d['sku'] ?? '') . '|' . (string) ($d['product_name'] ?? '');
                if ($key === '|') {
                    $key = 'riga_' . (string) ($d['id_document_detail'] ?? count($summary));
                }
                if (!isset($summary[$key])) {
                    $summary[$key] = [
                        'sku' => $d['sku'] ?? '—',
                        'product_name' => $d['product_name'] ?? '—',
                        'qty' => 0.0,
                        'amount' => 0.0,
                        'total' => 0.0,
                    ];
                }
                $summary[$key]['qty'] += $qty;
                $summary[$key]['amount'] += $importo;
                $summary[$key]['total'] += $rowTotal;
            }
            $invoice['invoice_total'] = $invTotal;
            $invoices[] = $invoice;
        }

        uasort($summary, static fn(array $a, array $b): int => strcasecmp((string) $a['product_name'], (string) $b['product_name']));

        $pdf = PdfHelper::generate('print/invoices_details.twig', [
            'title' => 'Dettaglio fatture',
            'invoices' => $invoices,
            'summary' => $summary,
            'grand_total' => array_sum(array_column($summary, 'total')),
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'fatture_dettagli');
    }
}
