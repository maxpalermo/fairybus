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

    public function upload(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $file = $this->request->getFile('backup');
        if ($file === null || !$file->isValid()) {
            return $this->jsonResponse(['success' => false, 'error' => 'File non caricato o non valido.'], 422);
        }
        if (!in_array(strtolower((string) $file->getClientExtension()), ['zip', 'sql'], true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Il file deve essere un dump .sql o un archivio .zip.'], 422);
        }

        try {
            $name = DbBackup::import($file->getTempName(), (string) $file->getClientName());

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
            // restore a blocchi: il frontend itera fino a done=true
            $offset = max(0, (int) $this->request->getPost('offset'));
            $limit = max(0, (int) $this->request->getPost('limit'));
            $result = DbBackup::restore($name, $offset, $limit);

            return $this->jsonResponse(['success' => true] + $result);
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

    /**
     * Tabelle che non appartengono all'applicazione (non fb_* e non
     * migrations, solo BASE TABLE): candidate alla pulizia post-import.
     */
    public function unusedTables(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        try {
            return $this->jsonResponse(['success' => true, 'tables' => self::unusedTableNames()]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Elimina UNA tabella non usata: il frontend itera sulla lista per
     * mostrare il progresso. Il nome deve essere nella whitelist
     * ricalcolata server-side — mai fidarsi del client.
     */
    public function dropTable(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $table = (string) $this->request->getPost('table');
        if (!in_array($table, self::unusedTableNames(), true)) {
            return $this->jsonResponse(['success' => false, 'error' => "Tabella '{$table}' non eliminabile."], 422);
        }

        try {
            $db = \Config\Database::connect();
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->query('DROP TABLE `' . str_replace('`', '', $table) . '`');
            $db->query('SET FOREIGN_KEY_CHECKS = 1');

            return $this->jsonResponse(['success' => true, 'table' => $table]);
        } catch (Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * @return list<string>
     */
    private static function unusedTableNames(): array
    {
        $names = [];
        foreach (\Config\Database::connect()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->getResultArray() as $row) {
            $name = (string) array_values($row)[0];
            if ($name !== 'migrations' && !str_starts_with($name, 'fb_')) {
                $names[] = $name;
            }
        }
        sort($names);

        return $names;
    }
}
