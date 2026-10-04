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

class FbMenuModel extends Model
{
    protected $table = 'fb_menu';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'id_parent',
        'title',
        'description',
        'icon',
        'color_bg',
        'color_fg',
        'position',
        'route',
    ];
    protected $useTimestamps = false;

    /**
     * Struttura di default del menu (stessa del menu "di fabbrica").
     * Usata come replica quando fb_menu è vuoto e come riferimento per
     * ripristinare il menu predefinito dall'editor.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaultTree(): array
    {
        return [
            ['title' => 'Dashboard', 'icon' => 'dashboard', 'route' => 'admin/dashboard'],
            [
                'title' => 'Anagrafiche',
                'icon' => 'book',
                'children' => [
                    ['title' => 'Clienti', 'route' => 'admin/customers'],
                    ['title' => 'Fornitori', 'route' => 'admin/suppliers'],
                    ['title' => 'Nazioni / Province / Città', 'route' => 'admin/locations'],
                ],
            ],
            [
                'title' => 'Officina',
                'icon' => 'wrench',
                'children' => [
                    ['title' => 'Veicoli', 'route' => 'admin/vehicles'],
                    ['title' => 'Marche', 'route' => 'admin/brands'],
                    ['title' => 'Caratteristiche', 'route' => 'admin/features'],
                    ['title' => 'Manutenzione', 'route' => 'admin/maintenance'],
                    ['title' => 'Voci di scadenza', 'route' => 'admin/expirations'],
                    ['title' => 'Calendario', 'route' => 'admin/calendar'],
                ],
            ],
            [
                'title' => 'Documenti',
                'icon' => 'file',
                'children' => [
                    ['title' => 'Carichi', 'route' => 'admin/documents'],
                    ['title' => 'Scarichi', 'route' => 'admin/unloads'],
                    ['title' => 'Fatture', 'route' => 'admin/invoices'],
                ],
            ],
            [
                'title' => 'Rifornimenti',
                'icon' => 'fuel',
                'children' => [
                    ['title' => 'Punti di rifornimento', 'route' => 'admin/refuelling-stations'],
                    ['title' => 'Carico', 'route' => 'admin/refuelling-load'],
                    ['title' => 'Gestione carburante', 'route' => 'admin/refuelling'],
                    ['title' => 'Statistiche', 'route' => 'admin/refuelling-stats'],
                ],
            ],
            [
                'title' => 'Magazzino',
                'icon' => 'box',
                'children' => [
                    ['title' => 'Categorie', 'route' => 'admin/categories'],
                    ['title' => 'Prodotti', 'route' => 'admin/products'],
                    ['title' => 'Giacenze', 'route' => 'admin/stocks'],
                ],
            ],
            ['title' => 'Impostazioni', 'icon' => 'settings', 'route' => 'admin/settings'],
        ];
    }

    /**
     * Albero dal DB: radici ordinate per position, ciascuna con children.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        $rows = $this->orderBy('position', 'ASC')->orderBy('id', 'ASC')->findAll();
        $roots = [];
        $children = [];
        foreach ($rows as $row) {
            if ($row['id_parent'] === null) {
                $roots[(int) $row['id']] = $row;
            } else {
                $children[(int) $row['id_parent']][] = $row;
            }
        }

        $out = [];
        foreach ($roots as $id => $root) {
            $root['children'] = $children[$id] ?? [];
            $out[] = $root;
        }

        return $out;
    }

    /**
     * True se esiste un menu salvato.
     */
    public function hasSavedMenu(): bool
    {
        return (bool) $this->db->table('fb_menu')->select('1')->limit(1)->get()->getRow();
    }

    /**
     * Sostituisce l'intera struttura del menu.
     *
     * @param list<array<string, mixed>> $tree nodi radice con eventuale chiave children
     */
    public function saveTree(array $tree): void
    {
        $db = $this->db;
        $db->transStart();

        $db->table('fb_menu')->truncate();

        foreach ($tree as $i => $node) {
            $children = $node['children'] ?? [];
            $id = $this->insertNode($node, null, $i);
            foreach ($children as $j => $child) {
                $this->insertNode($child, $id, $j);
            }
        }

        $db->transComplete();
    }

    /**
     * @param array<string, mixed> $node
     */
    private function insertNode(array $node, ?int $parentId, int $position): int
    {
        $hasChildren = !empty($node['children']);

        return (int) $this->insert([
            'id_parent' => $parentId,
            'title' => (string) ($node['title'] ?? ''),
            'description' => ($node['description'] ?? '') !== '' ? (string) $node['description'] : null,
            'icon' => ($node['icon'] ?? '') !== '' ? (string) $node['icon'] : null,
            'color_bg' => ($node['color_bg'] ?? '') !== '' ? (string) $node['color_bg'] : null,
            'color_fg' => ($node['color_fg'] ?? '') !== '' ? (string) $node['color_fg'] : null,
            'position' => $position,
            'route' => $hasChildren ? null : (($node['route'] ?? '') !== '' ? (string) $node['route'] : null),
        ]);
    }
}
