#!/usr/bin/env bash
#
# Fairy Bus — generatore pacchetti di aggiornamento (uso sviluppo)
#
#   ./make-patch.sh zip 1.0.1 app/Config/App.php public/assets/fairy-bus/js/pages/x.js
#       -> dist/fairybus-patch-1.0.1.zip  (path relativi a www/, pronto per ./update.sh)
#
#   ./make-patch.sh image 1.0.1
#       -> build Dockerfile.dist + docker save -> dist/fairybus-app-1.0.1.tar.gz
#
# Le patch zip si applicano sul server con:  ./update.sh fairybus-patch-1.0.1.zip
# Le immagini si applicano con:            ./update.sh fairybus-app-1.0.1.tar.gz
#
set -euo pipefail
cd "$(dirname "$0")/.."

err() { printf '\033[1;31mERRORE:\033[0m %s\n' "$*" >&2; exit 1; }
log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }

MODE="${1:-}"; VER="${2:-}"; shift 2 || err "Uso: $0 <zip|image> <versione> [file...]"
case "$VER" in [0-9]*) ;; *) err "Versione non valida: $VER (es. 1.0.1)";; esac

case "$MODE" in
    zip)
        [ $# -gt 0 ] || err "Specifica almeno un file (path relativi a www/)."
        OUT="dist/fairybus-patch-${VER}.zip"
        ( cd www && zip -q "$OLDPWD/$OUT" "$@" )
        log "Creato $OUT"
        ;;
    image)
        TAG="fairybus-app:${VER}"
        log "Build immagine $TAG ..."
        docker build -f Dockerfile.dist -t "$TAG" .
        OUT="dist/fairybus-app-${VER}.tar.gz"
        log "Esportazione in $OUT ..."
        docker save "$TAG" | gzip > "$OUT"
        log "Creato $OUT ($(du -h "$OUT" | cut -f1))"
        ;;
    *)
        err "Uso: $0 <zip|image> <versione> [file...]"
        ;;
esac
