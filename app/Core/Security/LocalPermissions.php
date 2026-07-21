<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Fase 10 / Step 2 — permessi locali RESTRITTIVI su dati e segreti. Su un'installazione locale
 * mono-utente i file sensibili non devono essere leggibili da altri utenti della macchina.
 *
 * Interventi PUNTUALI (mai ricorsivi, mai sui workspace esterni aperti da Code):
 *  - `.env` → `0600`;
 *  - radice `storage/` e directory del database → `0700`;
 *  - file SQLite → `0600`.
 *
 * I permessi sono REALMENTE garantiti: dopo il `chmod` si rilegge il mode effettivo e, se un percorso
 * ESISTENTE non risulta ristretto, si fallisce con `RuntimeException` (messaggio generico, nessun
 * percorso). I percorsi ASSENTI sono un no-op. Idempotente e compatibile con installazioni esistenti
 * (stringe i permessi, non tocca proprietà o contenuti).
 */
final class LocalPermissions
{
    public static function secureEnv(string $envPath): void
    {
        self::restrictFile($envPath, 0600);
    }

    /** Radice storage e directory database a 0700; file SQLite a 0600 (se già esistente). Nessuna ricorsione. */
    public static function secureStorage(string $storageRoot, string $databasePath): void
    {
        self::restrictDir($storageRoot, 0700);
        self::restrictDir(dirname($databasePath), 0700);
        self::restrictFile($databasePath, 0600);
    }

    /** Restringe il solo file SQLite a 0600 (usato subito dopo `new Database`, prima delle migrazioni). */
    public static function secureDatabaseFile(string $databasePath): void
    {
        self::restrictFile($databasePath, 0600);
    }

    private static function restrictFile(string $path, int $mode): void
    {
        if (!is_file($path)) {
            return; // percorso assente: no-op
        }
        self::apply($path, $mode);
    }

    private static function restrictDir(string $path, int $mode): void
    {
        if (!is_dir($path)) {
            return; // percorso assente: no-op
        }
        self::apply($path, $mode);
    }

    /** Applica e VERIFICA il mode effettivo: un percorso esistente non restringibile è un errore. */
    private static function apply(string $path, int $mode): void
    {
        $ok = @chmod($path, $mode);
        clearstatcache(true, $path);
        if ($ok === false || (fileperms($path) & 0777) !== $mode) {
            throw new \RuntimeException('Impossibile restringere i permessi di un percorso locale sensibile.');
        }
    }
}
