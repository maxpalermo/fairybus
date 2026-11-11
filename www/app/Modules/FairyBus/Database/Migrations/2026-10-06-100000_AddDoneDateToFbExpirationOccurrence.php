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
 */

declare(strict_types=1);

namespace FairyBus\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDoneDateToFbExpirationOccurrence extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('fb_expiration_occurrence', [
            'done_date' => [
                'type' => 'DATE',
                'null' => true,
                'after' => 'state',
                'comment' => 'Data di esecuzione scelta dall\'utente',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('fb_expiration_occurrence', 'done_date');
    }
}
