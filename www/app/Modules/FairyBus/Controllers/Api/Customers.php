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
use FairyBus\Libraries\LegacyDatabase;
use FairyBus\Models\FbAddressModel;
use FairyBus\Models\FbCustomerModel;
use FairyBus\Models\FbStateModel;

class Customers extends AdminController
{
    public function list(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $model = new FbCustomerModel();

        return $this->jsonResponse([
            'success' => true,
            'rows' => $model->listAll(),
        ]);
    }

    public function options(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $countries = (new \FairyBus\Models\FbCountryModel())->orderBy('name', 'ASC')->findAll();
        $states = (new FbStateModel())->orderBy('name', 'ASC')->findAll();

        return $this->jsonResponse([
            'success' => true,
            'countries' => $countries,
            'states' => $states,
        ]);
    }

    public function importFromLegacy(): ResponseInterface
    {
        if ($denied = $this->requirePermission('*')) {
            return $denied;
        }

        $db = \Config\Database::connect();
        if (!in_array('business_partner', LegacyDatabase::listTables($db), true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "business_partner" non trovata.'], 422);
        }

        $availableColumns = LegacyDatabase::getFieldNames($db, 'business_partner');
        $desiredColumns = [
            'id',
            'name',
            'vat_number',
            'email',
            'contact_person',
            'city',
            'post_code',
            'street',
            'suburb',
            'town',
            'contact_telephone_number',
            'contact_mobile_number',
            'status',
            'role',
        ];
        $selectColumns = array_values(array_intersect($availableColumns, $desiredColumns));
        if ($selectColumns === [] || !in_array('id', $selectColumns, true)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Tabella legacy "business_partner" non contiene colonne riconosciute.'], 422);
        }

        $rows = LegacyDatabase::withoutPrefix(
            $db,
            static fn(BaseConnection $db): array => $db->table('business_partner')
                ->select(implode(',', $selectColumns))
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray()
        );

        $db->table('fb_customer')->truncate();
        $db->table('fb_address')->where('id_customer IS NOT NULL')->delete();

        $stateModel = new FbStateModel();
        $customerModel = new FbCustomerModel();
        $addressModel = new FbAddressModel();

        $imported = 0;
        foreach ($rows as $row) {
            if (!isset($row['role']) || (int) $row['role'] !== 1) {
                continue;
            }

            $idCustomer = $customerModel->insert([
                'legacy_id' => (int) $row['id'],
                'company' => (string) ($row['name'] ?? ''),
                'vat_number' => !empty($row['vat_number']) ? (string) $row['vat_number'] : null,
                'email' => !empty($row['email']) ? (string) $row['email'] : null,
                'pec' => null,
                'contact_name' => !empty($row['contact_person']) ? (string) $row['contact_person'] : null,
                'active' => isset($row['status']) ? (int) $row['status'] : 1,
            ]);

            if (!$idCustomer) {
                continue;
            }

            $idCountry = null;
            $idState = null;
            if (!empty($row['town'])) {
                $state = $stateModel->findByIsoCode((string) $row['town']);
                if ($state !== null) {
                    $idState = (int) $state['id_state'];
                    $idCountry = (int) $state['id_country'];
                }
            }

            $addressModel->insert([
                'id_customer' => (int) $idCustomer,
                'id_country' => $idCountry,
                'id_state' => $idState,
                'address1' => !empty($row['street']) ? (string) $row['street'] : null,
                'address2' => !empty($row['suburb']) ? (string) $row['suburb'] : null,
                'postcode' => !empty($row['post_code']) ? (string) $row['post_code'] : null,
                'city' => !empty($row['city']) ? (string) $row['city'] : null,
                'phone_number' => !empty($row['contact_telephone_number']) ? (string) $row['contact_telephone_number'] : null,
                'mobile_number' => !empty($row['contact_mobile_number']) ? (string) $row['contact_mobile_number'] : null,
            ]);

            $imported++;
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => "Importati {$imported} clienti dalla tabella legacy business_partner.",
            'imported' => $imported,
        ]);
    }
}
