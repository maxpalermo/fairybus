# Fairy Bus — Gestionale autorimessa pullman

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20a%20Coffee-sostieni%20il%20progetto-ffdd00?logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/maxpalermo)

Web application modulare per la gestione di un'autorimessa/autofficina di pullman.

> **Download**: [Fairy Bus v1.0.0 — installer offline](https://github.com/maxpalermo/fairybus/releases/latest) (zip self-contained con immagini Docker, `install.sh` guidato e DB factory).

- **Framework**: CodeIgniter 4.7.4
- **Container Docker**: `fairybus-mysql` (app), `fairybus-mysql-db` (MariaDB 10.11), `fairybus-mysql-pma` (phpMyAdmin)
- **Database**: MariaDB 10.11 containerizzato nello stesso compose, accessibile tramite rete `caddy-net`
- **Prefisso tabelle**: `fb_` fa parte del nome delle tabelle nuove (es. `fb_customer`), dichiarato nei model e nelle migration — **non** è il `DBPrefix` di CodeIgniter: `database.default.DBPrefix` deve restare vuoto in `.env`, altrimenti le query cercherebbero `fb_fb_*`. Le tabelle legacy senza prefisso (`business_partner`, `trade`…) sono accessibili via `LegacyDatabase::withoutPrefix()`.
- **Templating**: Twig 3
- **Stile UI**: design system ispirato a shadcn

---

## Requisiti

- Docker + Docker Compose
- Rete Docker esterna `caddy-net` già esistente (`docker network create caddy-net`)
- (Opzionale) Caddy come reverse proxy per `https://fairybus-mysql.localhost`

---

## Primo avvio

```bash
# 1. Entra nella cartella del progetto
cd fairybus/mysql

# 2. Crea la rete Docker se non esiste
docker network create caddy-net 2>/dev/null || true

# 3. Configura le credenziali del database usate da docker-compose
cp .env.example .env   # poi modifica MYSQL_ROOT_PASSWORD e DB_PASSWORD

# 4. Avvia i container (app + db + phpMyAdmin)
docker compose up -d --build

# 5. Entra nel container per gestire dipendenze e comandi CI4
docker compose exec -w /var/www/html fairybus-mysql bash

# 6. All'interno del container, installa le dipendenze
composer install

# 7. Copia e personalizza l'ambiente applicativo
cp env .env

# 8. Esegui le migrazioni (quando il DB è disponibile)
php spark migrate --all
```

---

## Accesso all'app

Se usi Caddy, aggiungi questo snippet al tuo `Caddyfile`:

```caddy
fairybus-mysql.localhost {
    reverse_proxy fairybus-mysql:80 {
        header_up Host {host}
        header_up X-Real-IP {remote}
        header_up X-Forwarded-For {remote}
        header_up X-Forwarded-Proto {scheme}
        header_up X-Forwarded-Host {host}
    }
}
```

Poi apri: `https://fairybus-mysql.localhost/admin/dashboard`

phpMyAdmin è raggiungibile su `https://fairybus-mysql.localhost/pma/` (login `root` + `MYSQL_ROOT_PASSWORD` da `.env`).

In alternativa, per test interno dentro il container:

```bash
docker compose exec fairybus-mysql curl -s http://localhost/admin/dashboard
```

---

## Struttura del progetto

- `bin/` — script di utilità: `flush-cache-twig.sh`, `make-patch.sh` (patch zip / immagine dist), `set-ci4-permissions.sh`, `set-env-ci4.sh`, `cat-slow.sh`/`read-slow.sh` (slow query log), `fb-vm-forward.sh`, entrypoint dei container
- `conf/` — configurazioni montate nei container: `000-default.conf` (vhost), `apache-cache.conf`, `pma-proxy.conf` (proxy `/pma/`), `my.cnf`, `php.ini`, `php.ini.dist`
- `sql/` — script SQL di manutenzione/normalizzazione
- `docs/` — documentazione operativa
- `docker-compose.yml`, `Dockerfile`, `Dockerfile.dist` — stack dev e immagine di distribuzione (`Dockerfile.dist.dockerignore` filtra il context)
- `.env` / `.env.example` — credenziali DB per compose (`.env` non versionato)
- `www/` — applicazione CodeIgniter 4
- `www/app/Modules/FairyBus/` — modulo principale (controller, modelli, viste, migrazioni)
- `www/app/Libraries/Twig.php` — wrapper Twig per CI4
- `www/public/assets/fairy-bus/` — asset CSS/JS/immagini e librerie locali
- `dist/` — pacchetto installer offline (compose, `install.sh`, `update.sh`, DB factory `db-init/01-fairybus.sql`); gli artefatti `*.tar.gz`/`*.zip` non sono versionati
- `site/` — landing page del pacchetto di installazione
- `db_data/` — dati runtime MariaDB (non versionato)

---

## Autenticazione

Dopo aver eseguito le migrazioni, accedi con:

- **Email:** `admin@fairybus.local`
- **Password:** `admin`

Al primo accesso sarai obbligato a cambiare la password.

---

## Changelog

### 0.9.30 — 2026-11-12

- **Colonna Scheda nelle stampe dei riepiloghi** — la stampa PDF del singolo documento di riepilogo (`print/document.twig`, bottone "Stampa PDF" del dialog vista) mostra ora come prima colonna **Scheda** con "Scheda {id} del {data}" per le righe copiate dalle schede manutenzione, `—` per le righe manuali, come già nel dialog. Stessa colonna, condizionata per documento (`id_customer`), nel report dettagliato PDF (`documents_details.twig`) e nella variante HTML (`documents_details_html.twig`): nei report di scarico ogni riepilogo riporta la scheda d'origine, i carichi restano invariati. `FbDocumentDetailModel::listByDocuments` ora fa join `fb_maintenance` ed espone `maintenance_date` anche per le stampe massive.
- **Paginazione riepiloghi** — la tabella di `/admin/unloads` propone tagli pagina `[10, 20, 50, 100, 200, 500, 1000]` (niente più il 5 del default globale); `smartDisplay` resta attivo, quindi i tagli oltre il numero di righe attuale non vengono mostrati finché non servono; i carichi restano invariati.
- **Manutenzione: "Associa a fattura" → "Associa a Riepilogo"** — il bottone catena sulle schede libere (senza fattura e senza documento) apre ora un picker dei **documenti di riepilogo della stessa targa** (`api/documents?direction=out` filtrato su `id_vehicle`), con testata scheda in view-grid (Scheda/Data/Targa) e ricerca per numero, cliente o data. Alla selezione il nuovo endpoint `POST api/maintenance/{id}/link-document` flagga `fb_maintenance.id_document` e copia ricambi+manodopera nelle righe del riepilogo (quantità positive, nessun movimento magazzino; il riepilogo senza targa eredita quella della scheda; rifiutati riepiloghi di altro automezzo, schede già riepilogate o fatturate). La copia righe è ora condivisa in `FbDocumentDetailModel::importMaintenanceLines`, usata anche da `syncMaintenances`.
- **Elenco schede nel wizard riepilogo** — le voci del tab Manutenzioni mostrano "Scheda {id} del {data}" (prima solo la data).
- **Tab "Movimenti per Scheda" + Movimenti aggregato** — il wizard riepilogo ha un quarto tab con il dettaglio riga per riga raggruppato per scheda (colonne Scheda | Q.tà | Articolo | Prezzo | Sconto | Importo | I.v.a. | Totale, cella scheda a rowspan "Scheda {id} del {data}"); il tab Movimenti non mostra più il badge scheda ma un **riepilogo globale** che somma le righe con stesso codice e prezzo (chiave aggregazione: codice+articolo+prezzo+sconto+IVA, così la manodopera resta distinta per descrizione). Stessa vista anche in modifica: Movimenti mostra le righe salvate aggregate, "Movimenti per Scheda" le raggruppa per scheda d'origine (le righe senza scheda finiscono in "Righe manuali"). Il gate del wizard tratta il nuovo tab come Movimenti (serve almeno una scheda selezionata).
- **Titolo form riepilogo** — in modifica il dialog è ora "Riepilogo {numero} del {data} Targa {targa}" (la targa è omessa se assente).
- **KPI anche su "Movimenti per Scheda"** — il tab ha la stessa griglia di card (Prezzo totale, Totale colli, Numero movimenti) più una quarta card **Schede** (= schede selezionate/collegate al riepilogo). Le schede senza righe compaiono comunque in tabella con la dicitura "Nessun movimento".
- **Vista riepilogo: schede senza movimenti** — nel dialog "Documento di riepilogo" (da Manutenzione e da Scarichi) nuovo switch "Schede senza movimenti" (off di default): se attivo, la tabella elenca anche le schede collegate senza righe (dicitura "Nessun movimento") e il PDF le include (`?empty=1` su `admin/documents/{id}/print`). Aggiunti i contatori informativi "Schede riepilogate" e "Schede senza movimenti".
- **Schede senza movimenti evidenziate** — nella tabella Manutenzione le schede senza ricambi né manodopera (`parts_count`+`tasks_count` = 0, ora esposti da `listAll`) hanno lo sfondo riga arancione tenue (`fb-row-empty`).
- **Pulizia tabelle non usate** — Impostazioni → Backup: nuova card "Pulizia database" con bottone "Pulisci tabelle non usate": elenca le tabelle non `fb_*` (esclusa `migrations`, solo BASE TABLE — `GET api/settings/backups/unused-tables`), chiede conferma con anteprima nomi e le elimina una alla volta (`POST api/settings/backups/drop-table`) con dialog di avanzamento; il nome è sempre rivalidato lato server contro la whitelist ricalcolata, `FOREIGN_KEY_CHECKS` disabilitati durante il drop.
- **Carica backup: qualsiasi dump SQL** — rimosso il vincolo "dump di questa applicazione" (`assertDumpTables` eliminato): l'upload accetta file `.sql` e archivi `.zip` (usa `backup.sql` se presente, altrimenti la prima entry `*.sql`) e normalizza il contenuto in `fb-backup-*.zip`/`backup.sql`, così storico, download e restore restano uniformi; respinti solo i file senza contenuto SQL riconoscibile.
- **Ripristino con avanzamento** — il restore è ora a blocchi (`offset`/`limit` su `POST api/settings/backups/{name}/restore`, statement splittati in `DbBackup::loadStatements`): dialog non chiudibile con barra progressiva e contatore "N/M statement eseguiti", esito finale con bottone "Ricarica pagina" oppure errore con posizione dello statement fallito.
- **Propagazione scheda → riepilogo** — modificare una scheda collegata riallinea automaticamente le righe del riepilogo: `addDetail`/`deleteDetail`/`update` chiamano `resyncMaintenanceLines` (cancella e ricopia le righe `id_maintenance` della scheda), `delete` della scheda rimuove le righe copiate. Le risposte riportano `synced`/`doc_rows_removed` con toast "Riepilogo aggiornato: N righe riallineate".
- **Tabella riepiloghi: nuove colonne** — `/admin/unloads` ora mostra `check|ID|Numero|Data|Targa|Cliente|Schede|Totale importo|Totale importo (IVA inclusa)|Azioni`; `FbDocumentModel::listAll` espone `mnt_count`, `total_amount` e `total_amount_vat` via subquery (verificato: Doc 1 → 2.357,47 € come la stampa). La tabella carichi (`direction=in`) resta invariata. Le tre colonne numeriche hanno filtro input con i pattern `fbFilterSearch` (`>1000`, `=12`, `<->100,500`), così tutte le intestazioni restano allineate. `fmtMoney` formatta ora `1.234,56 EUR` (separatore migliaia, max 2 decimali, "EUR" al posto di "€").
- **Formattatore valuta unico** — tutti gli importi UI e stampe usano lo stesso formato `1 234,56 EUR` (separatore migliaia = spazio, virgola decimale, 2 decimali): nuovo `core/format.js` (`fmtMoney`) importato da documents/maintenance/invoices/vehicle_detail/refuelling/refuelling-stats/products/stocks/PurchasePicker (eliminate le 6 copie locali e le formattazioni inline); nuovo filtro Twig `|eur` registrato in `App\Libraries\Twig` applicato a tutti i campi currency di stampe/report (109 occorrenze; qty/ore/km/IVA/sconto invariati); variante a 4 decimali `fmtMoney4`/`|eur4` per prezzi unitari di precisione (prezzo al litro carburante, prezzi d'acquisto in giacenze); intestazioni `€/h`→`EUR/h`, `(€)`→`(EUR)`, placeholder `0,00 €`→`0,00 EUR`.
- **KPI di pagina su `/admin/unloads`** — 4 card colorate con icone (Totale righe, Totale schede riepilogate, Totale senza IVA, Totale con IVA) calcolate sulle righe attualmente filtrate in tabella: ricalcolo automatico su `post-body` a ogni caricamento, filtro, ordinamento o cambio pagina.
- **Wizard ricerca guidata nei filtri colonna** — icona "sliders" in ogni campo filtro input: popover con select operatore (contiene/inizia/finisce/uguale/maggiore/minore/diverso/compreso tra) + valore/i con tipo rilevato dai dati della colonna (`type=date` con calendario per le date, `number` per i numeri, `text` altrimenti); anteprima live del codice generato; "Applica" scrive la sintassi nel campo e lancia la ricerca, "Pulisci" svuota il filtro; riaprendo il wizard il codice esistente viene riletto in operatore+valori. Globale su tutte le tabelle (auto-iniettato come la legenda), pipeline di filtro e API invariate.

### 0.9.29 — 2026-11-11

- **Manutenzione terzi: toolbar selezione e filtro periodo** — nella scheda Manutenzioni del riepilogo (nuovo e modifica) nuova toolbar con bottoni **Tutti** (spunta le schede visibili), **Nessuno** (azzera tutto), filtro **Periodo da/a** sulle date delle schede e contatore "N selezionate". La selezione è ora un `Set` persistente (`form._mntChecked`): il filtro ri-renderizza la lista senza perdere le spunte, e riepilogo/salvataggio leggono il Set — una scheda nascosta dal filtro resta selezionata.
- **SUCCESSIVO anche in modifica** — aprendo un riepilogo esistente, il bottone compare sulla scheda Manutenzioni e ricostruisce l'anteprima Movimenti dalla selezione corrente prima di SALVA.
- **Targa nel documento di riepilogo** — `FbDocumentModel::findById`/`listAll` fanno join `fb_vehicle` → `vehicle_plate`: dialog vista con testata Cliente, Targa, Numero, Data, Tipo; colonna **Targa** con filtro select (tutte le targhe, dismessi inclusi) nella tabella Manutenzione terzi; riga **Targa** sotto Destinatario nella stampa PDF.
- **Colonna Scheda nei movimenti** — `listByDocument` espone `maintenance_date` (join `fb_maintenance`): nei dialog vista il primo campo riga è "Scheda {id} del {data}" per le righe copiate, `—` per quelle manuali.
- **Targa in tutte le stampe dei riepiloghi** — la voce Fattura (inutile sui documenti cliente) è sostituita da **Targa** nella stampa lista (`documents.twig`), nel report dettagliato PDF (`documents_details.twig`) e nella variante HTML auto-stampante (`documents_details_html.twig`); i carichi continuano a mostrare la fattura.

### 0.9.28 — 2026-11-11

- **Manutenzione terzi — wizard guidato** (ex pagina Scarichi, `/admin/unloads`): scheda "Riepilogo Manutenzione terzi" con tab ordinati Informazioni → Manutenzioni → Movimenti. Box evidenziato Cliente + Automezzo obbligatori in cima a Informazioni; numero documento da progressivo di sistema (`fb_configuration.unload_doc_counter`, avanzato solo dopo insert riuscito, endpoint `GET api/documents/next-number`) e data odierna precompilati; bottone **SUCCESSIVO** tra i tab con blocco navigazione diretta e **SALVA** disabilitato finché manca cliente/targa o almeno una scheda. Tab Manutenzioni: elenco delle sole schede del veicolo non ancora registrate (`id_document IS NULL`, non fatturate). Tab Movimenti: riepilogo read-only delle righe copiate (ricambi + manodopera valorizzata) — nessun inserimento manuale, nessun movimento di magazzino. Al salvataggio `fb_maintenance.id_document` viene flaggato e le schede escono dall'elenco disponibili (`syncMaintenances` in `api/documents/{id}/maintenances`).
- **Riepilogo = documento fiscale**: quantità e valori sempre positivi (`abs()` in copia e `|abs` in stampa); rimossi bottone "associa a fattura", colonna Fattura e filtro "Senza fattura" dalla pagina; dialog vista e stampa intitolati "Documento di riepilogo" con Destinatario, poi Numero e Data, senza voce fattura.
- **Manodopera a costo zero filtrata**: i task di manutenzione senza ore, tariffa €/h o importo (12.000+ righe "GENERAL" di rito) non generano più righe "Manodopera" a 0,00 € né nell'anteprima né nelle righe copiate; `tasks_count` dell'elenco schede allineato allo stesso filtro.
- **Manutenzione: colonna Scheda con link riepilogo** — nuova colonna dopo il checkbox: codice scheda + stato ("Riepilogo N" link blu / "non riepilogata"); il click apre un `<dialog>` stilizzato con testata documento (cliente, numero, data), tabella movimenti completa con totale e bottone **Stampa PDF**. `listAll` espone `doc_number`/`doc_date` via join `fb_document`.
- **Veicoli: fix salvataggio caratteristiche** — `features` era postata come stringa JSON (`{"4":"2"}`) e PHP la scartava (`is_array` fallito); ora i campi `features[N]` viaggiano flat e `setForVehicle` riceve l'array.
- **Veicoli: fix upload immagini/documenti** — causa del 500: i modelli `FbVehicleImageModel`/`FbVehicleDocumentModel` (e in latenza `FbUserRoleModel`/`FbUserPermissionModel`) avevano `useTimestamps=true` su tabelle con solo `created_at` → insert su `updated_at` inesistente. Fix: `$updatedField = ''`. In più, dopo upload/delete la lista si aggiorna in-place (`refreshVehicleExtras`) invece di riaprire un secondo dialog sopra il primo.
- **Immagini veicoli: layout e lightbox** — nel dialog modifica la griglia anteprime (più grande, in alto) e la zona upload compatta in basso; click sulla miniatura → lightbox centrato (`<dialog>` + backdrop scuro) sia nel form sia nella pagina dettaglio veicolo.
- **Sottoscorta prodotti: prezzo e fornitore** — il dialog sottoscorta mostra l'ultimo prezzo d'acquisto in EUR con fornitore su seconda riga; la cella prezzo apre il documento di carico sorgente **in nuova tab** (`target="_blank"`) con il campo fornitore compilato (fix race condition: il deep-link `?open=` ora attende `loadOptions()`/`loadDocTypes()` prima di aprire la scheda).
- **Progetto su GitHub** (`maxpalermo/fairybus`, pubblico): riorganizzazione `bin/` (script), `conf/` (apache/php/mysql), `sql/`, `docs/`; riferimenti aggiornati in compose e Dockerfile/Dockerfile.dist; credenziali compose esternalizzate in `.env` (`.env.example` versionato); `.gitignore` completo (`db_data/`, `www/vendor/`, `www/.env`, `public/uploads`, `writable` runtime, backup zip, artefatti `dist`/`site`).

### 0.9.27 — 2026-10-14

- **Manutenzione: dialog prezzi d'acquisto con lotti residui** — il picker lente ricambi ("Acquisti del prodotto") mostra ora in cima la sezione **"In giacenza per prezzo d'acquisto"**: una riga per lotto residuo (prezzo, residuo, fornitore/data/documento dell'ultimo carico a quel prezzo, sconto, IVA) — sono gli stessi lotti elencati dal picker articolo in modalità "per prezzo" (es. 88806: €120 residuo 4 + €87 residuo 1, entrambi OFFICINE 977). Sotto resta lo storico "Ultimo acquisto per fornitore". `api/products/{id}/purchases` restituisce anche `lots` (logica `priceLots` condivisa). Selezionando una riga il prezzo si compila e il **badge giacenza si aggiorna al residuo di quel prezzo** (prima restava quello del lotto scelto nel picker). Il badge è stato sostituito da una **barra info a tutta larghezza sotto la riga di inserimento**: mostra fornitore + documento del carico selezionato e la giacenza residua (blu; rossa se la q.tà supera il residuo). **Aggancio scarico al carico**: la riga invia `id_load` (lotto residuo scelto o carico storico con residuo) e `addDetail` lo salva su `fb_maintenance_detail`; se assente/invalido il server aggancia il carico FIFO più vecchio con residuo>0 allo stesso prezzo (`findFifoLoad`) — gli scarichi non restano più "non associati".
- **Riparazioni: colonna Documento con carico** — `linesForVehicle` espone ora `load_document_id`/`load_number`/`load_supplier` (join `id_load` → `fb_document_detail` → `fb_document`/`fb_supplier`): sotto il `#scheda` manutenzione la cella mostra numero carico (indigo, grassetto) e fornitore (grigio). Il click apre **nella stessa pagina** un dialog "Carico n. X" con testata (numero, data, fornitore, tipo, fattura, note — da `api/documents/{id}`) e tabella movimenti (codice, articolo, q.tà, prezzo, sconto, IVA, totale riga + totale documento), più il bottone "Apri in Documenti"; Ctrl/middle-click continua ad aprire la pagina documenti in nuova tab. Larghezze colonne: Data e Q.tà compresse, Documento allargata; colonna Totale enfatizzata (bold, verde).
- **Dettaglio veicolo: selezione righe riparazioni** — la tabella dello storico ha ora la colonna checkbox (`uniqueId: id_maintenance_detail`): il bottone Stampa report invia gli id selezionati (`ids` JSON in POST al PDF, CSV in GET al report HTML) e il server filtra `linesForVehicle` su quei dettagli; senza selezione si stampa tutto come prima. La soglia 250 righe per il passaggio al report HTML si applica al conteggio effettivo (selezionate o totali).
- **Prodotti: blocco codice duplicato** — nel form nuovo/modifica prodotto lo SKU viene verificato contro il catalogo (confronto case-insensitive, escluso il prodotto in modifica): uscendo dalla casella il campo diventa rosso se occupato; al salvataggio, se il codice è già assegnato, il submit è bloccato e si apre una **scheda a parte** con le info del prodotto esistente (SKU, nome, marca, categoria, prezzo vendita, giacenza, stato). Guardia identica lato server: `Api\Products::create`/`update`/`createAlias` rispondono 422 se lo SKU esiste su un'altra riga `fb_product` (SKU vuoto sempre ammesso). I duplicati già presenti nei dati legacy restano gestibili: in modifica il proprio codice non è considerato conflitto.

### 0.9.26 — 2026-10-14

- **Manutenzione: blocco scarico oltre giacenza** — nella scheda ricambi, selezionato l'articolo, sotto la casella Q.tà appare un badge con la giacenza disponibile (del lotto prezzo se attivo "Cerca per prezzo", altrimenti del prodotto); superandola la casella diventa rossa e l'alert blocca l'aggiunta; guardia identica lato server in `api/maintenance/{id}/details` (422 con disponibile/richiesto).
- **Fix filtro Stato veicoli** — la colonna Stato mancava di `searchFormatter: false`: il filtro select confrontava il valore (`active`/`retired`) con l'HTML del toggle e non trovava righe. Ora filtra sul valore raw, come le altre colonne select dell'app.
- **Stato veicolo: solo Attivo/Dismesso** — eliminato lo stato "In manutenzione" inesistente: `api/vehicles/definitions` e `setStatus` accettano solo `active`/`retired`; filtro select della pagina Veicoli e della nuova tab mostrano Attivo/Dismesso (+Tutti); la colonna Stato della tab Veicoli dello scadenzario usa lo stesso toggle check verde/times rosso della pagina Veicoli ed è cliccabile (dismetti/riattiva con conferma); il dettaglio veicolo mostra l'etichetta localizzata.
- **Scadenzario: nuova tab "Veicoli" con km editabili** — elenco veicoli (`api/vehicles`) con filtri colonna (Targa, Veicolo, Telaio, Stato a select); colonna **Km attuali** con casella di testo + tasto ✓ (o Invio): il nuovo valore non può essere inferiore al chilometraggio attuale (blocco client e server, `POST api/vehicles/{id}/km`); l'aggiornamento passa da `FbVehicleKmModel::register` (reason `Manual`) quindi resta traccia nel registro chilometrico e tocca solo `fb_vehicle.current_km`; i veicoli ritirati sono dimmed.

### 0.9.25 — 2026-10-10

- **Carburante: prezzo auto-compilato** — nel dialog scarico carburante il campo "Prezzo al litro" si riempie da solo con l'ultimo prezzo d'acquisto (carico `in` più recente per stazione+alimentazione, fallback sull'ultimo carico del tipo); `api/refuelling/available` restituisce anche `last_price`. Il valore auto-impostato si aggiorna al cambio stazione/alimentazione senza sovrascrivere un prezzo digitato a mano. Nuovo rifornimento: alimentazione preimpostata su **Diesel** e chiamata API (giacenza + prezzo) già all'apertura del dialog.
- **Scadenzario: tab Avvisi → tabella filtrabile** — la lista `fb-alerts-list` è sostituita da una Bootstrap Table con filtri colonna (Veicolo, Voce, Scadenza unificata data/km con ordinamento dedicato, **Mancanti** = giorni o km residui colorati, Stato a select, azioni riga modifica/nascondi/elimina); checkbox selezione → contatore e stampa PDF funzionano anche su questa tab; click riga apre il dialog occorrenza come prima.
- **Form veicolo restilizzato** — rimosse le tab Scadenze/Riparazioni dal dialog modifica (dati solo consultabili nell'anteprima); Informazioni a sezioni `.fb-form-section`, upload Immagini/Documenti con drop-zone `fb-upload-zone`, lista documenti a icone, header dialog con X di chiusura, switch caratteristiche migrati al pattern globale `.fb-switch` (rimosso il CSS legacy `fb-switch-label`/`fb-switch-slider` da `vehicles.css` che confliggeva col theme).
- **Dettaglio veicolo: restyle + toolbar Riparazioni** — header con bottoni icona (`fb-page-header`/`fb-page-actions`); nella tab Riparazioni nuova toolbar con chip totali (Riparazioni = righe/interventi dello storico, Pezzi di ricambio, Totale speso — calcolati sulle righe caricate) + bottone Cerca (`searchOnEnterKey`) + Stampa report.
- **Report storico riparazioni (PDF)** — `POST admin/vehicles/{id}/repairs/print` + `print/vehicle_repairs.twig` (A4 landscape): sezione per ogni manutenzione in ordine cronologico (righe ricambi/manodopera con totali parziali) e **pagina di riepilogo finale** con totali generali, tabella per manutenzione e aggregato ricambi per articolo. Rispetta lo switch "Forza download PDF". La query righe è ora `FbMaintenanceDetailModel::linesForVehicle` (condivisa con `api/maintenance/lines`). Se lo storico supera **250 righe** il frontend avvisa l'utente e apre `GET admin/vehicles/{id}/repairs/report`: lo stesso report in HTML auto-stampante (il browser impagina e salva in PDF, Dompdf non viene caricato). Rimosso `page-break-inside: avoid` che rallentava l'impaginazione su schede con molte righe.

### 0.9.24 — 2026-10-10

- **Anagrafiche allineate allo stile delle nuove pagine** — Clienti, Fornitori, Categorie, Marche, Caratteristiche e Locations adottano la toolbar delle pagine recenti (contatore selezione + bottoni icona: Cerca lente info, Stampa stampante warning, Nuovo plus success) e la ricerca filtri solo su Invio/`searchOnEnterKey` con il bottone "Cerca" (`triggerColumnSearch` e `fbBindSearchHints` spostati in `filter-patterns.js` condiviso).
- **Clienti: CRUD completo** — la pagina era sola lettura: ora nuovo/modifica/elimina (bloccata se il cliente ha documenti), anteprima a card, toggle Attivo in colonna e salvataggio indirizzo su `fb_address` (endpoint `POST api/customers`, `/update`, `/delete` — specchio dei fornitori).
- **Caratteristiche: CRUD + stampa** — form nuova/modifica (nome tecnico `alpha_dash`, etichetta, tipo switch/select/value), elimina bloccata se assegnata a veicoli (`fb_vehicle_feature`), `admin/features/print` + `print/features.twig`.
- **Locations: gestione e stampe** — toggle Attiva in colonna su nazioni e province (whitelist `api/toggle-field` estesa a `fb_customer`/`fb_category`/`fb_country`/`fb_state`); stampa della scheda attiva via `admin/locations/print` + `print/locations.twig` (3 layout: nazioni/province/città); toolbar contestuale: contatore e Cerca/Stampa seguono la tab attiva; ricerca città su Invio.
- **Categorie/Marche**: azioni riga a bottoni icona (matita/cestino); la colonna Attiva delle categorie è ora un toggle diretto (sostituisce il pulsante Attiva/Disattiva).
- **Form anagrafiche riorganizzati** — sezioni `.fb-form-section` (Anagrafica/Contatti/Indirizzo/Stato) in clienti e fornitori; fix switch Attivo/Carburante che erano rotti (il markup legacy `fb-switch-label` era definito solo in alcuni CSS pagina): migrati al pattern globale `.fb-switch` con varianti `fb-switch-success`/`fb-switch-danger`/`fb-switch-info` e `fb-form-grid-3/4` ora in `theme.css`.

### 0.9.23 — 2026-10-10

- **Riallineo: movimenti cliccabili** — nella vista dettaglio del carico (scarichi agganciati) e nell'anteprima "Movimenti già associati"/"selezionati" il numero movimento è ora un link che apre la scheda manutenzione/documento esatta (`?open=<id>` in nuova scheda), evitando di aprire manualmente una scheda diversa della stessa targa.

### 0.9.22 — 2026-10-10

- **Giacenza per prezzo: voci duplicate risolte** — la giacenza non coperta dai movimenti si sommava come nuovo lotto all'ultimo prezzo anche se quel prezzo esisteva già (es. 84+12 a 9,99 → ora 96 in voce unica). `priceLots` usa `$add()` anche per il lotto scoperto; vale per la scheda giacenze e il picker `?lots=1`.
- **Dialog riallineo**: pannelli compattati davvero (fix selettori `fb-table`), residuo carico come pill blu/rossa (`fb-res-badge`), griglia 52/48.

### 0.9.21 — 2026-10-10

- **Documenti: esito salvataggio prezzo sempre visibile** — alert info dopo ogni salvataggio prezzo riga: "N scarichi allineati" (cascata su `id_load`), "riga sganciata → anomala" (scarico con `id_load` rimosso, flag `detached` in risposta) o "nessuno scarico associato".

### 0.9.20 — 2026-10-10

- **Link scarico→carico (`id_load`)**: associazione persistente invece della FIFO-per-prezzo ricalcolata a lettura. Nuove colonne `id_load` su `fb_maintenance_detail` e `fb_document_detail` (migration `AddLoadLinkToMovements`, + `ALTER` produzione). "Allinea quantità" ha un terzo step **Aggancio scarichi** (`api/stocks/align` step `links`, chunk da 100): per ogni carico in ordine cronologico riempie il residuo con gli scarichi non agganciati dello **stesso prezzo** — prima quelli da data ≥ data carico, poi i precedenti; mai oltre il residuo. Eseguito su dev: 20.260 scarichi agganciati, ~6.700 anomali, 0 residui negativi.
- **Riallineo su link**: `lots-detail` calcola residui esatti per carico dagli `id_load` (non più FIFO presunta); `realign` accetta `load_id` (prezzo derivato dal carico) e scrive `id_load` (sgancio = NULL + prezzo 0). Detail view/anteprima mostrano gli scarichi agganciati a quel carico.
- **Cascata prezzi**: modificando il prezzo di una riga di **carico** gli scarichi agganciati prendono il nuovo prezzo (`POST api/documents/details/{id}/price` → `cascaded`); modificando il prezzo di una riga di **scarico** questa si sgancia (`id_load` NULL, diventa anomala); eliminando una riga di carico i suoi scarichi tornano anomali.

### 0.9.19 — 2026-10-10

- **Documenti: prezzo riga editabile** — nella tab Movimenti della scheda documento la cella Prezzo è un input-group (casella + tasto ✓ verde): salva via `POST api/documents/details/{id}/price`, ricalcola importo/totali/KPI. Su riga copiata conto terzi allinea anche `fb_maintenance_detail` sorgente; su carichi aggiorna `wholesale_price` dell'articolo (ultimo prezzo d'acquisto al netto dello sconto). Invio nella casella = salva.

### 0.9.18 — 2026-10-09

- **Giacenze: ricerca su comando** — i filtri colonna si applicano solo con **Invio** o col nuovo bottone **Cerca** (icona lente info in alto a destra, `triggerColumnSearch`); niente più re-render a ogni battuta. Opzione `searchOnEnterKey` su tabella giacenze, tabella movimenti in detail view e tabelle del dialog riallineo; `searchTimeOut` locale 300 ms per risposta immediata su Invio/Cerca.

### 0.9.17 — 2026-10-09

- **Filtri tabelle più reattivi**: debounce della ricerca per colonna portato da 500 a **800 ms** (`$.fn.bootstrapTable.defaults.searchTimeOut` impostato globalmente in `filter-patterns.js`). La scansione `fbFilterSearch` su tutte le celle non riparte più a ogni battuta mentre si digita; ridotte anche le chiamate sull'unica tabella `sidePagination: "server"` (Locations).

### 0.9.16 — 2026-10-09

- **Convenzione segno movimenti**: unificata — carichi salvati positivi, scarichi negativi nel DB; l'utente vede sempre `ABS(quantity)`. In scrittura: `Maintenance::addDetail` salva `-abs()`, `Documents::addDetail` applica il segno per direzione (`stockDirection`), le righe copiate conto terzi vengono registrate negative.
- **Giacenze: "Allinea quantità"** — nuovo bottone verde (sliders) nella toolbar della pagina Giacenze: apre un dialog modale con avanzamento **a blocchi**. Step 1: normalizza i segni (`fb_maintenance_detail` → `-ABS`, righe documenti fornitore → `+ABS`, righe documenti cliente con articolo → `-ABS`). Step 2: ricalcola `fb_stock` (quantity/inputs/outputs) dai movimenti, in chunk da 100 prodotti con progress a blocchi e contatore. Endpoint `POST api/stocks/align` (`step` = signs/count/stock).
- **Riallineo scarichi** (v2): dialog per-prodotto (bottone icona catena nella colonna Azioni della riga — niente più selezione articolo). Tabella **Carichi** con radio, filtri su tutte le colonne e detail view con gli scarichi stesso-prezzo (checkbox + "Sgancia selezionati" → prezzo 0.00 → tornano anomali); tabella **Scarichi anomali** con checkbox, badge conteggio+q.tà selezionate, **quantità editabile inline** (input + OK → `POST api/products/{id}/out-qty` con storno stock e sync copie conto terzi) ed **elimina movimento** (icona cestino → `POST .../out-delete`, rimuove anche task manodopera e copie, ripristina giacenza per righe non legacy). Anteprima → Conferma associazione invariati.

### 0.9.15 — 2026-10-08

- **Riallineo scarichi ai carichi**: nuovo bottone "Riallinea scarichi ai carichi" nella toolbar della pagina Giacenze. Apre un dialog a due colonne: a destra l'elenco dei **carichi** del prodotto (documento, data, fornitore, quantità, prezzo, residuo FIFO), a sinistra due pannelli — **scarichi associati** (con il carico consumato) e **scarichi non associati** (prezzo mai caricato o residuo esaurito, marcati "anomalo"). Selezionando scarichi (checkbox) + un carico si simula il residuo live e "Conferma associazione" riscrive `price` sui movimenti (`GET api/products/{id}/lots-detail`, `POST api/products/{id}/realign`; le righe copiate nei documenti conto terzi vengono allineate allo stesso prezzo). Preselezione automatica dell'articolo se una sola riga è spuntata in tabella.
- **Fix doppio conteggio scarichi**: `stockOutMoves` e `movements` ora escludono le righe `fb_document_detail` con `id_maintenance` (copie conto terzi): lo scarico era già conteggiato via `fb_maintenance_detail` e produceva lotti negativi fantasma e movimenti duplicati.
- **Manutenzione: associa/dissocia fattura dalla riga**: la colonna Azioni della pagina Manutenzione mostra "Associa a fattura" (icona catena) sulle schede senza link — picker con ricerca sulle fatture cliente — e "Dissocia dalla fattura" (catena barrata) sulle associate; nessun bottone sulle schede legate a uno scarico conto terzi (`id_document`), gestite dal flusso documento. `linkInvoice`/`unlinkInvoice` ora mantengono in sync `fb_maintenance.id_invoice` e accettano solo fatture cliente.
- **Picker ricambi manutenzione**: gli alias con giacenza restano trovabili cercando codice/nome del prodotto radice anche quando la radice è a zero — la famiglia alias di `buildLotItems` si costruisce su `productsCache` completo invece di `lotsCache` (già filtrato ai lotti > 0).
- **Veicoli**: colonna **Descrizione** con filtro nella tabella (`v.description`).

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
