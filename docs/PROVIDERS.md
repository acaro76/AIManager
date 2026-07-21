# Guida Provider

AIManager richiede almeno un provider AI funzionante. Puoi lavorare con un modello locale tramite
LM Studio, con un servizio cloud, oppure abilitarne più di uno e lasciare ad AIManager routing e
fallback.

## Scelta rapida

- **LM Studio**: i prompt restano sul Mac, ma servono RAM, un modello compatibile e LM Studio
  installato e avviato separatamente.
- **Cloud**: configurazione più immediata e modelli generalmente più capaci; prompt e allegati
  necessari lasciano la macchina e possono generare costi.
- **Più provider**: aumenta la continuità del servizio. Inizia comunque da uno solo e aggiungi gli
  altri soltanto quando il primo percorso è stabile.

Prezzi, limiti e nomi dei modelli cambiano: controlla sempre il sito ufficiale del provider prima di
attivare billing o scegliere un modello.

## Attivazione dalla UI

1. Apri **Provider** e scegli una scheda.
2. Verifica endpoint e modello.
3. Per un provider cloud inserisci la tua chiave API; per LM Studio lascia la chiave vuota.
4. Attiva il toggle **Attivo**.
5. Premi **Test**. Il test usa anche i valori appena inseriti senza salvarli.
6. Se il test riesce, premi **Salva**.
7. Torna alla Dashboard e apri **Nuova chat**.

La chiave viene scritta nel `.env` locale con permessi restrittivi e l'interfaccia la mostra in forma
mascherata. Lasciare vuoto il campo chiave conserva quella già salvata.

## LM Studio

1. Installa LM Studio dal progetto ufficiale.
2. Scarica e carica un modello adatto alla memoria disponibile.
3. Avvia il server OpenAI-compatible di LM Studio.
4. In AIManager apri **Provider → LM Studio**.
5. Usa normalmente `http://localhost:1234/v1`, premi **Modelli**, scegli il modello, quindi **Test** e
   **Salva**.

LM Studio non è incluso in AIManager. Se il test indica endpoint non raggiungibile, verifica che il
server sia avviato e ascolti sulla porta configurata. Se segnala che non esiste un modello, caricane
uno in LM Studio.

## Provider cloud supportati

La UI include OpenAI, Anthropic Claude, Gemini, DeepSeek, Groq, OpenRouter, Cerebras e Agnes AI.
Ogni chiave deve provenire dal tuo account presso il relativo servizio. Per un errore di test:

- **chiave mancante o non valida**: crea o sostituisci la credenziale;
- **modello non disponibile**: usa **Modelli** oppure verifica il catalogo ufficiale;
- **quota o billing**: controlla limiti e fatturazione sul provider;
- **endpoint non raggiungibile**: ripristina l'endpoint predefinito e verifica la rete;
- **timeout**: riprova e aumenta il timeout soltanto se il servizio risponde lentamente.

## Web e immagini

La ricerca web usa Tavily e richiede una chiave dedicata. La generazione immagini può usare
Cloudflare Workers AI o Gemini. Sono capacità opzionali: la chat testuale funziona senza di esse.

## Privacy

Un provider locale evita l'invio del prompt a un servizio cloud. Con un provider cloud, AIManager
invia il contesto necessario alla richiesta selezionata. Non inserire segreti nei prompt e consulta
le condizioni del servizio scelto.
