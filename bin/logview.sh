#!/bin/bash
#
# Visualizzatore dal vivo del log del server PHP: segue il file e COLORA le righe
# di 'php -S' (stato HTTP, avvio, connessioni) come la console vera del server.
# Usato dal pulsante "Console server (dal vivo)" dentro AIManager.
#
# Si CHIUDE da solo quando il server si ferma (porta non piu' in ascolto), cosi' la
# finestra non resta appesa con 'tail -F' e chiudendola macOS non chiede di
# terminare processi. Ctrl-C chiude la vista senza toccare il server.
#
# Argomenti: $1 = percorso del log, $2 = porta del server (default 8000).

LOG="${1:?percorso del log mancante}"
PORT="${2:-8000}"

printf '\033[1mAIManager — console del server dal vivo\033[0m  (si chiude da sola allo stop)\n'
printf '\033[90m%s\033[0m\n' "$LOG"

# Monitor mode: la pipeline in background ottiene un PROPRIO process group, cosi'
# possiamo terminarla per intero (tail + awk) senza uccidere questo script.
set -m

tail -n 200 -F "$LOG" 2>/dev/null | awk '
{
    if ($0 ~ /\[2[0-9][0-9]\]:/)              c = "32"   # 2xx verde
    else if ($0 ~ /\[3[0-9][0-9]\]:/)         c = "36"   # 3xx ciano
    else if ($0 ~ /\[4[0-9][0-9]\]:/)         c = "33"   # 4xx giallo
    else if ($0 ~ /\[5[0-9][0-9]\]:/)         c = "31"   # 5xx rosso
    else if ($0 ~ /Development Server.*started/) c = "1" # avvio in grassetto
    else                                      c = "90"   # Accepted/Closing/resto grigio
    printf "\033[%sm%s\033[0m\n", c, $0
    fflush()
}' &
VIEW_PGID="$!"

# Se Ctrl-C: chiudi la vista e basta (il server resta gestito dall'app).
trap 'kill -- -"$VIEW_PGID" 2>/dev/null; exit 0' INT TERM

# Attendi finche' il server e' in ascolto; quando sparisce, chiudi la vista.
while lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; do
    sleep 1
done

kill -- -"$VIEW_PGID" 2>/dev/null
printf '\n\033[90m— server fermato: log chiuso, puoi chiudere la finestra —\033[0m\n'
exit 0
