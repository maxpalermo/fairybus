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

namespace FairyBus\Models;

use CodeIgniter\Model;

class FbVehicleDocumentModel extends Model
{
    protected $table = 'fb_vehicle_document';
    protected $primaryKey = 'id_document';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $allowedFields = ['id_vehicle', 'document', 'description'];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';

    /**
     * @return list<array<string, mixed>>
     */
    public function getForVehicle(int $idVehicle): array
    {
        return $this->where('id_vehicle', $idVehicle)->orderBy('created_at', 'ASC')->findAll();
    }

    public function add(int $idVehicle, string $path, ?string $description = null): bool
    {
        return $this->insert([
            'id_vehicle' => $idVehicle,
            'document' => $path,
            'description' => $description,
        ]) !== false;
    }
}
