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
use FairyBus\Models\FbMaintenanceInvoiceModel;
use FairyBus\Models\FbMaintenanceModel;
use FairyBus\Models\FbMaintenanceTaskModel;

class Maintenance extends AdminController
{
    public function index(): ResponseInterface
    {
        return $this->renderAdmin('admin/maintenance.twig', [
            'page_title' => 'Manutenzione',
        ]);
    }

    public function printPdf(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $rows = (new FbMaintenanceModel())->listAll($ids);

        $pdf = PdfHelper::generate('print/maintenance.twig', [
            'title' => 'Manutenzioni',
            'rows' => $rows,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'landscape');

        return $this->pdfResponse($pdf, 'manutenzioni');
    }

    /**
     * Stampa della singola manutenzione con task e ricambi (anteprima inline).
     */
    public function printSingle(int $id): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        $maintenance = (new FbMaintenanceModel())->findById($id);
        if ($maintenance === null) {
            return $this->response->setStatusCode(404)->setBody('Manutenzione non trovata.');
        }

        $maintenance['tasks'] = (new FbMaintenanceTaskModel())->listByMaintenance($id);
        $maintenance['details'] = (new FbMaintenanceDetailModel())->listByMaintenance($id);
        $maintenance['invoices'] = (new FbMaintenanceInvoiceModel())->listByMaintenance($id);

        $pdf = PdfHelper::generate('print/maintenance_single.twig', [
            'title' => 'Manutenzione ' . $maintenance['id_maintenance'],
            'maintenance' => $maintenance,
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'manutenzione_' . $id);
    }

    /**
     * Stampa dettagliata delle manutenzioni selezionate: un documento per
     * manutenzione seguito, se sono più di una, dal riepilogo ricambi.
     */
    public function printDetails(): ResponseInterface
    {
        $ids = $this->getPrintIds();
        if ($ids instanceof ResponseInterface) {
            return $ids;
        }

        $maintenances = self::loadMaintenances($ids);
        $summary = self::aggregateDetails($maintenances);

        $pdf = PdfHelper::generate('print/maintenance_details.twig', [
            'title' => 'Dettaglio manutenzioni',
            'maintenances' => $maintenances,
            'summary' => $summary,
            'grand_total' => array_sum(array_column($summary, 'total')),
            'user' => $this->getCurrentUser(),
        ], 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'manutenzioni_dettagli');
    }

    /**
     * Report dettagliato massivo: scheda completa per manutenzione
     * (ricambi + manodopera) e riepilogo ricambi aggregato.
     * Sorgente: `ids` selezionati oppure `month`+`year`; `format` = pdf|html
     * (l'HTML si apre in nuova scheda e si stampa dal browser).
     */
    public function printReport(): ResponseInterface
    {
        if (!$this->isLoggedIn()) {
            return redirect()->to('/login');
        }

        set_time_limit(0);

        $format = (string) $this->request->getPost('format') === 'html' ? 'html' : 'pdf';

        $ids = [];
        $raw = (string) $this->request->getPost('ids');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $ids = array_values(array_filter(array_map('intval', $decoded), static fn(int $i): bool => $i > 0));
            }
        }
        $month = (int) $this->request->getPost('month') ?: null;
        $year = (int) $this->request->getPost('year') ?: null;
        if ($month !== null && ($month < 1 || $month > 12)) {
            $month = null;
        }

        if ($ids === [] && !($month && $year)) {
            return $this->response->setStatusCode(422)->setBody('Seleziona le righe oppure indica mese e anno.');
        }

        $maintenances = self::loadMaintenances($ids, $month, $year);
        if ($maintenances === []) {
            return $this->response->setStatusCode(422)->setBody('Nessuna manutenzione trovata per i criteri indicati.');
        }

        $summary = self::aggregateDetails($maintenances);

        $data = [
            'title' => 'Report manutenzioni',
            'maintenances' => $maintenances,
            'summary' => $summary,
            'grand_total' => array_sum(array_column($summary, 'total')),
            'period_label' => $month && $year ? sprintf('%02d/%d', $month, $year) : null,
            'user' => $this->getCurrentUser(),
        ];

        if ($format === 'html') {
            return $this->response
                ->setContentType('text/html', 'utf-8')
                ->setBody(Twig::render('admin/maintenance_details_html.twig', $data));
        }

        $pdf = PdfHelper::generate('print/maintenance_details.twig', $data, 'a4', 'portrait');

        return $this->pdfResponse($pdf, 'report_manutenzioni');
    }

    /**
     * Manutenzioni con tasks, ricambi e fatture: 4 query totali indipendentemente
     * dal numero di schede (niente N+1 sulle stampe massive).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private static function loadMaintenances(array $ids, ?int $month = null, ?int $year = null): array
    {
        $maintenances = (new FbMaintenanceModel())->listAll($ids, null, $month, $year);
        if ($maintenances === []) {
            return [];
        }

        $mIds = array_map('intval', array_column($maintenances, 'id_maintenance'));
        $group = static function (array $rows): array {
            $map = [];
            foreach ($rows as $r) {
                $map[(int) $r['id_maintenance']][] = $r;
            }
            return $map;
        };

        $tasksByM = $group((new FbMaintenanceTaskModel())->listByMaintenances($mIds));
        $detailsByM = $group((new FbMaintenanceDetailModel())->listByMaintenances($mIds));
        $invoicesByM = $group((new FbMaintenanceInvoiceModel())->listByMaintenances($mIds));

        foreach ($maintenances as &$m) {
            $id = (int) $m['id_maintenance'];
            $m['tasks'] = $tasksByM[$id] ?? [];
            $m['details'] = $detailsByM[$id] ?? [];
            $m['invoices'] = $invoicesByM[$id] ?? [];
        }
        unset($m);

        return $maintenances;
    }

    /**
     * Riepilogo ricambi aggregato sui dettagli delle manutenzioni:
     * quantita', imponibile e totale raggruppati per codice+nome.
     *
     * @param list<array<string, mixed>> $maintenances schede con chiave 'details'
     * @return array<string, array<string, mixed>>
     */
    private static function aggregateDetails(array $maintenances): array
    {
        $summary = [];
        foreach ($maintenances as $m) {
            foreach ($m['details'] ?? [] as $d) {
                $key = (string) ($d['sku'] ?? '') . '|' . (string) ($d['product_name'] ?? '');
                if ($key === '|') {
                    $key = 'riga_' . (string) ($d['id_maintenance_detail'] ?? count($summary));
                }
                $qty = abs((float) ($d['quantity'] ?? 0));
                $importo = $qty * (float) ($d['price'] ?? 0) * (1 - abs((float) ($d['discount'] ?? 0)) / 100);
                $rowTotal = $importo * (1 + (float) ($d['vat_rate'] ?? 0) / 100);
                if (!isset($summary[$key])) {
                    $summary[$key] = [
                        'sku' => $d['sku'] ?? '—',
                        'product_name' => $d['product_name'] ?? '—',
                        'unit_abbr' => $d['unit_abbr'] ?? 'pz',
                        'qty' => 0.0,
                        'amount' => 0.0,
                        'total' => 0.0,
                    ];
                }
                $summary[$key]['qty'] += $qty;
                $summary[$key]['amount'] += $importo;
                $summary[$key]['total'] += $rowTotal;
            }
        }

        uasort($summary, static fn(array $a, array $b): int => strcasecmp((string) $a['product_name'], (string) $b['product_name']));

        return $summary;
    }
}
