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
use FairyBus\Models\FbCityModel;
use FairyBus\Models\FbCountryModel;
use FairyBus\Models\FbStateModel;

class Locations extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/locations.twig', [
            'page_title' => 'Nazioni, Province e Città',
        ]);
    }

    /**
     * Stampa l'elenco della scheda attiva: POST ids[] + type
     * (countries|states|cities).
     */
    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $type = (string) $this->request->getPost('type');
        if (!in_array($type, ['countries', 'states', 'cities'], true)) {
            return $this->response->setStatusCode(422)->setBody('Tipo di stampa non valido.');
        }

        [$title, $rows] = match ($type) {
            'countries' => ['Elenco nazioni', $this->rowsFor(new FbCountryModel(), 'id_country', 'name', $ids)],
            'states' => ['Elenco province', $this->rowsFor(new FbStateModel(), 'id_state', 'name', $ids)],
            default => ['Elenco città', $this->rowsFor(new FbCityModel(), 'id_city', 'city', $ids)],
        };

        $pdf = PdfHelper::generate('print/locations.twig', [
            'title' => $title,
            'type' => $type,
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, $type);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(object $model, string $pk, string $orderField, array $ids): array
    {
        return $model->whereIn($pk, $ids)->orderBy($orderField, 'ASC')->findAll();
    }
}
