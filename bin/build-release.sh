#!/bin/bash
#
# Fase 10 / Step 4 — costruisce un artefatto macOS PULITO da HEAD.
#
# Solo runtime (allow-list), niente installer/firma/notarizzazione. Richiede il working tree pulito,
# costruisce esclusivamente da HEAD, verifica gli entry point e l'assenza dei percorsi vietati PRIMA
# di produrre l'artefatto definitivo. Non sovrascrive artefatti esistenti e non cancella nulla.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" || { echo "Errore: root non trovata." >&2; exit 1; }
cd "$ROOT" || { echo "Errore: impossibile entrare nella root." >&2; exit 1; }

fail() { echo "Errore: $1" >&2; exit 1; }

command -v git >/dev/null 2>&1 || fail "git non trovato."

# Working tree PULITO: nessuna modifica tracciata, nessun file non tracciato (non ignorato).
[ -z "$(git status --porcelain)" ] || fail "working tree non pulito: committa o pulisci prima di costruire."

SHA="$(git rev-parse --short HEAD)" || fail "HEAD non risolvibile."
NAME="AIManager-${SHA}"
DIST="$ROOT/dist"
TARBALL="$DIST/${NAME}.tar.gz"
CHECKSUM="${TARBALL}.sha256"

# Non sovrascrivere artefatti esistenti; non cancellare nulla di preesistente.
[ -e "$TARBALL" ] && fail "artefatto gia' esistente: $TARBALL (non lo sovrascrivo)."
[ -e "$CHECKSUM" ] && fail "checksum gia' esistente: $CHECKSUM (non lo sovrascrivo)."

# Allow-list RUNTIME. git archive include SOLO file tracciati a HEAD: test, .git, .claude, docs interne,
# .env e dati runtime restano fuori perche' non elencati qui.
PATHS=(app bin config database plugins public storage/plugins/.gitkeep .env.example README.md LICENSE SECURITY.md docs/RELEASE.md docs/PROVIDERS.md docs/USER_GUIDE.md docs/PUBLIC_ROADMAP.md)

tmp_tar="$(mktemp "${TMPDIR:-/tmp}/aimanager-release.XXXXXX")" || fail "mktemp fallito."
# Trap installata SUBITO dopo il primo temporaneo, con TUTTE le variabili gia' inizializzate (vuote):
# cosi' anche il fallimento del secondo mktemp (o di uno spostamento) non lascia residui.
tmp_gz=""
tmp_sum=""
trap 'rm -f "$tmp_tar" "$tmp_gz" "$tmp_sum"' EXIT

git archive --format=tar --prefix="${NAME}/" HEAD -- "${PATHS[@]}" > "$tmp_tar" \
    || fail "git archive fallito (una voce dell'allow-list manca a HEAD?)."

listing="$(tar -tf "$tmp_tar")" || fail "lettura dell'archivio fallita."

# Entry point PRESENTI.
present() { printf '%s\n' "$listing" | grep -qx "${NAME}/$1" || fail "entry point mancante nell'artefatto: $1"; }
present "public/index.php"
present "bin/launch.sh"
present ".env.example"
present "README.md"
present "LICENSE"

# Percorsi VIETATI assenti (l'allow-list ha tenuto). Si ignorano le voci di sola directory (finiscono con '/').
forbidden() {
    printf '%s\n' "$listing" | grep -v '/$' | grep -Eq "^${NAME}/$1" \
        && fail "percorso vietato presente nell'artefatto: $1"
    return 0
}
forbidden "tests/"
forbidden "\.git"
forbidden "\.claude"
forbidden "\.env$"
forbidden "storage/logs/"
forbidden "storage/cache/"
forbidden "storage/attachments/"
forbidden "storage/memory/"
forbidden "storage/backups/"
forbidden "storage/database/"

# Tra le docs entrano SOLO le guide pubbliche elencate; piani e handoff interni restano fuori.
public_docs="RELEASE.md|PROVIDERS.md|USER_GUIDE.md|PUBLIC_ROADMAP.md"
printf '%s\n' "$listing" | grep -E "^${NAME}/docs/" | grep -v '/$' \
    | grep -Ev "^${NAME}/docs/(${public_docs})$" \
    | grep -q . && fail "documentazione interna presente nell'artefatto."

# Solo ORA, a verifiche superate, si producono artefatto e checksum come TEMPORANEI dentro dist/, si
# verifica anche l'archivio compresso (gzip -t) e si spostano ai nomi definitivi SOLO a successo
# completo. Su qualsiasi errore restano rimossi solo i propri temporanei: nessun tarball parziale che
# blocchi le build successive.
mkdir -p "$DIST" || fail "creazione di dist/ fallita."

tmp_gz="$(mktemp "${DIST}/.build-${SHA}.tar.gz.XXXXXX")" || fail "mktemp (dist) fallito."
tmp_sum="$(mktemp "${DIST}/.build-${SHA}.sha256.XXXXXX")" || fail "mktemp (dist) fallito."

gzip -c "$tmp_tar" > "$tmp_gz" || fail "compressione dell'artefatto fallita."
gzip -t "$tmp_gz" || fail "archivio compresso non valido."

hash="$(shasum -a 256 "$tmp_gz" | cut -d' ' -f1)" || fail "checksum non calcolabile."
[ -n "$hash" ] || fail "checksum vuoto."
# Il checksum referenzia il nome DEFINITIVO, così `shasum -c` funziona sui nomi pubblicati.
printf '%s  %s\n' "$hash" "${NAME}.tar.gz" > "$tmp_sum" || fail "scrittura del checksum fallita."

# Pubblicazione ATOMICA e NO-CLOBBER: `ln` (hard link, stesso filesystem) fallisce se il nome esiste,
# quindi non sovrascrive mai un file preesistente. I temporanei restano e la trap li rimuove: la
# pulizia tocca solo i propri file, mai quelli preesistenti. Se il checksum non si pubblica, si
# elimina SOLTANTO il tarball definitivo appena creato da questa esecuzione.
ln "$tmp_gz" "$TARBALL" || fail "pubblicazione dell'artefatto fallita (nome gia' esistente?)."
if ! ln "$tmp_sum" "$CHECKSUM"; then
    rm -f "$TARBALL"
    fail "pubblicazione del checksum fallita (nome gia' esistente?)."
fi

echo "Artefatto: $TARBALL"
echo "Checksum:  $CHECKSUM"
echo "SHA-256:   $hash"
exit 0
