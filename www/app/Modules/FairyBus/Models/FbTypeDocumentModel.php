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

/**
 * Tipologie documento (fb_type_document): referenziate da
 * fb_document.type e fb_document_detail.type.
 * L'id 0 ("Default") e' il valore di ripiego e non e' eliminabile.
 */
class FbTypeDocumentModel extends Model
{
    public const TYPE_DEFAULT = 0;
    public const TYPE_WAREHOUSE = 1;
    public const TYPE_SERVICE = 2;
    public const TYPE_FUEL = 3;

    protected $table = 'fb_type_document';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = false;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'id',
        'name',
        'description',
    ];
    protected $useTimestamps = false;

    /**
     * Elenco ordinato dei tipi documento.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->orderBy('id', 'ASC')->findAll();
    }

    /**
     * Prossimo id disponibile (id assegnati manualmente, serve lo 0).
     */
    public function nextId(): int
    {
        $row = $this->db->table($this->table)->selectMax('id')->get()->getRowArray();

        return (int) ($row['id'] ?? -1) + 1;
    }

    /**
     * True se il tipo e' referenziato da documenti o righe dettaglio.
     */
    public function isInUse(int $id): bool
    {
        $inDocs = (bool) $this->db->table('fb_document')->where('type', $id)->limit(1)->get()->getRow();
        if ($inDocs) {
            return true;
        }

        return (bool) $this->db->table('fb_document_detail')->where('type', $id)->limit(1)->get()->getRow();
    }

    /**
     * Mappa id => name per i filtri/select del frontend.
     *
     * @return array<int, string>
     */
    public function optionsMap(): array
    {
        $map = [];
        foreach ($this->listAll() as $row) {
            $map[(int) $row['id']] = $row['name'];
        }

        return $map;
    }
}
