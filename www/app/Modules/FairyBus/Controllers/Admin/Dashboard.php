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

namespace FairyBus\Controllers\Admin;

use App\Controllers\AdminController;

class Dashboard extends AdminController
{
    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $kpis = [
            [
                'title' => 'Autobus in officina',
                'value' => '12',
                'trend' => 2,
                'positive' => true,
                'icon' => 'bus',
                'sparkline' => $this->sparkline([8, 9, 10, 9, 11, 10, 12, 11, 13, 12, 12, 12]),
            ],
            [
                'title' => 'Manutenzioni oggi',
                'value' => '3',
                'trend' => -1,
                'positive' => false,
                'icon' => 'wrench',
                'sparkline' => $this->sparkline([2, 3, 1, 4, 2, 3, 2, 1, 3, 2, 3, 3]),
            ],
            [
                'title' => 'Scadenze prossimi 7gg',
                'value' => '8',
                'trend' => 14,
                'positive' => false,
                'icon' => 'calendar',
                'sparkline' => $this->sparkline([3, 4, 2, 5, 3, 4, 6, 5, 7, 6, 8, 8]),
            ],
            [
                'title' => 'Interventi aperti',
                'value' => '5',
                'trend' => -20,
                'positive' => true,
                'icon' => 'clipboard',
                'sparkline' => $this->sparkline([9, 8, 8, 7, 7, 6, 6, 5, 5, 5, 5, 5]),
            ],
        ];

        $overviewLabels = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
        $overviewSeries = [
            'Interventi' => [12, 15, 13, 18, 20, 22, 21, 25, 28, 32, 35, 38],
            'Manutenzioni' => [4, 5, 4, 6, 7, 8, 7, 9, 11, 13, 15, 17],
            'Fatturato' => [8, 10, 9, 12, 14, 13, 15, 17, 16, 19, 21, 23],
        ];
        $overviewPaths = [];
        foreach ($overviewSeries as $name => $values) {
            $overviewPaths[$name] = [
                'line' => $this->linePath($values, 760, 220),
                'area' => $this->areaPath($values, 760, 220),
                'color' => match ($name) {
                    'Interventi' => '#f97316',
                    'Manutenzioni' => '#0ea5e9',
                    'Fatturato' => '#22c55e',
                    default => '#71717a',
                },
            ];
        }

        $upcomingExpirations = [
            ['label' => 'Assicurazione CS458930', 'vehicle' => 'Iveco Daily 45C', 'due' => '2026-09-05', 'type' => 'Assicurazione'],
            ['label' => 'Revisione annuale', 'vehicle' => 'Mercedes Sprinter', 'due' => '2026-09-07', 'type' => 'Revisione'],
            ['label' => 'Bollo', 'vehicle' => 'Fiat Ducato', 'due' => '2026-09-10', 'type' => 'Bollo'],
            ['label' => 'Tagliando 120.000 km', 'vehicle' => 'Setra S415', 'due' => '2026-09-12', 'type' => 'Manutenzione'],
        ];

        $expirationDistribution = [
            ['label' => 'Assicurazione', 'value' => 40, 'color' => '#f97316'],
            ['label' => 'Revisione', 'value' => 30, 'color' => '#0d9488'],
            ['label' => 'Bollo', 'value' => 20, 'color' => '#0369a1'],
            ['label' => 'Manutenzione', 'value' => 10, 'color' => '#fbbf24'],
        ];

        $lowStockParts = [
            ['name' => 'Filtro olio Bosch F026407183', 'qty' => 2, 'threshold' => 5],
            ['name' => 'Pastiglie freno TRW GDB1330', 'qty' => 1, 'threshold' => 4],
            ['name' => 'Olio motore 5W-30 20L', 'qty' => 0, 'threshold' => 2],
        ];

        return $this->renderAdmin('admin/dashboard.twig', [
            'page_title' => 'Dashboard',
            'kpis' => $kpis,
            'overview_labels' => $overviewLabels,
            'overview_paths' => $overviewPaths,
            'upcoming_expirations' => $upcomingExpirations,
            'expiration_distribution' => $expirationDistribution,
            'low_stock_parts' => $lowStockParts,
        ]);
    }

    /**
     * @param int[] $values
     */
    private function sparkline(array $values): string
    {
        return $this->linePath($values, 120, 40);
    }

    /**
     * @param int[] $values
     */
    private function linePath(array $values, int $width, int $height): string
    {
        $min = min($values);
        $max = max($values);
        $range = $max - $min ?: 1;
        $count = count($values);
        $step = $count > 1 ? $width / ($count - 1) : 0;

        $points = [];
        foreach ($values as $i => $v) {
            $x = $step * $i;
            $y = $height - (($v - $min) / $range * $height);
            $points[] = sprintf('%s,%s', round($x, 2), round($y, 2));
        }

        return 'M ' . implode(' L ', $points);
    }

    /**
     * @param int[] $values
     */
    private function areaPath(array $values, int $width, int $height): string
    {
        $line = $this->linePath($values, $width, $height);

        return sprintf('%s L %s,%s L %s,%s Z', $line, $width, $height, 0, $height);
    }
}
