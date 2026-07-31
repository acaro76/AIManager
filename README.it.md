[English](README.md) · **Italiano** · [Español](README.es.md)

# AIManager

**AIManager permette di lavorare con molteplici intelligenze artificiali da un unico
ambiente: modelli residenti sul proprio computer e servizi esterni gratuiti o a
pagamento.**

Conserva localmente conversazioni, progetti, documenti, istruzioni, contesto e memoria.
Puoi continuare lo stesso lavoro passando da un'intelligenza artificiale all'altra,
senza ricominciare da zero e senza dipendere da un solo fornitore. AIManager può scegliere
l'intelligenza artificiale più adatta e usarne un'altra quando la principale non è
disponibile.

## Requisiti

- macOS, per uso locale e singolo utente;
- PHP 8.5 con SQLite, cURL e mbstring;
- Git;
- almeno un fornitore: una chiave personale per un servizio esterno oppure
  [LM Studio](https://lmstudio.ai/download) per usare modelli locali.

Le funzioni **Code** richiedono anche le estensioni PHP `pcntl` e `posix`. Con i servizi
esterni non serve un modello locale. Per LM Studio, memoria e spazio necessari dipendono
dal modello scelto; un Mac mini M2 Pro con 16 GB di memoria unificata è una configurazione
di riferimento pratica per modelli locali di dimensioni contenute.

## Scaricamento

```bash
git clone https://github.com/acaro76/AIManager.git
cd AIManager
```

## Installazione

```bash
bash bin/install.sh
```

Il comando verifica i requisiti e prepara la configurazione locale senza chiedere né
mostrare chiavi.

## Avvio

```bash
bash bin/launch.sh
```

AIManager si apre nel browser all'indirizzo <http://127.0.0.1:8000>.

## Primo utilizzo

1. Apri **Fornitori**.
2. Scegli LM Studio oppure un servizio esterno.
3. Inserisci la tua chiave soltanto se il servizio la richiede.
4. Attiva il fornitore, esegui **Test** e premi **Salva**.
5. Apri **Nuova chat**.

AIManager rileva automaticamente i modelli disponibili in LM Studio. Le credenziali si
configurano dall'interfaccia e restano nella configurazione locale.

## Cosa puoi fare

- conversazioni progressive con instradamento e alternativa automatica tra fornitori;
- progetti, sessioni, memoria e continuità del contesto;
- ricerca web, allegati e generazione di immagini;
- configurazione e verifica dei fornitori dall'interfaccia;
- lavoro assistito su cartelle autorizzate con **Code**, incluse letture mirate, proposte
  di modifica, verifiche controllate e Git locale.

**Code non è una barriera di sicurezza del sistema operativo.** Le operazioni che
modificano file richiedono conferma; non esistono una riga di comando generale né una
pubblicazione Git implicita.

## Dati e controllo

- `.env` contiene le credenziali e resta locale;
- `storage/` contiene banca dati, conversazioni, memorie, allegati, registri e copie di
  sicurezza;
- le cartelle aperte in Code restano esterne ad AIManager;
- `.env`, dati di esecuzione, copie di sicurezza e cartelle di lavoro non devono essere
  pubblicati nel deposito.

Consulta la [guida dei fornitori](docs/PROVIDERS.md), la
[guida utente](docs/USER_GUIDE.md) e le istruzioni per
[aggiornamento e ripristino](docs/RELEASE.md). Per i confini di sicurezza consulta
[SECURITY.md](SECURITY.md).

## Licenza

AIManager è distribuito con licenza [Apache License 2.0](LICENSE).

---

Sviluppato da [Gennari Productions](https://gennari.es/) —
[Alessandro Gennari](https://gennari.es/alessandro-gennari.html), consulente AI,
Las Palmas de Gran Canaria.
