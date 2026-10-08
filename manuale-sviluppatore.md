# Fairy Bus — Manuale per lo sviluppatore

Documentazione tecnica dell'applicazione: architettura, classi PHP e JavaScript,
convenzioni, flussi funzionali e design system CSS.

- Stack: **CodeIgniter 4.7** (PHP 8.3), **Twig 3.28**, **MariaDB**, **Docker** (container `fairy-bus`), Dompdf, Bootstrap Table + jQuery, ES modules nativi (nessun bundler).
- Root app: `www/`. Modulo applicativo: `app/Modules/FairyBus/`.
- Database: `clienti_fata`. Tabelle applicative con prefisso **letterale** `fb_` (fa parte del nome, `DBPrefix` in `.env` deve restare vuoto). Tabelle legacy importate **senza** prefisso (`business_partner`, `movement`, `trade`, …).

---

## 1. Architettura

### 1.1 Struttura directory

```
www/
├── app/
│   ├── Config/Routes.php                  # tutte le rotte admin + api + auth
│   ├── Controllers/
│   │   ├── BaseController.php             # controller CI4 base
│   │   └── AdminController.php            # base comune pagine admin e API
│   ├── Libraries/Twig.php                 # motore template
│   ├── Commands/FbBackup.php              # `php spark fb:backup`
│   └── Modules/FairyBus/
│       ├── Controllers/
│       │   ├── Admin/                     # pagine HTML (render Twig) + PDF
│       │   ├── Api/                       # endpoint JSON
│       │   └── Auth/                      # login / cambio password
│       ├── Models/                        # Fb*Model (CodeIgniter\Model)
│       ├── Libraries/                     # PdfHelper, DbBackup, LegacyDatabase
│       ├── Database/Migrations/           # schema fb_* incrementale
│       └── Views/
│           ├── admin/                     # pagine admin + layout.twig
│           ├── auth/                      # login, change_password
│           └── print/                     # layout PDF + documenti stampa
└── public/assets/fairy-bus/
    ├── css/   theme.css · layout.css · components/ · pages/
    ├── js/    core/ · components/ · pages/
    └── vendors/ jquery, bootstrap-table, chosen, dompurify
```

### 1.2 Flusso di una richiesta

- **Pagina admin** (`GET admin/...`): `Admin\*::index()` → `AdminController::renderAdmin()`
  → `Twig::render('admin/<page>.twig', $data)` con dati comuni (menu, utente, CSRF, toast prefs, `help_url`).
- **API** (`GET|POST api/...`): `Api\*` → `requirePermission('*')` → JSON via `jsonResponse()`.
  Le pagine caricano `pages/<page>.js` (ES module) che usa `FetchHelper` verso gli endpoint.
- **PDF** (`POST admin/.../print*`): `getPrintIds()` (POST `ids` JSON, max 500) → modello →
  `PdfHelper::generate('print/<tpl>.twig', $data)` → `pdfResponse()` (inline o `attachment` se POST `download=1`).

### 1.3 Convenzioni trasversali

| Convenzione | Regola |
|---|---|
| Tabelle | `fb_<nome_singolare>`; PK `id_<nome>`; `legacy_id` per la mappa import |
| Quantità scarichi | Memorizzate **negative** in `fb_maintenance_detail`; in fatturazione/stampa si usa `ABS(quantity)` |
| `fb_document_detail.id_maintenance` | Righe copiate da una scheda: **non muovono lo stock** (già scaricato alla manutenzione) |
| Permessi | `requirePermission('*')` — admin con `*` vede tutto; le pagine usano `renderAdmin()` che gestisce login/cambio password |
| Cache busting | Asset caricati con `?v=N` nei template — **incrementare a ogni modifica** |
| `window.FB` | `{ baseUrl, csrf:{name,value}, toast }` iniettato da `admin/layout.twig` |
| Risposte API | `{ success: bool, ... }` + HTTP status (422 validazione, 404, 403) |
| Form submit | `FetchHelper.post(url, data)` invia `application/x-www-form-urlencoded`; array multipli come JSON string |

---

## 2. Infrastruttura PHP

### 2.1 `App\Controllers\AdminController` (abstract)

Base di tutti i controller Admin e Api del modulo.

| Metodo | Descrizione |
|---|---|
| `renderAdmin(string $template, array $data)` | Render pagina Twig; se non loggato → redirect `/login`; se `fb_force_password_change` → `/change-password`. Merge dati: `app_name`, `page_title`, `module_name`, `menu`, `user`, `csrf`, `toast_prefs`, `help_url` |
| `helpUrl()` | URL sezione del manuale PDF relativa alla pagina corrente (mappa rigenerata da `tools/build-manuale.php`) |
| `getToastPrefs()` | Preferenze toast utente (posizione, durata, tipi abilitati) |
| `isLoggedIn()` | `session('fb_user_id')` valorizzato |
| `can(string $permission)` | Verifica permesso: `*` o permesso esatto in sessione |
| `requirePermission(string $permission)` | `null` se ok, altrimenti risposta 403/redirect |
| `jsonResponse(array $data, int $status)` | JSON `application/json` |
| `getCsrf()` | `{name, value}` token CSRF |
| `buildMenu()` | Albero menu da `FbMenuModel::tree()` filtrato per permessi |
| `getPrintIds(int $maxRows = 500)` | Legge POST `ids` (JSON array) → `int[]`; errori 422 testuali se vuoto/overflow; redirect login se anonimo |
| `pdfResponse(string $pdf, string $name)` | Risposta `application/pdf`; `Content-Disposition` = `attachment` se POST `download=1` (switch "Forza download PDF"), altrimenti `inline` |
| `getCurrentUser()` | Utente corrente da `FbUserModel` + ruoli |

### 2.2 `App\Libraries\Twig`

Wrapper statico del motore Twig.

- `environment(): Environment` — `FilesystemLoader` su `app/Views` + `app/Modules/FairyBus/Views`; registra funzioni globali `base_url`, `site_url`, `asset`, `csrf_token`.
- `render(string $template, array $data): string` — render a stringa.

### 2.3 `FairyBus\Libraries\PdfHelper`

- `generate(string $template, array $data, string $size='a4', string $orientation='portrait'): string` — render Twig → Dompdf → binario PDF.
- `filename(string $name): string` — nome file sanitizzato `*.pdf`.

### 2.4 `FairyBus\Libraries\DbBackup`

Dump/restore MySQL puro PHP (mysqldump non presente nel container). Output ZIP in `writable/backups/` (fuori docroot, `.htaccess` deny).

- `dir(): string` — percorso cartella backup (creata se manca).
- `list(): array` — archivi esistenti `[name, size, modified]`.
- `create(?int $keep): string` — dump SQL (tabelle + viste escluse dai dati, `CREATE TABLE` + `INSERT` paginati per PK) compresso in `fb-backup-YYYYMMDD-HHMMSS.zip`; con `keep` elimina gli archivi più vecchi.
- `delete(string $name): bool` — elimina archivio (nome validato anti path-traversal).
- `path(string $name): ?string` — percorso assoluto se il nome è valido.
- `restore(string $name): int` — esegue gli statement dello ZIP, ritorna il conteggio; disabilita FK check durante il restore.

### 2.5 `FairyBus\Libraries\LegacyDatabase`

Accesso alle tabelle legacy senza prefisso (usato dagli import).

- `listTables($db): array` — tabelle del DB.
- `withoutPrefix($db, callable $cb)` — esegue `$cb` con `DBPrefix` temporaneamente azzerato.
- `getFieldNames($db, $table): array` — colonne della tabella.
- `insert($db, $table, $data): bool` — insert singolo.

### 2.6 `App\Commands\FbBackup`

`php spark fb:backup [--keep N]` — crea un backup via `DbBackup::create()`; pensato per cron (`docker exec -u www-data fairy-bus php spark fb:backup --keep 14`).

### 2.7 Controller `Auth\`

- `Login::index()` pagina login; `attempt()` verifica credenziali (`password_verify`), crea sessione (`fb_user_id`, `fb_permissions`, `fb_force_password_change`); `logout()` distrugge la sessione.
- `ChangePassword::index()`/`update()` — cambio password obbligatorio al primo accesso.

---

## 3. Modelli (`FairyBus\Models\Fb*Model`)

Tutti estendono `CodeIgniter\Model`, `returnType='array'`, niente soft delete. `allowedFields` è l'allowlist per insert/update.

### Anagrafiche

| Modello | Tabella | Metodi chiave |
|---|---|---|
| `FbBrandModel` | `fb_brand` | `listByIds($ids)`, `getByName($name)`, `getOrCreate($name)` (idempotente per import) |
| `FbCategoryModel` | `fb_category` | `listAll($ids)`, `listByIds`, `listActive`, `findChildren`, `findById`, `hasChildren`, `getChildrenIds` (ricorsivo), `getNextPosition`, `calculateDepth`, `createCategory`, `updateCategory`, `deleteCategory`, `toggleActive`, `getOptions`, `getTree` (albero annidato) |
| `FbCustomerModel` | `fb_customer` | `listAll($ids)` (join indirizzo primario), `findById` |
| `FbSupplierModel` | `fb_supplier` | `listAll($ids)` (join indirizzo + flag `fuel`), `findById` |
| `FbProductModel` | `fb_product` | `listAll($ids)` (giacenza, categoria, giacenza minima), `findById`, `toggleActive` |
| `FbAddressModel` | `fb_address` | `deleteBySupplier`, `deleteByCustomer` |
| `FbCityModel` / `FbStateModel` / `FbCountryModel` | `fb_city`/`fb_state`/`fb_country` | lookup geografico: `listFiltered`, `countFiltered`, `listByCountry`, `findByIsoCode` |

### Veicoli

| Modello | Tabella | Metodi chiave |
|---|---|---|
| `FbVehicleModel` | `fb_vehicle` | `listWithBrand($ids)` (join marca + features aggregate), `findWithDetails($id)` (anagrafica + features + immagini + documenti) |
| `FbFeatureModel` | `fb_feature` | `listAll`, `getByName`, `indexedByName` — definizioni caratteristiche veicolo (switch/number/date/text) |
| `FbVehicleFeatureModel` | `fb_vehicle_feature` | `getForVehicle`, `setForVehicle` (sync valori) |
| `FbVehicleImageModel` | `fb_vehicle_image` | `getForVehicle`, `add` |
| `FbVehicleDocumentModel` | `fb_vehicle_document` | `getForVehicle`, `add($idVehicle,$path,$description)` |
| `FbVehicleKmModel` | `fb_vehicle_km` | `register($idVehicle,$km,$date,$idMaintenance,$reasonClass)` — registro letture chilometriche (alimenta scadenze a km e consumi) |

### Magazzino e documenti

| Modello | Tabella | Metodi chiave |
|---|---|---|
| `FbStockModel` | `fb_stock` | `moveStock($idProduct,$movement,$qty)` dispatch su `loadStock`/`unloadStock`; `setStock` (inventario); `applyDelta` privato (aggiorna `quantity`,`inputs`,`outputs`, crea riga se assente — anche negativa); `setUnit`; `unitLabel`/`unitAbbr` (mappe `UNIT_LABELS`/`UNIT_ABBRS` per 9 unità); `listAll`, `listByIds`. Costanti `MOVEMENT_IN='in'`, `MOVEMENT_OUT='out'` |
| `FbDocumentModel` | `fb_document` | `listAll($ids, $direction='all')` (carichi `id_supplier`, scarichi `id_customer`; join partner/tipo/fattura), `findById`, `listAvailableForInvoice($idPartner, $idInvoice, $partner)` (documenti non ancora fatturati + quelli già sulla fattura per l'edit) |
| `FbDocumentDetailModel` | `fb_document_detail` | `listByDocument($id)` (join prodotto: `sku`, `product_name`) |
| `FbDocumentTypeModel` | `fb_document_type` | costanti `TYPE_DDT`, `TYPE_INVOICE` |
| `FbTypeDocumentModel` | `fb_type_document` | tipologie documento configurabili (`TYPE_DEFAULT/WAREHOUSE/SERVICE/FUEL`), `nextId`, `isInUse`, `optionsMap` |
| `FbInvoiceModel` | `fb_invoice` | `listAll($ids)` (join fornitore/cliente/veicolo + conteggi `documents_count`, `maintenances_count`, `invoice_kind`), `findById` |

### Manutenzione

| Modello | Tabella | Metodi chiave |
|---|---|---|
| `FbMaintenanceModel` | `fb_maintenance` | `listAll($ids, $idVehicle=null)` (join veicolo/marca + `invoices_count`; filtra `status != 'retired'` tranne quando `$idVehicle` è impostato), `findById` |
| `FbMaintenanceDetailModel` | `fb_maintenance_detail` | `listByMaintenance($id)` — righe ricambi con join prodotto e **task manodopera mergeati** (`labor_hours`, `labor_price_per_hour`, `labor_manpower`) + `unit_abbr` |
| `FbMaintenanceTaskModel` | `fb_maintenance_task` | `listByMaintenance` |
| `FbMaintenanceInvoiceModel` | `fb_maintenance_invoice` | Pivot scheda↔fattura. `listByMaintenance($id)`; `detailLines(array $ids)` — righe normalizzate (ricambi `ABS(qty)` + manodopera come riga "Manodopera"); `blocksByInvoice($idInvoice)` — blocchi scheda (id_maintenance, data, km, targa, `id_document`, `details[]`); `linkByDocument($idDoc,$idInvoice)` / `unlinkByDocument` — propagano il link fattura alle schede di uno scarico e mantengono `fb_maintenance.id_invoice` in sync |

### Scadenze

| Modello | Tabella | Metodi chiave |
|---|---|---|
| `FbExpirationModel` | `fb_expiration` | `listAll($ids, $idVehicle=null)` — definizioni + prima occorrenza aperta (`next_occurrence_id/date/km/state`) mergeata; `findById` |
| `FbExpirationOccurrenceModel` | `fb_expiration_occurrence` | `listAll($states=[])` (join scadenza/veicolo/tag + `state_label`), `listByIds`, `listAlerts($days,$kmMargin)`. Costanti `STATE_OPEN/NOTIFIED/SCHEDULED/EXPIRED/DONE` (0-4) e `STATE_LABELS` |
| `FbExpirationTagModel` | `fb_expiration_tag` | `listAll`, `intervalLabel($tag)` statica (etichetta "ogni N mesi/km") |

### Rifornimenti

| Modello | Tabella | Metodi chiave |
|---|---|---|
| `FbRefuellingModel` | `fb_refuelling` | `listAll`, `listFiltered($filters)`, `decorateConsumption($rows)` (km percorsi/litri per riga), `applyFilters($builder,$filters)` statica, `lastForVehicle($idVehicle,$excludeId,$before)` (ultimo km per prefill), `findById` |
| `FbRefuellingStationModel` | `fb_refuelling_station` | `listAll` (join fornitore/tipo carburante) |
| `FbFuelTypeModel` | `fb_fuel_type` | tipi carburante |

### Sistema

| Modello | Tabella | Metodi chiave |
|---|---|---|
| `FbUserModel` | `fb_users` | `findByEmail`, `findWithRoles`, `getRoles`, `getPermissions` (ruolo + override utente), `setPassword`, `toggleActive`, `setRoles` |
| `FbRoleModel` | `fb_roles` | `getPermissions`, `setPermissions` |
| `FbPermissionModel` | `fb_permissions` | `listAll` |
| `FbUserPermissionModel` / `FbUserRoleModel` | `fb_user_permissions` / `fb_user_roles` | `setForUser`, `getForUser` |
| `FbConfigurationModel` | `fb_configuration` | `get($name)` (riga con `value`), `setValue($name,$value)` upsert — chiavi note: `default_tax_rate`, `hourly_cost`, soglie alert |
| `FbMenuModel` | `fb_menu` | `defaultTree()` statico, `tree()`, `hasSavedMenu`, `saveTree($tree)` — menu personalizzabile drag&drop |

---

## 4. Database — tabelle `fb_*`

43 tabelle applicative. Convenzioni: PK `id_<nome>`, `legacy_id` chiave di
riconciliazione con l'import, `status`/`date_add`/`date_upd` ereditati dallo
schema legacy importato, `created_at`/`updated_at` sulle tabelle nuove.

### Utenti, ruoli e permessi

**`fb_users`** — utenti applicazione.

| Campo | Tipo | Note |
|---|---|---|
| `id` | int unsigned PK | |
| `email` | varchar(255) UNI | login |
| `password_hash` | varchar(255) | `password_hash()` PHP |
| `first_name`, `last_name` | varchar(100) | anagrafica |
| `is_active` | tinyint | 0 = disabilitato (non può loggarsi) |
| `force_password_change` | tinyint | redirect obbligatorio a `/change-password` |
| `last_login`, `created_at`, `updated_at` | datetime | |

**`fb_roles`** — ruoli (`id`, `name` UNI, `description`, timestamps).

**`fb_permissions`** — permessi atomici (`id`, `name` UNI — es. `*` admin).

**`fb_role_permissions`**, **`fb_user_roles`**, **`fb_user_permissions`** — pivot
(`user_id`/`role_id`/`permission_id`): permessi effettivi = ruoli + override utente.

### Configurazione e menu

**`fb_configuration`** — chiave/valore app (`name` UNI, `value` text). Chiavi usate:
`default_tax_rate` (IVA fallback picker), `hourly_cost` (costo orario manodopera),
soglie alert scadenze (`alert_days`, `alert_km`).

**`fb_settings`** — preferenze per scope (`scope`, `key`, `value`, `type`) — es. toast
dell'utente.

**`fb_menu`** — voci menu personalizzabili (`id_parent`, `title`, `icon`,
`color_bg`, `color_fg`, `position`, `route`).

**`fb_widgets`** — widget dashboard per utente (`user_id`, `name`, `type`,
`position`, `config` JSON, `is_active`).

### Anagrafiche e geografia

**`fb_customer`** / **`fb_supplier`** — anagrafiche (`company`, `vat_number`,
`email`, `pec`, `contact_name`, `active`; supplier ha anche `fuel` = fornitore carburante).

**`fb_address`** — indirizzi (`id_customer` XOR `id_supplier`, `id_country`,
`id_state`, `address1/2`, `postcode`, `city`, `phone_number`, `mobile_number`).

**`fb_country`**, **`fb_state`**, **`fb_city`** — lookup geografiche
(iso_code, join gerarchici) per gli indirizzi.

**`fb_category`** — categorie prodotto ad albero (`id_parent`, `level_depth`,
`position`, `active`, `name`, `description`).

**`fb_brand`** — marche (`name` UNI).

### Prodotti e magazzino

**`fb_product`** — anagrafica articoli.

| Campo | Tipo | Note |
|---|---|---|
| `id_product` | int unsigned PK | |
| `id_category`, `id_brand` | int unsigned | classificazione |
| `id_alias` | int unsigned | se ≠NULL il prodotto è un alias del prodotto radice |
| `sku` | varchar(255) | codice articolo |
| `name` | varchar(255) | descrizione |
| `price` | decimal(20,6) | prezzo vendita |
| `wholesale_price` | decimal(20,6) | ultimo prezzo d'acquisto (aggiornato ai carichi, netto sconto) |
| `tax_rate` | decimal(5,2) | aliquota IVA articolo (se 0 → fallback `default_tax_rate`) |
| `active` | tinyint | |

**`fb_stock`** — giacenza per prodotto (una riga per prodotto).

| Campo | Tipo | Note |
|---|---|---|
| `id_product` | int unsigned | |
| `quantity` | decimal(14,3) | giacenza attuale, **può essere negativa** |
| `inputs` / `outputs` | decimal(14,3) | contatori cumulativi carichi/scarichi |
| `unit` | tinyint | unità misura (indice `FbStockModel::UNIT_LABELS`) |
| `notification_limit` | decimal(14,3) | soglia sottoscorta (alert) |

**`fb_document`** — testata documento di magazzino (carico fornitore / scarico cliente / DDT).

| Campo | Tipo | Note |
|---|---|---|
| `type` | tinyint | tipo documento (`fb_document_type` / `fb_type_document`) |
| `number`, `date` | — | riferimento esterno |
| `id_supplier` / `id_customer` | int | carico = fornitore, scarico = cliente |
| `id_invoice` | int | fattura a cui il documento è associato (nullable) |
| `id_ddt` | int | riferimento DDT |
| `id_vehicle` | int | veicolo delle schede manutenzione associate (conto terzi) |
| `reference_class`/`reference_id` | — | collegamento polimorfico legacy |

**`fb_document_detail`** — riga movimento.

| Campo | Tipo | Note |
|---|---|---|
| `id_document`, `id_product` | int | testata + articolo (NULL per manodopera copiata) |
| `id_maintenance` | int | **riga copiata da scheda → non muove lo stock** |
| `quantity` | decimal(14,3) | carichi positivi; scarichi negativi (copie manutenzione: positive) |
| `price`, `discount` | decimal | prezzo unitario, sconto % |
| `lot` | varchar | lotto/prezzo acquisto selezionato |
| `vat_code`, `vat_rate` | — | codice e aliquota IVA |
| `type`, `warehouse_id`, `status` | — | meta legacy |
| `note` | varchar | es. "Manodopera: … (sc. #N)" |

**`fb_invoice`** — testata fattura (fornitore XOR cliente).

| Campo | Tipo | Note |
|---|---|---|
| `number`, `date` | — | numero/data fattura |
| `id_supplier` / `id_customer` | int | soggetto (carico/scarico) |
| `id_vehicle` | int | targa scheda (fatture cliente conto terzi) |
| `collection_fee`, `deposit`, `transport_fee` | decimal | spese: incasso, acconto, trasporto |

**`fb_document_type`** — tipo documento base (`TYPE_DDT=0`, `TYPE_INVOICE=1`).

**`fb_type_document`** — tipologie documento configurabili
(`TYPE_DEFAULT/WAREHOUSE/SERVICE/FUEL`).

### Manutenzione

**`fb_maintenance`** — scheda manutenzione veicolo.

| Campo | Tipo | Note |
|---|---|---|
| `id_vehicle` | int | veicolo |
| `date`, `km` | — | data e km al tagliando |
| `id_document` | int | scarico cliente che ha fatturato la scheda |
| `id_invoice` | int | fattura cliente che l'ha registrata (flag "non più disponibile") |
| `note` | varchar | |

**`fb_maintenance_detail`** — riga ricambi della scheda.

| Campo | Tipo | Note |
|---|---|---|
| `id_maintenance`, `id_product` | int | |
| `quantity` | decimal(12,3) | **negativa** (scarico magazzino) → `ABS` in fatturazione/stampa |
| `price`, `discount`, `lot` | — | prezzo/sconto/lotto |
| `vat_code`, `vat_rate` | — | IVA riga |
| `warehouse_id` | int | magazzino legacy |

**`fb_maintenance_task`** — lavorazioni/manodopera (`id_maintenance`,
`id_maintenance_detail` opzionale, `description`, `hours`, `price_per_hour`,
`manpower` = importo manodopera).

**`fb_maintenance_invoice`** — pivot scheda↔fattura (`id_maintenance`,
`id_invoice`) — mantenuta in sync con `fb_maintenance.id_invoice`.

**`fb_car_service`**, **`fb_car_service_task`** — servizi/tagliandi importati
(`id_maintenance`, `id_expiration*` — legano esecuzione scadenza al tagliando).

### Scadenze

**`fb_expiration_tag`** — voce di scadenza riusabile (`name`, `applies_to`,
`kind` date|km, `interval_value` + `interval_unit` per il rinnovo automatico).

**`fb_expiration`** — scadenza su veicolo (`id_vehicle`, `id_expiration_tag`,
`description`, `expiration_date`, `until_date`, `expires_atkm`, `periodicity`
once|yearly|km|days, `everyxdays`, `yearly_month/day`, `kind`, **`hidden`** =
nascosta dagli elenchi).

**`fb_expiration_occurrence`** — istanza schedulata.

| Campo | Tipo | Note |
|---|---|---|
| `id_expiration` | int | definizione madre |
| `expiration_date`, `km` | — | quando scade (uno dei due) |
| `state` | int | 0 In attesa · 1 Notificata · 2 Pianificata · 3 Scaduta · 4 Eseguita |
| `done_date` | date | data esecuzione effettiva |

**`fb_vehicle_km`** — registro letture km (`id_vehicle`, `amount`,
`registration_date`, `reason_class`/`reason_id`, `id_maintenance`) — alimenta
scadenze a km e calcolo consumi.

### Veicoli

**`fb_vehicle`** — anagrafica mezzi.

| Campo | Tipo | Note |
|---|---|---|
| `plate` | varchar(50) | targa |
| `id_brand`, `id_organization` | int | marca / organizzazione legacy |
| `status` | varchar(50) | `active` | `retired` (escluso da elenchi operativi) |
| `chassis_number`, `current_km` | — | telaio, km attuali |
| `start_date`, `end_date` | date | periodo operativo |
| `description`, `note` | text | classe veicolo, note |

**`fb_feature`** — definizione caratteristica (`type` enum
switch|select|value, `name` UNI, `label`).

**`fb_vehicle_feature`** — valori per veicolo (`id_vehicle`, `id_feature`,
`value`).

**`fb_vehicle_image`**, **`fb_vehicle_document`** — allegati veicolo
(`id_vehicle`, path `image`/`document`, `description` per i documenti).

### Rifornimenti

**`fb_refuelling`** — movimento carburante.

| Campo | Tipo | Note |
|---|---|---|
| `direction` | varchar(8) | `in` carico / `out` scarico |
| `id_vehicle`, `id_station`, `id_supplier`, `id_fuel_type` | int | contesto |
| `refuel_time` | datetime | data/ora |
| `liters`, `price_per_liter` | decimal | |
| `km_at_refuel`, `km_since_last_refuel` | bigint | odometro e delta (consumi) |

**`fb_refuelling_station`** — stazione/distributore (nome, indirizzo,
coordinate `lat`/`lon`/`epsg_code`).

**`fb_fuel_type`** — tipo carburante (`name`, totali `total_load`,
`total_unload`, `amount`).

---

## 5. Controller `Admin\` — pagine e stampe

Pattern uniforme: `index()` → `renderAdmin('admin/<page>.twig', [...])`;
`printPdf()` → tabella selezionata (`print/<page>.twig`);
alcuni hanno `printSingle($id)` (documento singolo) e `printDetails()` (dettaglio multiplo + riepilogo).

| Controller | Metodi | Note |
|---|---|---|
| `Dashboard` | `index` | widget home |
| `Brands`, `Categories`, `Customers`, `Suppliers`, `Vehicles`, `Products`, `Stocks`, `RefuellingStations`, `Expirations`, `Calendar` | `index`, `printPdf` | `Vehicles` ha anche `detail($id)` → `vehicle_detail.twig`; `Products` ha `printAlerts` (sottoscorta); `Refuelling` ha `loadPage` (redirect tab) |
| `Documents`, `Unloads` | `index`, `printPdf`, `printSingle` | carichi / scarichi (stesso form, `direction`) |
| `Maintenance` | `index`, `printPdf`, `printSingle`, `printDetails` | `printDetails`: scheda completa × N + riepilogo ricambi aggregato (`maintenance_details.twig`) |
| `Invoices` | `index`, `printPdf`, `printSingle`, `printDetails` | `printDetails`: fatture complete (DDT e/o schede manutenzione; le schede il cui contenuto è già in un documento collegato non si duplicano) + riepilogo fatture/articoli (`invoices_details.twig` + partial `detail_table.twig`) |
| `Refuelling` | `index`, `loadPage`, `printPdf` | — |
| `RefuellingStats` | `index` | statistiche consumi |
| `Features`, `Locations`, `Settings` | `index` | — |

Template print: `print/layout.twig` (layout PDF con `{% block content/footer/styles/header_right %}`) + un template per entità; `print/partials/detail_table.twig` è la tabella righe condivisa dalle stampe dettaglio.

---

## 6. Controller `Api\` — endpoint

Tutti con `requirePermission('*')` e risposte `{ success, ... }`.

### Anagrafiche e lookup

- `Api\Brands`: `list`, `create`, `update($id)`, `delete($id)`, `importFromLegacy`
- `Api\Categories`: `list`, `options`, `create`, `update`, `delete`, `toggleActive`, `importFromLegacy`
- `Api\Customers` / `Api\Suppliers`: `list`, `options`, (`create`, `update`, `delete` solo suppliers), `importFromLegacy`
- `Api\Locations`: `countries`, `states`, `cities`, `options` — select geografiche concatenate
- `Api\Lookup`: `options($type)`, `create($type)` — lookup generica su tabella whitelist `TABLES` (brand, category)
- `Api\Toggle`: `field()` — toggle boolean generico con whitelist `ALLOWED` (`fb_supplier.fuel/active`, `fb_product.active`)
- `Api\Features`: `list`, `configurations`, `saveConfiguration($name)`

### Veicoli — `Api\Vehicles`

`list` (`vehicle_id` opz. in filtri lato model), `definitions` (features + config per il form), `detail($id)` (anagrafica completa), `create`, `update`, `setStatus` (check/times → esclude i ritirati da scadenze/select), `delete`, `uploadImage`/`deleteImage`, `uploadDocument`/`deleteDocument`, `importFromLegacy`.

### Prodotti e stock

- `Api\Products`: `list`, `summary` (KPI), `purchases($id)` (storico acquisti), `movements($id)` (storico movimenti), `create`, `update`, `delete`, `detachAlias`, `toggleActive`, `options` — **picker prodotti**: `products` (principali con `label`, `stock_qty`, `tax_rate`, `alias_skus` via GROUP_CONCAT), `alias_products`, e con `?lots=1` anche `lot_products` (`buildLotProducts`/`priceLots`: carichi sommati per prezzo, scarichi sottratti al prezzo del movimento — niente FIFO); `lots($id)` (lotti per prezzo + ultimo/miglior prezzo d'acquisto), `aliases`/`createAlias`, `importFromLegacy`
- `Api\Stocks`: `list` (giacenze + prodotto), `update($id)`, `updateByProduct($idProduct)`, `importFromLegacy`

### Documenti — `Api\Documents`

`list` (`?direction=in|out`), `get`, `create`, `update`, `delete` (storna stock solo righe manuali), `assignInvoice` (aggancia/stacca documenti dalla fattura + `linkByDocument`/`unlinkByDocument`), **`syncMaintenances($id)`** (scarico conto terzi: collega `fb_maintenance.id_document`, copia ricambi+manodopera in `fb_document_detail` con `ABS(qty)` e `id_maintenance`, aggiorna `fb_document.id_vehicle`), `available` (documenti non fatturati per il picker DDT), `details`, `addDetail` (muove `fb_stock` salvo righe `id_maintenance`; su carico aggiorna `wholesale_price` netto sconto), `deleteDetail`, `importFromLegacy`.

### Manutenzione — `Api\Maintenance`

`list` (`?vehicle_id=`), **`lines`** (`?vehicle_id=` — righe dettaglio appiattite con data scheda, per la tabella Riparazioni del dettaglio veicolo), `get` (scheda + `tasks` + `details` + `invoices`), `create`, `update`, `delete` (registra km via `FbVehicleKmModel::register`), **`available`** (`vehicle_id`/`document_id`/`invoice_id`/`with_lines` — schede non fatturate né associate; `linked` per quelle già collegate al documento/fattura corrente; `parts_amount` con `ABS`), `details`, `addDetail`/`deleteDetail` (muovono `fb_stock` come scarico), `linkInvoice`/`unlinkInvoice`, `importFromLegacy`.

### Fatture — `Api\Invoices`

`list`, `get` (+ `maintenances` per fatture cliente), `create`, `update` (validazione dual-mode fornitore/cliente + `id_vehicle` + `maintenance_ids` → `syncMaintenances` interno su `fb_maintenance_invoice` e `fb_maintenance.id_invoice`, senza mai spostare schede di altre fatture), `delete` (pulisce i link), `assignMaintenance` (link/unlink singola scheda su fattura cliente).

### Scadenze — `Api\Expirations`

Voci: `tags`, `createTag`, `updateTag`, `deleteTag`.
Scadenze: `list` (`?vehicle_id=`), `create` (definizione + prima occorrenza), `update`.
Occorrenze: `occurrences` (`id_vehicle`, `id_expiration`, `from`, `to`, `states`, `km_only`, `date_only`, `include_hidden`), `createOccurrence`, `updateOccurrence` (esecuzione con `done_date`, rigenera prossima occorrenza da `interval_value/interval_unit` del tag), `deleteOccurrence`.
`alerts` (`days`,`km` — aperte entro soglia), `hide`/`unhide` (`fb_expiration.hidden`).

### Rifornimenti

- `Api\Refuelling`: `list` (`?direction=in|out` + filtri), `get`, `lastKm`, `vehicleFuel`, `stats`, `summary` (KPI), `options`, `create`, `update` (validazione `validateRefuelling` con **blocco scarico > carico residuo** stazione+alimentazione, `exclude_id` in edit), `alignKm`, `delete`, **`available`** (residuo carburante stazione+tipo), `importFromLegacy`
- `Api\RefuellingStations`: `list`, `create`, `update`, `delete`

### Sistema

- `Api\Settings`: utenti (`users`, `createUser`, `updateUser`, `deleteUser`, `toggleUserActive`, `resetPassword`), permessi (`permissions`, `updateUserPermissions`), ruoli (`roles`, `createRole`, `updateRolePermissions`), `changeOwnPassword`, `toastPrefs`, `config`/`saveConfig` (mappa chiavi `fb_configuration`), menu (`menu`, `menuCustom`, `saveMenu`), tipologie documento (`documentTypes` CRUD)
- `Api\Backup`: `list`, `create` (`?keep=`), `download($name)`, `restore($name)`, `delete($name)`
- `Api\Imports`: `tables`, `import($tableName)` — import tabelle legacy con mappa `legacy_id`
- `Api\Dashboard`: `widgets`

---

## 7. Frontend JavaScript

ES modules (`type="module"`), nessun bundler. Ogni pagina include `js/pages/<page>.js?v=N`.
jQuery è presente **solo** per Bootstrap Table; il resto è vanilla JS.

### 6.1 `core/`

| Modulo | API | Descrizione |
|---|---|---|
| `FetchHelper` | `get(url,data,signal)`, `post(url,data,signal)` (urlencoded, JSON array → JSON string, aggiunge CSRF), `postMultipart(url,FormData)`, `encodeFormData`, `abortable()` | Lancia `Error` con messaggio server su `!success` o HTTP≠2xx |
| `DialogHelper` | `confirm(msg,title)`→Promise&lt;bool&gt;, `alert`, `error(err)` | `<dialog>` nativi con bottoni sì/no/ok |
| `Toast` | `showToast({content,type,position,duration,banner})`, `showToastNotice/Success/Warning/Error` | Toast configurable via `window.FB.toast` |
| `ToastHelper` | `show/success/error/warning/info(msg,duration)` | Toast semplice |
| `PasswordToggle` | `init(root)` | Occhio mostra/nascondi password |
| `filter-patterns` | `window.fbFilterSearch` | Custom search di Bootstrap Table (match anche su HTML formatter); inietta legenda filtri |
| `select-on-focus`, `toggle-field` | — | utility form |

### 6.2 `components/`

| Componente | API principale | Descrizione |
|---|---|---|
| `PrintHelper` | `new PrintHelper({table,button,counter,url,idField,dialog})`; `bindForceDownloadSwitch(sel)`; `DOWNLOAD_KEY='fb_force_pdf_download'` | Conta righe selezionate (checkbox Bootstrap Table), POST nascosto in nuova tab `{ids:JSON, download?}` verso `url` |
| `SearchableSelect` | `new SearchableSelect(select,{items,placeholder,onChange,allowClear})`, `clear()` | Select nativa → input con dropdown filtrabile (position:fixed, esce dai dialog) |
| `TableSelect` | `new TableSelect(select,{items,columns,onSelect})`, `setItems`, `setValue`, `clear` | Autocomplete con dropdown **tabellare** (più colonne); `_select(value,item)` per value duplicati (lotti a prezzo) |
| `TableSelectManager` | `init(root)`, `initDelegated` | Inizializza i `select[data-table-select]` dichiarativi |
| `table-filter-select` | — | Potenzia i `filterControl:"select"` di Bootstrap Table con input ricercabile |
| `ViewGrid` | `viewItem(label,value,{span,icon})`, `viewGrid(items)`, `VIEW_ICONS` | Griglia read-only (label+icona+valore) dei dialog vista |
| `BaseBootstrapTable` | `new BaseBootstrapTable(selector,options)` `init/refresh/destroy` | Wrapper config tabella (locale it-IT, pagination, filterControl) |
| `KpiGrid` | `new KpiGrid(target,url,cardsFn,pickFn)`, `refresh()`, `fmtNum` | Griglia card KPI da endpoint (tone colori, split, bottoni azione) |
| `PurchasePicker` | `openPurchasePicker(purchasesData,onPick,onClose)`, `loadProductPurchases(idProduct)` | Dialog selezione riga acquisto (prezzo) da storico |
| `RefuellingKpis` | `new RefuellingKpis(target)`, `refresh` | KPI rifornimenti (consumo medio, litri, km) |

### 6.3 `pages/` — moduli pagina

Ogni file segue lo schema: cache opzioni → `initTable()` con `columns` (formatter + `filterControl` + `filterCustomSearch: window.fbFilterSearch`) → `window.<name>ActionsEvents` per i bottoni azione → dialog form da `<template>` nel Twig → submit `FetchHelper.post` → `bootstrapTable('refresh')`.

Particolarità per pagina:

| File | Responsabilità specifiche |
|---|---|
| `maintenance.js` | Scheda manutenzione con tab Ricambi (TableSelect con lotti prezzo) + Lavorazioni (ore/€h); view dialog read-only; `?open=N` apre scheda diretta; `available` picker schede; PrintHelper × 2 (elenco + dettagli) + switch download |
| `documents.js` | Form carico/scarico dual, tab movimenti con picker prezzi (giacenza live su lotti), tab **Manutenzioni** (scarichi cliente): select targa → elenco schede con checkbox → `POST api/documents/{id}/maintenances` |
| `invoices.js` | Dual-mode fornitore/cliente: DDT picker o schede manutenzione; dialog "Associa DDT"/"Associa manutenzioni" a 3 tab; colonna Tipo badge + Soggetto + Targa; PrintHelper elenco + dettagli |
| `expirations.js` | Voci di scadenza + scadenze veicolo + dialog esecuzione/rinnova con calcolo intervallo; `kindBadge`, `expirationDeadline` |
| `stocks.js` | Giacenze + detail view prodotto (lotti, movimenti, card ultimo/miglior prezzo) |
| `products.js` | Anagrafica prodotti con alias, switch attivo via `Api\Toggle` |
| `refuelling.js` | Carichi/scarichi carburante, box giacenza residua (`api/refuelling/available`), consumo medio |
| `refuelling-stats.js` | Grafici/statistiche consumi |
| `settings.js` | Utenti/ruoli/permessi/menu drag&drop/config/backup storico/tab dinamiche |
| `calendar.js` | Calendario occorrenze scadenze |
| `vehicles.js` | Anagrafica veicoli con tab form (info/caratteristiche/immagini/documenti/scadenze/riparazioni) |
| `vehicle_detail.js` | Pagina dettaglio: info/features/immagini/documenti + tab Scadenze (`api/expirations?vehicle_id`) e Riparazioni (`api/maintenance/lines?vehicle_id`) con init lazy |

---

## 8. CSS — design system

### 7.1 Struttura

- `theme.css` — design tokens + componenti condivisi (shadcn-inspired)
- `layout.css` — shell admin (sidebar, header, contenuto)
- `components/` — dialog, table, table-select, toast, chosen-override
- `pages/` — stili specifici per pagina

### 7.2 Token (`:root` in `theme.css`)

```
--fb-background / --fb-foreground        # sfondo / testo base
--fb-card / --fb-card-foreground         # card
--fb-muted / --fb-muted-foreground       # superfici e testo secondario
--fb-primary: #18181b                    # quasi-nero (bottoni primari)
--fb-secondary / --fb-accent             # varianti chiare
--fb-destructive: #ef4444                # azioni distruttive
--fb-border / --fb-input: #e4e4e7        # bordi/input
--fb-ring / --fb-radius: 0.625rem        # focus ring / raggio
--fb-font-sans / --fb-font-mono
```

Palette badge/badge-icon aggiuntive per pagina (blu `#2563eb`, verde `#16a34a`, ambra `#f59e0b`, viola `#7c3aed`, azzurro `#0ea5e9`).

### 7.3 Componenti principali (selettori)

| Classe | Comportamento |
|---|---|
| `.fb-page`, `.fb-page-header`, `.fb-page-actions` | Layout pagina: titolo+sottotitolo a sinistra, toolbar azioni a destra |
| `.fb-btn`, `.fb-btn-primary/secondary/ghost`, `.fb-btn-lg`, `.fb-btn-*-solid` | Bottoni; le varianti `*-solid` sono piene colorate (blu fornitore, verde cliente, viola dettagli), `fb-btn-warn` ambra stampa |
| `.fb-btn-icon`, `.fb-btn-icon-info/warning/danger/success/secondary/purple` | Bottoni solo-icona colorati per azioni tabella e toolbar |
| `.fb-btn-group` | Gruppo azioni in cella |
| `.fb-form`, `.fb-form-grid`, `.fb-form-group`, `.fb-form-label`, `.fb-form-input` | Form a griglia dentro `<dialog>` e pagine |
| `.fb-tabs` / `.fb-tab` / `.fb-tab-panel` | Tab con underline attivo; pannello `[hidden]` gestito da JS (`.fb-tab-panel.active`) |
| `.fb-switch` (+ `.fb-switch-slider`) | Toggle switch (es. Forza download PDF, Attivo) |
| `.fb-table`, `.fb-table-wrap` | Tabelle Bootstrap Table; `.num` allinea a destra, `.muted` testo secondario |
| `.fb-kpi-grid`, `.fb-kpi`, `.fb-kpi-*` | Card KPI (toni: primary/secondary/success/warning/info) |
| `.fb-view-grid`, `.fb-detail-field` | Griglia read-only dei dialog vista (`ViewGrid.js`) |
| `.fb-details-scroll`, `.fb-details-table`, `.fb-details-total` | Tabelle righe dettaglio scrollabili nei dialog |
| `.fb-badge`, `.fb-kind-badge` (km azzurro/date ambra), `.fb-state-badge` (0-4 colori), `.fb-periodicity`, `.fb-doc-link` | Pill e badge semantici |
| `.fb-dialog`, `.fb-dialog-wide`, `.fb-dialog-header/body` | Dialog nativi; `.wide` per form a schede |
| `.fb-upload-grid`, `.fb-upload-thumb` | Griglia anteprime immagini veicolo |
| `.fb-document-list`, `.fb-document-info` | Lista documenti allegati |
| `.fb-card-title`, `.fb-card` | Card sezione |
| `[hidden]` | Forzato `display:none!important` per vincere sui display author-level |

### 7.4 Bootstrap Table (components/table.css + override pagine)

- `filterControl` genera la riga filtri sotto l'header; `filterData:"var:..."` popola le select dai window-global; `.filter-control` wrapper.
- Checkbox colonna `state` per la selezione multipla usata da `PrintHelper`.
- Le tabelle dentro `.fb-tab-panel` nascosti vanno inizializzate **lazy** (la pagina veicolo lo fa al primo accesso) o `resetView` al rientro, perché bootstrap-table misura le larghezze al mount.

### 7.5 Print CSS

`print/layout.twig` incapsula gli stili PDF: `.data` (tabelle bordate), `.num` numeri a destra, `.muted`, `.center`, intestazione con logo/titolo, footer pagina. Colori in `pt`, font DejaVu (Dompdf), € mai a capo (`&nbsp;`).

---

## 9. Flussi funzionali chiave

### 8.1 Magazzino (stock)

`fb_document_detail` = riga movimento. `Api\Documents::addDetail/deleteDetail/delete` chiamano `FbStockModel::moveStock` (+carico/−scarico) **tranne** le righe con `id_maintenance` valorizzato (già scaricate). `fb_stock.quantity` può essere negativo (scarichi oltre giacenza consentiti). `products/options?lots=1` espone `priceLots`: giacenza scomposta per prezzo d'acquisto usata dal picker "Cerca per prezzo".

### 8.2 Manutenzione conto terzi

Catena: `fb_maintenance` (scheda officina, ricambi a q.tà negativa) → `fb_maintenance.id_document` → `fb_document` scarico cliente (righe copiate `ABS`, `id_maintenance`, **nessun movimento stock**) → `fb_document.id_invoice` → `fb_maintenance_invoice` + `fb_maintenance.id_invoice` (via `linkByDocument`, mantenuti in sync su assign/detach/delete/syncDocuments). Una scheda con `id_invoice` ≠ null non è più "disponibile". Fattura cliente alternativa: `Invoices::syncMaintenances` collega direttamente le schede.

### 8.3 Scadenze

`fb_expiration` (definizione: veicolo, tag, kind date/km, periodicità, hidden) → `fb_expiration_occurrence` (istanza con data/km/stato 0-4/`done_date`). Esecuzione → stato DONE + nuova occorrenza calcolata da `interval_value/interval_unit` del tag. `fb_vehicle_km` registra i km (alimenta scadenze km e consumi). Veicoli `status='retired'` esclusi dagli elenchi generali ma inclusi nel proprio dettaglio.

### 8.4 Rifornimenti

`fb_refuelling` (`direction` in/out, veicolo, stazione, tipo, litri, km). Residuo stazione+alimentazione = carichi−scarichi (`available()`); lo scarico è bloccato client+server se supera il residuo (`exclude_id` per l'edit).

### 8.5 Backup

`DbBackup` (dump PHP→ZIP in `writable/backups`) + `Api\Backup` (UI storico: ripristina/scarica/elimina) + `php spark fb:backup` (cron).

### 8.6 Import legacy

Ogni `Api\*::importFromLegacy()` mappa tabelle legacy (senza prefisso) → `fb_*` con `legacy_id` come chiave di riconciliazione; `Api\Imports` orchestra da UI (`LegacyDatabase::withoutPrefix`).

---

## 10. Riferimenti rapidi

- Rotte: `app/Config/Routes.php` (admin get/post, api get/post, auth).
- Regole progetto/changelog: `README.md`, `summary.md` (cronologia per versione).
- Manuale utente: `manuale.md` + `tools/build-manuale.php` + `tools/screenshot-manuale.js`.
- Convenzione tabelle `fb_`: sezione README "Prefisso tabelle".

---

## 11. Deploy e installazione

L'app gira nel container Docker **`fairy-bus`** (PHP 8.3 + Apache, docroot
`/var/www/html/public`), dietro il reverse proxy **Caddy** sulla rete esterna
`caddy-net`, con MariaDB in un container separato (hostname `mariaDB`).

### 11.1 File di deploy nel repository

| File | Ruolo |
|---|---|
| `Dockerfile` | `php:8.3-apache` + estensioni `intl mysqli pdo_mysql zip gd mbstring opcache`, `a2enmod rewrite`, composer dentro l'immagine, entrypoint che fa `chown www-data` su `writable/` e `public/` |
| `docker-compose.yml` | build → container `fairy-bus`; volume `./www → /var/www/html`; mount `docker/php/php.ini` (+ `php-xdebug.ini`, dev) e `docker/apache/000-default.conf`; env `TZ=Europe/Rome`, `CI_ENVIRONMENT`; rete `caddy-net` external |
| `docker/apache/000-default.conf` | VirtualHost `DocumentRoot /var/www/html/public`, `AllowOverride All` |
| `docker/php/php.ini` | `memory_limit=4G`, `post_max_size=1G`, `upload_max_filesize`, opcache abilitato |
| `docker/entrypoint.sh` | `chown -R www-data` su `writable/` e `public/` poi `apache2-foreground` |
| `Caddyfile.example` | `reverse_proxy fairy-bus:80` con header `X-Forwarded-*` |
| `cron-contabo.txt` | istruzioni cron/fuso orario sul VPS di produzione |

### 11.2 Installazione su un server

```bash
# 1. rete condivisa col reverse proxy (se non esiste)
docker network create caddy-net

# 2. checkout del progetto e build
git clone <repo> fata && cd fata
docker compose up -d --build

# 3. dipendenze PHP (composer è già nell'immagine)
docker exec -u www-data fairy-bus composer install --no-dev --optimize-autoloader

# 4. configurazione
cp www/env www/.env   # poi editare .env (vedi sotto)

# 5. database: creare lo schema e importare i dati
#    - dump SQL:  docker exec -i mariaDB mysql -u root -p clienti_fata < clienti_fata.sql
#    - oppure restore di un backup ZIP dalla UI (Impostazioni → Backup)
#    - poi le migration: docker exec -u www-data fairy-bus php spark migrate

# 6. verifica
curl -I http://localhost   # o il dominio Caddy configurato
```

### 11.3 File mancanti dopo il checkout

Il `.gitignore` esclude deliberatamente alcune directory — vanno
ricreate/generate sul server:

| Percorso | Come ripristinarlo |
|---|---|
| `www/vendor/` | `docker exec -u www-data fairy-bus composer install --no-dev` |
| `www/.env` | copiare da `www/env` (template commentato) e valorizzare le chiavi sotto |
| `www/writable/` | creare le sottocartelle `cache`, `logs`, `session`, `uploads`, `backups`, `twig` — `mkdir -p www/writable/{cache,logs,session,uploads,backups,twig}`; il `chown` lo fa l'entrypoint al riavvio, altrimenti `chown -R www-data:www-data writable` |
| `www/public/assets/fairy-bus/vendors/` | librerie front-end (jquery, bootstrap-table + filter-control, chosen, dompurify) — vanno copiate/scaricate se non presenti; sono referenziate da `admin/layout.twig` |
| dump SQL (`*.sql`, `*.gz`) | non versionati: copiarli sul server o creare il DB e ripristinare da un backup `fb-backup-*.zip` via UI |

### 11.4 `.env` di produzione

```ini
CI_ENVIRONMENT = production

app.baseURL = 'https://<dominio>/'
app.indexPage = ''

database.default.hostname = mariaDB        # host/container MariaDB
database.default.database = clienti_fata
database.default.username = <user>
database.default.password = <password>
database.default.DBDriver = MySQLi
database.default.DBPrefix =                # VUOTO: fb_ è nel nome delle tabelle

encryption.key = <64 caratteri hex>        # php spark key:generate
```

⚠️ `database.default.DBPrefix` deve restare **vuoto**: i nomi `fb_*` sono
scritti nei modelli; un prefisso attivo produrrebbe query su `fb_fb_*`.

### 11.5 Produzione vs sviluppo

- **Xdebug**: l'immagine lo installa e lo abilita via `php-xdebug.ini` montato
  dal compose. In produzione togliere il volume `php-xdebug.ini` e/o impostare
  `XDEBUG_MODE=off` nell'`environment` del compose.
- **`CI_ENVIRONMENT=production`**: disattiva debugbar e display_errors.
- **Permessi**: tutto `writable/` deve essere scrivibile da `www-data`
  (`chmod -R 775` o `chown -R www-data:www-data`); se `writable/backups` è
  stato creato da root o da un cron senza `-u`, i backup falliscono con
  `ZipArchive … Permission denied` — correggere l'owner o eliminare la
  cartella (viene ricreata dall'app).
- **Cache config**: dopo aver toccato `.env` svuotare `writable/cache/`.

### 11.6 Reverse proxy: Caddy o Nginx Proxy Manager

Il container espone la porta 80 **solo sulla rete docker** (non pubblicata
sull'host): l'accesso passa sempre dal reverse proxy.

**Opzione A — Caddy**: copiare `Caddyfile.example` adattando il dominio,
dentro un Caddyfile di un Caddy collegato alla rete `caddy-net`.

**Opzione B — Nginx Proxy Manager (NPM)**: il container deve stare sulla
**stessa rete docker di NPM** (di solito una rete external creata da NPM, es.
`npm_default` — verificare con `docker network ls` / `docker inspect` sul
container NPM). `docker-compose.yml` adattato:

```yaml
services:
  fairy-bus:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: fairy-bus
    hostname: fairy-bus
    restart: unless-stopped
    volumes:
      - ./www:/var/www/html
      - ./docker/php/php.ini:/usr/local/etc/php/php.ini:ro
      - ./docker/apache/000-default.conf:/etc/apache2/sites-available/000-default.conf:ro
    environment:
      - TZ=Europe/Rome
      - CI_ENVIRONMENT=production
      - XDEBUG_MODE=off                    # niente xdebug in produzione
    networks:
      - npm_default                        # la rete usata da Nginx Proxy Manager

networks:
  npm_default:
    external: true
```

Poi in NPM creare un **Proxy Host**:

| Campo | Valore |
|---|---|
| Domain Names | `fairybus.example.com` |
| Scheme | `http` |
| Forward Hostname / IP | `fairy-bus` (nome del container) |
| Forward Port | `80` |
| Websockets | non necessario |
| SSL | Let's Encrypt dalla tab SSL di NPM (force SSL + HSTS a piacere) |

Se NPM gira su **un altro host**, la rete condivisa non basta: pubblicare la
porta nel compose (`ports: ["8080:80"]`) e puntare il Proxy Host a
`<ip-host>:8080`, meglio se su interfaccia interna/VPN.

⚠️ In entrambi i casi aggiornare `app.baseURL` in `.env` col dominio pubblico.

**Backup periodico** (vedi `cron-contabo.txt` per la guida completa):

```cron
0 3 * * * /usr/bin/docker exec -u www-data fairy-bus php spark fb:backup --keep 14 >> /var/log/fairy-bus-backup.log 2>&1
```

  `-u www-data` è obbligatorio perché gli ZIP in `writable/backups/` siano
  gestibili anche dalla UI (storico: ripristina/scarica/elimina).

### 11.7 Comandi operativi

```bash
docker logs -f fairy-bus                          # log apache
docker exec -it fairy-bus bash                    # shell nel container
docker exec -u www-data fairy-bus php spark migrate        # migration nuove
docker exec -u www-data fairy-bus php spark migrate:status # stato migration
docker exec -u www-data fairy-bus composer update          # dipendenze
```

