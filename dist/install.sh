#!/usr/bin/env bash
#
# Fairy Bus — installer
# Uso interattivo:   ./install.sh
# Uso non interattivo (override via ambiente):
#   FB_PORT=8080 FB_BASE_URL=https://fb.example.com/ \
#   FB_ADMIN_EMAIL=me@x.it FB_ADMIN_PASSWORD=segreta ./install.sh
#
set -euo pipefail
cd "$(dirname "$0")"

GENERATED=0

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
err() { printf '\033[1;31mERRORE:\033[0m %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || err "Docker non trovato. Installa Docker Engine."
docker compose version >/dev/null 2>&1 || err "Plugin 'docker compose' non trovato (serve Docker Compose v2)."

ask() { # $1=env var  $2=prompt  $3=default
    # Variabile gia' settata (anche vuota) -> la usa, con fallback al default.
    # Non settata -> chiede all'utente.
    local var="$1" prompt="$2" def="${3:-}" val
    if [ "${!var+x}" = "x" ]; then
        val="${!var}"
        [ -z "$val" ] && val="$def"
    elif [ -n "$def" ]; then
        read -rp "$prompt [$def]: " val
        val="${val:-$def}"
    else
        read -rp "$prompt: " val
    fi
    printf -v "$var" '%s' "$val"
}

echo "=== Installazione Fairy Bus ==="
ask FB_PORT "Porta HTTP su cui esporre l'app" "8080"
ask FB_BASE_URL "URL pubblico dell'app (come la raggiungeranno gli utenti)" "http://localhost:${FB_PORT}/"
[[ "$FB_BASE_URL" != */ ]] && FB_BASE_URL="${FB_BASE_URL}/"

# Suffisso istanza: consente piu' installazioni sullo stesso Docker host.
# "" -> fairybus-app / fairybus-db / fairybus-pma
# "-demo" -> fairybus-app-demo / fairybus-db-demo / fairybus-pma-demo
ask FB_SUFFIX "Suffisso istanza (es. -demo, vuoto = installazione principale)" ""
if [ -n "$FB_SUFFIX" ]; then
    [[ "$FB_SUFFIX" != -* ]] && FB_SUFFIX="-${FB_SUFFIX}"
    [[ "$FB_SUFFIX" =~ ^-[a-z0-9][a-z0-9-]*$ ]] \
        || err "Suffisso non valido: usa solo lettere minuscole, numeri e '-'"
fi

ask FB_ADMIN_EMAIL "Email dell'utente amministratore" "admin@fairybus.local"

if [ -z "${FB_ADMIN_PASSWORD:-}" ]; then
    read -rsp "Password admin (vuota = generata automaticamente): " FB_ADMIN_PASSWORD
    echo
fi
if [ -z "${FB_ADMIN_PASSWORD:-}" ]; then
    ADMIN_PASS="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 14)"
    GENERATED=1
else
    ADMIN_PASS="$FB_ADMIN_PASSWORD"
fi

DB_NAME="${FB_DB_NAME:-fairybus_db}"
DB_USER="${FB_DB_USER:-fairybus}"
DB_PASSWORD="${FB_DB_PASSWORD:-$(openssl rand -hex 16)}"
MYSQL_ROOT_PASSWORD="${FB_ROOT_PASSWORD:-$(openssl rand -hex 16)}"
TZ_VAL="${FB_TZ:-Europe/Rome}"
ENC_KEY="hex2bin:$(openssl rand -hex 32)"

cat > .env <<EOF
COMPOSE_PROJECT_NAME=fairybus${FB_SUFFIX}
FB_SUFFIX=${FB_SUFFIX}
APP_IMAGE=fairybus-app:1.0.1
APP_PORT=${FB_PORT}
PMA_ABSOLUTE_URI=${FB_BASE_URL}pma/
TZ=${TZ_VAL}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
EOF

cat > app.env <<EOF
CI_ENVIRONMENT = production
app.baseURL = ${FB_BASE_URL}
app.indexPage =
database.default.hostname = db
database.default.database = ${DB_NAME}
database.default.username = ${DB_USER}
database.default.password = ${DB_PASSWORD}
database.default.DBDriver = MySQLi
database.default.DBPrefix =
database.default.port = 3306
encryption.key = ${ENC_KEY}
app.proxyIPs = 172.16.0.0/12,10.0.0.0/8,192.168.0.0/16
EOF

log "File di configurazione generati (.env, app.env)"

load_image() { # $1=nome immagine $2=file tar.gz
    if ! docker image inspect "$1" >/dev/null 2>&1; then
        [ -f "$2" ] || err "File immagine $2 non trovato nella cartella."
        log "Caricamento immagine $1..."
        gunzip -c "$2" | docker load
    fi
}

load_image fairybus-app:1.0.1         fairybus-app-1.0.1.tar.gz
load_image mariadb:10.11              mariadb-10.11.tar.gz
load_image phpmyadmin/phpmyadmin:5.2.3 phpmyadmin-5.2.3.tar.gz

# Il codice dell'app vive in ./app-src (bind mount): popolato dall'immagine
# al primo avvio, aggiornabile poi con ./update.sh (patch zip o nuova immagine).
if [ ! -d app-src ]; then
    log "Estrazione del codice app in ./app-src ..."
    CID=$(docker create fairybus-app:1.0.1)
    mkdir -p app-src
    docker cp "$CID:/var/www/html/." ./app-src/
    docker rm "$CID" >/dev/null
fi

log "Avvio dei container..."
docker compose up -d

app_ready() {
    if command -v curl >/dev/null 2>&1; then
        curl -fs -o /dev/null "http://localhost:${FB_PORT}/login" 2>/dev/null
    else
        (exec 3<>"/dev/tcp/127.0.0.1/${FB_PORT}") 2>/dev/null
    fi
}

log "Attesa che l'app risponda su http://localhost:${FB_PORT} ..."
READY=0
for _ in $(seq 1 90); do
    if app_ready; then READY=1; break; fi
    sleep 2
done
[ "$READY" = "1" ] || err "L'app non risponde dopo 3 minuti. Controlla: docker compose logs app"

log "Impostazione credenziali amministratore..."
HASH=$(docker compose exec -T -e FB_PASS="$ADMIN_PASS" app \
    php -r 'echo password_hash(getenv("FB_PASS"), PASSWORD_BCRYPT);')
printf "UPDATE fb_users SET password_hash='%s', email='%s' WHERE id=1;\n" "$HASH" "$FB_ADMIN_EMAIL" \
    | docker compose exec -T db sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'

echo
log "Installazione completata."
echo "  App:        ${FB_BASE_URL}"
echo "  Login:      ${FB_BASE_URL}login"
echo "  Email:      ${FB_ADMIN_EMAIL}"
if [ "$GENERATED" = "1" ]; then
    echo "  Password:   ${ADMIN_PASS}   <- generata, annotala"
fi
echo
echo "  Credenziali DB e chiavi sono in .env / app.env in questa cartella."
echo "  Per reinstallare da zero: docker compose down -v && rm -f .env app.env && ./install.sh"
