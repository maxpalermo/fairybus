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

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use FairyBus\Libraries\DbBackup;
use Throwable;

/**
 * Backup schedulabile del database (dump SQL in ZIP, writable/backups).
 * Esempio cron: `php spark fb:backup` oppure `php spark fb:backup --keep 14`.
 */
class FbBackup extends BaseCommand
{
    protected $group = 'FairyBus';
    protected $name = 'fb:backup';
    protected $description = 'Crea un backup ZIP del database in writable/backups.';
    protected $usage = 'fb:backup [--keep N]';
    protected $options = [
        '--keep' => 'Numero massimo di backup conservati (i piu\' vecchi vengono eliminati)',
    ];

    public function run(array $params)
    {
        $keep = CLI::getOption('keep');
        $keep = $keep !== null ? max(1, (int) $keep) : null;

        try {
            $name = DbBackup::create($keep);
            CLI::write('Backup creato: ' . $name, 'green');
        } catch (Throwable $e) {
            CLI::error('Backup fallito: ' . $e->getMessage());
            CLI::newLine();
            return EXIT_ERROR;
        }

        return EXIT_SUCCESS;
    }
}
