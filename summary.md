# Fairy Bus — Piano di lavoro e scelte tecniche

> Progetto: gestionale web per autorimessa/autofficina di pullman.
> Framework: CodeIgniter 4.7.4.
> Container Docker: `fairy-bus`.
> Database: MariaDB esterno/containerizzato via rete `caddy-net`, nome DB `clienti_fata`.
> Prefisso tabelle: `fb_`.

---

## 1. Ambiente e stack scelti

| Componente | Scelta | Motivazione |
|---|---|---|
| PHP | `8.3-apache` | Compatibile con CI4, supporto a lungo termine. |
| Framework | CodeIgniter 4.7.4 | Richiesto dall'utente; moduli nativi con namespace PSR-4. |
| Templating | Twig 3.28 | Richiesto; render CI4 delegato a wrapper `App\Libraries\Twig`. |
| JS | Vanilla ES6+ con classi | Richiesto; jQuery solo per Chosen e Bootstrap Table. |
| CSS | Custom design system in stile shadcn | Senza Tailwind: replico l'estetica con CSS custom properties per un ambiente leggero. |
| Tabelle interattive | Bootstrap Table 1.24.0 | Richiesto; inizializzazione via classi JS. |
| Select avanzate | Chosen 1.8.7 | Richiesto; inizializzazione jQuery. |
| Dialog | Native `<dialog>` | Richiesto; stile shadcn e componente JS riutilizzabile. |
| DB layer | CI4 Query Builder / prepared statements | Best practice CI4. |
| Autenticazione | Sessione CI4 + ruoli/permessi `fb_*` | Implementata: login, cambio password obbligatorio, `can()`/`requirePermission()`. |
| PDF | Dompdf via `Libraries/PdfHelper.php` | Stampa elenchi e schede da template `print/*.twig`. |
| Import legacy | `Libraries/LegacyDatabase.php` | Connessione separata alle tabelle legacy; `legacy_id` sulle tabelle `fb_*`. |

---

## 2. Struttura modulare attuale

```text
fata/                                     <- root progetto
├── docker-compose.yml
├── Dockerfile
├── Caddyfile.example
├── docker/
│   ├── apache/000-default.conf
│   ├── php/php.ini
│   ├── php/php-xdebug.ini
│   └── entrypoint.sh
├── www/                                  <- CodeIgniter 4
│   ├── app/
│   │   ├── Config/
│   │   │   ├── Autoload.php              <- namespace FairyBus registrato
│   │   │   └── Routes.php                <- tutte le route admin/* e api/*
│   │   ├── Controllers/
│   │   │   ├── AdminController.php       <- base pagine admin: auth, permessi,
│   │   │   │                              getPrintIds(), pdfResponse(), renderAdmin()
│   │   │   └── BaseController.php
│   │   ├── Libraries/Twig.php            <- wrapper Twig
│   │   └── Modules/FairyBus/
│   │       ├── Libraries/
│   │       │   ├── LegacyDatabase.php    <- connessione DB legacy (import)
│   │       │   └── PdfHelper.php         <- Dompdf + render print/*.twig
│   │       ├── Controllers/
│   │       │   ├── Admin/                <- pagine + printPdf per modulo
│   │       │   │   ├── Brands.php        Categories.php      Customers.php
│   │       │   │   ├── Dashboard.php     Documents.php       Expirations.php
│   │       │   │   ├── Features.php      Invoices.php        Locations.php
│   │       │   │   ├── Maintenance.php   Products.php        Refuelling.php
│   │       │   │   ├── RefuellingStations.php                RefuellingStats.php
│   │       │   │   ├── Settings.php      Stocks.php          Suppliers.php
│   │       │   │   └── Unloads.php       Vehicles.php        Calendar.php
│   │       │   ├── Api/                  <- REST JSON {success, rows|error}
│   │       │   │   ├── Brands.php        Categories.php      Customers.php
│   │       │   │   ├── Dashboard.php     Documents.php       Expirations.php
│   │       │   │   ├── Features.php      Imports.php         Invoices.php
│   │       │   │   ├── Locations.php     Lookup.php          Maintenance.php
│   │       │   │   ├── Products.php      Refuelling.php      RefuellingStations.php
│   │       │   │   ├── Settings.php      Stocks.php          Suppliers.php
│   │       │   │   └── Toggle.php        Vehicles.php
│   │       │   └── Auth/
│   │       │       ├── Login.php           <- login/logout
│   │       │       └── ChangePassword.php  <- cambio password obbligatorio
│   │       ├── Database/Migrations/      <- ~40 migrazioni fb_* (users, ruoli,
│   │       │                                veicoli, anagrafiche, prodotto, stock,
│   │       │                                documenti, manutenzione, scadenze,
│   │       │                                rifornimenti, menu, type_document)
│   │       ├── Models/                   <- un Fb*Model per tabella; i listAll()
│   │       │   │                            espongono campi join già formattati
│   │       │   ├── FbProductModel.php    <- + join fb_stock (giacenza per riga)
│   │       │   ├── FbStockModel.php      <- unit_label, low_stock, adjustStock()
│   │       │   ├── FbRefuellingModel.php <- decorateConsumption(), km_error
│   │       │   ├── FbDocumentModel.php, FbDocumentDetailModel.php, FbInvoiceModel.php,
│   │       │   │   FbTypeDocumentModel.php
│   │       │   ├── FbMaintenanceModel.php, FbMaintenanceTaskModel.php,
│   │       │   │   FbMaintenanceDetailModel.php, FbMaintenanceInvoiceModel.php
│   │       │   ├── FbExpirationModel.php, FbExpirationTagModel.php,
│   │       │   │   FbExpirationOccurrenceModel.php
│   │       │   ├── FbVehicleModel.php + FbVehicle{Feature,Image,Document,Km}Model.php
│   │       │   ├── FbCustomerModel.php, FbSupplierModel.php, FbAddressModel.php,
│   │       │   │   FbCountryModel.php, FbStateModel.php, FbCityModel.php
│   │       │   ├── FbBrandModel.php, FbCategoryModel.php, FbFeatureModel.php,
│   │       │   │   FbConfigurationModel.php, FbFuelTypeModel.php,
│   │       │   │   FbRefuellingStationModel.php, FbMenuModel.php
│   │       │   └── FbUserModel.php, FbRoleModel.php, FbPermissionModel.php,
│   │       │       FbUserRoleModel.php, FbUserPermissionModel.php
│   │       └── Views/
│   │           ├── admin/                <- una pagina per modulo + layout.twig
│   │           │   ├── products.twig     stocks.twig         refuelling.twig
│   │           │   ├── documents.twig    invoices.twig       unloads.twig
│   │           │   ├── maintenance.twig  expirations.twig    calendar.twig
│   │           │   ├── vehicles.twig     vehicle_detail.twig brands.twig
│   │           │   ├── categories.twig   customers.twig      suppliers.twig
│   │           │   ├── locations.twig    features.twig       dashboard.twig
│   │           │   ├── settings.twig     refuelling_stats.twig
│   │           │   └── refuelling_stations.twig
│   │           ├── print/                <- template PDF (layout.twig comune)
│   │           └── auth/                 <- login.twig, change_password.twig
│   ├── public/assets/fairy-bus/
│   │   ├── css/
│   │   │   ├── theme.css                 <- design system (.fb-*, .fb-kpi, .fb-toggle…)
│   │   │   ├── layout.css                <- sidebar/header/content
│   │   │   ├── components/               <- dialog, toast, table, table-select,
│   │   │   │                              chosen-override
│   │   │   └── pages/                    <- un .css per pagina
│   │   ├── vendors/                      <- jquery, chosen, bootstrap-table (+filter-control)
│   │   └── js/
│   │       ├── core/
│   │       │   ├── FetchHelper.js        <- fetch urlencoded/FormData + abort
│   │       │   ├── DialogHelper.js       <- <dialog> nativo: alert/confirm/error
│   │       │   ├── Toast.js / ToastHelper.js
│   │       │   ├── filter-patterns.js    <- operatori filtri colonna (window.fbFilterSearch)
│   │       │   ├── toggle-field.js       <- window.toggleTrueFalse (toggle booleani)
│   │       │   └── PasswordToggle.js
│   │       ├── components/
│   │       │   ├── BaseBootstrapTable.js <- wrapper tabella base
│   │       │   ├── KpiGrid.js            <- toolbar card KPI da endpoint summary
│   │       │   ├── RefuellingKpis.js     <- 5 card rifornimenti su KpiGrid
│   │       │   ├── ViewGrid.js           <- scheda info a mini-card iconate
│   │       │   ├── PrintHelper.js        <- stampa PDF righe selezionate
│   │       │   ├── SearchableSelect.js   <- select con input ricercabile (dialog)
│   │       │   ├── table-filter-select.js<- select ricercabile nei filtri colonna
│   │       │   ├── TableSelect.js + TableSelectManager.js <- select + quick-add lookup
│   │       └── pages/                    <- un .js ES module per pagina
│   └── vendor/                           <- dipendenze composer
├── README.md
└── summary.md
```

---

## 3. Piano di lavoro

### Fase 0 — Infrastruttura (completata)
1. Definire `Dockerfile` e `docker-compose.yml` per `fairy-bus` su `caddy-net`.
2. Inizializzare CodeIgniter 4 tramite Composer all'interno del container.
3. Configurare `env`, `Config/Autoload.php`, `Config/Routes.php`.
4. Integrare Twig con wrapper `App\Libraries\Twig`.
5. Scaricare librerie frontend locali (jQuery, Chosen, Bootstrap Table).

### Fase 1 — Contenitore grafico (completata)
1. Creare design system CSS (`theme.css`, `layout.css`, componenti).
2. Creare layout base Twig: sidebar + header + content area.
3. Creare `AdminController` che carica il layout e passa le variabili comuni.

### Fase 2 — Componenti JS riutilizzabili (completata)
1. `FetchHelper`: gestione `application/x-www-form-urlencoded`, `FormData`, `AbortController`.
2. `DialogHelper` / template `<dialog>` nativo con stile shadcn.
3. `ToastHelper`: notifiche leggere.
4. `BaseBootstrapTable`: wrapper Bootstrap Table con AJAX fetch.

### Fase 3 — Dashboard modulare (completata base)
1. Controller `Admin\Dashboard` e API `Api\Dashboard`.
2. Template `dashboard.twig` con area widget.
3. Widget di esempio: calendario, scadenze, promemoria.
4. Endpoint AJAX per caricare i dati dei widget.
5. Persistenza widget tramite tabella `fb_widgets` (migrazione pronta, da collegare all'API).

### Fase 4 — Autenticazione, ruoli, permessi (completata)
1. Schema `fb_users` con flag `force_password_change` e `last_login`.
2. Tabelle `fb_roles`, `fb_permissions`, `fb_user_roles`, `fb_role_permissions`.
3. Model `FbUserModel`, `FbRoleModel`, `FbPermissionModel`, `FbUserRoleModel`.
4. Pagina login `/login`, logout `/logout` e cambio password `/change-password`.
5. Protezione di `AdminController` con redirect automatico a `/login` e a `/change-password`.
6. Metodi `can()` e `requirePermission()` nel controller admin.
7. Seed ruolo `Amministratore` con permesso universale `*` assegnato all'utente admin.
8. Migrazioni eseguite e test login/cambio password/dashboard completati.

### Fase 5 — Impostazioni, utenti e permessi (completata)
1. Pagina `/admin/settings` con tab Utenti, Permessi, Cambia password.
2. CRUD utenti (crea, modifica, attiva/disattiva, elimina, reset password).
3. Tabella Bootstrap Table per elenco utenti con formattatori custom.
4. Gestione permessi diretti per utente tramite `fb_user_permissions`.
5. API REST per utenti, permessi e cambio password autonomo.
6. Form dialog nativo per creazione, modifica, permessi e conferme.

### Fase 6 — Modulo Veicoli, Marche e Caratteristiche (completato)
1. Tabelle `fb_vehicle`, `fb_brand`, `fb_feature`, `fb_vehicle_feature`, `fb_vehicle_image`, `fb_vehicle_document`, `fb_configuration`.
2. Seed caratteristiche e configurazioni select (tipo motore, alimentazione, euro).
3. Controller e pagine: Veicoli, Marche, Caratteristiche.
4. Pagina veicolo a schede: informazioni, caratteristiche, immagini, documenti, scadenze e riparazioni (placeholder).
5. Importazione marche e veicoli dalla tabella legacy `vehicle`.
6. Associazione caratteristiche per veicolo con switch, select e value.
7. Colonna `legacy_id` su `fb_vehicle` per tracciare ID legacy.

### Fase 7 — Anagrafiche e Magazzino (completata)
1. **Menu**: riorganizzato in Anagrafiche, Officina, Magazzino; icone colorate per sezione.
2. **Anagrafiche**: Fornitori (`fb_supplier` + `fb_address`) con importazione da `business_partner` filtrando `role = 0`; campo `legacy_id` per tracciare l'ID originale.
3. **Anagrafiche**: Clienti (`fb_customer` + `fb_address.id_customer`) con pagina di gestione `/admin/customers`, importazione da `business_partner` filtrando `role = 1` e campo `legacy_id` per tracciare l'ID originale; `id_customer` posizionato prima di `id_supplier` in `fb_address`.
4. **Anagrafiche**: Nazioni / Province / Città (`fb_country`, `fb_state`, `fb_city`).
5. **Magazzino**: Categorie (`fb_category`) con importazione da `category` e gestione gerarchica.
6. **Magazzino**: Prodotti (`fb_product`) con CRUD, importazione da `product`, alias e ricerca a cascata.
7. **Magazzino**: alias prodotto con form dedicato, conteggio alias, scollegamento alias (`id_alias = null`).
8. **Impostazioni**: scheda Importazioni con griglia di tile shadcn per marche, veicoli, categorie, fornitori, clienti, prodotti.
9. **UI tabelle**: colonna checkbox iniziale su tutte le tabelle dati.
10. **Componente riutilizzabile**: `TableSelect` + `TableSelectManager` — select Chosen con `data-table` e pulsante "+" animato; modale overlay che salva il nuovo nome via `POST /api/lookup/{tabella}` (endpoint generico con whitelist `brand`, `category`), poi refresh di tutte le select della stessa tabella e selezione del nuovo valore.

### Fase 8 — Moduli funzionali (in corso)

Completati:
- Giacenze (`fb_stock`) + fusione nella pagina prodotti (colonne um/giacenza/avvisi, upsert stock, sottoscorta)
- Documenti Carichi/Scarichi (`fb_document` + `fb_document_detail`) e Fatture (`fb_invoice`), con movimentazione giacenza
- Manutenzioni (`fb_maintenance`/`_task`/`_detail`/`_invoice`) con scarico ricambi e km veicolo
- Scadenze (`fb_expiration*` + `fb_expiration_tag`/`_occurrence`) e Calendario scadenze
- Rifornimenti (`fb_refuelling` + `fb_refuelling_station` + `fb_fuel_type`) split carico/scarico con consumi km/l

Da fare:
- Riparazioni / interventi officina (`fb_repairs`, `fb_repair_tasks`)
- Pagamenti (`fb_payments`)
- Ordini / DDT (`fb_orders`, `fb_order_details`, `fb_ddts`)
- Messaggi / notifiche / log
- Backup / restore

### Fase 9 — Importazione dati legacy (completata sostanzialmente)
1. `LegacyDatabase` + tile importazioni in Impostazioni per marche, veicoli,
   categorie, fornitori, clienti, prodotti, giacenze, documenti, manutenzioni,
   scadenze, rifornimenti.
2. Riferimenti risolti via `legacy_id` sulle tabelle `fb_*`.
3. Post-ops per dominio (es. allineamento km rifornimenti via `alignKmChain()`).

### Fase 10 — Testing e documentazione
1. Unit test con PHPUnit.
2. Changelog per ogni versione in `README.md`.
3. Aggiornamento `summary.md` con snippet riutilizzabili.

---

## 4. Versionamento

Useremo **Semantic Versioning** partendo da:

- `0.1.0` — scaffold CI4 + Docker + struttura modulare + dashboard base.
- `0.2.0` — autenticazione, login e cambio password obbligatorio.
- `0.3.0` — ruoli, permessi, seed admin e test del flusso completo.
- `0.4.0` — pagina Impostazioni con gestione utenti, permessi diretti e cambio password.
- `0.5.0` — modulo Veicoli, Marche, Caratteristiche e Configurazioni.
- `0.6.0` — Anagrafiche (fornitori, nazioni/province/città), Magazzino (categorie, prodotti, alias).
- `0.6.1` — Giacenze: tabella `fb_stock`, importazione da legacy `inventory`, pagina `/admin/stocks`.
- `0.6.2` — Giacenze: editing giacenza/soglia avviso; stampe PDF con Dompdf + `PdfHelper` + layout Twig `print/layout.twig`; stampa solo righe selezionate (max 500, limite memoria Dompdf).
- `0.6.3` — Stampa PDF su tutte le pagine elenco (Marche, Categorie, Clienti, Fornitori, Prodotti, Veicoli, Giacenze) via componente `PrintHelper.js` + helper `AdminController::getPrintIds()`/`pdfResponse()`; template `print/*.twig` per modulo; `listByIds`/`listAll($ids)` nei modelli.
- `0.6.4` — Documenti: tabelle `fb_document_type`, `fb_invoice`, `fb_document`, `fb_document_detail` + import da `trade`/`invoice`/`movement` (esclusi `reference_class='maintenance'` → futura `fb_maintenance`); partner risolto su `fb_customer`/`fb_supplier` via `business_partner.role`.
- `0.6.5` — Menu "Documenti": pagina Carichi (`/admin/documents`, dialog tab Informazioni/Movimenti) e Fatture (`/admin/invoices`, dialog tab Informazioni/DDT con collegamento dei documenti non fatturati del fornitore); CRUD completo via API; stampa PDF di entrambe le liste.
- `0.6.6` — Carichi: icone check/times nella colonna Fattura, button group icona (visualizza/modifica/associa/elimina), toolbar a icone con `title`; dialog Visualizza read-only stampabile (`GET /admin/documents/{id}/print` + `print/document.twig`); dialog Associa a fattura con ricerca numero/data/fornitore (`POST /api/documents/{id}/assign-invoice`, vincolo stesso fornitore); `FbStockModel::adjustStock()` — le righe dettaglio manuali movimentano la giacenza (+ su insert, storno su delete riga/documento), le righe legacy sono neutre. Fatture: button group azioni, dialog Visualizza stampabile (`GET /admin/invoices/{id}/print` + `print/invoice.twig`), dialog Associa DDT (multi-associazione, scollega = reset solo `id_invoice`, ricerca numero/data). Scarichi (`/admin/unloads`): documenti con `id_customer`, stesso modulo dei carichi (`documents.js` direction-aware via `data-direction`), stock decrementato/stornato, `api/documents?direction=in|out`.
- `0.7.0` — Manutenzione e scadenze: `fb_maintenance`/`_task`/`_detail`/`_invoice`, `fb_expiration_tag`/`_occurrence`/`fb_expiration`, `fb_car_service`/`_task`, `fb_vehicle_km`; import legacy completo (`maintenance`, `trade`+`movement` maintenance, `expiration*`, `car_service*`, `vehiclekmregistration`); pagina `/admin/maintenance` (dialog Info/Ricambi/Fatture, stock scaricato sui ricambi, km veicolo aggiornato via `fb_vehicle_km`); `/admin/expirations` (voci Km/Data + scadenze veicoli, periodica/una tantum); `/admin/calendar` (griglia mensile, scadenze km, pannello allerte); API `api/maintenance`, `api/expiration-tags`, `api/expirations`+`occurrences`+`alerts`; stampe PDF lista/singolo/voci.
- `0.7.1` — Rifornimenti (menu proprio): `fb_refuelling_station`, `fb_refuelling`, `fb_fuel_type` (id = ordinali enum legacy 0–6); import `refuelling_station`+`refuelling` con riferimenti risolti via `legacy_id` (veicolo, stazione, fornitore via `business_partner.role`); pagine `/admin/refuelling-stations` e `/admin/refuelling` con CRUD, select alimentazione da `fb_fuel_type`, tipo operazione Scarico/Carico, registrazione km veicolo; stampe PDF.
- `0.7.2` — Rifornimenti split per direzione: pagina Scarico (`/admin/refuelling`, solo `direction='out'`) e Carico (`/admin/refuelling-load`, `direction='in'`) con form dedicati — carico senza veicolo/km (forzati `NULL`), scarico senza fornitore; colonne condizionali per pagina (Fornitore su carico, Automezzo su scarico); componente riusabile **`components/RefuellingKpis.js`** — toolbar 5 card (Carico, Scarico, Giacenza, Ultimo carico, Ultimo scarico) su `GET api/refuelling/summary`, condivisa dalle due pagine.
- `0.7.3` — Scarico: colonne km (precedenti/attuali/differenza/**km-litro**) via `FbRefuellingModel::decorateConsumption()` — metodo del pieno: `km_diff / litri rifornimento precedente` dello stesso veicolo, mappa costruita in una query dedicata (funziona anche con filtri attivi); `km_error` → riga rossa tenue `fb-row-error` (diff<0 o km/l implicito >100, dati non congrui); casella "Differenza" readonly nel form colorata verde/gialla/rossa + blocco salvataggio su km invalidi; endpoint `POST api/refuelling/align-km` a blocchi (offset/limit su veicoli) con progress dialog — riempie `km_since_last_refuel` ordinando per `refuel_time`+`id` (non `date_add`, timestamp di importazione uniforme sui legacy); logica condivisa in `alignKmChain()` usata anche dall'import (post-ops: `direction='in'` per `id_supplier>0` + allineamento km).
- `0.7.4` — Statistiche rifornimenti (`/admin/refuelling-stats`, `RefuellingStats` + `api/refuelling/stats` con filtri data, grafici litri/costi/consumo/per-scarico; `api/refuelling/last-km`, `vehicle-fuel`); Tipi documento (`fb_type_document` seed 0–3, `FbTypeDocumentModel`, CRUD in tab "Tipi documento" Impostazioni, colonna Tipo select-filter in documents, campo nel form e nel PDF); `fb_supplier.fuel` + totali `total_load`/`total_unload`/`amount` su `fb_fuel_type`.
- `0.7.5` — Menu dinamico: tabella `fb_menu`, `FbMenuModel`, editor ad albero nella tab "Menu" di Impostazioni (voci/icone/colori/rotte), flag `fb_configuration.menu_custom` per menu personalizzato vs default; API `api/settings/menu`, `menu/save`, `menu/custom`.
- `0.8.0` — Fusione Prodotti/Giacenze: `FbProductModel::listAll()` con `LEFT JOIN fb_stock` → campi `id_stock`,`unit`,`unit_label`,`quantity`,`notification_limit`,`stock_note`,`low_stock` su ogni riga prodotto (niente N+1); colonne **um/Giacenza/Avvisi** (badge "Sotto X", riga `fb-stock-low` sotto soglia, "—" senza stock) e bottone **Giacenza** per riga → dialog upsert `POST api/stocks/product/{id}` (crea `fb_stock` registrando la q.tà iniziale in `inputs`, altrimenti aggiorna); pagina `/admin/stocks` mantenuta.
- `0.8.1` — Prodotti: toolbar KPI via nuovo **`components/KpiGrid.js`** su `GET api/products/summary` (conteggi `SUM/COUNT(DISTINCT)` in una query, join `fb_stock` per `alerts`/`low_stock`); card supportano `sub`, `button` e layout `split` a due valori (card Avvisi: "Prodotti con avvisi attivati" | "Prodotti sottoscorta" + bottone "VEDI SOTTOSCORTA"); dialog "Prodotti sotto scorta" — tabella snella (checkbox, SKU+alias, Nome, Prezzo, um, Giacenza, Avviso, bottone giacenza) che elenca solo `low_stock`, con stampa PDF a blocchi da 200 (`POST admin/products/print-alerts`, `print/product_alerts.twig`, selezionati o tutti); **detailView** Bootstrap Table — "+" su prodotti radice con `alias_count>0` (`detailFilter`), sotto-tabella replica con colonne Alias/Nome/Categoria nascoste e azioni operative (icona custom CSS: il font bootstrap-icons non è caricato); switch vista esclusivi "Solo originali"/"Solo alias" (filtro client su copia `allRows` popolata in `onLoadSuccess`); toggle attivo `fb-toggle` + button-group icone + dialog anteprima `ViewGrid`; form prodotto a tab (Info prodotto / Codici alias).
- `0.8.2` — UI cross-pagina: **`components/ViewGrid.js`** (`viewItem(label,value,opts)`/`viewGrid(items)`) — scheda informativa a mini-card con icona+tinta dedotte automaticamente dall'etichetta italiana (date, veicoli, partner, km, importi, contatti…), migrato su 7 pagine (rifornimenti, punti rifornimento, fornitori, documenti, fatture, manutenzioni, calendario); fix `SearchableSelect`/`table-filter-select` — le option `value=""` ("— Seleziona —") diventavano il valore dell'input: ora sono filtrate, l'input si svuota all'apertura e ripristina il label alla chiusura; fix crash `bootstrap-table-filter-control` — le colonne con `filterControl` non devono avere `searchable: false` (l'estensione non crea il controllo e poi accede a `options` su `undefined`); cache-buster `?v=N` sugli script di pagina (il browser teneva versioni stale).
- `0.8.3` — Dashboard con dati reali: `Admin\Dashboard` riscritto — KPI da query `fb_*` (automezzi attivi + con scadenze, scadenze aperte split scadute/30gg, sottoscorta/soglie, litri `direction='out'` del mese), sparkline su serie annuali; grafico "Andamento" = ultimi 12 anni (litri `fb_refuelling`, manutenzioni, documenti via `GROUP BY YEAR`, helper `perYear()`); lista scadenze = occorrenze aperte ordinate per urgenza (imminenti→scadute→km "a N km", `.fb-expiration-expired` rosso, km vs `current_km`); ciambella = distribuzione reale per `fb_expiration_tag` (top 4 + Altro, totale al centro); sottoscorta = top 5 `fb_stock`/`fb_product` per deficit (guardia soglia=0); icona KPI "fuel".
- `0.8.4` — Movimenti documento: dialog Carico/Scarico → `fb-dialog-wide`; select articolo → `SearchableSelect` (esteso: `data-search` sulle option, `value`, `clear()`) con ricerca su SKU/nome/**alias** (`api/products/options` ora espone `tax_rate` + `alias_skus` via GROUP_CONCAT); autofill aliquota IVA dal prodotto; `addDetail` propaga la `vat_rate` del movimento su `fb_product.tax_rate` se non impostata (0/NULL); `.fb-detail-add` a griglia allargata.
- `0.9.14` — Dettaglio veicolo (`admin/vehicles/{id}`): tab Scadenze (tabella `api/expirations?vehicle_id=` con badge tipo/stato occorrenza e filtri colonna) e tab Riparazioni (tabella righe dettaglio `api/maintenance/lines?vehicle_id=` — Data + Documento `#N` link `admin/maintenance?open=N` + Codice/Articolo/Q.tà/…/Totale come la vista scheda), init lazy al primo accesso alla tab. `FbExpirationModel::listAll`/`FbMaintenanceModel::listAll` + param `?int $idVehicle` (in dettaglio include anche mezzi ritirati). Stampa dettagli fatture: `POST admin/invoices/print-details` + `print/invoices_details.twig` (partial condiviso `print/partials/detail_table.twig`). Per fattura: intestazione completa + tabelle DDT collegati e schede manutenzione, q.tà `|abs`, totale per blocco e per fattura; pagina finale con riepilogo fatture + riepilogo articoli aggregato per SKU (prezzo medio, imponibile, ivato, totale generale). Anti-doppio conteggio: schede con `id_document` già tra i documenti della fattura stampate solo nella tabella documento (`blocksByInvoice` ora espone `m.id_document`). Toolbar: bottone viola "Stampa dettagli" (`fb-btn-purple-solid`), secondo `PrintHelper` — eredita switch Forza download.
- `0.9.13` — Fatture cliente conto terzi: toolbar con bottoni grandi colorati (Stampa ambra / Fattura fornitore blu / Fattura cliente verde). Form dual-mode: cliente+targa al posto di fornitore, tab "Manutenzione" con elenco schede veicolo non fatturate (`api/maintenance/available` esteso: `invoice_id`, `with_lines`, linked cross-vehicle), KPI live, save via `maintenance_ids` → `Invoices::syncMaintenances` su `fb_maintenance_invoice`; view+stampa PDF con blocchi scheda; `FbMaintenanceInvoiceModel::detailLines`/`blocksByInvoice`. Scarichi come manutenzione conto terzi: tab "Manutenzioni" nella scheda scarico — select targa (`api/maintenance/available`, esclude schede già fatturate/associate; `document_id` solo per edit) → checkbox schede; al save `POST api/documents/{id}/maintenances` collega (`fb_maintenance.id_document`) e copia ricambi+manodopera in `fb_document_detail` con `id_maintenance` → `addDetail`/`deleteDetail`/`delete` saltano lo stock (nessun doppio scarico), badge `sc. N` al posto del ✕. Propagazione `fb_maintenance_invoice` via `linkByDocument`/`unlinkByDocument` da `assignInvoice`/`delete`/`Invoices::syncDocuments`. Migration `AddMaintenanceDocumentLink` (`fb_maintenance.id_document`, `fb_maintenance.id_invoice`, `fb_document_detail.id_maintenance`, `fb_document.id_vehicle` — veicolo scelto in Informazioni, `id_invoice` sync con la pivot come flag "registrata").
- `0.9.12` — Scarico carburante: box "Disponibili: X lt" accanto alla quantità (rosso se superata, salvataggio bloccato; `GET api/refuelling/available` + check server-side in `validateRefuelling`, `exclude_id` in edit). Impostazioni → Generali: card "Aliquota IVA" (`default_tax_rate`, default 22) — fallback automatico nei picker ricambi/documenti quando il prodotto non ha IVA. Impostazioni → nuova scheda Backup: `DbBackup` (dump SQL→ZIP in `writable/backups`), API `Api\Backup` (create/list/download/restore/delete), storico con azioni, comando cron `php spark fb:backup --keep N`.
- `0.9.11` — Ricambi per prezzo d'acquisto: switch "Cerca per prezzo d'acquisto" sul titolo della scheda Ricambi (default ON, localStorage); `api/products/options?lots=1` espande i prodotti in lotti residui per prezzo (`priceLots`: carichi sommati per prezzo, scarichi sottratti al prezzo del movimento — niente FIFO, lo scarico lo sceglie l'utente; scarico a prezzo mai caricato → lotto negativo, chip rosso nella scheda, escluso dal picker); colonna "Prezzo acq." e autofill prezzo dal lotto. `TableSelect`: `_select(value,item)` per value duplicati + `setItems()`. Giacenza picker aggiornata live: `adjustStockCaches` scala/ripristina il lotto stesso-prezzo dopo ogni add/delete ricambio, senza refetch. Pagina Giacenza: card "Giacenza per prezzo d'acquisto" sopra i movimenti nel detail view (`GET api/products/{id}/lots`) — chip colorati q.tà×prezzo + totale valore. Form prodotto: campo UM in riga prezzi (`FbStockModel::setUnit`, select a 9 unità da `unit_labels` anche nei form giacenza), switch Attivo su barra tab, preview prezzi ivati live; `Documents::addDetail` su carico aggiorna `wholesale_price` al netto sconto (ultimo prezzo d'acquisto). IVA senza "Aliquota" ovunque → `22,00%` (`vatLabel` JS + print twig); € non separabile (`\u00A0`/`&nbsp;`); dialog scadenziario impilati (scheda info aperta sotto Esecuzione/Rinnova, refresh live via `onSaved`).
- `0.9.10` — Manutenzione: stampa dettagli multipla (`POST admin/maintenance/print-details` + `print/maintenance_details.twig`) — una scheda completa per documento + pagina riepilogo ricambi aggregata (q.tà, prezzo medio, imponibile, totale); Q.tà con unità di misura da `fb_stock` (`FbStockModel::UNIT_ABBRS`/`unitAbbr`, subquery in `listByMaintenance`), IVA solo valore `22,00%`, quantità/importi sempre `|abs`; switch "Forza download PDF" (localStorage → `download=1` → `pdfResponse` attachment) e stili `.fb-switch` promossi a `theme.css`.
- `0.9.9` — Veicoli: restyle pagina (toolbar icone, azioni icona colorate, KM formattati, toggle check/times su colonna Stato, righe ritirate in grigio); endpoint `POST api/vehicles/{id}/status`; **veicoli ritirati esclusi** da scadenze/avvisi/calendario/manutenzioni (`v.status != 'retired'` nei join) e dai select operativi (`api/vehicles?active=1` + filtri frontend su `status`, il campo `v.active` non esisteva).
- `0.9.8` — Calendario: tab "Storico scadenziario" (riga per veicolo+voce + detail view occorrenze ordinate desc, `?id_expiration=`); dialog "Rinnova" (calcolo automatico da `interval_value`/`interval_unit`, campo editabile con avviso, `POST api/expirations/occurrences` → `createOccurrence`); celle mancanti vuote per `state=4`; ordinamento default Targa→Voce→Scadenza desc; list-item "Scadenze del giorno" a card con targa stile plate EU.
- `0.9.7` — Scadenze nascoste: colonna `hidden` su `fb_expiration`; azioni modifica/elimina/nascondi-mostra sulle occorrenze (`occurrences/{id}/update|delete`, `expirations/hide|unhide`); switch "Vedi scadenze nascoste" sincronizzato sulle 3 tabelle (`include_hidden=1`), righe nascoste in viola; avvisi escludono le nascoste.
- `0.9.6` — Esecuzione scadenza con km: dialog Data+Km, `done_km` → `fb_vehicle_km` (`reason_class='Expiration'`) e aggiornamento `current_km` se superiore.
- `0.9.5` — Soglie avvisi configurabili: `fb_configuration.expiration_alert_days`/`expiration_alert_km` in Impostazioni → Generali, usate da badge "In scadenza" e `api/expirations/alerts`.
- `0.9.4` — Tab "Scadenze per veicolo": tabella veicoli con `detailView` → occorrenze del veicolo (filtro `id_vehicle` su `occurrences`); stampa sulle righe selezionate di tutte le tabelle aperte.
- `0.9.3` — Scadenziario riorganizzato: tab Calendario in coda, checkbox selezione, bottoni Stampa/Legenda/Nuova scadenza (dialog veicolo autocomplete + tipo data/km → `POST api/expirations`).
- `0.9.2` — Tab "Scadenze periodiche" in `/admin/calendar`: `date_only=1` su `api/expirations/occurrences` (solo occorrenze con `expiration_date` e tag non-km, ordinate per data); tabella Veicolo/Voce/Scadenza/Giorni mancanti (`days_remaining` calcolato in `responseHandler`, colori `.fb-days-ok/-soon/-neg`) /Stato con filtro select.
- `0.9.1` — `tools/screenshot-manuale.js`: Playwright/Chromium headless, login con FB_USER/FB_PASS (default admin@fairybus.local), 32 screenshot in `public/assets/fairy-bus/manuale/img/` — tutte le pagine (lista + schede chiave). Manuale completo: 21 rotte mappate in `manuale-pages.json`. `build-manuale.php`: immagini `![alt](img/x.png)` → `<figure>` con didascalia (risoluzione path via `imgSrc()`, chroot su `www/public`). Nota: per lo screenshot del dropdown TableSelect (position:fixed oltre il bordo del dialog) serve lo screenshot di pagina intera, non dell'elemento dialog.
- `0.9.0` — Manuale utente: `manuale.md` (marker `<!-- route: admin/xxx -->` sulle intestazioni `##`/`###`, ognuna inizia una pagina PDF), `tools/build-manuale.php` (MD→Dompdf, copertina+indice, render per-sezione per la mappa route→pagina) → `public/manuale.pdf` + `assets/fairy-bus/manuale-pages.json`. `AdminController::renderAdmin` aggiunge `help_url` (`manuale.pdf#page=N`, fallback `*`=introduzione); bottone `?` `.fb-help-btn` nell'header del layout.
- `0.8.13` — Impostazioni → Importazioni: tile "Importa tutto" (`data-action="import-all"`) che esegue in sequenza tutti gli `import-legacy` in ordine di dipendenza (marche→categorie→fornitori→clienti→veicoli→prodotti→giacenze→documenti→manutenzioni→rifornimenti) con dialog di avanzamento per-step (`runImportAll` in settings.js, stili `.fb-import-step*` in settings.css). Ogni endpoint trunca solo le proprie tabelle dati — configurazione/utenti/permessi mai toccati.
- `0.8.12b` — Ricambi: ordine colonne Codice, Articolo, Q.tà, Prezzo, Sconto, Importo, Ore, €/h, Manodopera, IVA, Totale; riga inserimento stesso ordine con label sopra ogni casella (`.fb-form-field` flex column); nuova classe dialog `fb-dialog-large` (74rem) — `dialog.css` ora versionato `?v=1` nel layout.
- `0.8.12` — Ricambi: tabella a 12 colonne (Q.tà, Codice, Articolo, Prezzo, Sconto, Importo, Ore, €/h, Manodopera, IVA, Totale); riga inserimento allineata con importo/manodopera/totale readonly live (`updateDetailRow`, ex `updateDetailManpower`); `detailTotals` include `labor_manpower` (Totale = (importo+manodopera)×(1+iva)); `listByMaintenance` fa LEFT JOIN su `fb_maintenance_task`; dialog form `fb-dialog-wide`; `initFormTabs` nasconde `.fb-form-actions` fuori dal tab info.
- `0.8.11` — `fb_configuration.hourly_cost`: endpoint `api/settings/config` (GET/POST), tab Generali in Impostazioni (form `hourly-cost-form`); `Maintenance::addDetail` sincronizza il config se il costo €/h inserito è diverso/non impostato; prefill del campo in maintenance.js + allineamento `hourlyCost` locale dopo ogni add.
- `0.8.10` — Nuovo componente riusabile `TableSelect` (`components/TableSelect.js` + `.fb-tableselect*` in theme.css): autocomplete→tabella HTML, colonne `{title,width,align,render}`, fixed-width libero + clamp viewport, ↑↓/Enter/Esc, scroll, `maxRows` (def 25), `items:[{value,label,search,data}]`, `onChange(value,item)`. Usato in tutti i picker articolo — documents.js (carichi+scarichi) e maintenance.js (Codice con radice sotto per alias, Articolo, Giacenza badge verde/giallo/rosso); `options` espone `stock_qty` (subquery fb_stock) su radici e alias.
- `0.8.9` — Autocomplete articoli "a famiglia": `options` ritorna `alias_products` separati; ogni option porta in `data-search` i termini di radice + alias → cercando un codice alias si vedono radice, alias trovato e fratelli; alias selezionabili (giacenza propria, prefix `↳`). `SearchableSelect`: cap rendering 400 item + hint; fix `[hidden]` vs `display:flex` sull'overlay manutenzione.
- `0.8.8` — Manutenzione: select veicolo → `SearchableSelect` (cerca per targa, marca, descrizione); `onChange` alimenta "Ultimi km registrati"; `id_vehicle` viaggia nell'hidden input del componente (FormData invariata).
- `0.8.7` — Manutenzione: riga km a 4 colonne (data / current_km veicolo readonly / km attuali / differenza live verde-rossa + warn + confirm al save); `fb_maintenance_task.id_maintenance_detail` (0=task generale, >0=task del ricambio); labor per-ricambio (ore, €/h, manpower=ore×€/h) creato in `addDetail` e rimosso in `deleteDetail`; `syncTask` scrive solo `description` sul task generale; `details` endpoint ritorna `labor{hours,manpower}` per il box "Totale ore"; PDF con totale manodopera.
- `0.8.6` — Tab Ricambi manutenzione allineato ai movimenti documenti: `SearchableSelect` articolo (sku/nome/alias, `data-search`), IVA autofill+fallback `lastVat`, focus→Q.tà, `fb-dialog-medium`, griglia `fb-detail-add` identica a documents.css, sync `tax_rate` prodotto in `Maintenance::addDetail`. Magazzino: `unloadStock` all'aggiunta, `loadStock` allo storno (righe `legacy_id` escluse). Display ricambi in `abs()` (legacy importati con qty negativa).
- `0.8.5` — `FbStockModel`: API semantica dei movimenti — `moveStock(id_product,'in'|'out',qty)` (accetta anche 'carico'/'scarico'), `loadStock(id,delta)`, `unloadStock(id,delta)`, `setStock(id,qty)` (inventario: setta quantity senza toccare i contatori); core privato `applyDelta()` (crea la riga stock anche in negativo se manca). `adjustStock` rimosso; call-site convertiti: `Documents::addDetail` (carico→load, scarico→unload via `stockDirection`), `Documents::deleteDetail`/`delete` (storno inverso), `Maintenance::addDetail` (unload ricambi), `deleteDetail`/`delete` (load rientro). Select-on-focus globale `core/select-on-focus.js` (focusin delegato su tutti gli input) e focus→Q.tà dopo selezione articolo; SearchableSelect `position:fixed` anti-clip nei dialog.
- `0.9.0` — Fatture, pagamenti, ordini, DDT.
- `0.10.0` — Calendario, promemoria, notifiche, log.
- `0.11.0` — Backup/restore e aggiornamenti.
- `1.0.0` — primo rilascio gestionale completo.

---

## 5. Snippet e pattern riutilizzabili

### 5.1 Query CI4 con prepared statement

```php
$db = Database::connect();
$id = $request->getPost('id');

$sql = "
    SELECT
        p.id_product,
        p.name,
        p.sku,
        s.quantity
    FROM fb_product p
    JOIN fb_stock s ON s.id_product = p.id_product
    WHERE p.id_product = :id:
";

$row = $db->query($sql, ['id' => $id])->getRow();
```

### 5.2 Fetch helper JS (x-www-form-urlencoded)

```javascript
import FetchHelper from '../core/FetchHelper.js';

const { signal, abort } = FetchHelper.abortable();
FetchHelper.post('/api/dashboard/widgets', { foo: 'bar' }, signal)
    .then(data => console.log(data))
    .catch(err => console.error(err));
```

### 5.3 Stampa PDF righe selezionate (pattern elenchi)

Backend — nel controller admin:

```php
public function printPdf(): ResponseInterface
{
    $ids = $this->getPrintIds(); // array<int> oppure Response 422/redirect
    if ($ids instanceof ResponseInterface) {
        return $ids;
    }
    $rows = (new FbXxxModel())->listAll($ids); // o listByIds($ids)
    $pdf = PdfHelper::generate('print/xxx.twig', [
        'title' => 'Elenco xxx',
        'rows' => $rows,
        'user' => $this->getCurrentUser(),
    ], 'a4', 'landscape');
    return $this->pdfResponse($pdf, 'xxx');
}
```

Rotta: `$routes->post('admin/xxx/print', '...::printPdf');`

Frontend — nella pagina (serve la colonna checkbox `{ field: "state", checkbox: true }`):

```javascript
import PrintHelper from "../components/PrintHelper.js";

new PrintHelper({
    table: "#xxx-table",
    button: '[data-action="print-xxx"]',
    counter: "#xxx-selected-count",
    url: "admin/xxx/print",
    idField: "id_xxx",
});
```

Markup nell'header pagina:

```html
<div class="fb-page-actions">
    <span class="fb-muted fb-selected-count" id="xxx-selected-count">0 selezionati</span>
    <button type="button" class="fb-btn fb-btn-secondary" data-action="print-xxx">Stampa PDF</button>
</div>
```

### 5.4 Dialog nativo

```javascript
import DialogHelper from '../core/DialogHelper.js';
const dialog = new DialogHelper();
const ok = await dialog.confirm('Vuoi proseguire?', 'Conferma');
```

### 5.5 Pattern di ricerca nei filtri colonna

Le caselle di filtro testuale delle Bootstrap Table (`filterControl: "input"`) supportano
operatori-prefisso, implementati in `public/assets/fairy-bus/js/core/filter-patterns.js`
(`window.fbFilterSearch`, assegnato come `filterCustomSearch` a tutte le colonne input —
il risultato della funzione sovrascrive il confronto standard dell'estensione).

| Pattern | Significato | Esempio |
|---|---|---|
| `<valore>` | contiene il valore (default, case-insensitive) | `roma` trova "Roma", "Romainville" |
| `%><valore>` | inizia con il valore | `%>abc` trova "ABCDEF" |
| `%/<valore>` | finisce con il valore | `%/sud` trova "Area Sud" |
| `=<valore>` | esattamente uguale (numeri confrontati numericamente) | `=10` trova solo "10", non "100" |
| `><valore>` | maggiore di (numeri e date) | `>1000` / `>01/01/2024` |
| `<<valore>` | minore di (numeri e date) | `<500` |
| `<><valore>` | diverso dal valore | `<>attivo` esclude "attivo" |
| `<-><v1>,<v2>` | compreso tra v1 e v2 (inclusi) | `<->100,200` / `<->01/01/2024,31/12/2024` |

Note:
- Per `>`, `<`, `=` e `<->` il valore della cella e gli argomenti vengono convertiti in
  numero: supporta formati `1234.56`, `1.234,56` e date `gg/mm/aaaa`, `aaaa-mm-gg`.
  Se il valore non è numerico/data, `>` `<` `<->` non producono match;
  `=` e `<>` ricadono sul confronto testuale.
- Il confronto testuale è sempre case-insensitive.
- I pattern valgono solo per le caselle input; le caselle select (menu ricercabile)
  usano confronto esatto sul valore dell'opzione.

### 5.6 Toolbar KPI — `components/KpiGrid.js`

Toolbar a card colorate (`.fb-kpi`, toni `success|danger|warning|info|secondary`)
popolata da un endpoint di riepilogo. Usata in Prodotti (`api/products/summary`)
e come base di `RefuellingKpis.js` (rifornimenti).

```javascript
import KpiGrid from "../components/KpiGrid.js";

const kpis = new KpiGrid("#products-kpis", `${window.FB.baseUrl}api/products/summary`, (s) => [
    { label: "Prodotti", value: KpiGrid.fmtNum(s.total), sub: `${KpiGrid.fmtNum(s.brands)} marche`, tone: "info" },
    // card con bottone azione:
    { label: "Non attivi", value: KpiGrid.fmtNum(s.inactive), tone: "danger",
      button: { label: "VEDI", onClick: () => openDialog() } },
    // card "split" a due valori affiancati (label+valore sx | label+valore dx):
    { split: { left: { label: "Con avvisi", value: KpiGrid.fmtNum(s.alerts) },
               right: { label: "Sotto scorta", value: KpiGrid.fmtNum(s.low_stock) } },
      tone: "danger", button: { label: "VEDI SOTTOSCORTA", onClick: openAlertsDialog } },
]);

await kpis.load();   // primo caricamento
kpis.refresh();      // dopo create/update/delete
```

Twig: `<div class="fb-kpi-grid" id="products-kpis"></div>` (skeleton card opzionali).

### 5.7 Scheda informativa — `components/ViewGrid.js`

Sostituisce la vecchia `.fb-view-grid` a celle: mini-card responsive con
icona + tinta dedotte dall'etichetta italiana (Data→viola, Automezzo→blu,
Fornitore/Cliente→teal, Prezzo/Importo→verde, Km→indigo, Indirizzo→rosa, …).

```javascript
import { viewItem, viewGrid } from "../components/ViewGrid.js";

dialogBody.innerHTML = viewGrid([
    { label: "SKU", value: row.sku },
    { label: "Prezzo vendita", value: formatPrice(row.price) },
    { label: "Nota", value: row.note, span: true },          // voce larga
    { label: "Extra", value: x, icon: svgString, tone: "warning", html: true },
]);
// oppure dentro un <dl class="fb-view-grid">…</dl> con viewItem() per voce
```

`opts`: `icon` (SVG custom), `tone` (forza la tinta), `html` (contenuto già HTML),
`span` (occupa tutta la riga). Valori vuoti → "—" attenuato. Usato in 7 pagine.

### 5.8 DetailView Bootstrap Table — sotto-tabella annidata

Pattern usato in Prodotti per mostrare gli alias di una riga. Punti chiave:

- `detailView: true` + `detailFilter: (i,row)=>bool` → il "+" appare solo sulle
  righe ammesse; `detailFormatter` ritorna il markup contenitore.
- Inizializzare la tabella figlia in `onExpandRow(index,row,$detail)` —
  `detailFormatter` gira prima che il nodo sia nel DOM.
- Le colonne si condividono con la tabella principale clonandole
  (`{...col, visible:false}` per nasconderne alcune); gli `events` delle azioni
  vanno definiti **prima** della costante colonne (sono `window.*` assegnati a
  runtime: se la costante è in cima al modulo, li cattura `undefined`).
- Il font **bootstrap-icons non è caricato**: sovrascrivere le icone con
  `icons: { detailOpen: "fb-detail-icon-open", detailClose: "fb-detail-icon-close" }`
  e renderizzare +/− via CSS `::before`.

```javascript
$table.bootstrapTable({
    uniqueId: "id_product",
    detailView: true,
    icons: { detailOpen: "fb-detail-icon-open", detailClose: "fb-detail-icon-close" },
    detailFilter: (i, row) => !row.id_alias && Number(row.alias_count || 0) > 0,
    detailFormatter: () => '<div class="fb-alias-detail"><table class="fb-alias-subtable"></table></div>',
    onExpandRow: (index, row, $detail) => {
        $detail.find(".fb-alias-subtable").bootstrapTable({
            data: collectAliases(allRows, row.id_product),
            uniqueId: "id_product",
            columns: productColumns.map((c) => HIDDEN.has(c.field) ? { ...c, visible: false } : { ...c }),
        });
    },
    columns: productColumns,
});
```

### 5.9 Filtri vista client-side su `allRows`

Pattern "Solo originali / Solo alias": mantenere una copia persistente del
dataset in `onLoadSuccess` e ri-caricare con `bootstrapTable("load", rows)` —
si compone con `customSearch` e `filterControl`, e i `detailFormatter` possono
pescare dalla copia completa anche quando la vista è filtrata. `load()` NON
riemette `load-success`, quindi riapplicare il filtro dentro `onLoadSuccess`
non genera loop (ma va riapplicato dopo ogni refresh server).

```javascript
let allRows = [], viewMode = "all";
onLoadSuccess: (data) => {
    allRows = data?.rows || [];
    if (viewMode !== "all") applyViewFilter();   // mantiene la vista dopo refresh
}
// switch esclusivi -> viewMode = "all"|"originals"|"aliases" -> applyViewFilter()
```

### 5.10 Upsert giacenza da pagina prodotti

`POST api/stocks/product/{id_product}` (in `Api\Stocks::saveForProduct`):
crea la riga `fb_stock` se assente (con `inputs = quantità` come movimento
iniziale) oppure aggiorna quantità/unità/soglia/nota. I campi stock viaggiano
già nelle righe di `GET api/products` grazie al join in
`FbProductModel::listAll()` — il dialog si apre senza fetch extra.

### 5.11 Stampa PDF in blocchi (chunked)

Per elenchi grandi: inviare più form `_blank` distanziati (~400ms, evita il
popup-blocker dopo il primo) con `ids` JSON + `part`/`parts`; backend con
`getPrintIds(200)` e titolo/nome file con "parte N di M".

```javascript
const CHUNK = 200;
for (let i = 0, part = 0; i < ids.length; i += CHUNK) {
    const chunk = ids.slice(i, i + CHUNK);
    setTimeout(() => submitPrint(chunk, ++part, Math.ceil(ids.length / CHUNK)), part * 400);
}
```

### 5.12 Note su Bootstrap Table in questo progetto

- `searchable: false` **non va mai** su colonne con `filterControl`: l'estensione
  non crea il controllo e poi crasha su `l[0].options` (tabella vuota, nessun dato).
- Gli `events` di colonna sono oggetti `window.*` — devono essere assegnati
  **prima** dell'array `columns` (o passati inline), altrimenti vengono
  catturati `undefined` e i bottoni risultano muti **senza errori console**.
- `updateCellByUniqueId` richiede `uniqueId` sulla tabella; per tabelle annidate
  usare `$(el).closest("table")` così l'update colpisce la tabella giusta.
- Script di pagina: aggiungere sempre `?v=N` allo `<script type="module">` e
  bumpare a ogni modifica — il browser serve volentieri versioni stale dei moduli ES.
- **Anche gli `import` ESM hanno cache propria**: `import X from "../components/X.js"`
  non eredita il `?v` dell'importer — se modifichi un componente importato,
  aggiungi `?v=N` anche allo specifier (es. `SearchableSelect.js?v=2`).
  Idem per i CSS globali (`theme.css`) nel layout.
- `SearchableSelect`: dropdown `position:absolute` tagliato da `<dialog>`
  (`overflow:auto` nativo) → dropup automatico `.fb-searchable-select-up` quando
  sotto l'input ci sono < 260px (`_renderDropdown` misura `getBoundingClientRect`).

---

## 6. Note operative

- Il container `fairy-bus` non espone porte sull'host; viene raggiunto tramite Caddy sulla rete `caddy-net`.
- L'entrypoint del container si assicura che `www-data` possa scrivere in `/var/www/html/writable`.
- Le librerie frontend (jQuery, Chosen, Bootstrap Table) sono scaricate localmente in `public/assets/fairy-bus/vendors/` per non dipendere dalla rete.
- Twig è configurato con cache in `writable/twig/cache` e auto-reload in ambiente di sviluppo.
- Il nome del container MariaDB atteso è `mariaDB`; se nel tuo ambiente ha un nome diverso, aggiorna `.env`.
- Per l'update degli status active dei prodotti: UPDATE fb_product p JOIN product l ON l.id = p.legacy_id SET p.active = IF(l.status = 1, 1, 0);