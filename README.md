# AIManager

AIManager è un centro AI locale per macOS che riunisce chat, progetti, memoria, provider multipli e
un ambiente Code controllato. I dati applicativi restano sulla macchina; quando scegli un provider
cloud, richieste e allegati necessari vengono inviati a quel servizio secondo le sue condizioni.

Il progetto ha superato il gate tecnico della prima release ed è usabile localmente. La distribuzione
attuale è un archivio manuale per macOS: non è una `.app`, non include installer, firma,
notarizzazione o aggiornamento automatico.

## Funzioni disponibili

- chat in streaming con routing e fallback fra provider;
- progetti, sessioni, memoria e continuità del contesto;
- ricerca web, allegati e generazione immagini opzionali;
- configurazione e test delle credenziali dalla UI Provider;
- Code su una cartella autorizzata, con letture mirate, proposte di modifica, verifiche curate,
  comandi di sola lettura, server PHP locale e Git assistito fino al commit locale.

Code non è una sandbox del sistema operativo. Le operazioni modificanti richiedono conferma e non
esiste una shell generale né un push Git implicito.

## Requisiti

- macOS, uso locale e singolo utente;
- PHP 8.5 con SQLite, cURL e mbstring;
- `pcntl` e `posix` per comandi Code e processi persistenti;
- almeno un provider AI: una chiave cloud personale oppure LM Studio installato separatamente.

## Avvio rapido

```bash
cp .env.example .env
bin/launch.sh
```

AIManager si apre su `http://127.0.0.1:8000`. Alla prima apertura:

1. entra in **Provider**;
2. scegli LM Studio oppure un provider cloud;
3. inserisci endpoint, modello e, se richiesta, la tua chiave;
4. attiva il provider, esegui **Test** e poi **Salva**;
5. apri **Nuova chat**.

Non è necessario compilare manualmente le chiavi in `.env`: la UI Provider le salva localmente.
Consulta [la guida Provider](docs/PROVIDERS.md) e [la guida utente](docs/USER_GUIDE.md) per il percorso
completo. Installazione, aggiornamento e rollback sono descritti in [RELEASE.md](docs/RELEASE.md).

## Dati e privacy

- `.env` contiene le credenziali ed è locale;
- `storage/` contiene database, conversazioni, memorie, allegati, log e backup;
- le cartelle aperte in Code restano esterne ad AIManager;
- `.env`, dati runtime, backup e workspace non devono entrare in commit o release.

Per i confini e la segnalazione responsabile dei problemi consulta [SECURITY.md](SECURITY.md).

## Stato e contributi

La priorità è rendere affidabile il primo utilizzo e validarlo con utenti esterni, non aggiungere
funzioni indiscriminatamente. Vedi la [roadmap pubblica](docs/PUBLIC_ROADMAP.md).

Prima di proporre modifiche leggi [CONTRIBUTING.md](CONTRIBUTING.md).

## Licenza

AIManager è distribuito con licenza [Apache License 2.0](LICENSE).

---

Sviluppato da [Gennari Productions](https://gennari.es) — [Alessandro Gennari](https://gennari.es/alessandro-gennari.html), AI Consultant, Las Palmas de Gran Canaria.
