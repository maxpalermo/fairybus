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
use FairyBus\Models\FbProductModel;
use FairyBus\Models\FbStockModel;

class Products extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/products.twig', [
            'page_title' => 'Prodotti',
            'unit_labels' => FbStockModel::UNIT_LABELS,
        ]);
    }

    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $rows = (new FbProductModel())->listAll($ids);

        $pdf = PdfHelper::generate('print/products.twig', [
            'title' => 'Elenco prodotti',
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'prodotti');
    }

    /** Stampa l'elenco dei prodotti sotto scorta (giacenza <= soglia, blocchi da max 200). */
    public function printAlerts(): ResponseInterface
    {
        $ids = $this->getPrintIds(200);
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $rows = array_values(array_filter(
            (new FbProductModel())->listAll($ids),
            static fn(array $r): bool => !empty($r['low_stock'])
        ));

        $part = max(1, (int) $this->request->getPost('part'));
        $parts = max(1, (int) $this->request->getPost('parts'));

        $title = 'Prodotti sotto scorta';
        $name = 'prodotti-sotto-scorta';
        if ($parts > 1) {
            $title .= " — parte {$part} di {$parts}";
            $name .= "-parte-{$part}";
        }

        $pdf = PdfHelper::generate('print/product_alerts.twig', [
            'title' => $title,
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, $name);
    }
}
