# Fairy Bus — Gestionale autorimessa pullman

Web application modulare per la gestione di un'autorimessa/autofficina di pullman.

- **Framework**: CodeIgniter 4.7.4
- **Container Docker**: `fairy-bus`
- **Database**: MariaDB esterno/containerizzato, accessibile tramite rete `caddy-net`
- **Prefisso tabelle**: `fb_` fa parte del nome delle tabelle nuove (es. `fb_customer`), dichiarato nei model e nelle migration — **non** è il `DBPrefix` di CodeIgniter: `database.default.DBPrefix` deve restare vuoto in `.env`, altrimenti le query cercherebbero `fb_fb_*`. Le tabelle legacy senza prefisso (`business_partner`, `trade`…) sono accessibili via `LegacyDatabase::withoutPrefix()`.
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

### 0.9.14 — 2026-10-08
- **Dettaglio veicolo: schede Scadenze e Riparazioni**: la pagina `admin/vehicles/{id}` ora mostra la tabella delle **scadenze del veicolo** (`api/expirations?vehicle_id=` — voce, tipo Data/Km con badge, prossima occorrenza, periodicità, stato occorrenza con badge colorato, nota) e la tabella **riparazioni** (`api/maintenance/lines?vehicle_id=` — una riga per dettaglio scheda: **Data** e **Documento** `#N` link alla scheda `admin/maintenance?open=N`, poi Codice/Articolo/Q.tà/Prezzo/Sconto/Importo/Ore/€\h/Manodopera/I.v.a./Totale come la vista manutenzione, q.tà in `|abs|`). Entrambe con filtri sulle colonne (`filterControl`), inizializzazione lazy al primo accesso alla tab (bootstrapTable su pannello nascosto misurerebbe male le larghezze). `FbExpirationModel::listAll`/`FbMaintenanceModel::listAll` accettano `?int $idVehicle` — quando impostato includono anche i mezzi ritirati (il filtro `status != retired` resta solo per gli elenchi generali).
- **Stampa dettagli fatture**: nuovo bottone viola "Stampa dettagli" in Magazzino → Fatture (`POST admin/invoices/print-details`, template `print/invoices_details.twig` + partial condiviso `print/partials/detail_table.twig`). Per ogni fattura selezionata stampa il documento completo — intestazione (Soggetto/Cliente, numero, data, targa, spese, nota), tabelle dei **DDT collegati** e delle **schede manutenzione** associate — con quantità sempre positive e totale per blocco/fattura. In coda (con 2+ fatture) pagina **Riepilogo fatture** (numero/soggetto/data/totale) + **Riepilogo articoli** aggregato per codice (q.tà, prezzo medio, imponibile, totale ivato, totale generale). Anti-duplicazione: le schede le cui righe sono già copiate in un documento collegato alla fattura vengono stampate solo dentro la tabella del documento (`blocksByInvoice` espone `m.id_document`, filtrate in `Admin\Invoices::printDetails`). Rispetta lo switch "Forza download PDF" (secondo `PrintHelper` sullo stesso `idField`).

### 0.9.13 — 2026-10-08
- **Fatture cliente (conto terzi)**: pagina Magazzino → Fatture con due bottoni grandi differenziati — "Fattura fornitore" (blu) e "Fattura cliente" (verde) + "Stampa" (ambra). La scheda fattura cliente sostituisce Fornitore con **Cliente + Automezzo (targa)** e il tab DDT con **Manutenzione**: elenco delle schede del veicolo non ancora fatturate (`api/maintenance/available?invoice_id` accetta anche schede già su questa fattura, `with_lines=1` espone ricambi+manodopera normalizzati), con check multiplo e KPI "Prezzo totale"/"Schede manutenzione" live. Al salvataggio `maintenance_ids` crea/rimuove i link `fb_maintenance_invoice` (`Invoices::syncMaintenances`, mai spostate da altre fatture); `delete` pulisce i link. Dialog vista e **stampa PDF** mostrano le schede come blocchi dettaglio ("Scheda del … — targa"); etichette Cliente/N. schede automatiche, colonna lista "N. doc." conta schede per le fatture cliente (`maintenances_count`). Helpers `FbMaintenanceInvoiceModel::detailLines()`/`blocksByInvoice()`.
- **Scarichi = manutenzione conto terzi**: nella scheda add/edit dello scarico (Magazzino → Scarichi) nuova tab **Manutenzioni** — si sceglie la targa del veicolo e compaiono le schede manutenzione non ancora fatturate né già associate ad altri documenti; selezionandole, al salvataggio ricambi e manodopera vengono copiati come righe del documento. Il documento si può comunque compilare a mano come prima.
- **Nessun doppio movimento stock**: le righe copiate portano `fb_document_detail.id_maintenance` — `addDetail`/`deleteDetail`/`delete` saltano il movimento `fb_stock` (i ricambi sono già usciti alla registrazione della manutenzione).
- **Tracciabilità**: `fb_maintenance.id_document` marca la scheda associata (migration `AddMaintenanceDocumentLink`); `GET api/maintenance/available` (`vehicle_id`, oppure solo `document_id` in edit per risalire alla targa) + `POST api/documents/{id}/maintenances` sincronizzano add/remove con controlli (scheda già associata o già fatturata → 422). Le righe copiate mostrano il badge `sc. N` al posto del tasto elimina — si staccano deselezionando la scheda.
- **Propagazione in fattura**: quando lo scarico è associato/rimosso da una fattura cliente, i record `fb_maintenance_invoice` delle schede collegate vengono creati/eliminati di conseguenza (`FbMaintenanceInvoiceModel::linkByDocument`/`unlinkByDocument` chiamati da `Documents::assignInvoice`/`delete` e `Invoices::syncDocuments`).
- Righe manodopera senza articolo: descrizione "Manodopera: … (sc. #N)" con `vat_rate` ripreso dall'aliquota predefinita.
- **Tabella fatture**: nuova colonna **Tipo** (badge "Carico" blu / "Scarico" verde con filtro select `invoice_kind` precomputato) e colonna **Soggetto** al posto di Fornitore — icona colorata pacco (fornitore, blu) o persona (cliente, verde) prima del nome.
- **`fb_document.id_vehicle` + `fb_maintenance.id_invoice`**: il veicolo si sceglie nella scheda Informazioni dello scarico (campo documento, salvato in create/update) e la tab Manutenzioni legge da lì le schede del veicolo senza documento/fattura. `fb_maintenance.id_invoice` registra la fattura cliente — mantenuto in sync con `fb_maintenance_invoice` da `linkByDocument`/`unlinkByDocument`, `Invoices::syncMaintenances` e `delete` (azzera solo se non restano altri link); `available()` usa la colonna come flag "già fatturata".

### 0.9.12 — 2026-10-08
- **Scarico carburante limitato al carico residuo**: nella scheda add/edit dello scarico, accanto alla quantità compare una casella informativa colorata (`fb-fuel-avail`) che mostra i litri disponibili per la coppia stazione+alimentazione selezionata; diventa rossa e blocca il salvataggio se la quantità supera il residuo. Nuovo endpoint `GET api/refuelling/available` (`fuelAvailable()` = carichi−scarichi); in edit la riga corrente è esclusa dal conteggio (`exclude_id`). Validazione anche server-side in `validateRefuelling()` (422 su litri > residuo).
- **Aliquota IVA predefinita**: nuova card "Aliquota IVA" in Impostazioni → Generali (`default_tax_rate` in `fb_configuration`, default 22). Quando un prodotto non ha IVA propria, il valore configurato viene proposto in automatico nei ricambi manutenzione e nelle righe documenti (esposto da `GET api/settings/config`); un'IVA esplicita su prodotto o riga non viene mai sovrascritta.
- **Scheda Backup/Restore** in Impostazioni: pulsante "Crea backup ora" (dump SQL completo generato in PHP → archivio ZIP in `writable/backups`, fuori dalla document root), tabella storico con azioni **Ripristina** (sovrascrive il DB), **Scarica** e **Elimina**; comando schedulabile via cron `php spark fb:backup --keep N` (`App\Commands\FbBackup`). Nuova libreria `FairyBus\Libraries\DbBackup` e API `Api\Backup` (list/create/download/restore/delete) con nomi file validati contro path traversal.
- Fix: `initAlertThresholdsForm` registrato anche nel branch `DOMContentLoaded` di settings.js.

### 0.9.11 — 2026-10-07
- **Ricambi per prezzo d'acquisto (lotti per prezzo)**: switch "Cerca per prezzo d'acquisto" sul titolo della scheda Ricambi (default ON, persistente in `localStorage`). ON: il TableSelect articolo espone il prodotto **una riga per lotto residuo** (`api/products/options?lots=1` → `buildLotProducts`/`priceLots` — i carichi si sommano per prezzo, gli scarichi si sottraggono al prezzo registrato sul movimento; residuo senza storico → lotto all'ultimo prezzo); colonne Codice/Articolo/**Prezzo acq.**/Giacenza per-lotto; selezione → autofill prezzo col prezzo del lotto. OFF: comportamento precedente (giacenza totale + ultimo prezzo d'acquisto). Nessuna allocazione FIFO: lo scarico lo sceglie l'utente.
- **Lotti negativi**: uno scarico a un prezzo mai caricato produce una voce negativa — visibile nella scheda giacenza come chip rosso ("Scaricato a un prezzo mai caricato"), esclusa dal picker ricambi (solo `qty > 0`).
- **`TableSelect`**: `_select(value, item)` passa l'item cliccato (necessario con `value` duplicati tra lotti); nuovo `setItems()` che preserva `search`/`data` custom.
- **Giacenza picker aggiornata live**: `adjustStockCaches()` scala/ripristina la giacenza nelle cache locali dopo ogni aggiunta/eliminazione ricambio sul lotto stesso-prezzo (senza match crea una voce nuova, anche negativa); il dropdown riaperto mostra subito la quantità aggiornata.
- **Form prodotto**: campo "Unità di misura" come prima casella della riga prezzi (ordine UM→IVA→Acquisto→Vendita, salvato su `fb_stock` via `FbStockModel::setUnit()`), switch "Attivo" spostato sulla barra tab, sezione informativa colorata con prezzi ivati live (`data-price-preview`). `api/products` create/update accettano `unit`. Le select UM dei form giacenza (`products.twig`, `stocks.twig`) elencano tutte le 9 unità da `FbStockModel::UNIT_LABELS` via `unit_labels`.
- **Ultimo prezzo d'acquisto**: `Documents::addDetail` su carico aggiorna `fb_product.wholesale_price` col prezzo riga al netto dello sconto (se > 0).
- **Scheda "Giacenza per prezzo d'acquisto"** nella pagina Giacenza: nel detail view di ogni riga, sopra la tabella movimenti, card colorata con un chip per lotto residuo (q.tà × prezzo, colori a rotazione, rosso se negativo) e totale pezzi+valore a destra. Nuovo endpoint `GET api/products/{id}/lots` che riusa `priceLots()` (condivisa con `buildLotProducts`).
- **IVA senza "Aliquota" ovunque**: `vatLabel()` in `maintenance.js`/`documents.js`/`invoices.js` formatta `22,00%` (fallback testo grezzo per codici non numerici); stessa correzione nei template di stampa `document.twig`/`invoice.twig`.
- **Simbolo € non separabile**: `\u00A0`/`&nbsp;` tra valore e € in tutti i formatter JS, nei template di stampa e nei placeholder KPI — il segno non va mai a capo da solo.
- **Dialog scadenziario impilati**: la scheda info occorrenza resta aperta sotto "Esecuzione"/"Rinnova" (modal nativi in pila) e si aggiorna live dopo il salvataggio (`renderInfo` + `onSaved` in `openDoneDialog`); anche il dialog "Scadenze del giorno" non si chiude più al click sulla riga.

### 0.9.10 — 2026-10-07
- **Manutenzione — stampa dettagli**: nuova icona azzurra in toolbar → `POST admin/maintenance/print-details` (`Maintenance::printDetails` + template `print/maintenance_details.twig`): una scheda completa per ogni manutenzione selezionata (veicolo/data/km/fatture/nota, lavoro eseguito, ricambi con totali) su pagina dedicata; se selezionati >1 documenti, pagina finale "Riepilogo ricambi utilizzati" aggregata per articolo (q.tà, prezzo medio ponderato, imponibile, totale ivato, totale generale). Page-break gestiti con `page-break-after` su tutti i doc tranne l'ultimo + `page-break-before` sul riepilogo (il break finale forzato faceva perdere il contenuto in Dompdf).
- **Q.tà con unità di misura e sempre positive**: `fb_stock.unit` esposto ai ricambi via subquery in `FbMaintenanceDetailModel::listByMaintenance` (nuova mappa `FbStockModel::UNIT_ABBRS` + `unitAbbr()` → pz/lt/mt/cm/mm/ml/kg/g/mq); la colonna IVA mostra solo il valore (`22,00%`, niente "Aliquota"); quantità e importi sempre `|abs` in stampa e nel riepilogo.
- **Switch "Forza download PDF"**: toggle nella toolbar manutenzione (persistente in `localStorage`); `PrintHelper` invia `download=1` → `AdminController::pdfResponse` risponde `Content-Disposition: attachment` invece di `inline`. Stili `.fb-switch` promossi da `calendar.css` a `theme.css` (ora globali); helper `PrintHelper.bindForceDownloadSwitch()` riusabile su ogni pagina.

### 0.9.9 — 2026-10-07
- **Pagina Veicoli allineata al nuovo stile**: sottotitolo, bottoni icona in toolbar (stampa ambra, nuovo verde), azioni riga a icone colorate (dettagli/modifica/elimina), colonna KM formattata, colonna **Stato come toggle check/times cliccabile** (verde attivo / rosso ritirato, con conferma alla disattivazione) e filtro select con etichette italiane; righe ritirate evidenziate in grigio corsivo. Nuovo endpoint `POST api/vehicles/{id}/status` (aggiorna solo lo stato, senza toccare features/altri campi) e nuova variante `.fb-btn-icon-secondary` nel tema.
- **Veicoli ritirati esclusi dalle liste operative**: `v.status != 'retired'` nei join di `FbExpirationOccurrenceModel` (lista, avvisi, per-id), `FbExpirationModel::listAll` e `FbMaintenanceModel::listAll`; `api/vehicles?active=1` per i select operativi; filtri frontend di scadenziario/manutenzioni/rifornimenti aggiornati da `v.active` (campo inesistente) a `status !== 'retired'`. In Statistiche rifornimenti i veicoli ritirati restano selezionabili per consultare lo storico.

### 0.9.8 — 2026-10-07
- **Tab "Storico scadenziario"** in `/admin/calendar`: una riga per coppia veicolo+voce con colonna Km attuali, detail view con le occorrenze ordinate per scadenza discendente e colonna azioni solo nel dettaglio; filtro `id_expiration` su `api/expirations/occurrences`.
- **Dialog "Rinnova" scadenza**: dalla scheda info dell'occorrenza — mostra targa/data odierna/km attuali readonly, banner con voce + intervallo (`interval_value`/`interval_unit` del tag, ora esposti dalle API) e nuova scadenza calcolata (oggi+intervallo o km+intervallo); campo editabile data/km precompilato, avviso se inferiore ad oggi/km attuali (salvataggio comunque consentito), vuoto = valori calcolati. Nuovo endpoint `POST api/expirations/occurrences` (`createOccurrence`, `state=0`).
- **Rifiniture scadenziario**: celle "mancanti" vuote per le occorrenze eseguite (`state=4`); ordinamento default Targa asc → Voce asc → Scadenza desc su tutte le tabelle e in SQL dello storico; lista "Scadenze del giorno" del calendario ridisegnata a card (targa stile plate EU, voce+scadenza impilate, badge stato).

### 0.9.7 — 2026-10-07
- **Scadenze nascoste**: colonna `hidden` su `fb_expiration` (migration `AddHiddenToFbExpiration`); colonna azioni con icone colorate su tutte le tabelle del calendario — modifica occorrenza (dialog compatta con data o km), elimina occorrenza, **nascondi/mostra scadenza** (per coppia veicolo+voce via `POST api/expirations/hide|unhide`). Switch "Vedi scadenze nascoste" sincronizzato sulle tre tabelle (`?include_hidden=1`); righe nascoste evidenziate in viola con bottone "Ripristina". Gli avvisi (`listAlerts`) escludono sempre le nascoste.

### 0.9.6 — 2026-10-06
- **Esecuzione scadenza con km**: "Segna come eseguita" apre un dialog con Data esecuzione (default oggi) e Km attuali (default `vehicle.current_km`). `updateOccurrence` con `state=4` + `done_km` registra la lettura in `fb_vehicle_km` (`reason_class='Expiration'`, `reason_id=id_expiration_occurrence`) e aggiorna `fb_vehicle.current_km` se superiore.

### 0.9.5 — 2026-10-06
- **Soglie avvisi scadenze configurabili**: `fb_configuration.expiration_alert_days` (default 30) e `expiration_alert_km` (default 2000), modificabili in Impostazioni → Generali → card "Avvisi scadenze". `api/settings/config` li espone e li salva; il calendario li usa per badge "In scadenza" e per la query `api/expirations/alerts`.

### 0.9.4 — 2026-10-06
- **Tab "Scadenze per veicolo"** in `/admin/calendar`: tabella veicoli (Targa, Veicolo, Km attuali — filtri su targa e nome) con `detailView`: il "+" espande una tabella di dettaglio con tutte le scadenze del veicolo (checkbox, Tipo km/data, Voce, Scadenza a km, Km attuali, Km mancanti, Data scadenza, Giorni mancanti, Stato — filtri su tutte le colonne). Nuovo filtro `id_vehicle` su `api/expirations/occurrences`. La stampa seleziona le righe di tutte le tabelle dettaglio aperte.

### 0.9.3 — 2026-10-06
- **Scadenziario riorganizzato**: tab Calendario spostato in coda (Avvisi → Periodiche → Chilometriche → Calendario); checkbox di selezione in entrambe le tabelle; bottoni **Stampa** (righe selezionate della tabella attiva → `admin/calendar/print` PDF), **Legenda** (dialog con significato degli stati) e **Nuova scadenza** (dialog con veicolo autocomplete, voce, tipo data/km → `POST api/expirations` crea scadenza + occorrenza).

### 0.9.2 — 2026-10-06
- **Tab "Scadenze periodiche"** in `/admin/calendar`: tabella a date (Veicolo, Voce, Scadenza, Giorni mancanti colorati, Stato) via `api/expirations/occurrences?date_only=1` — esclude le occorrenze km e quelle senza data. Click riga → stesso dialog "Segna come eseguita" delle altre viste.

### 0.9.1 — 2026-10-05
- **Manuale completo su tutte le pagine** (40 pagine): Dashboard, Anagrafiche (Clienti/Fornitori/Locations), Documenti (Carichi/Scarichi/Fatture), Rifornimenti (Stazioni/Carico/Gestione/Statistiche), Magazzino, Officina, Impostazioni — ogni rotta mappata nella sezione giusta.
- **Screenshot automatici nel manuale**: `tools/screenshot-manuale.js` (Playwright, `npm i` in `tools/`) cattura 32 schermate reali — liste, schede di creazione, overlay "salva prima", ricerca articolo con alias — in `public/assets/fairy-bus/manuale/img/`.
- `build-manuale.php` supporta `![didascalia](img/x.png)` → figure con bordo e didascalia nel PDF. Workflow completo: screenshot + rebuild PDF.

### 0.9.0 — 2026-10-05
- **Manuale d'uso** (`manuale.md` → `public/manuale.pdf`): sorgente Markdown con marker `<!-- route: admin/xxx -->`; `php tools/build-manuale.php` genera il PDF (copertina + indice con numeri di pagina, ogni sezione su pagina nuova) e il manifest `assets/fairy-bus/manuale-pages.json` (route → pagina).
- **Bottone "?" in ogni pagina** (header): apre il manuale in una nuova scheda alla sezione della pagina corrente (`manuale.pdf#page=N`); pagine non documentate aprono l'introduzione. `help_url` calcolato in `AdminController::renderAdmin`.
- Contenuto iniziale: introduzione e convenzioni UI, sezioni Magazzino (Categorie/Prodotti/Giacenze), Officina (Veicoli/Marche/Caratteristiche/Manutenzione/Voci di scadenza/Calendario), Impostazioni con le tab.

### 0.8.12 — 2026-10-05
- **Tabella ricambi ristrutturata** (12 colonne): Q.tà, Codice, Articolo, Prezzo, Sconto, Importo, Ore, €/h, Manodopera, IVA, Totale. La riga di inserimento è allineata alle colonne con Importo/Manodopera/Totale readonly calcolati live; il pulsante Aggiungi scrive subito nel DB (scarico giacenza incluso). Dialog form allargato a `fb-dialog-wide`; bottoni Salva/Annulla nascosti nei tab Ricambi/Fatture.
- `FbMaintenanceDetailModel::listByMaintenance` ora include ore/€h/manodopera della riga manodopera collegata (`id_maintenance_detail`).

### 0.8.11 — 2026-10-05
- **Configurazione globale `hourly_cost`** (`fb_configuration`): nuovo endpoint `api/settings/config` (GET/POST) e tab **Generali** in Impostazioni con il campo "Costo orario manodopera (€/h)".
- Il campo **Costo €/h** dei ricambi manutenzione è precompilato dal default globale; se inserisci un valore diverso (o non ancora configurato) viene salvato automaticamente in configurazione al momento dell'aggiunta del ricambio.

### 0.8.10 — 2026-10-05
- **Nuovo componente `TableSelect`**: autocomplete con dropdown a tabella HTML — colonne configurabili (`{title, width, align, render(item)}`), larghezza libera oltre l'input (`position:fixed`, clampato alla viewport, apertura verso l'alto se serve), navigazione ↑↓/Enter/Esc con riga attiva evidenziata, scroll verticale, `maxRows` configurabile (default 25) con riga "altri risultati…", `<tr data-value>` con id selezionabile.
- **Tutti i picker articolo** usano `TableSelect` (Carichi, Scarichi, Ricambi manutenzione): colonne Codice (alias blu + codice radice sotto), Articolo, Giacenza (badge verde >0 / giallo 0 / rosso <0); ricerca a famiglia su radice+alias. `api/products/options` espone `stock_qty` anche per gli alias.

### 0.8.9 — 2026-10-05
- **Ricerca articoli a famiglia**: `api/products/options` espone anche `alias_products`; negli autocomplete (carichi, scarichi, ricambi manutenzione) cercando un codice qualsiasi compaiono il prodotto radice e tutti i suoi alias (marcati `↳`, selezionabili — hanno giacenza propria).
- `SearchableSelect`: rendering limitato a 400 voci con hint "digita per restringere" (lista prodotti ~7.200 voci).
- Fix overlay "Salva prima la manutenzione" che restava visibile (CSS `display:flex` vs attributo `hidden`).

### 0.8.7 — 2026-10-05
- **Riga km nel tab Informazioni**: Data — Ultimi km registrati (readonly, da `fb_vehicle.current_km`) — Km attuali — Differenza (readonly, live: verde se positiva, rossa se negativa + avviso e conferma al salvataggio); i km salvati aggiornano `current_km` come prima.
- **Manodopera per ricambio**: nuova colonna `fb_maintenance_task.id_maintenance_detail`; nella riga di inserimento ricambi campi Tot ore / Costo €/h / Manodopera (readonly = ore × costo); il task generale (`id_maintenance_detail=0`) conserva solo la descrizione; eliminando un ricambio si elimina anche la sua manodopera.
- **Box "Totale ore di lavorazione"** nel tab Informazioni (ore + manodopera totale di tutti i task, live ad ogni aggiunta/rimozione); rimosse le caselle Ore/Manodopera/Prezzo orario dal tab; dialog Visualizza e PDF con totale manodopera.

### 0.8.6 — 2026-10-05
- **Manutenzione → Ricambi allineato ai movimenti Carico/Scarico**: stesso dialog `fb-dialog-medium`, `SearchableSelect` articolo (cerca per codice, alias, nome), autofill IVA prodotto con fallback sull'ultima aliquota, focus automatico su Q.tà, stessa griglia `fb-detail-add`.
- **Scarico/ricarico magazzino**: aggiungere un ricambio chiama `unloadStock` (−giacenza), eliminarlo `loadStock` (rientro) — righe legacy escluse; sync `tax_rate` prodotto anche da manutenzione.
- **Valori sempre positivi**: quantità, importi e KPI ricambi mostrati in valore assoluto (le righe legacy importate con segno negativo non appaiono più come −12,33 € / −1 pz).

### 0.8.5 — 2026-10-05
- **`FbStockModel`: API semantica dei movimenti** — `moveStock(id_product, 'in'|'out', qty)`, `loadStock`, `unloadStock`, `setStock` (imposta la giacenza senza toccare i contatori); rimosso `adjustStock`, tutti i call-site convertiti (carichi/scarichi e ricambi manutenzione, insert e storno).
- **Dialog movimenti**: larghezza `fb-dialog-medium`; selezione articolo → focus automatico su Q.tà; IVA precaricata dal prodotto con fallback sull'ultima aliquota usata nel documento; `SearchableSelect` con dropdown `position: fixed` (mai più tagliato dai dialog) e riposizionamento su scroll/resize.
- **Select-on-focus globale**: `core/select-on-focus.js` nel layout — al focus ogni campo di testo/numero seleziona tutto il contenuto, in tutta l'app e nei dialog dinamici.

### 0.8.4 — 2026-10-05
- **Movimenti carico/scarico**: dialog allargato (`fb-dialog-wide`, niente scroll orizzontale); select articolo sostituita da **SearchableSelect** — autocomplete con ricerca per codice, nome e codici alias; alla selezione l'aliquota IVA del prodotto viene precaricata nella riga.
- **Sync IVA prodotto**: se il prodotto non ha `tax_rate` impostata e nel movimento viene inserita, `addDetail` la riporta sull'anagrafica prodotto.
- `api/products/options` espone `tax_rate` e `alias_skus`; `SearchableSelect` esteso con `data-search`, `value` e `clear()`.

### 0.8.3 — 2026-10-04
- **Dashboard con dati reali** (`Admin\Dashboard`): rimossi tutti i mock — KPI da query su `fb_*` (automezzi attivi + n. con scadenze aperte; scadenze aperte con split scadute/entro 30gg; ricambi sottoscorta su soglie attive; litri erogati nel mese, `direction='out'`), sparkline su serie annuali reali.
- **Grafico Andamento → ultimi 12 anni**: serie per anno di litri carburante (`fb_refuelling`), manutenzioni e documenti — mostra la storia importata e l'attività corrente.
- **Lista scadenze**: occorrenze aperte reali ordinate per urgenza (imminenti → scadute recenti → chilometriche "a N km"), date scadute in rosso (`fb-expiration-expired`); **ciambella** = distribuzione reale per etichetta (top 4 + Altro) con totale aperte al centro; **sottoscorta** = 5 ricambi più critici da `fb_stock`/`fb_product` (guardia soglia=0).
- Nuova icona KPI "fuel" (pompa); i sottotitoli KPI mostrano dati reali invece del finto trend "% vs mese scorso".

### 0.8.2 — 2026-10-03
- **UI cross-pagina**: nuovo componente riusabile `components/ViewGrid.js` (`viewItem`/`viewGrid`) — scheda informativa a mini-card con icona e tinta dedotte automaticamente dall'etichetta (date, veicoli, partner, km, importi, contatti); migrato su rifornimenti, punti rifornimento, fornitori, documenti, fatture, manutenzioni e calendario.
- **Fix**: `SearchableSelect`/`table-filter-select` — le option con `value=""` ("— Seleziona —") diventavano il valore dell'input filtro; crash di `bootstrap-table-filter-control` sulle colonne con `filterControl` e `searchable: false`; cache-buster `?v=N` sugli script di pagina (il browser serviva versioni stale).

### 0.8.1 — 2026-10-03
- **Prodotti**: toolbar KPI tramite nuovo componente `components/KpiGrid.js` su `GET api/products/summary` — card con `sub`, `button` e layout `split` a due valori (card Avvisi: "Prodotti con avvisi attivati" | "Prodotti sottoscorta" + bottone "VEDI SOTTOSCORTA").
- **Dialog sottoscorta**: elenco dei soli prodotti con giacenza ≤ soglia (checkbox, SKU+alias, nome, prezzo, um, giacenza, avviso, bottone giacenza) e stampa PDF a blocchi da 200 righe (`POST admin/products/print-alerts`, `print/product_alerts.twig`); stampa le righe selezionate, oppure tutte se nessuna è selezionata.
- **Detail view alias**: "+" sulle righe dei prodotti radice con alias (`detailFilter` di Bootstrap Table) → sotto-tabella replica delle colonne principali (nascoste Alias/Nome/Categoria) con azioni operative; icone `+`/`−` in puro CSS (il font bootstrap-icons non è caricato).
- **Switch vista esclusivi** "Solo originali" / "Solo alias" sotto la toolbar (filtro client-side sulla copia persistente `allRows`).
- **UI tabella**: toggle attivo `fb-toggle` verde/rosso cliccabile (`api/toggle` con `fb_product` in whitelist), button-group azioni a icone, dialog anteprima a card.
- **Form prodotto a tab**: "Info prodotto" / "Codici alias" (visibile solo su prodotto radice in modifica), switch Attivo reale, dialog compatto senza scrollbar.

### 0.8.0 — 2026-10-03
- **Fusione Prodotti/Giacenze**: `FbProductModel::listAll()` con `LEFT JOIN fb_stock` → `id_stock`, `unit`/`unit_label`, `quantity`, `notification_limit`, `stock_note`, `low_stock` su ogni prodotto (una query, niente N+1).
- **Nuove colonne prodotti**: um, Giacenza (rossa in grassetto sotto soglia), Avvisi (badge "Sotto X"); righe sotto soglia evidenziate con `fb-stock-low`.
- **Bottone Giacenza per riga**: dialog (quantità, um, soglia avviso, nota) con upsert `POST api/stocks/product/{id}` — crea la riga `fb_stock` se assente registrando la quantità iniziale in `inputs`. La pagina `/admin/stocks` resta attiva.

### 0.7.5 — 2026-10-03
- **Menu dinamico**: tabella `fb_menu` e tab "Menu" in Impostazioni con editor ad albero della sidebar (voci, icone, colori, rotte); flag `menu_custom` in `fb_configuration` per attivare/disattivare il menu personalizzato (altrimenti replica del default); API `api/settings/menu`, `menu/save`, `menu/custom`.

### 0.7.4 — 2026-10-02
- **Statistiche rifornimenti**: nuova pagina `/admin/refuelling-stats` con grafici (litri, costi, consumo km/l, litri per scarico) alimentati da `GET api/refuelling/stats` con filtri data; nuovi endpoint `api/refuelling/last-km` e `vehicle-fuel`.
- **Tipi documento**: tabella `fb_type_document` (id, name, description; seed 0–3) con CRUD nella tab "Tipi documento" di Impostazioni; colonna Tipo (select filter) in Carichi/Scarichi, campo tipo nel form documento e nella stampa PDF.
- **Carburante**: `fb_supplier.fuel` (fornitore di carburante) e totali `total_load`/`total_unload`/`amount` su `fb_fuel_type`.

### 0.7.3 — 2026-10-02
- **Scarico rifornimenti**: colonne km precedenti/attuali/differenza/km-litro via `FbRefuellingModel::decorateConsumption()` — metodo del pieno (`km_diff / litri rifornimento precedente` dello stesso veicolo, mappa costruita in una query dedicata); `km_error` → riga rossa `fb-row-error` su dati non congrui; casella "Differenza" readonly colorata verde/gialla/rossa e blocco salvataggio su km invalidi.
- **Allineamento km**: `POST api/refuelling/align-km` a blocchi con dialog di progresso — riempie `km_since_last_refuel` ordinando per `refuel_time`+`id`; logica `alignKmChain()` riusata anche dall'import legacy (post-ops: direzione `in` per righe con fornitore + allineamento km).

### 0.7.2 — 2026-10-02
- **Rifornimenti split per direzione**: pagina Scarico (`/admin/refuelling`, `direction='out'`) e pagina Carico (`/admin/refuelling-load`, `direction='in'`) con form dedicati — carico senza veicolo/km (forzati `NULL`), scarico senza fornitore; colonne condizionali per pagina.
- **Componente `RefuellingKpis.js`**: toolbar 5 card (Carico, Scarico, Giacenza, Ultimo carico, Ultimo scarico) su `GET api/refuelling/summary`, condivisa dalle due pagine.

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

Academic Free License version 3.0 — vedi `LICENSE`.

Copyright since 2026 Massimiliano Palermo.
