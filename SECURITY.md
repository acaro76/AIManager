# Security Policy

## Versioni supportate

Durante la fase iniziale è supportata soltanto l'ultima release pubblicata. Le installazioni locali
non si aggiornano automaticamente.

## Segnalare una vulnerabilità

Non aprire un issue pubblico con credenziali, dati personali o istruzioni di exploit. Usa la scheda
**Security** del repository → **Report a vulnerability**. Se l'opzione non è disponibile, segnala il
problema privatamente al proprietario del progetto.

Indica versione o commit, impatto, prerequisiti e una riproduzione minima priva di dati reali. Non
allegare `.env`, database, conversazioni, log completi o contenuti di workspace.

## Confini del prodotto

- AIManager è destinato a macOS, uso locale e singolo utente.
- Il server ascolta soltanto su `127.0.0.1` e non va esposto in rete.
- Code limita percorsi e strumenti, ma non è una sandbox del sistema operativo.
- Comandi, processi e operazioni mutanti richiedono conferma; non esiste push Git implicito.
- I provider cloud ricevono i dati necessari alla richiesta e restano soggetti alle proprie policy.

Il repository pubblico non deve contenere `.env`, `storage/`, database, backup, log, allegati,
artefatti locali o la cronologia del repository storico.
