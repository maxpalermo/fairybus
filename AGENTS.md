# Fairy Bus — regole operative per l'agente

Gestionale autorimessa pullman: **CodeIgniter 4.7.4 + Twig 3 + MariaDB 10.11**,
stack Docker. Repo pubblico: `github.com/maxpalermo/fairybus` (branch `main`).

Docs complementari (nella parent dir, fuori dal repo): `manuale-sviluppatore.md`
(architettura), `summary.md` (piano di lavoro storico). Changelog completo:
`README.md` (voci `### 0.x.y — data`).

## Ambiente

- **Container**: `fairybus-mysql` (app PHP 8.3+Apache), `fairybus-mysql-db`
  (MariaDB 10.11), `fairybus-mysql-pma` (phpMyAdmin) — rete esterna `caddy-net`.
- **URL**: app `https://fairybus-mysql.localhost`, PMA `.../pma/`
  (root + `MYSQL_ROOT_PASSWORD` da `.env` di root).
- **Credenziali**: `mysql/.env` → password usate da docker-compose;
  `www/.env` → config CI4 (DB, encryption key). **Entrambi gitignored**:
  mai committarli, mai copiare valori nei file tracciati. Template:
  `.env.example` e `www/env`.
- **DB dev**: `fairybus_db` su `fairybus-mysql-db` (user `fairybus`).
  Il DB di produzione è separato: **mai dedurre schema/dati di produzione
  dal DB locale**.
- Path app: `www/` nel repo = `/var/www/html` nel container `fairybus-mysql`.

## Struttura repo (root = questa cartella)

- `bin/` — script: `flush-cache-twig.sh`, `make-patch.sh` (zip patch /
  immagine dist), `set-ci4-permissions.sh`, `set-env-ci4.sh`,
  `cat-slow.sh`/`read-slow.sh`, `fb-vm-forward.sh`, entrypoint container
- `conf/` — `000-default.conf`, `apache-cache.conf`, `pma-proxy.conf`,
  `my.cnf`, `php.ini`, `php.ini.dist` (montati via compose / COPY nei Dockerfile)
- `sql/` — script SQL di manutenzione
- `docs/` — documentazione operativa
- `Dockerfile` (dev) / `Dockerfile.dist` + `Dockerfile.dist.dockerignore`
- `dist/` — installer offline (`install.sh`, `update.sh`,
  `db-init/01-fairybus.sql` factory); artefatti `*.tar.gz`/`*.zip` NON versionati
- `site/` — landing del pacchetto (zip installer esclusi da git)
- `www/` — applicazione CI4: `app/Modules/FairyBus/{Controllers/{Admin,Api,Auth},
  Models, Libraries, Views/{admin,auth,print}, Database/Migrations}`;
  asset in `public/assets/fairy-bus/{css,js}/{core,components,pages}`
- `db_data/` — dati runtime MariaDB (gitignored)

## Convenzioni da rispettare

- **Tabelle**: `fb_` fa parte del **nome** (es. `fb_customer`), NON è il
  `DBPrefix` di CI4 (vuoto in `.env`). Tabelle legacy senza prefisso via
  `LegacyDatabase::withoutPrefix()`.
- **Quantità scarichi**: negative nel DB; in vista/stampa sempre `ABS(quantity)`
  o `|abs`. Eccezione righe riepilogo manutenzione terzi: salvate **positive**
  (il riepilogo non tocca magazzino).
- **`fb_document_detail.id_maintenance` ≠ null** → riga copiata da scheda:
  **mai** movimentare `fb_stock` (già scaricato alla manutenzione).
- **Timestamps**: modelli su tabelle senza `updated_at` devono impostare
  `protected $updatedField = '';` (bug storico su veicoli/ruoli).
- **Cache busting**: ogni asset nei Twig ha `?v=N` — **incrementa sempre**
  dopo aver toccato JS/CSS, in TUTTI i template che lo includono.
- **Italiano** per UI, commenti, changelog.
- UI: design system `fb-*` (shadcn-like), icone SVG feather colorate,
  `<dialog>` nativi clonati da `<template>`, `DialogHelper`,
  `SearchableSelect`/`TableSelect` per i picker.

## Pattern di sviluppo

- Nuova entità: `Fb*Model` → `Api\*` (JSON, `requirePermission('*')`,
  `jsonResponse`) → `Admin\*::index` (`renderAdmin`) + `printPdf` →
  `admin/*.twig` (Bootstrap Table + `<template>`) → `js/pages/*.js`.
- Tabelle: `filterControl` + `filterCustomSearch: window.fbFilterSearch`;
  select filtro via `filterData: "var:nomeGlobale"` (popolare il globale
  prima dell'init della tabella); azioni riga via `window.*ActionsEvents`;
  tabelle in tab nascoste → init lazy.
- Stampa: `PrintHelper` (POST `ids` JSON, `download=1` se switch attivo) →
  `getPrintIds()` → `PdfHelper::generate` → `pdfResponse()`. Report massivi:
  variante HTML auto-stampante (`*_html.twig`) oltre ~250 righe.
- DB changes: migration in `Database/Migrations` + **fornire sempre lo
  `ALTER` SQL per la produzione** (il deploy non esegue migrate).

## Manutenzione terzi (wizard riepilogo)

- Pagina `/admin/unloads`, documento con `id_customer` = "Documento di
  riepilogo" (È la fattura: niente associa-fattura, niente colonna fattura).
- Numero progressivo: `fb_configuration.unload_doc_counter` (name/value,
  avanzato solo dopo insert riuscito); endpoint `api/documents/next-number`.
- Selezione schede = `Set` in `form._mntChecked` (sopravvive al filtro
  periodo); sync server: `POST api/documents/{id}/maintenances` →
  `syncMaintenances` flagga `fb_maintenance.id_document` e copia righe
  positive in `fb_document_detail` (solo task manodopera con ore/tariffa/importo > 0).
- `api/maintenance/available`: schede del veicolo senza `id_document` e
  non fatturate; `with_lines=1` per l'anteprima movimenti.

## Verifica obbligatoria

```bash
php -l <ogni .php modificato>
node --check <ogni .js modificato>
docker exec fairybus-mysql sh -c 'rm -rf /var/www/html/writable/twig/cache/*'
# query DB di test:
docker exec fairybus-mysql php -r '$pdo=new PDO("mysql:host=fairybus-mysql-db;dbname=fairybus_db","fairybus","<DB_PASSWORD da .env>"); ...'
```

## Git / GitHub

- Repo pubblico → **mai** committare `.env`, `db_data/`, `www/public/uploads/`,
  `www/vendor/`, `www/writable/*` runtime, backup, `*.zip`/`*.tar.gz`.
  Controllare sempre `git status` prima del push; file >100MB rifiutati da GitHub.
- Autore commit: `Massimiliano Palermo <maxx.palermo@gmail.com>`
  (`git -c user.name=... -c user.email=... commit`).
- `gh` CLI in `~/.local/bin/gh`, già autenticato (release, issues, PR).
- Artefatti di distribuzione → GitHub Releases, non nel repo.

## Changelog

A fine feature: voce `### 0.x.y — data` in `README.md`. Aggiornare anche
`summary.md` (parent dir, fuori repo) con riga sintetica se pertinente.
Versioni asset `?v=N` incrementate vanno riportate nella risposta.
