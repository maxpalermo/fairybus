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
use FairyBus\Libraries\DbBackup;
use Throwable;

/**
 * Backup/restore del database da Impostazioni -> Backup.
 * Gli archivi ZIP restano in writable/backups (fuori dalla document root);
 * creazione schedulabile via CLI: `php spark fb:backup [--keep N]`.
 */
class Backup extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        try {
            return $this->jsonResponse(['success' => true, 'rows' => DbBackup::list()]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        try {
            $name = DbBackup::create();

            return $this->jsonResponse(['success' => true, 'name' => $name]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function download(string $name): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $path = DbBackup::path($name);
        if ($path === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Backup non trovato.'], 404);
        }

        return $this->response->download($path, null)->setFileName($name);
    }

    public function restore(string $name): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        try {
            $statements = DbBackup::restore($name);

            return $this->jsonResponse(['success' => true, 'statements' => $statements]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function delete(string $name): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        if (!DbBackup::delete($name)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Backup non trovato o non eliminabile.'], 404);
        }

        return $this->jsonResponse(['success' => true]);
    }
}
