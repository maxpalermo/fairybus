#!/usr/bin/env bash
# set-ci4-permissions.sh
#
# Imposta i permessi nei container tramite `docker exec -u root`.
# Path fissi:
#   ci4 : /var/www/html  (owner: www-data)   +  /var/www/html/writable (775)
#   db  : /var/lib/mysql (owner: mysql)      (755)
#
# Uso:
#   sudo ./set-ci4-permissions.sh ci4=<container> db=<container> [opzioni]
#
# Opzioni:
#   --ci4-user=USER   utente nel container ci4   (default: www-data)
#   --db-user=USER    utente nel container db    (default: mysql)
#   --dry-run         mostra i comandi senza eseguirli
#   --show-ids        stampa solo gli UID:GID rilevati ed esce
#   -h, --help        aiuto
#
# Esempi:
#   ./set-ci4-permissions.sh ci4=fairybus-mysql db=fairybus-mysql-db --show-ids
#   sudo ./set-ci4-permissions.sh ci4=fairybus-mysql db=fairybus-mysql-db --dry-run
#   sudo ./set-ci4-permissions.sh ci4=fairybus-mysql db=fairybus-mysql-db

set -uo pipefail

CI4_PATH="/var/www/html"
CI4_WRITABLE="/var/www/html/writable"
DB_PATH="/var/lib/mysql"

CI4_CONTAINER=""; DB_CONTAINER=""
CI4_USER="www-data"; DB_USER="mysql"
DRY_RUN=false; SHOW_IDS_ONLY=false
EXIT_CODE=0

for arg in "$@"; do
  case "$arg" in
    -h|--help)       sed -n '2,20p' "$0" | sed 's/^# \?//'; exit 0 ;;
    ci4=*)           CI4_CONTAINER="${arg#ci4=}" ;;
    db=*)            DB_CONTAINER="${arg#db=}" ;;
    --ci4-user=*)    CI4_USER="${arg#--ci4-user=}" ;;
    --db-user=*)     DB_USER="${arg#--db-user=}" ;;
    --dry-run)       DRY_RUN=true ;;
    --show-ids)      SHOW_IDS_ONLY=true ;;
    -*) echo "Opzione sconosciuta: $arg" >&2; exit 1 ;;
    *)  echo "Argomento inatteso: $arg (usa ci4=<c> o db=<c>)" >&2; exit 1 ;;
  esac
done

[[ -z "$CI4_CONTAINER" && -z "$DB_CONTAINER" ]] && {
  echo "Errore: specifica almeno ci4=<container> o db=<container>" >&2; exit 1; }

# ---------- helper ----------
check_container() {
  local c="$1" svc="$2"; [[ -z "$c" ]] && return 0
  docker inspect "$c" >/dev/null 2>&1 || { echo "Errore [$svc]: container '$c' non trovato" >&2; return 1; }
  docker inspect -f '{{.State.Running}}' "$c" | grep -qx true \
    || { echo "Errore [$svc]: container '$c' non è in esecuzione" >&2; return 1; }
}

get_ids() {
  local c="$1" u="$2" raw uid gid
  raw=$(docker exec "$c" id "$u" 2>/dev/null) || { printf ''; return 0; }
  uid=$(printf '%s\n' "$raw" | sed -n 's/.*uid=\([0-9]\+\).*/\1/p')
  gid=$(printf '%s\n' "$raw" | sed -n 's/.*gid=\([0-9]\+\).*/\1/p')
  [[ -n "$uid" && -n "$gid" ]] && printf '%s:%s\n' "$uid" "$gid"
}

path_exists() { docker exec "$1" test -d "$2" 2>/dev/null; }

run() {  # docker exec -u root ...
  if $DRY_RUN; then
    printf '[dry-run] docker exec -u root'; printf ' %q' "$@"; printf '\n'
    return 0
  fi
  docker exec -u root "$@" || { echo "  comando fallito: $*" >&2; EXIT_CODE=1; }
}

# ---------- validazione ----------
check_container "$CI4_CONTAINER" ci4 || exit 1
check_container "$DB_CONTAINER"  db  || exit 1

# ---------- diagnosi utenti ----------
CI4_IDS=""; DB_IDS=""
[[ -n "$CI4_CONTAINER" ]] && CI4_IDS=$(get_ids "$CI4_CONTAINER" "$CI4_USER")
[[ -n "$DB_CONTAINER"  ]] && DB_IDS=$(get_ids "$DB_CONTAINER"  "$DB_USER")

echo "=== Riepilogo ==="
if [[ -n "$CI4_CONTAINER" ]]; then
  if [[ -n "$CI4_IDS" ]]; then
    printf 'ci4 : container=%s utente=%s (%s)\n' "$CI4_CONTAINER" "$CI4_USER" "$CI4_IDS"
    printf '      path=%s (owner=%s, mode 755)\n' "$CI4_PATH"     "$CI4_USER"
    printf '      path=%s (mode 775)\n'            "$CI4_WRITABLE"
  else
    printf 'ci4 : container=%s [utente %s NON TROVATO – step saltato]\n' "$CI4_CONTAINER" "$CI4_USER" >&2
  fi
fi
if [[ -n "$DB_CONTAINER" ]]; then
  if [[ -n "$DB_IDS" ]]; then
    printf 'db  : container=%s utente=%s (%s)\n' "$DB_CONTAINER" "$DB_USER" "$DB_IDS"
    printf '      path=%s (owner=%s, mode 755)\n' "$DB_PATH" "$DB_USER"
  else
    printf 'db  : container=%s [utente %s NON TROVATO – step saltato]\n' "$DB_CONTAINER" "$DB_USER" >&2
  fi
fi

$SHOW_IDS_ONLY && exit 0

# ---------- CI4 ----------
if [[ -n "$CI4_CONTAINER" && -n "$CI4_IDS" ]]; then
  echo; echo "==> ci4 ($CI4_CONTAINER)"

  if path_exists "$CI4_CONTAINER" "$CI4_PATH"; then
    echo "    chown -R $CI4_USER:$CI4_USER $CI4_PATH"
    run "$CI4_CONTAINER" chown -R "$CI4_USER:$CI4_USER" "$CI4_PATH"
    echo "    chmod -R 755 $CI4_PATH"
    run "$CI4_CONTAINER" chmod -R 755 "$CI4_PATH"
  else
    echo "  salto $CI4_PATH (non esiste)" >&2; EXIT_CODE=1
  fi

  if path_exists "$CI4_CONTAINER" "$CI4_WRITABLE"; then
    echo "    chmod -R 775 $CI4_WRITABLE"
    run "$CI4_CONTAINER" chmod -R 775 "$CI4_WRITABLE"
  else
    echo "  salto $CI4_WRITABLE (non esiste)" >&2; EXIT_CODE=1
  fi
fi

# ---------- MariaDB ----------
if [[ -n "$DB_CONTAINER" && -n "$DB_IDS" ]]; then
  echo; echo "==> db ($DB_CONTAINER)"

  if path_exists "$DB_CONTAINER" "$DB_PATH"; then
    echo "    chown -R $DB_USER:$DB_USER $DB_PATH"
    run "$DB_CONTAINER" chown -R "$DB_USER:$DB_USER" "$DB_PATH"
    echo "    chmod -R 755 $DB_PATH"
    run "$DB_CONTAINER" chmod -R 755 "$DB_PATH"
  else
    echo "  salto $DB_PATH (non esiste)" >&2; EXIT_CODE=1
  fi
fi

echo; echo "Fatto (exit=$EXIT_CODE)."
exit $EXIT_CODE

