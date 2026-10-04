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
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Libraries\LegacyDatabase;

class Imports extends AdminController
{
    /**
     * List legacy tables (those not starting with the fb_ prefix)
     * together with their record count.
     */
    public function tables(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        /** @var BaseConnection $db */
        $db = \Config\Database::connect();
        $legacy = [];

        foreach (LegacyDatabase::listTables($db) as $table) {
            $legacy[] = [
                'name' => $table,
                'count' => LegacyDatabase::withoutPrefix(
                    $db,
                    static fn(BaseConnection $db): int => $db->table($table)->countAllResults(),
                ),
            ];
        }

        usort($legacy, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);

        return $this->jsonResponse([
            'success' => true,
            'tables' => $legacy,
        ]);
    }

    /**
     * Stub for importing a legacy table.
     * The actual mapping will be implemented later.
     */
    public function import(string $tableName): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        /** @var BaseConnection $db */
        $db = \Config\Database::connect();
        $allTables = LegacyDatabase::listTables($db);

        if (!in_array($tableName, $allTables, true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella non valida.'], 422);
        }

        $total = LegacyDatabase::withoutPrefix(
            $db,
            static fn(BaseConnection $db): int => $db->table($tableName)->countAllResults(),
        );

        // TODO: implement real import logic per table mapping.
        return $this->jsonResponse([
            'success' => true,
            'message' => "Importazione della tabella \"{$tableName}\" avviata.",
            'table' => $tableName,
            'total' => $total,
            'imported' => 0,
        ]);
    }
}
