#!/bin/bash
#
# Avvia AIManager in modo silenzioso: server PHP locale (solo 127.0.0.1) in background +
# apertura browser. Niente finestra Terminale: i log vanno su storage/logs/server.log e si aprono a
# richiesta dal pulsante "Console server (dal vivo)" dentro AIManager. Per fermare: pulsante nell'app.
#
# NB: NON usa 'set -e' e termina SEMPRE con 'exit 0'. Lanciato da AIManager.app via 'do shell script',
# un'uscita non-zero diventa un dialogo d'errore: qui va evitato. Gli errori si mostrano con un alert.

HOST="127.0.0.1"
PORT="8000"
URL="http://${HOST}:${PORT}"
HEALTH_URL="${URL}/system/health"

# Fase 10 / Step 3 — i file runtime creati d'ora in poi (log incluso) restano privati.
umask 077

# Cartella del progetto = genitore di bin/ (cosi' lo script resta valido se sposti il repo)
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" || exit 0
cd "$ROOT" || exit 0
LOG="$ROOT/storage/logs/server.log"

# Mostra un alert (non blocca l'uscita 0). I messaggi non contengono virgolette.
alert() {
    osascript -e "display alert \"AIManager\" message \"$1\"" >/dev/null 2>&1
}

# La health identifica DAVVERO AIManager: risposta esatta e stabile, non un HTTP 200 qualsiasi.
is_aimanager() {
    [ "$(curl -s -m 2 "$HEALTH_URL" 2>/dev/null)" = "AIManager:ok" ]
}

# NB: nessuna autenticazione all'avvio (rimossa su richiesta 2026-07-10). Se in futuro serve
# proteggere l'app (es. sezione CODE), va fatto per-azione DENTRO l'app, non con un cancello qui.

# php: prima quello nel PATH, poi il percorso Homebrew come ripiego
PHP="$(command -v php 2>/dev/null)"
[ -x "$PHP" ] || PHP="/opt/homebrew/bin/php"
if [ ! -x "$PHP" ]; then
    alert "php non trovato. Installa PHP: brew install php"
    exit 0
fi

# Se la porta e' gia' occupata: apri il browser SOLO se a rispondere e' davvero AIManager. Se e' un
# altro servizio, non aprire il browser e non avviare un secondo server.
if lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    if is_aimanager; then
        open "$URL"
    else
        alert "La porta $PORT e' occupata da un altro servizio. AIManager non e' stato avviato."
    fi
    exit 0
fi

# LM Studio e' il provider locale preferito. Se la sua API non risponde, prova ad avviarla via CLI.
# Best-effort: se fallisce o manca si prosegue comunque (l'app ripiega sui provider cloud abilitati),
# e NON deve bloccare l'avvio di AIManager.
if ! lsof -nP -iTCP:1234 -sTCP:LISTEN >/dev/null 2>&1; then
    LMS="$HOME/.lmstudio/bin/lms"
    [ -x "$LMS" ] && "$LMS" server start >/dev/null 2>&1
fi

# Server PHP in background, log su file, stdin/out/err staccati (cosi' il lanciatore ritorna subito e
# il processo sopravvive alla chiusura della .app).
mkdir -p "$(dirname "$LOG")"
nohup "$PHP" -S "${HOST}:${PORT}" -t public >"$LOG" 2>&1 </dev/null &
SERVER_PID=$!

# Attendi la HEALTH REALE (non una risposta HTTP qualsiasi): apri il browser solo quando AIManager si
# identifica. Se non arriva entro un tempo ragionevole, termina SOLO il PID appena avviato e segnala.
STARTED=0
for _ in $(seq 1 40); do
    if is_aimanager; then
        STARTED=1
        break
    fi
    sleep 0.25
done

if [ "$STARTED" = "1" ]; then
    open "$URL"
else
    kill "$SERVER_PID" >/dev/null 2>&1
    alert "AIManager non ha risposto all'avvio. Controlla storage/logs/server.log"
fi

exit 0
