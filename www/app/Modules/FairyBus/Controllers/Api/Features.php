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
use FairyBus\Models\FbConfigurationModel;
use FairyBus\Models\FbFeatureModel;

class Features extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbFeatureModel();
        $rows  = $model->listAll();

        foreach ($rows as &$row) {
            $row['id_feature'] = (int) $row['id_feature'];
        }

        return $this->jsonResponse([
            'success' => true,
            'rows'    => $rows,
        ]);
    }

    /**
     * Return configuration values for select-type features.
     */
    public function configurations(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model          = new FbConfigurationModel();
        $configurations = [];

        foreach (['engine_type', 'fuel_type', 'euro'] as $name) {
            $row = $model->get("select_{$name}");
            if ($row !== null) {
                $configurations[$name] = json_decode($row['value'], true) ?? [];
            } else {
                $configurations[$name] = [];
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'configurations' => $configurations,
        ]);
    }

    public function saveConfiguration(string $name): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if (! in_array($name, ['engine_type', 'fuel_type', 'euro'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Configurazione non valida.'], 422);
        }

        $value = $this->request->getPost('value');
        if (! is_string($value) || $value === '') {
            return $this->jsonResponse(['success' => false, 'error' => 'Valore non valido.'], 422);
        }

        // Validate JSON structure.
        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Il valore deve essere un array JSON.'], 422);
        }

        $model = new FbConfigurationModel();
        $model->setValue("select_{$name}", $value);

        return $this->jsonResponse(['success' => true]);
    }
}
