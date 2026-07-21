# Guida utente

## Primo utilizzo

1. Avvia AIManager con `bin/launch.sh`.
2. Configura e testa almeno un provider seguendo [PROVIDERS.md](PROVIDERS.md).
3. Apri **Nuova chat** e invia una richiesta semplice.
4. Crea un progetto soltanto quando vuoi conservare contesto e memoria separati.

## Chat e progetti

La chat libera serve per richieste senza memoria di progetto. Un progetto raccoglie sessioni,
istruzioni e conoscenza durevole sullo stesso lavoro. AIManager conserva localmente conversazioni e
memorie; il provider riceve il contesto necessario a produrre la risposta.

Gli allegati testuali possono entrare nel contesto. Le immagini richiedono un provider con capacità
vision. La ricerca web e la generazione immagini sono opzionali e si configurano nella sezione
Provider.

## Code

Code lavora su una cartella scelta esplicitamente:

1. apri **Code**;
2. autorizza una cartella di progetto;
3. crea una sessione e descrivi l'obiettivo;
4. controlla ogni proposta prima di confermarla;
5. usa verifiche e stato Git per valutare il risultato.

AIManager può leggere file ammessi, proporre modifiche, eseguire verifiche curate, avviare il server
PHP locale ed assistere con staging e commit. Non offre una shell generale, non esegue push e non è
una sandbox del sistema operativo. Revocare una cartella blocca nuovi accessi ma conserva la
cronologia locale.

Prima di lavorare su un repository con modifiche preesistenti, controlla lo stato Git. AIManager
esclude dalle proprie operazioni i percorsi sensibili e runtime, ma resta responsabilità dell'utente
revisionare diff e conferme.

## Dove sono i dati

- credenziali: `.env`;
- database, conversazioni, memorie, allegati, log e backup: `storage/`;
- codice su cui lavora Code: cartelle esterne autorizzate dall'utente.

Non condividere `.env` o `storage/`. Per un aggiornamento usa la procedura side-by-side in
[RELEASE.md](RELEASE.md), che mantiene separati codice vecchio, codice nuovo e dati.

## Problemi comuni

- **AIManager non parte**: verifica PHP 8.5 e `storage/logs/server.log`.
- **Porta 8000 occupata**: ferma l'altro servizio oppure l'istanza AIManager già attiva.
- **Nessun provider disponibile**: abilita, testa e salva almeno un provider.
- **LM Studio offline**: avvia il server locale e carica un modello.
- **Risposta cloud rifiutata**: verifica chiave, quota, billing e modello.
- **Code non apre una cartella**: scegli una directory reale, leggibile e diversa dalla directory di
  AIManager.

I messaggi di errore mostrati all'utente evitano dettagli sensibili. Le informazioni tecniche restano
nei log locali.
