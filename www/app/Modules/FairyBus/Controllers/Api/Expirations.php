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
        $model->update($id, [
            'id_expiration_tag' => (int) $post('id_expiration_tag') ?: null,
            'description' => $post('description') !== '' ? $post('description') : null,
            'expiration_date' => $post('expiration_date') !== '' ? $post('expiration_date') : null,
            'until_date' => $post('until_date') !== '' ? $post('until_date') : null,
            'expires_atkm' => $post('expires_atkm') !== '' ? (int) $post('expires_atkm') : null,
            'periodicity' => $post('periodicity') !== '' ? $post('periodicity') : 'once',
            'everyxdays' => $post('everyxdays') !== '' ? (int) $post('everyxdays') : null,
            'yearly_month' => $post('yearly_month') !== '' ? (int) $post('yearly_month') : null,
            'yearly_day' => $post('yearly_day') !== '' ? (int) $post('yearly_day') : null,
            'kind' => $post('kind') !== '' ? $post('kind') : 'date',
            'note' => $post('note') !== '' ? $post('note') : null,
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
        if ($model->find($id) === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Occorrenza non trovata.'], 404);
        }

        $state = $this->request->getPost('state');
        if ($state === null || !isset(FbExpirationOccurrenceModel::STATE_LABELS[(int) $state])) {
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
        $model->update($id, $data);

        return $this->jsonResponse(['success' => true]);
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
