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
use FairyBus\Models\FbRefuellingModel;

class Refuelling extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/refuelling.twig', [
            'page_title' => 'Scarico carburante',
            'direction' => 'out',
            'heading' => 'Scarico carburante',
            'subtitle' => 'Scarichi di carburante verso gli automezzi.',
        ]);
    }

    /**
     * Pagina carico: identica allo scarico, ma mostra direction='in'.
     */
    public function loadPage(): ResponseInterface
    {
        return $this->renderAdmin('admin/refuelling.twig', [
            'page_title' => 'Gestione carburante — Carico',
            'direction' => 'in',
            'heading' => 'Carico carburante',
            'subtitle' => 'Carichi di carburante (cisterne/punti di rifornimento).',
        ]);
    }

    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $direction = in_array($this->request->getPost('direction'), ['in', 'out'], true)
            ? $this->request->getPost('direction')
            : null;

        $rows = (new FbRefuellingModel())->listAll($ids);
        if ($direction !== null) {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['direction'] === $direction));
        }

        $title = $direction === 'in' ? 'Carico carburante' : ($direction === 'out' ? 'Gestione carburante — Scarico' : 'Gestione carburante');
        $pdf = PdfHelper::generate('print/refuelling.twig', [
            'title' => $title,
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'rifornimenti');
    }
}
