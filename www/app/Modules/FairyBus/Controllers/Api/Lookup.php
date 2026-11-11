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
use FairyBus\Models\FbBrandModel;
use FairyBus\Models\FbCategoryModel;

/**
 * Endpoint generico per le select "creabili".
 *
 * GET  /api/lookup/{type}  -> elenco valori della tabella
 * POST /api/lookup/{type}  -> crea un nuovo valore (campo "name")
 */
class Lookup extends AdminController
{
    /**
     * Whitelist delle tabelle gestibili.
     *
     * @var array<string, array<string, mixed>>
     */
    private const TABLES = [
        'brand' => [
            'label' => 'Marca',
        ],
        'category' => [
            'label' => 'Categoria',
        ],
    ];

    public function options(string $type): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if (!isset(self::TABLES[$type])) {
            return $this->jsonResponse(['success' => false, 'error' => "Tabella non supportata: {$type}"], 422);
        }

        return $this->jsonResponse([
            'success' => true,
            'label'   => self::TABLES[$type]['label'],
            'items'   => $this->fetchItems($type),
        ]);
    }

    public function create(string $type): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if (!isset(self::TABLES[$type])) {
            return $this->jsonResponse(['success' => false, 'error' => "Tabella non supportata: {$type}"], 422);
        }

        $name = trim((string) $this->request->getPost('name'));
        if ($name === '') {
            return $this->jsonResponse(['success' => false, 'error' => 'Il nome è obbligatorio.'], 422);
        }

        try {
            $id = match ($type) {
                'brand'    => $this->createBrand($name),
                'category' => $this->createCategory($name),
                default    => 0,
            };
        } catch (\Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }

        if ($id <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Inserimento non riuscito.'], 422);
        }

        return $this->jsonResponse([
            'success' => true,
            'id'      => $id,
            'name'    => $name,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchItems(string $type): array
    {
        return match ($type) {
            'brand' => array_map(
                static fn (array $b): array => ['id' => (int) $b['id_brand'], 'name' => (string) $b['name']],
                (new FbBrandModel())->orderBy('name', 'ASC')->findAll()
            ),
            'category' => array_map(
                static fn (array $c): array => ['id' => (int) $c['id_category'], 'name' => (string) $c['name']],
                (new FbCategoryModel())->getOptions()
            ),
            default => [],
        };
    }

    private function createBrand(string $name): int
    {
        $exists = (new FbBrandModel())->where('name', $name)->first();
        if ($exists !== null) {
            return (int) $exists['id_brand'];
        }

        return (int) (new FbBrandModel())->insert(['name' => $name]);
    }

    private function createCategory(string $name): int
    {
        return (int) (new FbCategoryModel())->createCategory([
            'id_parent' => 1,
            'name'      => $name,
            'active'    => 1,
        ]);
    }
}
