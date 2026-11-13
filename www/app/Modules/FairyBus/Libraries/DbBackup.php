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

    /**
     * Archivio demo fisso: non compare nello storico (non matcha PREFIX),
     * non e' eliminabile/scaricabile, serve solo al ripristino.
     */
    public const DEMO_NAME = 'fb-demo.zip';

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
        if ($name === self::DEMO_NAME) {
            return false; // il backup demo non e' eliminabile
        }
        $path = self::path($name);
        if ($path === null) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Modalita' demo: flag in fb_configuration.demo_mode (value='1').
     * La riga non e' esposta in alcuna interfaccia: i config si scrivono
     * solo via endpoint con nomi hardcoded, quindi si disattiva solo dal
     * database (es. phpMyAdmin: value=0). In demo lo storico backup e'
     * disabilitato e resta attivo solo il ripristino di fb-demo.zip.
     */
    public static function isDemoMode(): bool
    {
        static $demo = null;
        if ($demo === null) {
            try {
                $row = \Config\Database::connect()
                    ->table('fb_configuration')
                    ->select('value')
                    ->where('name', 'demo_mode')
                    ->get()
                    ->getRowArray();
                $demo = ($row['value'] ?? '0') === '1';
            } catch (\Throwable $e) {
                $demo = false;
            }
        }

        return $demo;
    }

    /**
     * Info sull'archivio demo (sezione dedicata nello storico): null se assente.
     *
     * @return array{name: string, size: int, created_at: string, mtime: int}|null
     */
    public static function demoInfo(): ?array
    {
        $path = self::dir() . DIRECTORY_SEPARATOR . self::DEMO_NAME;
        if (!is_file($path)) {
            return null;
        }

        return [
            'name' => self::DEMO_NAME,
            'size' => (int) filesize($path),
            'created_at' => date('Y-m-d H:i:s', (int) filemtime($path)),
            'mtime' => (int) filemtime($path),
        ];
    }

    /**
     * Importa un backup caricato dall'esterno: accetta un file .sql oppure
     * un archivio .zip contenente un dump SQL qualsiasi (backup.sql se
     * presente, altrimenti la prima entry *.sql). Il contenuto viene
     * normalizzato in fb-backup-*.zip con backup.sql, cosi' storico,
     * download e restore restano uniformi. Restituisce il nome salvato.
     */
    public static function import(string $sourcePath, string $clientName): string
    {
        $sql = self::extractSql($sourcePath, $clientName);
        if (trim($sql) === '' || !preg_match('/(CREATE|DROP|ALTER|INSERT\s+INTO)\s/i', $sql)) {
            throw new RuntimeException('Il file caricato non contiene un dump SQL valido.');
        }

        $dir = self::dir();
        $base = basename($clientName);
        if (preg_match('/^' . preg_quote(self::PREFIX, '/') . '\d{8}-\d{6}\.zip$/', $base) && !is_file($dir . DIRECTORY_SEPARATOR . $base)) {
            $name = $base;
        } else {
            // nome generato: scorre in avanti fino al primo timestamp libero
            $ts = time();
            do {
                $name = self::PREFIX . date('Ymd-His', $ts) . '.zip';
                $ts++;
            } while (is_file($dir . DIRECTORY_SEPARATOR . $name));
        }
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossibile salvare il backup importato.');
        }
        $zip->addFromString(self::SQL_NAME, $sql);
        $zip->close();
        @chmod($path, 0o644);

        // la data mostrata nello storico riflette il timestamp nel nome file
        $dt = \DateTime::createFromFormat('Ymd-His', substr($name, strlen(self::PREFIX), 15));
        if ($dt !== false) {
            @touch($path, $dt->getTimestamp());
        }

        return $name;
    }

    /**
     * Contenuto SQL dal file caricato: testo grezzo per .sql, estrazione
     * per .zip (backup.sql se presente, altrimenti la prima entry *.sql).
     */
    private static function extractSql(string $sourcePath, string $clientName): string
    {
        if (strtolower(pathinfo($clientName, PATHINFO_EXTENSION)) !== 'zip') {
            $sql = @file_get_contents($sourcePath);
            if ($sql === false) {
                throw new RuntimeException('Impossibile leggere il file caricato.');
            }

            return $sql;
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Estensione PHP "zip" non disponibile.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException('Il file caricato non e\' un archivio ZIP valido.');
        }
        $sql = $zip->getFromName(self::SQL_NAME);
        if ($sql === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                if (strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'sql') {
                    $sql = $zip->getFromIndex($i);
                    break;
                }
            }
        }
        $zip->close();
        if ($sql === false) {
            throw new RuntimeException('L\'archivio non contiene alcun file .sql.');
        }

        return $sql;
    }

    /**
     * Percorso sicuro del file: rifiuta nomi non conformi (path traversal).
     */
    public static function path(string $name): ?string
    {
        $valid = $name === self::DEMO_NAME || preg_match('/^' . preg_quote(self::PREFIX, '/') . '\d{8}-\d{6}\.zip$/', $name);
        if (!$valid) {
            return null;
        }
        $path = self::dir() . DIRECTORY_SEPARATOR . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * Ripristina il database dall'archivio ZIP a blocchi di statement
     * (offset/limit alimentano il dialog di avanzamento; $limit <= 0 = tutto).
     *
     * @return array{total: int, executed: int, done: bool}
     */
    public static function restore(string $name, int $offset = 0, int $limit = 0): array
    {
        $statements = self::loadStatements($name);
        $total = count($statements);
        $offset = max(0, $offset);
        $slice = $limit > 0 ? array_slice($statements, $offset, $limit) : array_slice($statements, $offset);

        $db = \Config\Database::connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');

        $executed = 0;
        try {
            foreach ($slice as $statement) {
                $db->query($statement);
                $executed++;
            }
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }

        return [
            'total' => $total,
            'executed' => $executed,
            'done' => ($offset + $executed) >= $total,
        ];
    }

    /**
     * Statement SQL del backup splittati e ripuliti. Ogni statement del dump
     * termina con ";\n"; i valori escapati non contengono mai un newline
     * grezzo, quindi lo split e' sicuro.
     *
     * @return list<string>
     */
    private static function loadStatements(string $name): array
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

        $statements = [];
        foreach (explode(";\n", $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            $statements[] = $statement;
        }

        return $statements;
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
