# Fairy Bus — Gestionale autorimessa pullman

Web application modulare per la gestione di un'autorimessa/autofficina di pullman.

- **Framework**: CodeIgniter 4.7.4
- **Container Docker**: `fairy-bus`
- **Database**: MariaDB esterno/containerizzato, accessibile tramite rete `caddy-net`
- **Prefisso tabelle**: `fb_`
- **Templating**: Twig 3
- **Stile UI**: design system ispirato a shadcn

---

## Requisiti

- Docker + Docker Compose
- Rete Docker esterna `caddy-net` già esistente (verrà creata automaticamente se mancante)
- Container MariaDB già presente nella rete `caddy-net`, raggiungibile con hostname `mariaDB`
- (Opzionale) Caddy come reverse proxy per `https://fairybus.localhost`

---

## Primo avvio

```bash
# 1. Entra nella cartella del progetto
cd /home/massimiliano/docker/apache/fata

# 2. Crea la rete Docker se non esiste
docker network create caddy-net 2>/dev/null || true

# 3. Avvia il container
docker compose up -d --build

# 4. Entra nel container per gestire dipendenze e comandi CI4
docker compose exec -w /var/www/html fairy-bus bash

# 5. All'interno del container, installa le dipendenze (già presenti dopo primo build)
composer install

# 6. Copia e personalizza l'ambiente se necessario
cp env .env

# 7. Esegui le migrazioni (quando il DB è disponibile)
php spark migrate --all
```

---

## Accesso all'app

Se usi Caddy, aggiungi questo snippet al tuo `Caddyfile`:

```caddy
fairybus.localhost {
    reverse_proxy fairy-bus:80 {
        header_up Host {host}
        header_up X-Real-IP {remote}
        header_up X-Forwarded-For {remote}
        header_up X-Forwarded-Proto {scheme}
        header_up X-Forwarded-Host {host}
    }
}
```

Poi apri: `https://fairybus.localhost/admin/dashboard`

In alternativa, per test interno dentro il container:

```bash
docker compose exec fairy-bus curl -s http://localhost/admin/dashboard
```

---

## Configurazione database

Le credenziali attese sono:

```dotenv
database.default.hostname = mariaDB
database.default.database = clienti_fata
database.default.username = root
database.default.password = 4cc3550
database.default.DBDriver = MySQLi
```

Se il tuo container MariaDB ha un hostname diverso da `mariaDB`, modifica il file `.env` dentro il container.

---

## Struttura del progetto

- `www/` — applicazione CodeIgniter 4
- `www/app/Modules/FairyBus/` — modulo principale (controller, modelli, viste, migrazioni)
- `www/app/Libraries/Twig.php` — wrapper Twig per CI4
- `www/public/assets/fairy-bus/` — asset CSS/JS/immagini e librerie locali
- `docker/` — configurazione Apache, PHP e entrypoint
- `summary.md` — piano di lavoro, scelte tecniche e snippet riutilizzabili

---

## Autenticazione

Dopo aver eseguito le migrazioni, accedi con:

- **Email:** `admin@fairybus.local`
- **Password:** `admin`

Al primo accesso sarai obbligato a cambiare la password.

---

## Changelog

### 0.7.1 — 2026-10-02
- **Rifornimenti** (nuovo menu, icona pompa): `/admin/refuelling-stations` (Punti di rifornimento: nome, indirizzo, comune/provincia/CAP, coordinate) e `/admin/refuelling` (Gestione carburante: data, automezzo, stazione, fornitore, alimentazione, tipo operazione Scarico/Carico, litri, €/litro, km) con CRUD e stampa PDF lista.
- **Tabelle nuove** (migrazione `2026-10-02-090000`): `fb_fuel_type` (id fissi = ordinali enum legacy: 0 Benzina … 6 Metano), `fb_refuelling_station`, `fb_refuelling` — tutte con `legacy_id`.
- **Import legacy** (`POST /api/refuelling/import-legacy`, tile "Rifornimenti" in Impostazioni): `refuelling_station` → 1 punto, `refuelling` → 20063 righe; riferimenti risolti via `legacy_id` (`vehicle_id`→`fb_vehicle`, `fuel_station_id`→stazione, `supplier_id`→`fb_supplier` tramite `business_partner.role`). 526 righe legacy senza veicolo corrispondente conservate con `id_vehicle` NULL.
- **Km veicolo**: il campo "Chilometri automezzo" del form registra in `fb_vehicle_km` (motivo Refuelling) e allinea `fb_vehicle.current_km`.
- **Fix**: `FbVehicleKmModel::register` non popola più `id_maintenance` quando il motivo non è Maintenance.
- **Sicurezza**: corretti tutti i 111 call-site API che ignoravano il valore di ritorno di `requirePermission('*')` — gli endpoint rispondevano 200 anche senza sessione; ora restituiscono correttamente 403.

### 0.7.0 — 2026-10-01
- **Manutenzione** (menu Officina): pagina `/admin/maintenance` con tabella, dialog a schede Informazioni/Ricambi/Fatture, visualizza/modifica/elimina e stampa PDF (lista + singolo intervento).
- **Tabelle nuove** (migrazione `2026-10-01-120000`): `fb_maintenance`, `fb_maintenance_task`, `fb_maintenance_detail` (ricambi), `fb_maintenance_invoice` (link N:N a `fb_invoice`), `fb_car_service`, `fb_car_service_task`, `fb_expiration_tag`, `fb_expiration`, `fb_expiration_occurrence`, `fb_vehicle_km` — tutte con `legacy_id`.
- **Import legacy** (`POST /api/maintenance/import-legacy`, tile "Manutenzioni" in Impostazioni): da `maintenance`, `maintenance_task`, `trade`+`movement` (`reference_class='maintenance'` → ricambi), `expiration_tag` (extra `20000`→km / `4months`→data), `expiration`, `expiration_occurrence`, `car_service`, `car_service_task`, `vehiclekmregistration`. 8451 manutenzioni, 20339 ricambi, 1367 scadenze, 5505 occorrenze, 25098 registrazioni km.
- **Km veicolo**: il campo Km nel form crea una registrazione `fb_vehicle_km` (motivo Maintenance) e allinea `fb_vehicle.current_km`.
- **Magazzino**: i ricambi di manutenzione **scaricano** la giacenza (delete li ripristina); le righe legacy restano neutre.
- **Voci di scadenza** (`/admin/expirations`): CRUD etichette con tipo Km/Data e intervallo, più tabella scadenze veicoli con modifica periodicità (una tantum/annuale/ogni N giorni/chilometrica) e soglia data/km; stampa PDF.
- **Calendario** (`/admin/calendar`): griglia mensile con scadenze a data (chip per giorno, rosse se scadute), tabella scadenze chilometriche vs km attuali, pannello allerte (30 gg / 2000 km) e dialog occorrenza con "Segna come eseguita".
- **API**: `api/maintenance` (CRUD, dettagli, link/unlink fattura), `api/expiration-tags` (CRUD), `api/expirations` (lista, update), `api/expirations/occurrences` (filtro mese/stati), `api/expirations/alerts`, `api/expirations/occurrences/{id}/update` (stato 0–4).

### 0.6.6 — 2026-10-01
- **Carichi**: colonna Fattura con icone colorate check/times (tooltip con numero fattura); colonna Azioni convertita in button group con icone Visualizza / Modifica / Associa a fattura / Elimina.
- **Carichi**: toolbar con bottoni icona colorati e testo descrittivo solo in `title`.
- **Carichi**: dialog "Visualizza" in sola lettura con intestazione e righe dettaglio + bottone "Stampa PDF" che apre `GET /admin/documents/{id}/print` (nuovo template `print/document.twig` con totali IVA inclusa).
- **Carichi**: dialog "Associa a fattura" con elenco filtrato per fornitore del documento, ricerca client-side per numero/data/fornitore e possibilità di scollegare la fattura; endpoint `POST /api/documents/{id}/assign-invoice` (verifica fornitore fattura = fornitore documento, una sola fattura per documento).
- **Magazzino**: `FbStockModel::adjustStock()` — movimento quantità/inputs/outputs per prodotto; le righe dettaglio create manualmente incrementano la giacenza, la cancellazione riga/documento la storna. I movimenti importati dal legacy (`legacy_id` valorizzato) non toccano lo stock perché già compresi nella giacenza importata da `inventory`.
- **Fatture**: stesso trattamento — colonna Azioni a button group (Visualizza / Modifica / Associa DDT / Elimina), toolbar a icone, dialog "Visualizza" read-only stampabile (`GET /admin/invoices/{id}/print` + `print/invoice.twig` con sezione per ogni DDT e totale fattura).
- **Fatture**: dialog "Associa DDT" con elenchi "DDT associati" (scollega = reset del solo campo `id_invoice`, documento preservato) e "DDT disponibili" del fornitore, ricerca per numero/data, associazioni multiple consentite.
- **Scarichi**: nuova voce menu `/admin/unloads` — documenti verso clienti (`id_customer` su `fb_document`); stessa pagina/caratteristiche dei carichi (tabella, dialog Informazioni/Movimenti, visualizza, associa a fattura, elimina, stampa) ma il magazzino viene **scaricato**: righe manuali decrementano la giacenza e le cancellazioni la ripristinano (`FbStockModel::adjustStock` con segno in base a `id_customer`).
- **API**: `api/documents` accetta `direction=in|out`; `available` accetta anche `customer_id`; `assign-invoice` verifica il partner corretto (fornitore per carichi, cliente per scarichi); `invoices::syncDocuments` collega DDT in base al partner della fattura.
- **UI**: `documents.js` riusato per entrambe le direzioni via `data-direction` sulla tabella (select cliente/fornitore, etichette, endpoint di stampa `admin/unloads/print`).
- **UI**: classi condivise `fb-btn-group`, `fb-btn-icon` (varianti colore info/warning/danger/success), `fb-ico-ok`/`fb-ico-no`, `fb-view-grid` (griglia valori read-only), `fb-details-scroll` (tabella scrollabile con thead sticky) e `fb-picker-*` (lista selezionabile nei dialog) in `theme.css`.

### 0.6.5 — 2026-10-01
- **Documenti**: nuovo menu "Documenti" con Carichi (`/admin/documents`) e Fatture (`/admin/invoices`).
- **Carichi**: lista documenti (data, numero, fornitore, flag fattura) con filtro "Senza fattura", stampa PDF e dialog a tab Informazioni/Movimenti (KPI prezzo totale/colli/movimenti, aggiunta e rimozione righe dettaglio).
- **Fatture**: lista con conteggio DDT, stampa PDF e dialog a tab Informazioni/DDT — il tab DDT elenca i documenti del fornitore non fatturati e li collega/scollega alla fattura (`document_ids` sincronizzati via `syncDocuments` su create/update; svincolo automatico alla delete).
- **API**: `api/documents` (CRUD + `available` + CRUD dettagli) e `api/invoices` (CRUD).
- **UI**: stili condivisi in `theme.css` per tab nei form (`fb-form-tabs`), KPI card (`fb-kpi-*`) e checkbox inline toolbar.

### 0.6.4 — 2026-10-01
- **Documenti**: nuove tabelle `fb_document_type` (seed 0=DDT, 1=INVOICE), `fb_invoice`, `fb_document`, `fb_document_detail` con colonne `legacy_id` e mapping partner cliente/fornitore.
- **Importazioni**: `POST /api/documents/import-legacy` importa `invoice` → `fb_invoice`, `trade` → `fb_document` (escluso `reference_class='maintenance'`, destinato a `fb_maintenance`) e `movement` → `fb_document_detail` (via `trade_id`; prodotto risolto tramite `product_list` → `fb_product.legacy_id`; IVA da `vatrate`).
- **Impostazioni**: tile "Documenti" nelle Importazioni guidate.

### 0.6.3 — 2026-10-01
- **Stampe PDF**: stampa delle righe selezionate su tutte le pagine elenco — Marche, Categorie, Clienti, Fornitori, Prodotti, Veicoli e Giacenze (`POST /admin/{sezione}/print`, max 500 righe).
- **Helper condivisi**: `AdminController::getPrintIds()` (parsing/validazione `ids` POST, errori 422) e `AdminController::pdfResponse()` (risposta PDF inline).
- **Frontend**: nuovo componente riusabile `PrintHelper.js` (selezione righe, contatore "N selezionati", submit via form nascosto in nuova scheda); `stocks.js` rifattorizzato per usarlo.
- **Modelli**: `listByIds()`/`listAll($ids)` con filtro per ID su marche, categorie, clienti, fornitori, prodotti e veicoli.
- **Template di stampa**: `print/{brands,categories,customers,suppliers,products,vehicles}.twig` che estendono `print/layout.twig` (portrait per marche/categorie, landscape per gli altri).
- **UI**: contatore selezione e bottone "Stampa PDF" nell'header di tutte le pagine elenco; classi condivise `fb-page-header`/`fb-page-actions`/`fb-selected-count` in `theme.css`.

### 0.6.2 — 2026-10-01
- **Giacenze**: modifica giacenza tramite dialog (quantità, unità di misura, soglia di avviso, nota) con `POST /api/stocks/{id}/update`.
- **Stampe PDF**: installato `dompdf/dompdf` via composer; nuovo `FairyBus\Libraries\PdfHelper` che renderizza template Twig e genera il PDF.
- **Stampe PDF**: layout di stampa comune `print/layout.twig` (header aziendale, margini stampante `@page` 20/16/24/16mm, tabella con thead ripetuto) estendibile dai moduli.
- **Stampe PDF**: numerazione "Pagina X di Y" disegnata via canvas in `PdfHelper` (il counter CSS `pages` di Dompdf non è affidabile); attenzione: `margin`/`padding` su `html`/`body` e il selettore `*` azzerano i margini `@page`.
- **Giacenze**: stampa PDF delle righe selezionate (max 500) via `POST /admin/stocks/print` in nuova scheda; contatore righe selezionate nell'header; template `print/stocks.twig` in A4 landscape con colonna Stato "Sotto scorta".

### 0.6.1 — 2026-10-01
- **Magazzino**: nuova tabella `fb_stock` per le giacenze (migration `CreateFbStockTable`) con quantità, entrate, uscite, soglia di avviso e unità di misura.
- **Model**: `FbStockModel` con `listAll()` che incrocia le giacenze con prodotti, marca e categoria; mappa unità legacy (0=Pezzi, 1=Metri, 2=Litri) e flag `low_stock`.
- **API**: `GET /api/stocks` (elenco giacenze) e `POST /api/stocks/import-legacy` (importazione da `inventory` con match prodotto via `legacy_id`, insert in batch, normalizzazione `notification_limit` negativi → NULL).
- **Impostazioni**: nuovo tile "Giacenze" nella scheda Importazioni guidate (sorgente `inventory`).
- **Giacenze**: pagina `/admin/stocks` con tabella Bootstrap Table (Codice, Articolo, um, Q.tà, Avvisi), evidenziazione delle righe sotto soglia di avviso.

### 0.6.0 — 2026-09-22
- **Menu**: riorganizzazione in sezioni Anagrafiche, Officina, Magazzino; icone colorate per voce.
- **Anagrafiche**: gestione Fornitori (`/admin/suppliers`) con importazione dalla tabella legacy `business_partner`.
- **Anagrafiche**: gestione Nazioni / Province / Città (`/admin/locations`) con tabelle `fb_country`, `fb_state`, `fb_city`.
- **Magazzino**: gestione Categorie (`/admin/categories`) con importazione dalla tabella legacy `category`.
- **Magazzino**: gestione Prodotti (`/admin/products`) con CRUD, importazione dalla tabella legacy `product`, alias a cascata e ricerca per SKU/nome/marca/alias.
- **Prodotti**: colonna `legacy_id` su `fb_product` e `fb_vehicle` per tracciare gli ID legacy.
- **Prodotti**: form a due sezioni (Informazioni generali + Codici alias), dialog adattivo per prodotto originale o alias; select Marca e Categoria con opzione "-- NUOVO --" per creare al volo nuovi valori via modale.
- **Componente riutilizzabile**: `TableSelect` + `TableSelectManager` — select Chosen con `data-table` e pulsante "+" animato; il "+" apre un modale che salva il nuovo nome via `POST /api/lookup/{tabella}` e aggiorna tutte le select della stessa tabella.
- **Impostazioni**: scheda Importazioni con griglia di tile shadcn (icona + etichetta + sorgente) per marche, veicoli, categorie, fornitori, clienti e prodotti.
- **Clienti**: pagina di gestione (`/admin/customers`), tabella `fb_customer` (replica di `fb_supplier`) con `legacy_id` e importazione da `business_partner` con `role = 1`.
- **Fornitori**: campo `legacy_id` su `fb_supplier` per tracciare l'ID legacy.
- **Indirizzi**: colonna `id_customer` posizionata prima di `id_supplier` in `fb_address`.

### 0.5.0 — 2026-09-11
- Nuovo modulo Veicoli con pagina `/admin/vehicles`.
- Tabelle `fb_vehicle`, `fb_brand`, `fb_feature`, `fb_vehicle_feature`, `fb_vehicle_image`, `fb_vehicle_document`, `fb_configuration`.
- Seed caratteristiche veicolo e configurazioni select (tipo motore, alimentazione, euro).
- Schede veicolo: informazioni, immagini, documenti, scadenze e riparazioni (placeholder).
- Upload immagini e documenti con associazione al veicolo.
- Gestione Marche (`/admin/brands`) con importazione dalla tabella legacy `vehicle`.
- Gestione Caratteristiche (`/admin/features`) con configurazione valori select.
- Importazione veicoli dalla tabella legacy `vehicle` con distribuzione in `fb_vehicle` e `fb_vehicle_feature`.
- Menu laterale aggiornato con Veicoli, Marche e Caratteristiche.

### 0.4.0 — 2026-08-31
- Pagina Impostazioni (`/admin/settings`) con tab Utenti, Permessi e Cambia password.
- Gestione utenti: crea, modifica, attiva/disattiva, elimina, reset password.
- Gestione permessi diretti per utente (aggiungi, revoca, modifica).
- Tabella Bootstrap Table per elenco utenti e rendering custom per permessi.
- API REST per tutte le operazioni di settings.

### 0.3.0 — 2026-08-31
- Aggiornamento database: tabelle utenti, ruoli, permessi e associazioni.
- Model `FbUserModel`, `FbRoleModel`, `FbPermissionModel`, `FbUserRoleModel`.
- Seed ruolo `Amministratore` con permesso universale `*` e associazione all'utente admin.
- Metodi `can()` e `requirePermission()` in `AdminController`.
- Migrazioni eseguite con successo nel container.
- Test login completo con cambio password obbligatorio al primo accesso.

### 0.2.0 — 2026-08-31
- Implementazione autenticazione con sessione.
- Pagina login in stile shadcn (`/login`).
- Utente iniziale `admin@fairybus.local` / `admin` con flag `force_password_change`.
- Pagina cambio password obbligatoria (`/change-password`) al primo accesso.
- Protezione delle pagine admin tramite `AdminController`.
- Logout e navigazione utente nell'header.

### 0.1.0 — 2026-08-31
- Analisi requisiti dal file `prompt.txt`.
- Definizione dello stack tecnico: CodeIgniter 4, Twig, MariaDB, Docker.
- Creazione container Docker `fairy-bus` con PHP 8.3, Apache, Xdebug e Composer.
- Configurazione rete `caddy-net` per integrazione con reverse proxy Caddy.
- Installazione CodeIgniter 4.7.4 e Twig 3.28 dentro il container.
- Progettazione struttura modulare (`app/Modules/FairyBus`).
- Implementazione wrapper Twig, layout admin, dashboard iniziale e componenti JS di base.
- Prime migrazioni per utenti, ruoli, impostazioni e widget.
- README iniziale, `Caddyfile.example` e versionamento.

---

## Licenza

Academic Free License version 3.0 — vedi `LICENSE.md`.

Copyright since 2026 Massimiliano Palermo.
