<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 9 / Step 1. CONTRATTO di dominio della MEMORIA DI LAVORO di Code.
 *
 * Value object IMMUTABILE, ancora NON collegato al runtime (nessun DB, nessun repository,
 * nessun servizio, nessuna route). È solo la forma canonica di ciò che una sessione Code può
 * conservare per riprendere il lavoro: contesto CURATO — obiettivo, file rilevanti, decisioni,
 * TODO, ecc. Le liste sono `list<string>`: il contratto NON prevede campi raw dedicati (repository,
 * contenuto file, diff, output, log); che una singola stringa curata resti pulita è responsabilità
 * del riepilogatore (Step 3), non una garanzia di questo contratto.
 *
 * REGOLE DI SICUREZZA (fail-closed, tutte applicate in {@see self::fromArray()}):
 *  - vocabolario CHIUSO per `state`; un valore fuori vocabolario è rifiutato;
 *  - chiavi SCONOSCIUTE rifiutate: non esiste alcun campo raw dedicato (`content`, `diff`, `output`,
 *    `log`, `digest`, `pid`… non sono chiavi del payload). Questo blocca i campi, non il testo che
 *    il riepilogatore sceglierà di mettere nelle stringhe curate;
 *  - tipi errati rifiutati (scalari non-stringa, liste non-lista, item non-stringa);
 *  - i percorsi in `relevant_files` passano da {@see RelativePath} (relativi e canonici: niente
 *    assoluti, `..`, backslash, NUL) e da {@see SensitivePathPolicy} (niente `.env`, chiavi, `.git`…);
 *  - ogni stringa è UTF-8 CANONICO (byte non validi ⇒ rifiuto, non pulizia silenziosa) e senza NUL;
 *  - limiti numerici di cardinalità e lunghezza per campo, più un tetto COMPLESSIVO di 16 KiB sul
 *    JSON canonico;
 *  - deduplica DETERMINISTICA che conserva l'ordine della PRIMA occorrenza (non riordina).
 *
 * La serializzazione JSON ({@see self::toJson()}) è canonica e deterministica: chiavi in ordine
 * FISSO, UTF-8 non-escapato, slash non-escapati. `fromJson(toJson())` è un round-trip stabile.
 *
 * I messaggi d'errore NON riportano il valore ricevuto: un item potrebbe essere un percorso
 * sensibile o testo ostile, e finirebbe altrimenti nei log.
 */
final class CodeWorkingMemory
{
    /** Versione dello schema del contratto. Emessa sempre, accettata solo se === 1. */
    public const SCHEMA_VERSION = 1;

    /** Tetto COMPLESSIVO sul JSON canonico serializzato (byte). */
    public const MAX_PAYLOAD_BYTES = 16384;

    // Limiti di LUNGHEZZA (byte) delle singole stringhe.
    private const MAX_OBJECTIVE_BYTES = 500;
    private const MAX_ITEM_BYTES = 300;
    private const MAX_PATH_BYTES = 400;

    // Limiti di CARDINALITÀ delle liste (dopo deduplica).
    private const MAX_RELEVANT_FILES = 20;
    private const MAX_DECISIONS = 12;
    private const MAX_APPLIED_CHANGES = 20;
    private const MAX_VERIFICATIONS = 12;
    private const MAX_ACTIVE_PROCESSES = 5;
    private const MAX_TODOS = 12;
    private const MAX_PROVIDERS = 5;
    private const MAX_UNRESOLVED_ERRORS = 8;
    private const MAX_DURABLE_FACTS = 20;

    /** @var list<string> Vocabolario CHIUSO di `state`: stato del lavoro descritto dalla memoria. */
    public const STATES = ['active', 'blocked', 'completed'];

    /** @var list<string> Chiavi AMMESSE nel payload. Qualunque altra è rifiutata (fail-closed). */
    private const KNOWN_KEYS = [
        'schema_version', 'objective', 'state', 'relevant_files', 'decisions',
        'applied_changes', 'verifications', 'active_processes', 'todos', 'providers',
        'unresolved_errors', 'durable_facts',
    ];

    /**
     * @param list<string> $relevantFiles percorsi relativi, canonici e non sensibili
     * @param list<string> $decisions
     * @param list<string> $appliedChanges descrizioni curate (testo libero, non un campo raw)
     * @param list<string> $verifications esiti curati (testo libero, non un campo raw)
     * @param list<string> $activeProcesses descrizioni curate (testo libero, non un campo raw)
     * @param list<string> $todos
     * @param list<string> $providers
     * @param list<string> $unresolvedErrors descrizioni curate (testo libero, non un campo raw)
     * @param list<string> $durableFacts fatti/vincoli architetturali stabili
     */
    private function __construct(
        public readonly string $objective,
        public readonly string $state,
        public readonly array $relevantFiles,
        public readonly array $decisions,
        public readonly array $appliedChanges,
        public readonly array $verifications,
        public readonly array $activeProcesses,
        public readonly array $todos,
        public readonly array $providers,
        public readonly array $unresolvedErrors,
        public readonly array $durableFacts,
    ) {
    }

    /**
     * Costruisce e VALIDA la memoria da un payload associativo. Unico ingresso: tutte le regole
     * di sicurezza vivono qui.
     *
     * @param array<string, mixed> $payload
     * @param SensitivePathPolicy|null $policy policy dei file sensibili (default condiviso)
     * @throws \InvalidArgumentException fail-closed su qualsiasi violazione
     */
    public static function fromArray(array $payload, ?SensitivePathPolicy $policy = null): self
    {
        $policy ??= new SensitivePathPolicy();

        foreach (array_keys($payload) as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                throw new \InvalidArgumentException('Chiave di memoria sconosciuta.');
            }
        }

        if (array_key_exists('schema_version', $payload) && $payload['schema_version'] !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('schema_version non supportata.');
        }

        $memory = new self(
            objective: self::scalarText($payload, 'objective', self::MAX_OBJECTIVE_BYTES),
            state: self::enum($payload, 'state', self::STATES, 'active'),
            relevantFiles: self::paths($payload, 'relevant_files', self::MAX_RELEVANT_FILES, $policy),
            decisions: self::stringList($payload, 'decisions', self::MAX_DECISIONS),
            appliedChanges: self::stringList($payload, 'applied_changes', self::MAX_APPLIED_CHANGES),
            verifications: self::stringList($payload, 'verifications', self::MAX_VERIFICATIONS),
            activeProcesses: self::stringList($payload, 'active_processes', self::MAX_ACTIVE_PROCESSES),
            todos: self::stringList($payload, 'todos', self::MAX_TODOS),
            providers: self::stringList($payload, 'providers', self::MAX_PROVIDERS),
            unresolvedErrors: self::stringList($payload, 'unresolved_errors', self::MAX_UNRESOLVED_ERRORS),
            durableFacts: self::stringList($payload, 'durable_facts', self::MAX_DURABLE_FACTS),
        );

        // Tetto COMPLESSIVO: si misura sul JSON canonico effettivo (ciò che verrà persistito).
        if (strlen($memory->toJson()) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('Memoria oltre il limite complessivo di 16 KiB.');
        }

        return $memory;
    }

    /**
     * Ingresso dal PERSISTITO: a differenza di {@see self::fromArray()} (comoda per la costruzione
     * interna), qui `schema_version` è OBBLIGATORIA e deve valere 1. Un JSON senza versione o con
     * versione diversa fallisce: così un payload legacy o corrotto non viene interpretato a caso.
     *
     * @throws \InvalidArgumentException JSON non valido o payload che viola il contratto
     */
    public static function fromJson(string $json, ?SensitivePathPolicy $policy = null): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Memoria: JSON non valido.');
        }
        if (!is_array($data) || ($data !== [] && !self::isAssoc($data))) {
            throw new \InvalidArgumentException('Memoria: il payload deve essere un oggetto.');
        }
        if (!array_key_exists('schema_version', $data) || $data['schema_version'] !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('Memoria: schema_version mancante o non supportata.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data, $policy);
    }

    /**
     * Rappresentazione canonica: chiavi in ordine FISSO, schema_version sempre presente.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'objective' => $this->objective,
            'state' => $this->state,
            'relevant_files' => $this->relevantFiles,
            'decisions' => $this->decisions,
            'applied_changes' => $this->appliedChanges,
            'verifications' => $this->verifications,
            'active_processes' => $this->activeProcesses,
            'todos' => $this->todos,
            'providers' => $this->providers,
            'unresolved_errors' => $this->unresolvedErrors,
            'durable_facts' => $this->durableFacts,
        ];
    }

    /** JSON canonico e DETERMINISTICO (ordine fisso, UTF-8 e slash non-escapati). */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    // --- validazione dei campi (privata) ---------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $vocab
     */
    private static function enum(array $payload, string $key, array $vocab, string $default): string
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }
        $value = $payload[$key];
        if (!is_string($value) || !in_array($value, $vocab, true)) {
            throw new \InvalidArgumentException("Valore fuori vocabolario per \"{$key}\".");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private static function scalarText(array $payload, string $key, int $maxBytes): string
    {
        if (!array_key_exists($key, $payload)) {
            return '';
        }

        return self::text($payload[$key], $maxBytes, $key);
    }

    /**
     * Lista di stringhe curate: tipo lista, ogni item stringa canonica entro il limite, deduplica
     * che conserva l'ordine della prima occorrenza, cardinalità entro il tetto.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function stringList(array $payload, string $key, int $maxItems): array
    {
        $raw = self::rawList($payload, $key);
        $items = [];
        foreach ($raw as $entry) {
            $items[] = self::text($entry, self::MAX_ITEM_BYTES, $key);
        }
        $unique = self::dedup($items);
        if (count($unique) > $maxItems) {
            throw new \InvalidArgumentException("Troppi elementi in \"{$key}\".");
        }

        return $unique;
    }

    /**
     * Lista di PERCORSI: come stringList, ma ogni item è validato relativo/canonico e non sensibile.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function paths(array $payload, string $key, int $maxItems, SensitivePathPolicy $policy): array
    {
        $raw = self::rawList($payload, $key);
        $items = [];
        foreach ($raw as $entry) {
            $path = self::text($entry, self::MAX_PATH_BYTES, $key);
            try {
                RelativePath::assert($path);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException("Percorso non relativo/canonico in \"{$key}\".");
            }
            if ($policy->isSensitive($path)) {
                throw new \InvalidArgumentException("Percorso sensibile non ammesso in \"{$key}\".");
            }
            $items[] = $path;
        }
        $unique = self::dedup($items);
        if (count($unique) > $maxItems) {
            throw new \InvalidArgumentException("Troppi percorsi in \"{$key}\".");
        }

        return $unique;
    }

    /**
     * Estrae una lista (JSON array ⇒ lista PHP). Assente ⇒ []. Un oggetto o un non-array è un tipo
     * errato: fail-closed.
     *
     * @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private static function rawList(array $payload, string $key): array
    {
        if (!array_key_exists($key, $payload)) {
            return [];
        }
        $raw = $payload[$key];
        if (!is_array($raw) || ($raw !== [] && !array_is_list($raw))) {
            throw new \InvalidArgumentException("Il campo \"{$key}\" deve essere una lista.");
        }

        return $raw;
    }

    /**
     * Una stringa CURATA: string, senza NUL, UTF-8 CANONICO (byte non validi ⇒ rifiuto, non
     * pulizia), entro il limite di byte.
     */
    private static function text(mixed $value, int $maxBytes, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException("Il campo \"{$field}\" deve essere una stringa.");
        }
        if (strpos($value, "\0") !== false) {
            throw new \InvalidArgumentException("Il campo \"{$field}\" contiene un byte NUL.");
        }
        if (Utf8::clean($value) !== $value) {
            throw new \InvalidArgumentException("Il campo \"{$field}\" non è UTF-8 canonico.");
        }
        if (strlen($value) > $maxBytes) {
            throw new \InvalidArgumentException("Il campo \"{$field}\" supera il limite di lunghezza.");
        }

        return $value;
    }

    /**
     * Deduplica DETERMINISTICA che conserva l'ordine della PRIMA occorrenza (non riordina).
     *
     * @param list<string> $items
     * @return list<string>
     */
    private static function dedup(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if (!isset($seen[$item])) {
                $seen[$item] = true;
                $out[] = $item;
            }
        }

        return $out;
    }

    /** True se l'array ha almeno una chiave stringa (oggetto JSON) o è vuoto-assoc atteso. */
    private static function isAssoc(array $data): bool
    {
        return $data === [] || !array_is_list($data);
    }
}
