#!/bin/bash

# Prepara una nuova copia locale di AIManager. Non legge chiavi, non avvia servizi
# e non scrive fuori dalla cartella del progetto.

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" || exit 1
ENV_FILE="$ROOT/.env"
ENV_EXAMPLE="$ROOT/.env.example"

fail() {
    echo "Errore: $1" >&2
    exit 1
}

warning() {
    echo "Avviso: $1" >&2
}

[ "$(uname -s 2>/dev/null)" = "Darwin" ] \
    || fail "AIManager è attualmente supportato soltanto su macOS."

command -v git >/dev/null 2>&1 \
    || fail "Git non è installato. Vedi https://git-scm.com/downloads/mac"
command -v php >/dev/null 2>&1 \
    || fail "PHP 8.5 non è installato. Vedi https://www.php.net/downloads.php"

php -r 'exit(version_compare(PHP_VERSION, "8.5.0", ">=") ? 0 : 1);' \
    || fail "serve PHP 8.5 o successivo."

for extension in pdo_sqlite curl mbstring; do
    php -r "exit(extension_loaded('$extension') ? 0 : 1);" \
        || fail "manca l'estensione PHP $extension."
done

for extension in pcntl posix; do
    php -r "exit(extension_loaded('$extension') ? 0 : 1);" \
        || warning "manca l'estensione PHP $extension: alcune funzioni Code saranno limitate."
done

[ -f "$ENV_EXAMPLE" ] || fail "manca .env.example."

if [ -e "$ENV_FILE" ]; then
    [ -f "$ENV_FILE" ] || fail ".env esiste ma non è un file; non è stato modificato."
    echo "Configurazione .env già presente: non è stata sovrascritta."
else
    cp "$ENV_EXAMPLE" "$ENV_FILE" || fail "creazione di .env non riuscita."
    chmod 600 "$ENV_FILE" 2>/dev/null || true
    echo "Configurazione locale creata."
fi

echo
echo "Installazione completata."
echo "Avvia AIManager con:"
echo "bash bin/launch.sh"
