<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.2. Esito della validazione di sandbox di una proposta di patch.
 *
 * O la patch è INTERAMENTE valida (ok = true, `patch` presente, `entries` con diff per ogni file),
 * oppure è rifiutata (ok = false, `patch` = null, `reason` = primo motivo a vocabolario chiuso).
 * Non esistono patch "parzialmente applicabili": una proposta si accetta o si scarta tutta.
 *
 * I motivi sono un VOCABOLARIO CHIUSO: descrivono la CATEGORIA del problema (file cambiato,
 * sensibile, fuori confine, testo non trovato…) senza mai riportare contenuti dei file.
 */
final class CodePatchValidation
{
    public const SENSITIVE = 'sensitive';     // percorso protetto (.env, chiavi, DB, .git…)
    public const BLOCKED = 'blocked';         // fuori dalla root, symlink nel percorso, o revocato
    public const SYMLINK = 'symlink';         // il target è un symlink
    public const NOT_FOUND = 'not_found';     // update su file inesistente
    public const EXISTS = 'exists';           // create su percorso già occupato
    public const BINARY = 'binary';           // il file (o il contenuto) non è testo
    public const TOO_LARGE = 'too_large';     // oltre il tetto per file o complessivo
    public const NO_MATCH = 'no_match';       // "old" non presente nel file
    public const AMBIGUOUS = 'ambiguous';     // "old" presente più di una volta
    public const TOO_MANY_OPS = 'too_many_ops';

    /** @var list<string> */
    public const REASONS = [
        self::SENSITIVE, self::BLOCKED, self::SYMLINK, self::NOT_FOUND, self::EXISTS,
        self::BINARY, self::TOO_LARGE, self::NO_MATCH, self::AMBIGUOUS, self::TOO_MANY_OPS,
    ];

    /**
     * @param list<array{path: string, op: string, diff: string, added: int, removed: int}> $entries
     */
    private function __construct(
        public readonly bool $ok,
        public readonly ?CodePatch $patch,
        public readonly string $reason,
        public readonly array $entries,
    ) {
    }

    /**
     * @param list<array{path: string, op: string, diff: string, added: int, removed: int}> $entries
     */
    public static function valid(CodePatch $patch, array $entries): self
    {
        return new self(true, $patch, '', $entries);
    }

    public static function invalid(string $reason): self
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Motivo di validazione non ammesso.');
        }

        return new self(false, null, $reason, []);
    }
}
