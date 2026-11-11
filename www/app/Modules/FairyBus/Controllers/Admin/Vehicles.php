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
use App\Libraries\Twig;
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Libraries\PdfHelper;
use FairyBus\Models\FbMaintenanceDetailModel;
use FairyBus\Models\FbVehicleModel;

class Vehicles extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/vehicles.twig', [
            'page_title' => 'Veicoli',
        ]);
    }

    public function detail(int $idVehicle): ResponseInterface
    {
        return $this->renderAdmin('admin/vehicle_detail.twig', [
            'page_title' => 'Dettaglio veicolo',
            'id_vehicle' => $idVehicle,
        ]);
    }

    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $rows = (new FbVehicleModel())->listWithBrand($ids);

        $pdf = PdfHelper::generate('print/vehicles.twig', [
            'title' => 'Elenco veicoli',
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'veicoli');
    }

    /**
     * Report dettagliato dello storico riparazioni di un veicolo:
     * una sezione per manutenzione (cronologica) + pagina di riepilogo
     * finale con totali e aggregato ricambi.
     * POST admin/vehicles/(:num)/repairs/print
     */
    public function printRepairs(int $idVehicle): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        $data = $this->buildRepairsData($idVehicle, $this->selectedDetailIds());
        if ($data === null) {
            return $this->response->setStatusCode(404)->setBody('Veicolo non trovato.');
        }

        $pdf = PdfHelper::generate('print/vehicle_repairs.twig', [
            'title' => 'Storico riparazioni ' . ($data['vehicle']['plate'] ?? ''),
            'vehicle' => $data['vehicle'],
            'maintenances' => $data['maintenances'],
            'parts_summary' => $data['parts_summary'],
            'totals' => $data['totals'],
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'riparazioni_' . ($data['vehicle']['plate'] ?? $idVehicle));
    }

    /**
     * Stesso report in formato HTML auto-stampante: usato dal frontend
     * quando lo storico è molto lungo (Dompdf diventerebbe troppo lento).
     * Il browser impagina e stampa/salva in PDF senza caricare il server.
     * GET admin/vehicles/(:num)/repairs/report
     */
    public function repairsReport(int $idVehicle): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        $data = $this->buildRepairsData($idVehicle, $this->selectedDetailIds());
        if ($data === null) {
            return $this->response->setStatusCode(404)->setBody('Veicolo non trovato.');
        }

        $html = Twig::render('print/vehicle_repairs.twig', [
            'title' => 'Storico riparazioni ' . ($data['vehicle']['plate'] ?? ''),
            'vehicle' => $data['vehicle'],
            'maintenances' => $data['maintenances'],
            'parts_summary' => $data['parts_summary'],
            'totals' => $data['totals'],
            'user' => $this->getCurrentUser(),
        ]);

        // Barra azioni a schermo (nascosta in stampa) + auto-print al load
        $inject = <<<'HTML'
<style>
    .print-footer { display: none !important; }
    @media screen {
        body { padding-top: 46px; }
        .fb-report-bar { position: fixed; top: 0; left: 0; right: 0; z-index: 9999; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 16px; background: #18181b; color: #f4f4f5; font-family: sans-serif; font-size: 13px; }
        .fb-report-bar button { padding: 5px 14px; border: none; border-radius: 6px; background: #f59e0b; color: #18181b; font-weight: 700; font-size: 13px; cursor: pointer; }
        .fb-report-bar button:hover { background: #fbbf24; }
    }
    @media print { .fb-report-bar { display: none !important; } }
</style>
<div class="fb-report-bar">
    <span>Report pronto per la stampa — usa il bottone o Ctrl+P, poi scegli "Salva come PDF".</span>
    <button type="button" onclick="window.print()">Stampa / Salva PDF</button>
</div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 500));</script>
HTML;

        return $this->response
            ->setContentType('text/html', 'utf-8')
            ->setBody(str_replace('</body>', $inject . '</body>', $html));
    }

    /**
     * Assembla veicolo + righe raggruppate per manutenzione + totali +
     * aggregato ricambi. Restituisce null se il veicolo non esiste.
     *
     * @return array{vehicle: array, maintenances: list<array>, parts_summary: array, totals: array}|null
     */
    /**
     * Ids delle righe dettaglio selezionate in tabella:
     * POST `ids` (JSON) per il PDF, GET `ids` (CSV) per il report HTML.
     * Null = tutto lo storico.
     *
     * @return list<int>|null
     */
    private function selectedDetailIds(): ?array
    {
        $raw = (string) ($this->request->getPost('ids') ?? $this->request->getGet('ids') ?? '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $values = is_array($decoded) ? $decoded : explode(',', $raw);
        $ids = array_values(array_filter(array_map('intval', $values), static fn(int $i): bool => $i > 0));
        return $ids === [] ? null : $ids;
    }

    private function buildRepairsData(int $idVehicle, ?array $onlyDetailIds = null): ?array
    {
        $vehicle = (new FbVehicleModel())->listWithBrand([$idVehicle])[0] ?? null;
        if ($vehicle === null) {
            return null;
        }

        $lines = (new FbMaintenanceDetailModel())->linesForVehicle($idVehicle);

        // Storico cronologico: la query torna DESC, raggruppo riordinando ASC
        $lines = array_reverse($lines);

        if ($onlyDetailIds !== null) {
            $lines = array_values(array_filter(
                $lines,
                static fn(array $l): bool => in_array((int) $l['id_maintenance_detail'], $onlyDetailIds, true),
            ));
        }

        $maintenances = [];
        $partsSummary = [];
        $totals = [
            'repairs' => 0,
            'lines' => count($lines),
            'parts_qty' => 0.0,
            'parts_amount' => 0.0,
            'labor_hours' => 0.0,
            'labor_amount' => 0.0,
            'grand_total' => 0.0,
        ];

        foreach ($lines as $line) {
            $idM = (int) $line['id_maintenance'];
            if (!isset($maintenances[$idM])) {
                $maintenances[$idM] = [
                    'id_maintenance' => $idM,
                    'date' => $line['maintenance_date'],
                    'km' => $line['maintenance_km'],
                    'note' => $line['maintenance_note'],
                    'lines' => [],
                    'tot_qty' => 0.0,
                    'tot_parts' => 0.0,
                    'tot_labor' => 0.0,
                    'tot_hours' => 0.0,
                    'total' => 0.0,
                ];
            }
            $m = &$maintenances[$idM];

            $qty = abs((float) ($line['quantity'] ?? 0));
            $importo = $qty * (float) ($line['price'] ?? 0) * (1 - abs((float) ($line['discount'] ?? 0)) / 100);
            $vat = $line['vat_rate'] !== null ? (float) $line['vat_rate'] : 0.0;
            $labor = abs((float) ($line['labor_manpower'] ?? 0));
            $rowTotal = ($importo + $labor) * (1 + $vat / 100);

            $line['_importo'] = $importo;
            $line['_total'] = $rowTotal;
            $m['lines'][] = $line;

            $m['tot_qty'] += $qty;
            $m['tot_parts'] += $importo;
            $m['tot_labor'] += $labor;
            $m['tot_hours'] += abs((float) ($line['labor_hours'] ?? 0));
            $m['total'] += $rowTotal;

            $totals['parts_qty'] += $qty;
            $totals['parts_amount'] += $importo;
            $totals['labor_hours'] += abs((float) ($line['labor_hours'] ?? 0));
            $totals['labor_amount'] += $labor;
            $totals['grand_total'] += $rowTotal;

            if (($line['id_product'] ?? null) !== null) {
                $key = (string) ($line['sku'] ?? '') . '|' . (string) ($line['product_name'] ?? '') . '|' . (string) ($line['price'] ?? '');
                if (!isset($partsSummary[$key])) {
                    $partsSummary[$key] = [
                        'sku' => $line['sku'] ?? '—',
                        'product_name' => $line['product_name'] ?? '—',
                        'price' => (float) ($line['price'] ?? 0),
                        'qty' => 0.0,
                        'amount' => 0.0,
                        'total' => 0.0,
                    ];
                }
                $partsSummary[$key]['qty'] += $qty;
                $partsSummary[$key]['amount'] += $importo;
                $partsSummary[$key]['total'] += $importo * (1 + $vat / 100);
            }
        }
        unset($m);

        $totals['repairs'] = count($maintenances);
        uasort($partsSummary, static fn(array $a, array $b): int => strcasecmp((string) $a['product_name'], (string) $b['product_name']));

        return [
            'vehicle' => $vehicle,
            'maintenances' => array_values($maintenances),
            'parts_summary' => $partsSummary,
            'totals' => $totals,
        ];
    }
}
