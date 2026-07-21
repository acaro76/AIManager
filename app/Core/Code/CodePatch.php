<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.2. La patch CANONICA, versionata e a vocabolario chiuso: l'artefatto
 * verificato prodotto da CodePatchValidator, con gli hash calcolati dal server. È la fonte di
 * verità dell'APPLICAZIONE e del DIGEST usato dalla conferma.
 *
 * Il DIFF unificato è una vista DERIVATA (UnifiedDiff) e non fa parte della forma canonica: non
 * è mai un input eseguibile, solo qualcosa da mostrare all'utente.
 *
 * Il `digest()` è l'impronta dell'INTERA proposta (versione + operazioni canoniche). Qualsiasi
 * variazione della patch — un percorso, una modifica, un hash — cambia il digest: la conferma
 * lega `operation_id` + `patch_digest`, quindi una patch modificata richiede una nuova conferma.
 */
final class CodePatch
{
    public const VERSION = 1;

    /** @var list<CodePatchOp> */
    public readonly array $operations;

    /** @param list<CodePatchOp> $operations */
    public function __construct(array $operations)
    {
        if ($operations === []) {
            throw new \InvalidArgumentException('Una patch deve contenere almeno un\'operazione.');
        }
        $this->operations = array_values($operations);
    }

    /**
     * Forma canonica dell'intera patch (versione + operazioni). Deterministica: stesso input →
     * stessa struttura → stesso JSON → stesso digest.
     *
     * @return array{version: int, operations: list<array<string, mixed>>}
     */
    public function toCanonical(): array
    {
        return [
            'version' => self::VERSION,
            'operations' => array_map(
                static fn (CodePatchOp $op): array => $op->toCanonical(),
                $this->operations
            ),
        ];
    }

    /**
     * JSON canonico (UTF-8, slash non escapati) — base del digest. Deterministico.
     */
    public function toCanonicalJson(): string
    {
        $json = json_encode(
            $this->toCanonical(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        // In pratica non fallisce (i contenuti sono già passati da Utf8::clean nel validator),
        // ma un digest non deve mai dipendere da un json_encode non deterministico: fail closed.
        if (!is_string($json)) {
            throw new \RuntimeException('Serializzazione canonica della patch non riuscita.');
        }

        return $json;
    }

    /** Impronta SHA-256 dell'intera patch canonica: identità stabile per la conferma. */
    public function digest(): string
    {
        return hash('sha256', $this->toCanonicalJson());
    }

    /**
     * Ricostruisce la forma canonica dai soli METADATI (percorsi/tipi/hash), come li conserva il
     * repository in `files_json`. Serve alla verifica di COERENZA in fase di applicazione: il
     * digest ricalcolato dai metadati DEVE combaciare con quello memorizzato e con quello che il
     * client presenta alla conferma. Deterministica e identica a toCanonical().
     *
     * @param list<array{path: string, op: string, base_sha256: ?string, result_sha256: string}> $files
     * @return array{version: int, operations: list<array<string, mixed>>}
     */
    public static function canonicalFromMetadata(array $files): array
    {
        $operations = [];
        foreach ($files as $file) {
            if (($file['op'] ?? '') === self::opCreate()) {
                $operations[] = [
                    'op' => self::opCreate(),
                    'path' => (string) $file['path'],
                    'result_sha256' => (string) $file['result_sha256'],
                ];
                continue;
            }
            $operations[] = [
                'op' => CodePatchProposal::OP_UPDATE,
                'path' => (string) $file['path'],
                'base_sha256' => $file['base_sha256'] === null ? null : (string) $file['base_sha256'],
                'result_sha256' => (string) $file['result_sha256'],
            ];
        }

        return ['version' => self::VERSION, 'operations' => $operations];
    }

    /**
     * @param list<array{path: string, op: string, base_sha256: ?string, result_sha256: string}> $files
     */
    public static function digestFromMetadata(array $files): string
    {
        $json = json_encode(
            self::canonicalFromMetadata($files),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($json)) {
            throw new \RuntimeException('Serializzazione canonica dai metadati non riuscita.');
        }

        return hash('sha256', $json);
    }

    private static function opCreate(): string
    {
        return CodePatchProposal::OP_CREATE;
    }

    /**
     * Metadati (percorsi/tipi/hash) per il repository delle operazioni e l'audit. Mai contenuti.
     *
     * @return list<array{path: string, op: string, base_sha256: ?string, result_sha256: string}>
     */
    public function metadata(): array
    {
        return array_map(
            static fn (CodePatchOp $op): array => $op->toMetadata(),
            $this->operations
        );
    }

    /** Byte cumulativi del contenuto risultante (per il tetto complessivo). */
    public function totalNewBytes(): int
    {
        $total = 0;
        foreach ($this->operations as $op) {
            $total += strlen($op->newContent);
        }

        return $total;
    }
}
