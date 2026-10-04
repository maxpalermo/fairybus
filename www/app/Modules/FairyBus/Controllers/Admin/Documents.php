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

        $docKind = !empty($document['id_customer']) ? 'scarico' : 'carico';
        $pdf = PdfHelper::generate('print/document.twig', [
            'title' => "Documento di {$docKind} " . ($document['number'] ?? $document['id_document']),
            'document' => $document,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'documento_' . ($document['number'] ?? $id));
    }
}
