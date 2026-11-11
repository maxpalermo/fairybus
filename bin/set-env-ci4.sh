#!/usr/bin/env bash
# set-env-ci4.sh
#
# Legge /var/www/html/.env dal container CodeIgniter, propone di modificare
# le chiavi più comuni una per una (default: N), applica le modifiche
# confermate e salva un backup nel container.
#
# Uso:
#   ./set-env-ci4.sh <container>
#
# Esempio:
#   ./set-env-ci4.sh fairybus-mysql

set -uo pipefail

ENV_PATH="/var/www/html/.env"
CONTAINER="${1:-}"
[[ -z "$CONTAINER" ]] && { echo "Uso: $0 <container>" >&2; exit 1; }

docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -qx true \
  || { echo "Errore: container '$CONTAINER' non attivo" >&2; exit 1; }
docker exec "$CONTAINER" test -f "$ENV_PATH" \
  || { echo "Errore: $ENV_PATH non trovato in $CONTAINER" >&2; exit 1; }

TMP=$(mktemp) || exit 1
NEW=$(mktemp) || exit 1
trap 'rm -f "$TMP" "$NEW" "$NEW.tmp"' EXIT

docker exec "$CONTAINER" cat "$ENV_PATH" > "$TMP" \
  || { echo "Errore lettura $ENV_PATH" >&2; exit 1; }

BACKUP="$ENV_PATH.bak.$(date +%Y%m%d-%H%M%S)"
docker exec -u root "$CONTAINER" cp "$ENV_PATH" "$BACKUP" \
  && echo "Backup: $BACKUP" || echo "Attenzione: backup fallito" >&2

KEYS=(
  "CI_ENVIRONMENT"
  "app.baseURL"
  "database.default.hostname"
  "database.default.database"
  "database.default.username"
  "database.default.password"
  "database.default.DBDriver"
  "database.default.DBPrefix"
  "database.default.port"
  "encryption.key"
  "app.indexPage"
)
SENSITIVE=("database.default.password" "encryption.key")

is_sensitive() { local k="$1" s; for s in "${SENSITIVE[@]}"; do [[ "$s" == "$k" ]] && return 0; done; return 1; }

get_active() {
  local k="$1" esc; esc=$(printf '%s' "$k" | sed 's/\./\\./g')
  grep -E "^[[:space:]]*${esc}[[:space:]]*=" "$TMP" | head -1 \
    | sed -E "s/^[[:space:]]*${esc}[[:space:]]*=[[:space:]]*//" | sed 's/[[:space:]]*$//'
}
get_commented() {
  local k="$1" esc; esc=$(printf '%s' "$k" | sed 's/\./\\./g')
  grep -E "^[[:space:]]*#[[:space:]]*${esc}[[:space:]]*=" "$TMP" | head -1 \
    | sed -E "s/^[[:space:]]*#[[:space:]]*${esc}[[:space:]]*=[[:space:]]*//" | sed 's/[[:space:]]*$//'
}

declare -A NEW_VALUES

echo "=== Modifica $ENV_PATH in $CONTAINER ==="

for key in "${KEYS[@]}"; do
  active=$(get_active "$key")
  commented=$(get_commented "$key")

  if [[ -n "$active" ]]; then
    if is_sensitive "$key"; then echo "  [$key] attuale: ********"
    else echo "  [$key] attuale: ${active:-<vuoto>}"; fi
  elif [[ -n "$commented" ]]; then
    echo "  [$key] commentato nel file: $commented"
  else
    echo "  [$key] non presente nel file"
  fi

  read -r -p "  Cambiare $key? [y/N] " ans || { echo; break; }
  case "${ans,,}" in
    y|yes|s|si)
      if is_sensitive "$key"; then
        read -r -s -p "  Nuovo valore: " newval || { echo; break; }; echo
      else
        read -r -p "  Nuovo valore: " newval || { echo; break; }
      fi
      NEW_VALUES["$key"]="$newval"
      ;;
  esac
done

if [[ ${#NEW_VALUES[@]} -eq 0 ]]; then
  echo "Nessuna modifica."
  exit 0
fi

cp "$TMP" "$NEW"
for key in "${!NEW_VALUES[@]}"; do
  val="${NEW_VALUES[$key]}"
  awk -v key="$key" -v val="$val" '
    BEGIN {
      esc = key; gsub(/\./, "\\.", esc)
      pat = "^[[:space:]]*#?[[:space:]]*" esc "[[:space:]]*="
      done = 0
    }
    $0 ~ pat && !done { print key " = " val; done=1; next }
    { print }
    END { if (!done) print key " = " val }
  ' "$NEW" > "$NEW.tmp" && mv "$NEW.tmp" "$NEW"
done

echo
echo "=== Riepilogo modifiche ==="
diff -u "$TMP" "$NEW" || true
echo

read -r -p "Applicare al container? [y/N] " confirm
case "${confirm,,}" in
  y|yes|s|si) ;;
  *) echo "Annullato."; exit 0 ;;
esac

docker exec -i -u root "$CONTAINER" sh -c "cat > '$ENV_PATH'" < "$NEW" \
  || { echo "Errore scrittura $ENV_PATH" >&2; exit 1; }

# Ripristina owner/permessi tipici di CodeIgniter 4
docker exec -u root "$CONTAINER" chown www-data:www-data "$ENV_PATH" 2>/dev/null || true
docker exec -u root "$CONTAINER" chmod 644 "$ENV_PATH" 2>/dev/null || true

echo "Fatto. Backup: $BACKUP"

