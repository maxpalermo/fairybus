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
use CodeIgniter\HTTP\ResponseInterface;
use FairyBus\Models\FbExpirationModel;
use FairyBus\Models\FbExpirationOccurrenceModel;
use FairyBus\Models\FbExpirationTagModel;

class Expirations extends AdminController
{
    /* ---------- Etichette (voci di scadenza) ---------- */

    public function tags(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new FbExpirationTagModel())->listAll(),
        ]);
    }

    public function createTag(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $data = $this->validateTag();
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        $id = (int) (new FbExpirationTagModel())->insert($data + ['date_add' => date('Y-m-d H:i:s')]);

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    public function updateTag(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbExpirationTagModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Etichetta non trovata.'], 404);
        }

        $data = $this->validateTag();
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        $model->update($id, $data + ['date_upd' => date('Y-m-d H:i:s')]);

        return $this->jsonResponse(['success' => true]);
    }

    public function deleteTag(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbExpirationTagModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Etichetta non trovata.'], 404);
        }
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /* ---------- Scadenze su veicolo ---------- */

    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new FbExpirationModel())->listAll(),
        ]);
    }

    /**
     * Nuova scadenza su veicolo + prima occorrenza.
     */
    public function create(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $rules = [
            'id_vehicle' => 'required|integer|greater_than[0]',
            'description' => 'permit_empty|max_length[255]',
            'expiration_date' => 'permit_empty|valid_date',
            'expires_atkm' => 'permit_empty|integer',
            'periodicity' => 'permit_empty|in_list[once,yearly,km,days]',
            'kind' => 'permit_empty|in_list[km,date]',
            'id_expiration_tag' => 'permit_empty|integer',
            'note' => 'permit_empty|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $post = fn(string $k) => $this->request->getPost($k);
        // getPost() ritorna null (non "") se la chiave manca: normalizza entrambi
        $val = fn(string $k) => ($v = $post($k)) !== null && $v !== '' ? $v : null;
        $kind = $post('kind') === 'km' ? 'km' : 'date';
        $expirationDate = $val('expiration_date');
        $expiresAtKm = $val('expires_atkm') !== null ? (int) $val('expires_atkm') : null;

        if ($kind === 'date' && $expirationDate === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Indica la data di scadenza.'], 422);
        }
        if ($kind === 'km' && ($expiresAtKm === null || $expiresAtKm <= 0)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Indica il chilometraggio di scadenza.'], 422);
        }

        $now = date('Y-m-d H:i:s');
        $id = (int) (new FbExpirationModel())->insert([
            'id_vehicle' => (int) $post('id_vehicle'),
            'id_expiration_tag' => $val('id_expiration_tag') !== null ? (int) $val('id_expiration_tag') : null,
            'description' => $val('description'),
            'expiration_date' => $kind === 'date' ? $expirationDate : null,
            'expires_atkm' => $kind === 'km' ? $expiresAtKm : null,
            'periodicity' => $val('periodicity') ?? ($kind === 'km' ? 'km' : 'once'),
            'kind' => $kind,
            'note' => $val('note'),
            'date_add' => $now,
        ]);

        (new FbExpirationOccurrenceModel())->insert([
            'id_expiration' => $id,
            'expiration_date' => $kind === 'date' ? $expirationDate : null,
            'km' => $kind === 'km' ? $expiresAtKm : null,
            'state' => FbExpirationOccurrenceModel::STATE_OPEN,
            'date_add' => $now,
        ]);

        return $this->jsonResponse(['success' => true, 'id' => $id]);
    }

    /**
     * Modifica scadenza: periodica (yearly/km/days) oppure una tantum (once).
     */
    public function update(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbExpirationModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Scadenza non trovata.'], 404);
        }

        $rules = [
            'description' => 'permit_empty|max_length[255]',
            'expiration_date' => 'permit_empty|valid_date',
            'until_date' => 'permit_empty|valid_date',
            'expires_atkm' => 'permit_empty|integer',
            'periodicity' => 'permit_empty|in_list[once,yearly,km,days]',
            'everyxdays' => 'permit_empty|integer',
            'yearly_month' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[12]',
            'yearly_day' => 'permit_empty|integer|greater_than_equal_to[1]|less_than_equal_to[31]',
            'kind' => 'permit_empty|in_list[km,date]',
            'id_expiration_tag' => 'permit_empty|integer',
            'note' => 'permit_empty|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $post = fn(string $k) => $this->request->getPost($k);
        $val = fn(string $k) => ($v = $post($k)) !== null && $v !== '' ? $v : null;
        $model->update($id, [
            'id_expiration_tag' => $val('id_expiration_tag') !== null ? (int) $val('id_expiration_tag') : null,
            'description' => $val('description'),
            'expiration_date' => $val('expiration_date'),
            'until_date' => $val('until_date'),
            'expires_atkm' => $val('expires_atkm') !== null ? (int) $val('expires_atkm') : null,
            'periodicity' => $val('periodicity') ?? 'once',
            'everyxdays' => $val('everyxdays') !== null ? (int) $val('everyxdays') : null,
            'yearly_month' => $val('yearly_month') !== null ? (int) $val('yearly_month') : null,
            'yearly_day' => $val('yearly_day') !== null ? (int) $val('yearly_day') : null,
            'kind' => $val('kind') ?? 'date',
            'note' => $val('note'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonResponse(['success' => true]);
    }

    /* ---------- Occorrenze (calendario + allerte) ---------- */

    /**
     * Elenco occorrenze per calendario. Parametri GET:
     *  - from, to: intervallo date
     *  - states: csv stati (es. "0,1,2,3" esclude le eseguite)
     */
    public function occurrences(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $statesParam = (string) $this->request->getGet('states');
        $states = $statesParam !== '' ? array_map('intval', explode(',', $statesParam)) : [];

        $rows = (new FbExpirationOccurrenceModel())->listAll($states);

        if ($this->request->getGet('include_hidden') !== '1') {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => (int) ($r['hidden'] ?? 0) !== 1));
        }

        $idVehicle = (int) $this->request->getGet('id_vehicle');
        if ($idVehicle > 0) {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['id_vehicle'] === $idVehicle));
        }

        $idExpiration = (int) $this->request->getGet('id_expiration');
        if ($idExpiration > 0) {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['id_expiration'] === $idExpiration));
        }

        $from = $this->request->getGet('from');
        $to = $this->request->getGet('to');
        if ($from || $to) {
            $rows = array_values(array_filter($rows, static function (array $r) use ($from, $to): bool {
                $d = $r['expiration_date'] ?? null;
                if ($d === null) {
                    return true; // scadenze km senza data: sempre visibili
                }
                if ($from && $d < $from) {
                    return false;
                }

                return !($to && $d > $to);
            }));
        }

        if ($this->request->getGet('km_only') === '1') {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['km'] !== null && (int) $r['km'] > 0));
            usort($rows, static function (array $a, array $b): int {
                $da = (int) $a['km'] - (int) ($a['current_km'] ?? 0);
                $db = (int) $b['km'] - (int) ($b['current_km'] ?? 0);
                return $da <=> $db;
            });
        }

        if ($this->request->getGet('date_only') === '1') {
            $rows = array_values(array_filter($rows, static fn(array $r): bool => $r['expiration_date'] !== null && ($r['tag_kind'] ?? '') !== 'km'));
        }

        return $this->jsonResponse(['success' => true, 'rows' => $rows]);
    }

    /**
     * Allerte: occorrenze aperte entro N giorni o entro M km dalla lettura attuale.
     * GET ?days=30&km=2000
     */
    public function alerts(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $days = (int) $this->request->getGet('days') ?: 30;
        $km = (int) $this->request->getGet('km') ?: 2000;

        return $this->jsonResponse([
            'success' => true,
            'rows' => (new FbExpirationOccurrenceModel())->listAlerts($days, $km),
        ]);
    }

    /**
     * Cambia lo stato di un'occorrenza (es. segna come eseguita).
     */
    public function updateOccurrence(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbExpirationOccurrenceModel();
        $occurrence = $model->find($id);
        if ($occurrence === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Occorrenza non trovata.'], 404);
        }

        $state = $this->request->getPost('state');
        if ($state === null) {
            // Modifica semplice: solo data e/o km dell'occorrenza
            $data = ['date_upd' => date('Y-m-d H:i:s')];
            $date = $this->request->getPost('expiration_date');
            if ($date !== null) {
                $data['expiration_date'] = $date !== '' ? $date : null;
            }
            $km = $this->request->getPost('km');
            if ($km !== null) {
                $data['km'] = $km !== '' ? (int) $km : null;
            }
            if (count($data) === 1) {
                return $this->jsonResponse(['success' => false, 'error' => 'Nessun dato da aggiornare.'], 422);
            }
            $model->update($id, $data);

            return $this->jsonResponse(['success' => true]);
        }
        if (!isset(FbExpirationOccurrenceModel::STATE_LABELS[(int) $state])) {
            return $this->jsonResponse(['success' => false, 'error' => 'Stato non valido.'], 422);
        }

        $data = ['state' => (int) $state, 'date_upd' => date('Y-m-d H:i:s')];
        $date = $this->request->getPost('expiration_date');
        if ($date !== null && $date !== '') {
            $data['expiration_date'] = $date;
        }
        $km = $this->request->getPost('km');
        if ($km !== null && $km !== '') {
            $data['km'] = (int) $km;
        }
        $doneDate = null;
        if ((int) $state === FbExpirationOccurrenceModel::STATE_DONE) {
            $doneDate = (string) $this->request->getPost('done_date');
            $data['done_date'] = $doneDate !== '' ? $doneDate : date('Y-m-d');
        }
        $model->update($id, $data);

        // Esecuzione: registra la lettura km (registro chilometrico + veicolo)
        if ((int) $state === FbExpirationOccurrenceModel::STATE_DONE) {
            $doneKm = (int) $this->request->getPost('done_km');
            if ($doneKm > 0) {
                $db = \Config\Database::connect();
                $expiration = $db->table('fb_expiration')
                    ->where('id_expiration', (int) $occurrence['id_expiration'])
                    ->get()
                    ->getRowArray();
                $idVehicle = (int) ($expiration['id_vehicle'] ?? 0);
                if ($idVehicle > 0) {
                    $registration = $db->table('fb_vehicle_km')
                        ->where('reason_class', 'Expiration')
                        ->where('reason_id', $id)
                        ->get()
                        ->getRowArray();

                    if ($registration !== null) {
                        // Modifica di un'esecuzione esistente: aggiorna la lettura
                        $db->table('fb_vehicle_km')
                            ->where('id_vehicle_km', (int) $registration['id_vehicle_km'])
                            ->update([
                                'amount' => $doneKm,
                                'registration_date' => $data['done_date'] . ' ' . date('H:i:s'),
                                'date_upd' => date('Y-m-d H:i:s'),
                            ]);
                        // Riallinea il chilometraggio alla lettura massima
                        $maxKm = $db->table('fb_vehicle_km')
                            ->selectMax('amount')
                            ->where('id_vehicle', $idVehicle)
                            ->get()
                            ->getRowArray();
                        if ($maxKm !== null && $maxKm['amount'] !== null) {
                            $db->table('fb_vehicle')
                                ->where('id_vehicle', $idVehicle)
                                ->update(['current_km' => (int) $maxKm['amount']]);
                        }
                    } else {
                        (new \FairyBus\Models\FbVehicleKmModel())->register(
                            $idVehicle,
                            $doneKm,
                            $data['done_date'] . ' ' . date('H:i:s'),
                            $id,
                            'Expiration'
                        );
                    }
                }
            }
        }

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Crea una nuova occorrenza di scadenza (rinnovo).
     */
    public function createOccurrence(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idExpiration = (int) $this->request->getPost('id_expiration');
        $date = $this->request->getPost('expiration_date');
        $km = $this->request->getPost('km');
        if ($idExpiration <= 0 || (($date === null || $date === '') && ($km === null || $km === ''))) {
            return $this->jsonResponse(['success' => false, 'error' => 'Scadenza o data/km mancanti.'], 422);
        }

        $exists = \Config\Database::connect()
            ->table('fb_expiration')
            ->where('id_expiration', $idExpiration)
            ->countAllResults();
        if ($exists === 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Scadenza non trovata.'], 404);
        }

        $model = new FbExpirationOccurrenceModel();
        $id = $model->insert([
            'id_expiration' => $idExpiration,
            'expiration_date' => $date !== null && $date !== '' ? $date : null,
            'km' => $km !== null && $km !== '' ? (int) $km : null,
            'state' => FbExpirationOccurrenceModel::STATE_OPEN,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);

        return $this->jsonResponse(['success' => true, 'id_expiration_occurrence' => $id]);
    }

    /**
     * Elimina un'occorrenza di scadenza.
     */
    public function deleteOccurrence(int $id): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbExpirationOccurrenceModel();
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Occorrenza non trovata.'], 404);
        }
        $model->delete($id);

        return $this->jsonResponse(['success' => true]);
    }

    /**
     * Nasconde tutte le scadenze di un veicolo per una data voce (id_vehicle + id_expiration_tag).
     */
    public function hide(): ResponseInterface
    {
        return $this->setHidden(1);
    }

    /**
     * Ripristina le scadenze nascoste di un veicolo per una data voce.
     */
    public function unhide(): ResponseInterface
    {
        return $this->setHidden(0);
    }

    private function setHidden(int $hidden): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $idVehicle = (int) $this->request->getPost('id_vehicle');
        $idTag = (int) $this->request->getPost('id_expiration_tag');
        if ($idVehicle <= 0 || $idTag <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Veicolo o voce di scadenza mancanti.'], 422);
        }

        $updated = \Config\Database::connect()
            ->table('fb_expiration')
            ->where('id_vehicle', $idVehicle)
            ->where('id_expiration_tag', $idTag)
            ->update(['hidden' => $hidden, 'date_upd' => date('Y-m-d H:i:s')]);

        if ($updated === false) {
            return $this->jsonResponse(['success' => false, 'error' => 'Aggiornamento non riuscito.'], 500);
        }

        return $this->jsonResponse(['success' => true, 'updated' => (int) $updated]);
    }

    /**
     * @return array<string, mixed>|ResponseInterface
     */
    private function validateTag(): array|ResponseInterface
    {
        $rules = [
            'name' => 'required|max_length[255]',
            'applies_to' => 'permit_empty|max_length[255]',
            'kind' => 'permit_empty|in_list[km,date]',
            'interval_value' => 'permit_empty|integer|greater_than[0]',
            'interval_unit' => 'permit_empty|in_list[km,days,months,years]',
            'note' => 'permit_empty|max_length[255]',
        ];
        if (!$this->validate($rules)) {
            return $this->jsonResponse(['success' => false, 'errors' => $this->validator->getErrors()], 422);
        }

        $kind = $this->request->getPost('kind') === 'km' ? 'km' : 'date';
        $unit = $this->request->getPost('interval_unit') ?: ($kind === 'km' ? 'km' : 'months');

        return [
            'name' => (string) $this->request->getPost('name'),
            'applies_to' => $this->request->getPost('applies_to') !== '' ? $this->request->getPost('applies_to') : 'Scadenza per veicolo',
            'kind' => $kind,
            'interval_value' => (int) $this->request->getPost('interval_value') ?: null,
            'interval_unit' => $unit,
            'note' => $this->request->getPost('note') !== '' ? $this->request->getPost('note') : null,
        ];
    }
}
