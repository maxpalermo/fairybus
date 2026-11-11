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
    /** Colori usati dalla legenda del grafico a ciambella. */
    private const DONUT_COLORS = ['#f97316', '#0d9488', '#0369a1', '#fbbf24', '#71717a'];

    public function index(): \CodeIgniter\HTTP\ResponseInterface
    {
        $db = \Config\Database::connect();
        $today = date('Y-m-d');

        // --- Conteggi principali ---
        $vehicles = (int) $db->table('fb_vehicle')->where('status', 'active')->countAllResults();
        $vehWithDeadlines = (int) ($db->table('fb_expiration_occurrence o')
            ->select('COUNT(DISTINCT e.id_vehicle) AS n', false)
            ->join('fb_expiration e', 'e.id_expiration = o.id_expiration', 'left')
            ->where('COALESCE(o.state,0) <> 4', null, false)
            ->get()->getRow('n') ?? 0);

        $occExpired = (int) $db->table('fb_expiration_occurrence')
            ->where('COALESCE(state,0) <> 4', null, false)
            ->where('expiration_date <', $today)
            ->countAllResults();
        $occNext30 = (int) $db->table('fb_expiration_occurrence')
            ->where('COALESCE(state,0) <> 4', null, false)
            ->where('expiration_date >=', $today)
            ->where('expiration_date <=', date('Y-m-d', strtotime('+30 days')))
            ->countAllResults();
        $occOpen = $occExpired + $occNext30
            + (int) $db->table('fb_expiration_occurrence')
                ->where('COALESCE(state,0) <> 4', null, false)
                ->where('expiration_date >', date('Y-m-d', strtotime('+30 days')))
                ->countAllResults();

        $thresholds = (int) $db->table('fb_stock')->where('notification_limit IS NOT NULL', null, false)->countAllResults();
        $lowStock = (int) $db->table('fb_stock')
            ->where('notification_limit IS NOT NULL', null, false)
            ->where('quantity <= notification_limit', null, false)
            ->countAllResults();

        $refuelMonth = $db->table('fb_refuelling')
            ->select('COALESCE(SUM(liters),0) AS liters, COUNT(*) AS n', false)
            ->where('direction', 'out')
            ->where("refuel_time >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", null, false)
            ->get()->getRowArray() ?? ['liters' => 0, 'n' => 0];

        // --- Serie annuali (ultimi 12 anni) per grafico e sparkline ---
        $years = range((int) date('Y') - 11, (int) date('Y'));
        $litersYear = $this->perYear($db, 'fb_refuelling', 'refuel_time', 'SUM(liters)', "direction = 'out'", $years);
        $maintYear = $this->perYear($db, 'fb_maintenance', 'date', 'COUNT(*)', null, $years);
        $docsYear = $this->perYear($db, 'fb_document', 'date', 'COUNT(*)', null, $years);
        $vehYear = $this->perYear($db, 'fb_vehicle', 'start_date', 'COUNT(*)', null, $years);

        $kpis = [
            [
                'title' => 'Automezzi attivi',
                'value' => number_format($vehicles, 0, ',', '.'),
                'sub' => $vehWithDeadlines . ' con scadenze aperte',
                'icon' => 'bus',
                'color' => '#0ea5e9',
                'sparkline' => $this->sparkline($vehYear),
            ],
            [
                'title' => 'Scadenze aperte',
                'value' => number_format($occOpen, 0, ',', '.'),
                'sub' => $occExpired . ' scadute · ' . $occNext30 . ' entro 30gg',
                'icon' => 'calendar',
                'color' => '#f97316',
                'sparkline' => $this->sparkline($this->perYear($db, 'fb_expiration_occurrence', 'expiration_date', 'COUNT(*)', 'COALESCE(state,0) <> 4', $years)),
            ],
            [
                'title' => 'Ricambi sottoscorta',
                'value' => (string) $lowStock,
                'sub' => 'su ' . $thresholds . ' soglie attive',
                'icon' => 'clipboard',
                'color' => '#ef4444',
                'sparkline' => $this->sparkline($this->perYear($db, 'fb_stock', 'date_add', 'COUNT(*)', 'notification_limit IS NOT NULL', $years)),
            ],
            [
                'title' => 'Litri erogati (mese)',
                'value' => number_format((float) $refuelMonth['liters'], 0, ',', '.'),
                'sub' => $refuelMonth['n'] . ' rifornimenti',
                'icon' => 'fuel',
                'color' => '#22c55e',
                'sparkline' => $this->sparkline($litersYear),
            ],
        ];

        // --- Grafico "Andamento": serie per anno ---
        $overviewSeries = [
            'Litri carburante' => $litersYear,
            'Manutenzioni' => $maintYear,
            'Documenti' => $docsYear,
        ];
        $overviewPaths = [];
        foreach ($overviewSeries as $name => $values) {
            $overviewPaths[$name] = [
                'line' => $this->linePath($values, 760, 220),
                'area' => $this->areaPath($values, 760, 220),
                'color' => match ($name) {
                    'Litri carburante' => '#f97316',
                    'Manutenzioni' => '#0ea5e9',
                    'Documenti' => '#22c55e',
                    default => '#71717a',
                },
            ];
        }

        // --- Scadenze: prima le imminenti (data crescente), poi le scadute più recenti ---
        $upcomingExpirations = $this->upcomingExpirations($db, $today);

        // --- Distribuzione scadenze aperte per tipologia ---
        $expirationDistribution = $this->expirationDistribution($db);

        // --- Ricambi sotto scorta (i più critici prima) ---
        $lowStockParts = $db->table('fb_stock s')
            ->select('p.name, s.quantity AS qty, s.notification_limit AS threshold')
            ->join('fb_product p', 'p.id_product = s.id_product', 'left')
            ->where('s.notification_limit IS NOT NULL', null, false)
            ->where('s.quantity <= s.notification_limit', null, false)
            ->orderBy('(s.quantity - s.notification_limit)', 'ASC', false)
            ->limit(5)
            ->get()->getResultArray();

        return $this->renderAdmin('admin/dashboard.twig', [
            'page_title' => 'Dashboard',
            'kpis' => $kpis,
            'overview_labels' => array_map('strval', $years),
            'overview_paths' => $overviewPaths,
            'upcoming_expirations' => $upcomingExpirations,
            'expiration_distribution' => $expirationDistribution,
            'expiration_total' => array_sum(array_column($expirationDistribution, 'count')),
            'low_stock_parts' => $lowStockParts,
        ]);
    }

    /**
     * Occorrenze aperte ordinate per urgenza: imminenti a data (ASC),
     * poi scadute (più recenti prima), poi scadenze chilometriche.
     *
     * @return list<array<string, mixed>>
     */
    private function upcomingExpirations(\CodeIgniter\Database\BaseConnection $db, string $today, int $limit = 5): array
    {
        $rows = $db->table('fb_expiration_occurrence o')
            ->select('o.expiration_date, o.km, e.id_expiration_tag, e.description,
                v.plate AS vehicle_plate, v.current_km, t.name AS tag_name, t.kind AS tag_kind')
            ->join('fb_expiration e', 'e.id_expiration = o.id_expiration', 'left')
            ->join('fb_vehicle v', 'v.id_vehicle = e.id_vehicle', 'left')
            ->join('fb_expiration_tag t', 't.id_expiration_tag = e.id_expiration_tag', 'left')
            ->where('COALESCE(o.state,0) <> 4', null, false)
            ->orderBy("CASE WHEN o.expiration_date >= '{$today}' THEN 0 WHEN o.expiration_date IS NOT NULL THEN 1 ELSE 2 END", 'ASC', false)
            ->orderBy('o.expiration_date', 'ASC')
            ->orderBy('o.km', 'ASC')
            ->limit(40)
            ->get()->getResultArray();

        $items = [];
        foreach ($rows as $row) {
            $isKm = $row['expiration_date'] === null && $row['km'] !== null;
            $items[] = [
                'label' => $row['description'] ?: ($row['tag_name'] ?? 'Scadenza'),
                'vehicle' => $row['vehicle_plate'] ?? '—',
                'type' => $row['tag_name'] ?? '—',
                'due_label' => $isKm
                    ? 'a ' . number_format((float) $row['km'], 0, ',', '.') . ' km'
                    : date('d/m/Y', strtotime((string) $row['expiration_date'])),
                'expired' => $isKm
                    ? ((float) $row['km'] <= (float) ($row['current_km'] ?? 0))
                    : ($row['expiration_date'] !== null && $row['expiration_date'] < $today),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Scadenze aperte raggruppate per etichetta (top 4 + "Altro").
     *
     * @return list<array{label: string, value: float, count: int, color: string}>
     */
    private function expirationDistribution(\CodeIgniter\Database\BaseConnection $db): array
    {
        $rows = $db->table('fb_expiration_occurrence o')
            ->select("COALESCE(t.name, 'Altro') AS name, COUNT(*) AS n", false)
            ->join('fb_expiration e', 'e.id_expiration = o.id_expiration', 'left')
            ->join('fb_expiration_tag t', 't.id_expiration_tag = e.id_expiration_tag', 'left')
            ->where('COALESCE(o.state,0) <> 4', null, false)
            ->groupBy('name')
            ->orderBy('n', 'DESC')
            ->get()->getResultArray();

        $total = array_sum(array_column($rows, 'n')) ?: 1;
        $top = array_slice($rows, 0, 4);
        $rest = array_slice($rows, 4);
        if ($rest !== []) {
            $top[] = ['name' => 'Altro', 'n' => array_sum(array_column($rest, 'n'))];
        }

        $out = [];
        foreach ($top as $i => $row) {
            $out[] = [
                'label' => $row['name'],
                'count' => (int) $row['n'],
                'value' => round($row['n'] * 100 / $total, 1),
                'color' => self::DONUT_COLORS[$i % count(self::DONUT_COLORS)],
            ];
        }

        return $out;
    }

    /**
     * Aggregazione per anno sugli ultimi N anni solari.
     *
     * @param list<int> $years
     * @return list<float>
     */
    private function perYear(\CodeIgniter\Database\BaseConnection $db, string $table, string $dateCol, string $agg, ?string $where, array $years): array
    {
        $builder = $db->table($table)
            ->select("YEAR({$dateCol}) AS y, {$agg} AS v", false)
            ->where("{$dateCol} IS NOT NULL", null, false)
            ->groupBy('y');
        if ($where !== null) {
            $builder->where($where, null, false);
        }

        $map = [];
        foreach ($builder->get()->getResultArray() as $row) {
            $map[(int) $row['y']] = (float) $row['v'];
        }

        return array_map(static fn(int $y): float => $map[$y] ?? 0.0, $years);
    }

    /**
     * @param float[] $values
     */
    private function sparkline(array $values): string
    {
        return $this->linePath($values, 120, 40);
    }

    /**
     * @param float[] $values
     */
    private function linePath(array $values, int $width, int $height): string
    {
        $values = $values === [] ? [0.0] : $values;
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
     * @param float[] $values
     */
    private function areaPath(array $values, int $width, int $height): string
    {
        $line = $this->linePath($values, $width, $height);

        return sprintf('%s L %s,%s L %s,%s Z', $line, $width, $height, 0, $height);
    }
}
