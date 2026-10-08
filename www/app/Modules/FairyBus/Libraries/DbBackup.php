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

namespace FairyBus\Libraries;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Backup/restore del database in archivio ZIP (dump SQL generato in PHP,
 * senza dipendere da mysqldump). I file restano in writable/backups,
 * fuori dalla document root.
 */
class DbBackup
{
    private const PREFIX = 'fb-backup-';
    private const SQL_NAME = 'backup.sql';
    private const INSERT_CHUNK = 200;

    public static function dir(): string
    {
        $dir = rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossibile creare la cartella backup: ' . $dir);
        }

        // protezione extra nel caso writable fosse esposto dal web server
        foreach ([
            'index.html' => '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body></body></html>',
            '.htaccess' => "Require all denied\nDeny from all\n",
        ] as $file => $content) {
            if (!is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                @file_put_contents($dir . DIRECTORY_SEPARATOR . $file, $content);
            }
        }

        return $dir;
    }

    /**
     * @return list<array{name: string, size: int, created_at: string, mtime: int}>
     */
    public static function list(): array
    {
        $dir = self::dir();
        $rows = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . self::PREFIX . '*.zip') ?: [] as $file) {
            $rows[] = [
                'name' => basename($file),
                'size' => (int) filesize($file),
                'created_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
                'mtime' => (int) filemtime($file),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return $rows;
    }

    /**
     * Crea il backup: dump SQL compresso in ZIP. Restituisce il nome file.
     * Con $keep > 0 elimina i backup piu' vecchi oltre la soglia.
     */
    public static function create(?int $keep = null): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Estensione PHP "zip" non disponibile: impossibile creare l\'archivio.');
        }

        $db = \Config\Database::connect();
        $sql = self::dump($db);

        $name = self::PREFIX . date('Ymd-His') . '.zip';
        $path = self::dir() . DIRECTORY_SEPARATOR . $name;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile creare il file ZIP del backup.');
        }
        $zip->addFromString(self::SQL_NAME, $sql);
        $zip->close();

        if (!is_file($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('Il file di backup risulta vuoto.');
        }

        if ($keep !== null && $keep > 0) {
            foreach (array_slice(self::list(), $keep) as $old) {
                @unlink(self::dir() . DIRECTORY_SEPARATOR . $old['name']);
            }
        }

        return $name;
    }

    public static function delete(string $name): bool
    {
        $path = self::path($name);
        if ($path === null) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Percorso sicuro del file: rifiuta nomi non conformi (path traversal).
     */
    public static function path(string $name): ?string
    {
        if (!preg_match('/^' . preg_quote(self::PREFIX, '/') . '\d{8}-\d{6}\.zip$/', $name)) {
            return null;
        }
        $path = self::dir() . DIRECTORY_SEPARATOR . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * Ripristina il database dall'archivio ZIP. Restituisce il numero di
     * statement eseguiti.
     */
    public static function restore(string $name): int
    {
        $path = self::path($name);
        if ($path === null) {
            throw new RuntimeException('Backup non trovato: ' . $name);
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Estensione PHP "zip" non disponibile.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Impossibile aprire l\'archivio del backup.');
        }
        $sql = $zip->getFromName(self::SQL_NAME);
        $zip->close();
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Il backup non contiene un dump SQL valido.');
        }

        $db = \Config\Database::connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');

        $executed = 0;
        try {
            // ogni statement del dump termina con ";\n"; i valori escapati non
            // contengono mai un newline grezzo, quindi lo split e' sicuro
            foreach (explode(";\n", $sql) as $statement) {
                $statement = trim($statement);
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                $db->query($statement);
                $executed++;
            }
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }

        return $executed;
    }

    /**
     * Dump SQL completo: DROP + CREATE per ogni tabella e INSERT dei dati.
     */
    private static function dump(BaseConnection $db): string
    {
        $sql = '-- Fairy Bus backup ' . date('Y-m-d H:i:s') . "\n"
            . 'SET FOREIGN_KEY_CHECKS=0;' . "\n"
            . 'SET NAMES ' . $db->charset . ';' . "\n\n";

        foreach ($db->listTables() as $table) {
            $create = $db->query('SHOW CREATE TABLE `' . $table . '`')->getRowArray();
            $createSql = $create['Create Table'] ?? array_values($create)[1] ?? null;
            if ($createSql === null) {
                continue;
            }

            $sql .= 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $createSql . ";\n\n";

            $count = (int) $db->table($table)->countAllResults(false);
            if ($count === 0) {
                continue;
            }

            // ordinamento stabile sulla prima colonna (per convenzione la PK)
            // per una paginazione deterministica delle INSERT
            $firstCol = $db->getFieldNames($table)[0] ?? null;
            $columns = null;
            for ($offset = 0; $offset < $count; $offset += self::INSERT_CHUNK) {
                $b = $db->table($table)->limit(self::INSERT_CHUNK, $offset);
                if ($firstCol !== null) {
                    $b->orderBy('`' . $firstCol . '`');
                }
                $rows = $b->get()->getResultArray();
                if ($rows === []) {
                    break;
                }
                $columns ??= array_keys($rows[0]);
                $cols = '`' . implode('`, `', $columns) . '`';

                $values = [];
                foreach ($rows as $row) {
                    $escaped = [];
                    foreach ($columns as $col) {
                        $escaped[] = $row[$col] === null ? 'NULL' : $db->escape($row[$col]);
                    }
                    $values[] = '(' . implode(', ', $escaped) . ')';
                }
                $sql .= 'INSERT INTO `' . $table . '` (' . $cols . ') VALUES ' . "\n"
                    . implode(",\n", $values) . ";\n\n";
            }
        }

        return $sql . 'SET FOREIGN_KEY_CHECKS=1;' . "\n";
    }
}
