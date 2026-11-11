#!/usr/bin/env bash
#
# Fairy Bus — aggiornamento
#
# Uso:
#   ./update.sh fairybus-patch-1.0.1.zip      patch: file da sovrascrivere in app-src
#   ./update.sh fairybus-app-1.0.1.tar.gz     release: nuova immagine app (docker save)
#
# Il codice dell'app vive in ./app-src (bind mount su /var/www/html):
# - patch zip -> i file vengono estratti sopra app-src (path relativi alla radice
#   del progetto, es. app/Config/App.php, public/assets/...)
# - immagine tar.gz -> docker load + risincronizzazione completa di app-src
# In entrambi i casi vengono eseguite le migrazioni e pulita la cache Twig.
#
set -euo pipefail
cd "$(dirname "$0")"

log()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mATTENZIONE:\033[0m %s\n' "$*" >&2; }
err()  { printf '\033[1;31mERRORE:\033[0m %s\n' "$*" >&2; exit 1; }

[ "${1:-}" != "" ] || err "Uso: ./update.sh <patch.zip|immagine.tar.gz>"
PKG="$1"
[ -f "$PKG" ] || err "File non trovato: $PKG"
[ -f .env ]   || err "File .env non trovato: esegui prima ./install.sh"

# shellcheck disable=SC1091
. ./.env
APP_IMAGE="${APP_IMAGE:-fairybus-app:1.0.1}"
APP_CONTAINER="fairybus-app${FB_SUFFIX:-}"

sync_app_src() { # $1 = tag immagine
    local tag="$1" cid
    log "Risincronizzazione di ./app-src dall'immagine $tag ..."
    cid=$(docker create "$tag")
    mkdir -p app-src
    find app-src -mindepth 1 -delete
    docker cp "$cid:/var/www/html/." ./app-src/
    docker rm "$cid" >/dev/null
}

fix_ownership() {
    # file estratti/come root via docker: li riporto all'utente corrente
    docker run --rm -v "$PWD/app-src:/w" "$APP_IMAGE" \
        chown -R "$(id -u):$(id -g)" /w >/dev/null 2>&1 || true
}

post_update() {
    log "Migrazioni database..."
    if ! docker compose exec -T app php spark migrate --all; then
        warn "Migrazioni non riuscite — controlla 'docker compose logs app' e ripeti a mano."
    fi

    log "Pulizia cache Twig..."
    docker compose exec -T app sh -c 'rm -rf /var/www/html/writable/twig/cache/*' || true

    log "Riavvio del container app (opcache)..."
    docker compose restart app
}

case "$PKG" in
    *.zip)
        log "Applicazione patch $PKG"
        # unzip eseguito dentro l'immagine: non richiede unzip sull'host
        docker run --rm \
            -v "$PWD/app-src:/var/www/html" \
            -v "$PWD:/pkg:ro" \
            "$APP_IMAGE" unzip -o "/pkg/$PKG" -d /var/www/html >/dev/null
        fix_ownership
        post_update
        ;;

    *.tar.gz|*.tgz)
        log "Caricamento nuova immagine da $PKG ..."
        OUT=$(gunzip -c "$PKG" | docker load)
        echo "$OUT"
        NEW_TAG=$(echo "$OUT" | sed -n 's/^Loaded image: //p' | tail -1)
        [ -n "$NEW_TAG" ] || err "Tag immagine non rilevato dall'archivio."

        sync_app_src "$NEW_TAG"
        fix_ownership

        if grep -q '^APP_IMAGE=' .env; then
            sed -i "s|^APP_IMAGE=.*|APP_IMAGE=${NEW_TAG}|" .env
        else
            echo "APP_IMAGE=${NEW_TAG}" >> .env
        fi

        log "Riavvio con la nuova immagine $NEW_TAG ..."
        docker compose up -d
        post_update
        ;;

    *)
        err "Formato non riconosciuto: usa un .zip (patch) o .tar.gz (immagine)."
        ;;
esac

log "Aggiornamento completato."
