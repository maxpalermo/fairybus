# Fairy Bus — Installazione

Gestionale flotta bus: veicoli, magazzino, documenti, manutenzioni,
rifornimenti, fatture, scadenzario.

## Requisiti

- Server Linux con **Docker Engine** e plugin **Docker Compose v2**
- Una porta libera (default 8080)

## Installazione

```bash
unzip fairybus-install-1.0.1.zip
cd fairybus-install
./install.sh
```

L'installer chiede:

| Parametro | Default | Note |
|---|---|---|
| Porta HTTP | `8080` | la porta su cui l'app risponde |
| URL pubblico | `http://localhost:PORTA/` | deve essere l'URL che gli utenti digitano (con dominio o IP). Necessario per link corretti |
| Email admin | `admin@fairybus.local` | utenza di login |
| Password admin | generata | stampata a fine installazione |

Al termine: apri `URL/login` con le credenziali indicate.

## phpMyAdmin

Disponibile su `URL/pma/` (es. `http://localhost:8080/pma/`), anche
dalla voce di menu **PMA** visibile agli amministratori. Non è esposta
una porta dedicata: l'app fa da proxy interno. Credenziali: `root` /
`MYSQL_ROOT_PASSWORD` oppure l'utente applicativo `DB_USER`/`DB_PASSWORD`
(entrambi in `.env`).

## Piu' istanze sullo stesso server

Scompatta il pacchetto in una **seconda cartella**, esegui `./install.sh`
e al prompt "Suffisso istanza" indica ad es. `-demo`, scegliendo una
porta diversa. Risultato:

- container: `fairybus-app-demo`, `fairybus-db-demo`, `fairybus-pma-demo`
- volumi/rete separati: `fairybus-demo_db_data`, `fairybus-demo_app_writable`

Suffisso vuoto = installazione principale (`fairybus-app` ecc.).

## Dietro HTTPS / reverse proxy

L'app espone solo HTTP su `APP_PORT`. Per HTTPS metti davanti un
reverse proxy (Caddy, Nginx, NPM...) che termina TLS e fa proxy verso
`127.0.0.1:PORTA`. Se il proxy è in un container Docker, la sua subnet
rientra già in `app.proxyIPs` (reti private predefinite); altrimenti
aggiungi il CIDR della macchina proxy. Poi imposta in `app.env`:

```
app.baseURL = https://gestionale.miodominio.it/
```

e riavvia: `docker compose up -d`. Se cambi porta o URL aggiorna anche
`PMA_ABSOLUTE_URI` in `.env` (deve restare `<baseURL>pma/`).

## Parametri modificabili

- `.env` → porta, credenziali MariaDB, timezone, tag immagine app (`APP_IMAGE`)
- `app.env` → configurazione CodeIgniter (baseURL, DB, chiave cifratura)

Dopo ogni modifica: `docker compose up -d` per applicare.

## Aggiornamenti

Il codice dell'app vive in `./app-src` (cartella bind-mounted su
`/var/www/html`): gli aggiornamenti si applicano con `./update.sh`,
senza reinstallare. Dati (DB, upload) non vengono toccati.

**Patch leggera** — file `fairybus-patch-X.Y.Z.zip` con i soli file
modificati (path relativi alla radice del progetto):

```bash
./update.sh fairybus-patch-1.0.1.zip
```

**Nuova release** — file `fairybus-app-X.Y.Z.tar.gz` con l'immagine
completa (piu' pesante, ~200 MB):

```bash
./update.sh fairybus-app-1.0.1.tar.gz
```

In entrambi i casi lo script esegue le migrazioni DB (`spark migrate`),
pulisce la cache Twig e riavvia il container (necessario per opcache).
Eventuali modifiche manuali ai file in `app-src` vengono sovrascritte.

## Backup

```bash
docker compose exec db mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" fairybus_db > backup.sql
```

Dati persistenti nei volumi: `db_data` (database) e `app_writable`
(sessioni, upload, cache).

## Reinstallazione da zero

```bash
docker compose down -v   # ATTENZIONE: cancella database e upload
rm -f .env app.env
./install.sh
```

## Requisiti risorsa

~1.5 GB RAM liberi (MariaDB buffer pool 2G configurabile in `my.cnf`).

## Changelog

### 1.0.1 — 2026-10-30

**Interfaccia**
- Sidebar comprimibile a sole icone (stato persistente, flyout sottomenu, tooltip)
- Voce menu PMA aperta in nuova scheda
- Select dei form ricercabili (ricerca testuale nel dropdown) in tutte le schede
- Tutte le tabelle: selezione righe/pagina [5,10,20,50,100,200,500,1000], default 50
- Bottone lente "Cerca" nella toolbar di ogni pagina: i filtri colonna si applicano solo a comando (Invio o click), non più durante la digitazione
- Bottone "+" anche nelle toolbar dialog prodotto; icone colorate su Salva/Chiudi
- Refuelling: ordine campi Stazione · Alimentazione · Data
- Fix filtro select su colonne con badge HTML (Tipo/Periodicità/Stato veicolo)

**Sconti**
- Semantica uniforme: solo "Sconto", sempre positivo (`ABS`), mai maggiorazione — in UI, API, stampe e DB
- Stored procedure `fb_normalize_discounts()` (ABS su tutte le colonne discount) e `fb_fix_maintenance_discounts()` (allinea sconto manutenzione a quello del carico via id_load); installate nel DB factory
- Form manutenzione: sconto propagato dal carico in tutti i percorsi di selezione; barra info carico con sconto e prezzo finale

**Stampe**
- "Stampa dettagli" su Carichi e Manutenzioni: scheda completa per documento + riepilogo articoli aggregato + totale generale
- Nuovo "Report" (Carichi/Scarichi/Manutenzioni): sorgente righe selezionate oppure mese/anno, formato PDF oppure **HTML stampabile dal browser** (istantaneo anche con centinaia di documenti, niente timeout Dompdf)
- Eliminato l'N+1: dettagli/fatture/attività caricati in batch; `set_time_limit(0)` su tutte le stampe

**Prodotti / giacenze**
- Bottone "Allinea nomi alias" con batch + barra di avanzamento
- Vista "Solo non associati" e tab "Non associati" nel dialog prodotto per collegare alias esistenti (`attach-alias`)
- Fix: salvando un alias, `id_alias` non viene più azzerato
- Eliminazione protetta per prodotti/marche/categorie/clienti/fornitori/stazioni/veicoli/etichette con messaggio sulle referenze; cascata mirata (giacenze a zero, dati propri del veicolo)
- Filtro "Codice" giacenze ottimizzato (indice precalcolato, da O(n²) a O(n))
