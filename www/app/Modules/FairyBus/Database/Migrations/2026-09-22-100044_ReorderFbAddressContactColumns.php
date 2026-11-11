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

namespace FairyBus\Database\Migrations;

use CodeIgniter\Database\Migration;

class ReorderFbAddressContactColumns extends Migration
{
    public function up(): void
    {
        // Riordina: id_customer subito dopo id_address, poi id_supplier
        $this->db->query('ALTER TABLE `fb_address` MODIFY COLUMN `id_customer` INT(11) UNSIGNED NULL AFTER `id_address`');
        $this->db->query('ALTER TABLE `fb_address` MODIFY COLUMN `id_supplier` INT(11) UNSIGNED NULL AFTER `id_customer`');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE `fb_address` MODIFY COLUMN `id_supplier` INT(11) UNSIGNED NULL AFTER `id_address`');
        $this->db->query('ALTER TABLE `fb_address` MODIFY COLUMN `id_customer` INT(11) UNSIGNED NULL AFTER `id_supplier`');
    }
}
