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
use App\Libraries\Twig;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Libraries\PdfHelper;
use FairyBus\Models\FbDocumentModel;

class Documents extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/documents.twig', [
            'page_title' => 'Carichi',
        ]);
    }

    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $rows = (new FbDocumentModel())->listAll($ids, 'in');

        $pdf = PdfHelper::generate('print/documents.twig', [
            'title' => 'Documenti di carico',
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'documenti');
    }

    /**
     * Stampa del singolo documento con le righe dettaglio (anteprima inline).
     */
    public function printSingle(int $id): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        $model = new FbDocumentModel();
        $document = $model->findById($id);
        if ($document === null) {
            return $this->response->setStatusCode(404)->setBody('Documento non trovato.');
        }

        $document['details'] = (new \FairyBus\Models\FbDocumentDetailModel())->listByDocument($id);

        $docKind = !empty($document['id_customer']) ? 'riepilogo' : 'carico';
        $pdf = PdfHelper::generate('print/document.twig', [
            'title' => "Documento di {$docKind} " . ($document['number'] ?? $document['id_document']),
            'document' => $document,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'documento_' . ($document['number'] ?? $id));
    }

    /**
     * Stampa dettagliata dei documenti selezionati: una scheda completa per
     * documento seguita, se sono più di uno, dal riepilogo articoli aggregato.
     */
    public function printDetails(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $model = new FbDocumentModel();
        $detailModel = new \FairyBus\Models\FbDocumentDetailModel();

        // Documenti e dettagli in 2 query totali (niente N+1 sulle stampe massive)
        $documents = $model->listAll($ids, 'in');
        $detailsByDoc = [];
        foreach ($detailModel->listByDocuments($ids) as $d) {
            $detailsByDoc[(int) $d['id_document']][] = $d;
        }
        foreach ($documents as &$document) {
            $document['details'] = $detailsByDoc[(int) $document['id_document']] ?? [];
        }
        unset($document);

        $summary = self::aggregateDetails($documents);

        $pdf = PdfHelper::generate('print/documents_details.twig', [
            'title' => 'Dettaglio documenti',
            'documents' => $documents,
            'summary' => $summary,
            'grand_total' => array_sum(array_column($summary, 'total')),
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'documenti_dettagli');
    }

    /**
     * Report sintetico massivo: una riga per documento con i totali.
     * Sorgente: `ids` selezionati oppure `month`+`year`. `format` = pdf|html:
     * l'HTML e' una pagina stampabile aperta in nuova scheda (nessun Dompdf,
     * scala a centinaia/migliaia di documenti senza timeout).
     */
    public function printReport(): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        set_time_limit(0);

        $format = (string) $this->request->getPost('format') === 'html' ? 'html' : 'pdf';
        $direction = (string) $this->request->getPost('direction') === 'out' ? 'out' : 'in';

        // Sorgente: righe selezionate (`ids` JSON) oppure mese+anno.
        $ids = [];
        $raw = (string) $this->request->getPost('ids');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = array_values(array_filter(array_map('intval', $decoded), static fn(int $i): bool => $i > 0));
            }
        }
        $month = (int) $this->request->getPost('month') ?: null;
        $year = (int) $this->request->getPost('year') ?: null;
        if ($month !== null && ($month < 1 || $month > 12)) {
            $month = null;
        }

        if ($ids === [] && !($month && $year)) {
            return $this->response->setStatusCode(422)->setBody('Seleziona le righe oppure indica mese e anno.');
        }

        $documents = (new FbDocumentModel())->listAll($ids, $direction, $month, $year);
        if ($documents === []) {
            return $this->response->setStatusCode(422)->setBody('Nessun documento trovato per i criteri indicati.');
        }

        // Dettagli di tutti i documenti in una sola query + riepilogo aggregato.
        $detailsByDoc = [];
        foreach ((new \FairyBus\Models\FbDocumentDetailModel())->listByDocuments(array_column($documents, 'id_document')) as $d) {
            $detailsByDoc[(int) $d['id_document']][] = $d;
        }
        foreach ($documents as &$document) {
            $document['details'] = $detailsByDoc[(int) $document['id_document']] ?? [];
        }
        unset($document);

        $summary = self::aggregateDetails($documents);

        $data = [
            'title' => $direction === 'in' ? 'Report documenti di carico' : 'Report documenti di scarico',
            'documents' => $documents,
            'summary' => $summary,
            'grand_total' => array_sum(array_column($summary, 'total')),
            'period_label' => $month && $year ? sprintf('%02d/%d', $month, $year) : null,
            'user' => $this->getCurrentUser(),
        ];

        if ($format === 'html') {
            return $this->response
                ->setContentType('text/html', 'utf-8')
                ->setBody(Twig::render('admin/documents_details_html.twig', $data));
        }

        $pdf = PdfHelper::generate('print/documents_details.twig', $data, 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'report_documenti');
    }

    /**
     * Riepilogo articoli aggregato sui dettagli dei documenti:
     * quantita', imponibile e totale raggruppati per codice+nome.
     *
     * @param list<array<string, mixed>> $documents documenti con chiave 'details'
     * @return array<string, array<string, mixed>>
     */
    private static function aggregateDetails(array $documents): array
    {
        $summary = [];
        foreach ($documents as $document) {
            foreach ($document['details'] ?? [] as $d) {
                $key = (string) ($d['sku'] ?? '') . '|' . (string) ($d['product_name'] ?? '');
                if ($key === '|') {
                    $key = 'riga_' . (string) ($d['id_document_detail'] ?? count($summary));
                }
                $qty = abs((float) ($d['quantity'] ?? 0));
                $importo = $qty * (float) ($d['price'] ?? 0) * (1 - abs((float) ($d['discount'] ?? 0)) / 100);
                $rowTotal = $importo * (1 + (float) ($d['vat_rate'] ?? 0) / 100);
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
        }

        uasort($summary, static fn(array $a, array $b): int => strcasecmp((string) $a['product_name'], (string) $b['product_name']));

        return $summary;
    }
}
