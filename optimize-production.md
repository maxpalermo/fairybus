# Ottimizzazione di CodeIgniter 4 per la Produzione

Questa guida elenca i passaggi necessari per portare un progetto CodeIgniter 4 in ambiente di produzione, ottimizzando le prestazioni e pulendo tutte le cache.

## 1. Impostare l'ambiente di produzione

Apri il file `.env` nella root del progetto e imposta:

```ini
CI_ENVIRONMENT = production
```

In alternativa, usa il comando Spark:

```bash
php spark env production
```

> **Nota:** il file `.env` non deve mai essere committato nel repository Git. Deve esistere solo sul server di produzione e avere permessi ristretti (es. `chmod 600 .env`).

## 2. Ottimizzare l'applicazione

Se usi CodeIgniter 4.5.0 o superiore, esegui:

```bash
php spark optimize
```

Questo comando esegue diverse ottimizzazioni automatiche per la produzione.

## 3. Ottimizzare Composer

Rimuovi le dipendenze di sviluppo e ottimizza l'autoloader:

```bash
composer install --no-dev --optimize-autoloader
```

## 4. Pulire tutte le cache

Per garantire che il programma parta pulito e che tutte le modifiche a `.env` e ai file di configurazione vengano applicate, esegui:

```bash
php spark cache:clear
rm -rf writable/cache/*
```

La cartella `writable/cache/` contiene sottocartelle come `config/` e `routes/` che memorizzano configurazioni compilate. Se non vengono eliminate manualmente, CodeIgniter continuerà a usare le vecchie configurazioni anche dopo aver modificato `.env`.

## 5. Riepilogo dei comandi per il deploy

Dalla root del progetto, esegui in sequenza:

```bash
# 1. Imposta l'ambiente di produzione (modifica .env)
php spark env production

# 2. Ottimizza l'applicazione (se usi CI 4.5+)
php spark optimize

# 3. Rimuovi le dipendenze di sviluppo
composer install --no-dev --optimize-autoloader

# 4. Pulisci TUTTE le cache
php spark cache:clear
rm -rf writable/cache/*
```

## Note finali

- Dopo queste operazioni, l'applicazione girerà in modalità produzione, con prestazioni ottimizzate e senza cache obsolete.
- Assicurati che i permessi delle cartelle `writable/` siano corretti (scrivibili dal web server).
- Se usi un sistema di cache esterno (Redis, Memcached), `php spark cache:clear` pulirà anche quello.