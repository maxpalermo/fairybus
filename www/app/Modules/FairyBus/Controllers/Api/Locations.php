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

namespace FairyBus\Controllers\Api;

use App\Controllers\AdminController;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Models\FbCityModel;
use FairyBus\Models\FbCountryModel;
use FairyBus\Models\FbStateModel;

class Locations extends AdminController
{
    public function countries(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbCountryModel();

        return $this->jsonResponse([
            'success' => true,
            'rows'    => $model->orderBy('name', 'ASC')->findAll(),
        ]);
    }

    public function states(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbStateModel();
        $idCountry = $this->request->getGet('country_id');

        return $this->jsonResponse([
            'success' => true,
            'rows'    => $model->listByCountry($idCountry !== null && $idCountry !== '' ? (int) $idCountry : null),
        ]);
    }

    public function cities(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbCityModel();
        $isoCode = $this->request->getGet('iso_code') ?: null;
        $search  = $this->request->getGet('search') ?: null;
        $limit   = (int) ($this->request->getGet('limit') ?? 50);
        $offset  = (int) ($this->request->getGet('offset') ?? 0);

        $rows  = $model->listFiltered($isoCode, $search, $limit, $offset);
        $total = $model->countFiltered($isoCode, $search);

        return $this->jsonResponse([
            'success' => true,
            'rows'    => $rows,
            'total'   => $total,
        ]);
    }

    public function options(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $countries = (new FbCountryModel())->orderBy('name', 'ASC')->findAll();
        $states    = (new FbStateModel())->orderBy('name', 'ASC')->findAll();

        return $this->jsonResponse([
            'success'   => true,
            'countries' => $countries,
            'states'    => $states,
        ]);
    }
}
