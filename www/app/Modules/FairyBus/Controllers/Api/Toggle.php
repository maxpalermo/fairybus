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

/**
 * Toggle istantaneo di campi booleani (0/1) su tabelle fb_*.
 * POST api/toggle-field { table, field, id } -> { success, value }
 * Solo tabelle/campi in whitelist.
 */
class Toggle extends AdminController
{
    /** @var array<string, array{pk: string, fields: list<string>}> */
    private const ALLOWED = [
        'fb_supplier' => ['pk' => 'id_supplier', 'fields' => ['fuel', 'active']],
        'fb_product' => ['pk' => 'id_product', 'fields' => ['active']],
    ];

    public function field(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $table = (string) $this->request->getPost('table');
        $field = (string) $this->request->getPost('field');
        $id = (int) $this->request->getPost('id');

        if (!isset(self::ALLOWED[$table]) || !in_array($field, self::ALLOWED[$table]['fields'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Campo non consentito.'], 422);
        }
        if ($id <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'ID non valido.'], 422);
        }

        $pk = self::ALLOWED[$table]['pk'];
        $db = \Config\Database::connect();
        $row = $db->table($table)->select($field)->where($pk, $id)->get()->getRowArray();
        if ($row === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Record non trovato.'], 404);
        }

        $value = (int) $row[$field] === 1 ? 0 : 1;
        $db->table($table)->where($pk, $id)->update([$field => $value]);

        return $this->jsonResponse(['success' => true, 'value' => $value]);
    }
}
