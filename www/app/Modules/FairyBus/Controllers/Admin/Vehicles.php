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
use FairyBus\Models\FbVehicleModel;

class Vehicles extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/vehicles.twig', [
            'page_title' => 'Veicoli',
        ]);
    }

    public function detail(int $idVehicle): ResponseInterface
    {
        return $this->renderAdmin('admin/vehicle_detail.twig', [
            'page_title' => 'Dettaglio veicolo',
            'id_vehicle' => $idVehicle,
        ]);
    }

    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $rows = (new FbVehicleModel())->listWithBrand($ids);

        $pdf = PdfHelper::generate('print/vehicles.twig', [
            'title' => 'Elenco veicoli',
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'veicoli');
    }
}
