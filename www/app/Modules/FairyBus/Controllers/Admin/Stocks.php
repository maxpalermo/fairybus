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
use FairyBus\Models\FbStockModel;

class Stocks extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/stocks.twig', [
            'page_title' => 'Giacenze',
            'unit_labels' => FbStockModel::UNIT_LABELS,
        ]);
    }

    /**
     * Stampa PDF delle giacenze selezionate (anteprima inline nel browser).
     * Riceve via POST il parametro `ids` (JSON array di id_stock).
     */
    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $model = new FbStockModel();
        $rows = $model->listByIds($ids);
        $lowCount = count(array_filter($rows, static fn(array $r): bool => !empty($r['low_stock'])));

        $pdf = PdfHelper::generate('print/stocks.twig', [
            'title' => 'Giacenze di magazzino',
            'rows' => $rows,
            'low_count' => $lowCount,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'giacenze');
    }
}
