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

namespace FairyBus\Controllers\Api;

use App\Controllers\AdminController;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;

class Refuelling extends AdminController
{
    private const BATCH_SIZE = 500;

    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $direction = $this->request->getGet('direction');
        $model = new \FairyBus\Models\FbRefuellingModel();
        $rows = in_array($direction, ['in', 'out'], true)
            ? $model->listFiltered(['direction' => $direction])
            : $model->listAll();

        return $this->jsonResponse([
            'success' => true,
            'rows' => $rows,
        ]);
    }

    public function get(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $row = (new \FairyBus\Models\FbRefuellingModel())->findById($id);
        if ($row === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Rifornimento non trovato.'], 404);
        }

        return $this->jsonResponse(['success' => true, 'refuelling' => $row]);
    }

    /**
     * Ultimo rifornimento di un veicolo: km registrati e data.
     * exclude_id + before: in edit cerca il rifornimento precedente a quello in modifica.
     */
    public function lastKm(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idVehicle = (int) $this->request->getGet('vehicle_id');
        if ($idVehicle <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo non valido.'], 422);
        }

        $excludeId = (int) $this->request->getGet('exclude_id');
        $before = str_replace('T', ' ', (string) $this->request->getGet('before'));
        $before = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $before) ? $before : null;

        $last = (new \FairyBus\Models\FbRefuellingModel())->lastForVehicle($idVehicle, $excludeId, $before);

        return $this->jsonResponse([
            'success' => true,
            'km' => $last !== null ? (int) $last['km_at_refuel'] : null,
            'date' => $last['refuel_time'] ?? null,
        ]);
    }

    /**
     * Alimentazione del veicolo: fb_vehicle_feature (feature 'fuel_type')
     * contiene l'ordinale enum legacy, che coincide con fb_fuel_type.id.
     */
    public function vehicleFuel(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idVehicle = (int) $this->request->getGet('vehicle_id');
        if ($idVehicle <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo non valido.'], 422);
        }

        $db = \Config\Database::connect();
        $fuelTypeId = null;
        $feature = $db->table('fb_vehicle_feature vf')
            ->select('vf.value')
            ->join('fb_feature f', 'f.id_feature = vf.id_feature')
            ->where('vf.id_vehicle', $idVehicle)
            ->where('f.name', 'fuel_type')
            ->get()
            ->getRowArray();
        if ($feature !== null && $feature['value'] !== null && $feature['value'] !== '') {
            $candidate = (int) $feature['value'];
            if ($db->table('fb_fuel_type')->where('id_fuel_type', $candidate)->countAllResults() > 0) {
                $fuelTypeId = $candidate;
            }
        }

        return $this->jsonResponse(['success' => true, 'fuel_type' => $fuelTypeId]);
    }

    /**
     * Statistiche rifornimenti: totali per tipo carburante (carichi/scarichi/
     * giacenza), serie mensili (litri, costi, consumo medio km/l), consumo per
     * singolo scarico e lista movimenti — tutti soggetti agli stessi filtri.
     */
    public function stats(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $date = static function (string $key): ?string {
            $v = (string) \Config\Services::request()->getGet($key);

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        };

        $filters = [
            'supplier_id' => (int) $this->request->getGet('supplier_id') ?: null,
            'vehicle_id' => (int) $this->request->getGet('vehicle_id') ?: null,
            'fuel_type_id' => $this->request->getGet('fuel_type_id') !== '' && $this->request->getGet('fuel_type_id') !== null
                ? (int) $this->request->getGet('fuel_type_id') : null,
            'direction' => in_array($this->request->getGet('direction'), ['in', 'out'], true) ? $this->request->getGet('direction') : null,
            'date_from' => $date('date_from'),
            'date_to' => $date('date_to'),
        ];

        $db = \Config\Database::connect();
        $model = new \FairyBus\Models\FbRefuellingModel();

        // --- Totali per tipo carburante ----------------------------------------
        $b = $db->table('fb_refuelling r')
            ->select('r.id_fuel_type, ft.name, r.direction, SUM(r.liters) AS liters')
            ->join('fb_fuel_type ft', 'ft.id_fuel_type = r.id_fuel_type', 'left')
            ->groupBy('r.id_fuel_type')
            ->groupBy('r.direction');
        \FairyBus\Models\FbRefuellingModel::applyFilters($b, $filters);
        $byType = $b->get()->getResultArray();

        $totals = [];
        foreach ($byType as $row) {
            $id = (int) ($row['id_fuel_type'] ?? 0);
            $totals[$id] ??= ['id_fuel_type' => $row['id_fuel_type'], 'name' => $row['name'] ?? 'N/D', 'load' => 0.0, 'unload' => 0.0];
            $totals[$id][$row['direction'] === 'in' ? 'load' : 'unload'] = (float) $row['liters'];
        }
        foreach ($totals as &$t) {
            $t['stock'] = round($t['load'] - $t['unload'], 2);
        }
        unset($t);
        $totals = array_values($totals);

        // --- Serie mensili: litri e costi ---------------------------------------
        $b = $db->table('fb_refuelling r')
            ->select("DATE_FORMAT(r.refuel_time, '%Y-%m') AS ym, r.direction, SUM(r.liters) AS liters, SUM(r.liters * r.price_per_liter) AS cost")
            ->where('r.refuel_time IS NOT NULL', null, false)
            ->groupBy('ym')
            ->groupBy('r.direction')
            ->orderBy('ym', 'ASC');
        \FairyBus\Models\FbRefuellingModel::applyFilters($b, $filters);
        $monthly = $b->get()->getResultArray();

        $litersMonthly = [];
        $costMonthly = [];
        foreach ($monthly as $row) {
            $ym = (string) $row['ym'];
            $litersMonthly[$ym] ??= ['ym' => $ym, 'in' => 0.0, 'out' => 0.0];
            $litersMonthly[$ym][$row['direction'] === 'in' ? 'in' : 'out'] = (float) $row['liters'];
            $costMonthly[$ym]['ym'] = $ym;
            $costMonthly[$ym]['cost'] = ($costMonthly[$ym]['cost'] ?? 0) + (float) $row['cost'];
        }
        $litersMonthly = array_values($litersMonthly);
        $costMonthly = array_values($costMonthly);

        // --- Consumo medio mensile e per scarico --------------------------------
        // km/l = (km_at_refuel - km_since_last_refuel) / liters
        $b = $db->table('fb_refuelling r')
            ->select("r.refuel_time, r.liters, r.km_at_refuel, r.km_since_last_refuel, v.plate")
            ->join('fb_vehicle v', 'v.id_vehicle = r.id_vehicle', 'left')
            ->where("r.direction", 'out')
            ->where('r.km_since_last_refuel IS NOT NULL', null, false)
            ->where('r.km_at_refuel > r.km_since_last_refuel', null, false)
            ->where('r.liters > 0', null, false)
            ->orderBy('r.refuel_time', 'ASC')
            ->orderBy('r.id_refuelling', 'ASC');
        // il filtro direzione e' forzato a 'out': i consumi riguardano gli scarichi
        $consumptionFilters = $filters;
        $consumptionFilters['direction'] = 'out';
        \FairyBus\Models\FbRefuellingModel::applyFilters($b, $consumptionFilters);
        $consumptionRows = $b->get()->getResultArray();

        $perUnload = [];
        $monthlyAcc = [];
        foreach ($consumptionRows as $row) {
            $kmDiff = (float) $row['km_at_refuel'] - (float) $row['km_since_last_refuel'];
            $liters = (float) $row['liters'];
            if ($liters <= 0 || $kmDiff <= 0) {
                continue;
            }
            $kml = $kmDiff / $liters;
            if ($kml > 100) {
                continue; // dato palesemente errato
            }
            $ym = substr((string) $row['refuel_time'], 0, 7);
            $perUnload[] = [
                'date' => substr((string) $row['refuel_time'], 0, 10),
                'vehicle' => $row['plate'],
                'liters' => $liters,
                'km_diff' => $kmDiff,
                'km_per_liter' => round($kml, 3),
            ];
            $monthlyAcc[$ym]['km'] = ($monthlyAcc[$ym]['km'] ?? 0) + $kmDiff;
            $monthlyAcc[$ym]['liters'] = ($monthlyAcc[$ym]['liters'] ?? 0) + $liters;
        }
        $consumptionMonthly = [];
        foreach ($monthlyAcc as $ym => $acc) {
            $consumptionMonthly[] = ['ym' => $ym, 'km_per_liter' => round($acc['km'] / $acc['liters'], 3)];
        }

        return $this->jsonResponse([
            'success' => true,
            'fuel_totals' => $totals,
            'charts' => [
                'liters_monthly' => $litersMonthly,
                'cost_monthly' => $costMonthly,
                'consumption_monthly' => $consumptionMonthly,
                'consumption_per_unload' => $perUnload,
            ],
            'rows' => $model->listFiltered($filters),
        ]);
    }

    /**
     * Totali globali: litri caricati/scaricati, giacenza e ultimo carico.
     */
    public function summary(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $byDirection = $db->table('fb_refuelling')
            ->select('direction, SUM(liters) AS liters, COUNT(*) AS movements')
            ->groupBy('direction')
            ->get()
            ->getResultArray();

        $load = 0.0;
        $unload = 0.0;
        $loadCount = 0;
        $unloadCount = 0;
        foreach ($byDirection as $row) {
            if ($row['direction'] === 'in') {
                $load = (float) $row['liters'];
                $loadCount = (int) $row['movements'];
            } else {
                $unload = (float) $row['liters'];
                $unloadCount = (int) $row['movements'];
            }
        }

        $lastFor = static function (string $direction) use ($db): ?array {
            return $db->table('fb_refuelling r')
                ->select('r.refuel_time, r.liters, r.price_per_liter, v.plate AS vehicle_plate, s.company AS supplier_name, st.name AS station_name, ft.name AS fuel_type_name')
                ->join('fb_vehicle v', 'v.id_vehicle = r.id_vehicle', 'left')
                ->join('fb_supplier s', 's.id_supplier = r.id_supplier', 'left')
                ->join('fb_refuelling_station st', 'st.id_refuelling_station = r.id_station', 'left')
                ->join('fb_fuel_type ft', 'ft.id_fuel_type = r.id_fuel_type', 'left')
                ->where('r.direction', $direction)
                ->orderBy('r.refuel_time', 'DESC')
                ->orderBy('r.id_refuelling', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();
        };

        return $this->jsonResponse([
            'success' => true,
            'load' => $load,
            'load_count' => $loadCount,
            'unload' => $unload,
            'unload_count' => $unloadCount,
            'stock' => round($load - $unload, 2),
            'last_load' => $lastFor('in'),
            'last_unload' => $lastFor('out'),
        ]);
    }

    /**
     * Opzioni per le select del form: stazioni, tipi carburante.
     */
    public function options(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        return $this->jsonResponse([
            'success' => true,
            'stations' => (new \FairyBus\Models\FbRefuellingStationModel())->orderBy('name', 'ASC')->findAll(),
            'fuel_types' => (new \FairyBus\Models\FbFuelTypeModel())->orderBy('id_fuel_type', 'ASC')->findAll(),
        ]);
    }

    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $data = $this->validateRefuelling();
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        $model = new \FairyBus\Models\FbRefuellingModel();
        $id = (int) $model->insert($data + ['date_add' => date('Y-m-d H:i:s')]);
        $this->registerKm($data, $id);

        if ($this->request->getPost('update_vehicle_fuel')) {
            $this->syncVehicleFuel((int) $data['id_vehicle'], $data['id_fuel_type']);
        }

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbRefuellingModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Rifornimento non trovato.'], 404);
        }

        $data = $this->validateRefuelling();
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        $model->update($id, $data + ['date_upd' => date('Y-m-d H:i:s')]);
        $this->registerKm($data, $id);

        if ($this->request->getPost('update_vehicle_fuel')) {
            $this->syncVehicleFuel((int) $data['id_vehicle'], $data['id_fuel_type']);
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Allineamento km_since_last_refuel sugli scarichi: per ogni veicolo,
     * in ordine cronologico (refuel_time, id), il campo riceve i km del
     * rifornimento precedente. Elaborazione a blocchi (offset/limit sui
     * veicoli) per la barra di avanzamento lato client.
     */
    public function alignKm(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $offset = max(0, (int) $this->request->getPost('offset'));
        $limit = min(200, max(1, (int) $this->request->getPost('limit') ?: 50));

        $db = \Config\Database::connect();
        $vehicleRows = $db->table('fb_refuelling')
            ->select('id_vehicle')
            ->where('direction', 'out')
            ->where('id_vehicle IS NOT NULL', null, false)
            ->groupBy('id_vehicle')
            ->orderBy('id_vehicle', 'ASC')
            ->get()
            ->getResultArray();

        $vehicleIds = array_map(static fn(array $r): int => (int) $r['id_vehicle'], $vehicleRows);
        $total = count($vehicleIds);
        $slice = array_slice($vehicleIds, $offset, $limit);

        $updated = $this->alignKmChain($db, $slice);
        $processed = $offset + count($slice);

        return $this->jsonResponse([
            'success' => true,
            'total_vehicles' => $total,
            'processed' => $processed,
            'updated' => $updated,
            'finished' => $processed >= $total,
        ]);
    }

    public function delete(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new \FairyBus\Models\FbRefuellingModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Rifornimento non trovato.'], 404);
        }
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Allinea la feature 'fuel_type' del veicolo al tipo selezionato nel rifornimento.
     * Il valore della feature e' l'ordinale enum (= fb_fuel_type.id).
     */
    private function syncVehicleFuel(int $idVehicle, ?int $idFuelType): void
    {
        if ($idVehicle <= 0 || $idFuelType === null) {
            return;
        }

        $db = \Config\Database::connect();
        $feature = $db->table('fb_feature')->where('name', 'fuel_type')->get()->getRowArray();
        if ($feature === null) {
            return;
        }

        $idFeature = (int) $feature['id_feature'];
        $existing = $db->table('fb_vehicle_feature')
            ->where('id_vehicle', $idVehicle)
            ->where('id_feature', $idFeature)
            ->get()
            ->getRowArray();

        if ($existing !== null) {
            $db->table('fb_vehicle_feature')
                ->where('id_vehicle_feature', $existing['id_vehicle_feature'])
                ->update(['value' => (string) $idFuelType, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            $db->table('fb_vehicle_feature')->insert([
                'id_vehicle' => $idVehicle,
                'id_feature' => $idFeature,
                'value' => (string) $idFuelType,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function registerKm(array $data, ?int $idRefuelling = null): void
    {
        $idVehicle = (int) ($data['id_vehicle'] ?? 0);
        $km = (int) ($data['km_at_refuel'] ?? 0);
        if ($idVehicle <= 0 || $km <= 0) {
            return;
        }
        (new \FairyBus\Models\FbVehicleKmModel())->register($idVehicle, $km, $data['refuel_time'] ?? null, $idRefuelling, 'Refuelling');
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    private function validateRefuelling(): array|ResponseInterface
    {
        $direction = $this->request->getPost('direction') === 'in' ? 'in' : 'out';
        $isLoad = $direction === 'in';

        $rules = [
            'id_vehicle' => $isLoad ? 'permit_empty|integer' : 'required|integer|greater_than[0]',
            'id_station' => 'required|integer|greater_than[0]',
            'id_fuel_type' => 'required|integer|greater_than_equal_to[0]',
            'id_supplier' => 'permit_empty|integer',
            'direction' => 'permit_empty|in_list[in,out]',
            'refuel_time' => 'permit_empty|regex_match[/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/]',
            'liters' => 'required|decimal|greater_than[0]',
            'price_per_liter' => 'permit_empty|decimal',
            'km_at_refuel' => $isLoad ? 'permit_empty|integer' : 'required|integer|greater_than_equal_to[0]',
            'km_since_last_refuel' => 'permit_empty|integer',
            'note' => 'permit_empty|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $time = str_replace('T', ' ', (string) $this->request->getPost('refuel_time'));
        if ($time !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $time)) {
            $time .= ':00';
        } elseif ($time !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $time)) {
            $time .= ' 00:00:00';
        }

        return [
            // sul carico veicolo e km restano NULL
            'id_vehicle' => $isLoad ? null : (int) $this->request->getPost('id_vehicle'),
            'id_station' => (int) $this->request->getPost('id_station'),
            'id_fuel_type' => $this->request->getPost('id_fuel_type') !== '' ? (int) $this->request->getPost('id_fuel_type') : null,
            // sullo scarico il fornitore non è mostrato nel form -> NULL
            'id_supplier' => $isLoad ? ((int) $this->request->getPost('id_supplier') ?: null) : null,
            'direction' => $direction,
            'refuel_time' => $time !== '' ? $time : null,
            'liters' => (float) $this->request->getPost('liters'),
            'price_per_liter' => (float) ($this->request->getPost('price_per_liter') ?: 0),
            'km_at_refuel' => $isLoad ? null : (int) $this->request->getPost('km_at_refuel'),
            'km_since_last_refuel' => $isLoad ? null : ($this->request->getPost('km_since_last_refuel') !== '' ? (int) $this->request->getPost('km_since_last_refuel') : null),
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
        ];
    }

    /* ---------- Import legacy ---------- */

    /**
     * Importa refuelling_station -> fb_refuelling_station e
     * refuelling -> fb_refuelling (veicolo/stazione/fornitore/tipo risolti via legacy_id).
     */
    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        $legacyTables = \FairyBus\Libraries\LegacyDatabase::listTables($db);

        foreach (['refuelling', 'refuelling_station'] as $required) {
            if (!in_array($required, $legacyTables, true)) {
                return $this->jsonResponse(['success' => false, 'error' => "Tabella legacy \"{$required}\" non trovata."], 422);
            }
        }

        $vehicleMap = $this->legacyMap($db, 'fb_vehicle', 'id_vehicle');
        $supplierMap = $this->legacyMap($db, 'fb_supplier', 'id_supplier');

        $report = ['stations' => 0, 'refuellings' => 0, 'skipped' => 0];

        // --- Punti di rifornimento ---------------------------------------------------

        $db->table('fb_refuelling_station')->truncate();

        $stations = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('refuelling_station')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $batch = [];
        foreach ($stations as $row) {
            $batch[] = [
                'legacy_id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                // legacy: "city" contiene la provincia (es. CS), "town" il comune
                'province' => !empty($row['city']) ? (string) $row['city'] : null,
                'town' => !empty($row['town']) ? (string) $row['town'] : null,
                'post_code' => !empty($row['post_code']) ? (string) $row['post_code'] : null,
                'street' => !empty($row['street']) ? (string) $row['street'] : null,
                'suburb' => !empty($row['suburb']) ? (string) $row['suburb'] : null,
                'lat' => (float) ($row['lat'] ?? 0) ?: null,
                'lon' => (float) ($row['lon'] ?? 0) ?: null,
                'epsg_code' => (int) ($row['epsg_code'] ?? 0) ?: null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
        }
        if ($batch !== []) {
            $db->table('fb_refuelling_station')->insertBatch($batch);
            $report['stations'] = count($batch);
        }

        $stationMap = $this->legacyMap($db, 'fb_refuelling_station', 'id_refuelling_station');

        // business_partner.role: 0 = fornitore, 1 = cliente
        $partnerRole = [];
        if (in_array('business_partner', $legacyTables, true)) {
            foreach (\FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
                return $db->table('business_partner')->select('id, role')->get()->getResultArray();
            }) as $bp) {
                $partnerRole[(int) $bp['id']] = (int) $bp['role'];
            }
        }

        // --- Rifornimenti ------------------------------------------------------------

        $db->table('fb_refuelling')->truncate();

        $rows = \FairyBus\Libraries\LegacyDatabase::withoutPrefix($db, static function (BaseConnection $db): array {
            return $db->table('refuelling')->orderBy('id', 'ASC')->get()->getResultArray();
        });

        $count = 0;
        $batch = [];
        $flush = function () use ($db, &$batch, &$count): void {
            if ($batch !== []) {
                $db->table('fb_refuelling')->insertBatch($batch);
                $count += count($batch);
                $batch = [];
            }
        };

        foreach ($rows as $row) {
            $legacyPartnerId = (int) ($row['supplier_id'] ?? 0) ?: null;
            $idSupplier = null;
            if ($legacyPartnerId !== null) {
                // supplier_id legacy punta a business_partner: ruolo 0 -> fb_supplier
                $idSupplier = ($partnerRole[$legacyPartnerId] ?? 0) === 0
                    ? ($supplierMap[$legacyPartnerId] ?? null)
                    : null;
            }

            $batch[] = [
                'legacy_id' => (int) $row['id'],
                'id_vehicle' => $vehicleMap[(int) ($row['vehicle_id'] ?? 0)] ?? null,
                'id_station' => $stationMap[(int) ($row['fuel_station_id'] ?? 0)] ?? null,
                'id_supplier' => $idSupplier,
                'id_fuel_type' => $row['fuel_type'] !== null ? (int) $row['fuel_type'] : null,
                'direction' => 'out',
                'refuel_time' => !empty($row['refuel_time']) ? $row['refuel_time'] : null,
                'liters' => (float) ($row['liters'] ?? 0),
                'price_per_liter' => (float) ($row['price_per_liter'] ?? 0),
                'km_at_refuel' => $row['km_at_refuel'] !== null ? (int) $row['km_at_refuel'] : null,
                'km_since_last_refuel' => $row['km_since_last_refuel'] !== null ? (int) $row['km_since_last_refuel'] : null,
                'status' => $row['status'] !== null ? (int) $row['status'] : null,
                'note' => !empty($row['note']) ? (string) $row['note'] : null,
                'date_add' => !empty($row['created_date']) ? $row['created_date'] : null,
                'date_upd' => !empty($row['last_modified_date']) ? $row['last_modified_date'] : null,
            ];
            if (count($batch) >= self::BATCH_SIZE) {
                $flush();
            }
        }
        $flush();
        $report['refuellings'] = $count;

        // --- Post-import -----------------------------------------------------------
        // i movimenti con fornitore sono carichi in cisterna
        $db->table('fb_refuelling')->where('id_supplier >', 0)->update(['direction' => 'in']);
        $report['loads'] = $db->affectedRows();
        // allinea i km del rifornimento precedente su tutti gli scarichi
        $report['km_aligned'] = $this->alignKmChain($db);

        return $this->jsonResponse([
            'success' => true,
            'message' => sprintf(
                'Punti di rifornimento: %d, rifornimenti: %d (carichi: %d). Km allineati: %d.',
                $report['stations'],
                $report['refuellings'],
                $report['loads'],
                $report['km_aligned']
            ),
        ]);
    }

    /**
     * Riempie km_since_last_refuel con i km dello scarico precedente dello
     * stesso veicolo (ordine refuel_time, id). Se $vehicleIds è null lavora
     * su tutti i veicoli che hanno scarichi. Restituisce le righe aggiornate.
     */
    private function alignKmChain(BaseConnection $db, ?array $vehicleIds = null): int
    {
        if ($vehicleIds === null) {
            $vehicleIds = array_map(
                static fn(array $r): int => (int) $r['id_vehicle'],
                $db->table('fb_refuelling')
                    ->select('id_vehicle')
                    ->where('direction', 'out')
                    ->where('id_vehicle IS NOT NULL', null, false)
                    ->groupBy('id_vehicle')
                    ->get()
                    ->getResultArray()
            );
        }

        $updated = 0;
        foreach ($vehicleIds as $idVehicle) {
            $rows = $db->table('fb_refuelling')
                ->select('id_refuelling, km_at_refuel, km_since_last_refuel')
                ->where('direction', 'out')
                ->where('id_vehicle', $idVehicle)
                ->orderBy('refuel_time', 'ASC')
                ->orderBy('id_refuelling', 'ASC')
                ->get()
                ->getResultArray();

            $prevKm = null;
            foreach ($rows as $r) {
                // il primo rifornimento del veicolo non ha un precedente
                $expected = $prevKm;
                if ((string) ($r['km_since_last_refuel'] ?? '') !== (string) ($expected ?? '')) {
                    $db->table('fb_refuelling')
                        ->where('id_refuelling', (int) $r['id_refuelling'])
                        ->update(['km_since_last_refuel' => $expected, 'date_upd' => date('Y-m-d H:i:s')]);
                    $updated++;
                }
                $prevKm = $r['km_at_refuel'] !== null ? (int) $r['km_at_refuel'] : $prevKm;
            }
        }

        return $updated;
    }

    /**
     * @return array<int, int>
     */
    private function legacyMap(BaseConnection $db, string $table, string $pk): array
    {
        $map = [];
        foreach ($db->table($table)->select("{$pk}, legacy_id")->where('legacy_id IS NOT NULL', null, false)->get()->getResultArray() as $r) {
            $map[(int) $r['legacy_id']] = (int) $r[$pk];
        }

        return $map;
    }
}
