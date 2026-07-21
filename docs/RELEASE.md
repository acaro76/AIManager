# AIManager — Rilascio v1 (locale, macOS)

Guida operativa per costruire l'artefatto e aggiornare/rollbackare a mano, in sicurezza. Questa è la
documentazione **spedita** nell'artefatto; il piano interno è in `docs/FASE10_PIANO.md` (non incluso).

## Prerequisiti v1

- macOS, **singolo utente**; server integrato vincolato a **`127.0.0.1:8000`**.
- **PHP 8.5** con estensioni **SQLite** (`pdo_sqlite`/`sqlite3`), **cURL**, **mbstring**.
- `pcntl` e `posix` sono richiesti dai **comandi Code** e dai **processi persistenti**; le verifiche
  curate funzionano anche senza (terminazione ridotta).
- Ingresso supportato: **`bin/launch.sh`**. `AIManager.app` **non** fa parte dell'artefatto v1.

## Build e verifica del checksum

```bash
bin/build-release.sh
# stampa i percorsi dell'artefatto in dist/ e del relativo .sha256
```

Il builder costruisce solo da `HEAD`, richiede il working tree pulito e include soltanto il runtime
(niente test, `.git`, `.claude`, documentazione interna, `.env` o dati runtime). Verifica il checksum
(seleziona automaticamente l'ultimo artefatto prodotto):

```bash
cd dist
ART="$(ls -t AIManager-*.tar.gz | head -1)"
shasum -a 256 -c "$ART.sha256"
```

## Prima installazione

Imposta i percorsi (modifica i valori tra virgolette) ed esegui:

```bash
ART="/percorso/AIManager-nuova.tar.gz"      # l'archivio da installare
DEST="/percorso/installazione"              # cartella dove installare
APP="$DEST/$(basename "$ART" .tar.gz)"       # cartella di destinazione
[ -e "$APP" ] && { echo "Destinazione gia' esistente: $APP (rimuovila o scegli un'altra)" >&2; exit 1; }
mkdir -p "$DEST"
tar -xzf "$ART" -C "$DEST"                    # cosi' non puo' fondersi con una copia precedente
cd "$APP"
cp .env.example .env                          # crea il file locale; le chiavi si configurano dalla UI
bin/launch.sh                                 # avvia su http://127.0.0.1:8000
```

Al primo avvio il DB SQLite viene creato in `storage/database/` e le migrazioni sono applicate.
Apri **Provider**, configura e attiva almeno un provider, esegui **Test** e infine **Salva**. La
modifica manuale di `.env` serve soltanto per troubleshooting: nel percorso normale le credenziali
si inseriscono dalla UI e restano mascherate.

## Aggiornamento side-by-side (ad app FERMA)

L'aggiornamento è **manuale e affiancato**: la nuova versione vive in una cartella nuova, i dati
restano tuoi. Imposta i percorsi (modifica i valori tra virgolette):

```bash
OLD="/percorso/AIManager-vecchia"           # installazione attuale
NEWART="/percorso/AIManager-nuova.tar.gz"   # archivio della nuova versione
DEST="/percorso"                            # dove estrarre la nuova, accanto alla vecchia
```

1. **Ferma** l'app in esecuzione (pulsante Stop nell'app).
2. **Backup del DB con la versione VECCHIA** (che conosce lo schema attuale):
   ```bash
   ( cd "$OLD" && php bin/backup.php )       # stampa percorso e SHA-256 del backup verificato
   ```
3. **Estrai la nuova** accanto alla vecchia (calcola prima la destinazione e rifiuta se esiste già,
   così `tar` non può fondersi con una copia precedente):
   ```bash
   NEW="$DEST/$(basename "$NEWART" .tar.gz)"
   [ -e "$NEW" ] && { echo "Destinazione gia' esistente: $NEW (rimuovila o scegli un'altra)" >&2; exit 1; }
   tar -xzf "$NEWART" -C "$DEST"
   ```
4. **Porta dati e segreti** dalla vecchia alla nuova, preservandoli. Il `/.` copia il **contenuto** di
   `storage/` (non la directory), così non si crea `storage/storage`:
   ```bash
   cp -p "$OLD/.env" "$NEW/.env"
   cp -Rp "$OLD/storage/." "$NEW/storage/"
   ```
5. **Primo boot** della nuova:
   ```bash
   ( cd "$NEW" && bin/launch.sh )
   ```
   Al boot, se ci sono migrazioni pendenti su un DB preesistente, viene creato **automaticamente** un
   backup verificato prima di migrare (`storage/backups/`).
6. **Controlli**: la health deve rispondere `AIManager:ok`, poi controlla il log:
   ```bash
   curl -s http://127.0.0.1:8000/system/health; echo
   tail -n 40 "$NEW/storage/logs/server.log"
   ```

## Rollback

1. **Ferma** la nuova versione.
2. **Riavvia la directory precedente**:
   ```bash
   ( cd "$OLD" && bin/launch.sh )
   ```
   La cartella vecchia conserva **codice e DB coerenti fra loro** (lo stato di prima
   dell'aggiornamento).

> **Avvertenza**: l'attività svolta **dopo** l'aggiornamento resta nella **nuova** copia (nel suo
> `storage/`). Il rollback torna allo stato del backup pre-aggiornamento: eventuali sessioni, memorie o
> file prodotti nella nuova versione non sono presenti nella vecchia.

## Dati e confini

- I tuoi dati sono in `storage/` e in `.env`: **non** entrano mai nell'artefatto.
- I **workspace Code esterni** (cartelle che apri con Code) sono tuoi e **non** vengono mai copiati né
  modificati da build, backup o aggiornamento.
