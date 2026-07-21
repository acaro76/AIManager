<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/**
 * Fase 10 / Step 3 — backup SQLite COERENTE prima delle migrazioni. Protegge il DB dalle migrazioni
 * (NON è il backup completo di allegati, `.env` o workspace esterni). I backup VERIFICATI vengono
 * conservati senza alcuna retention (nessuna rotazione o cancellazione dei backup precedenti); l'unico
 * file che può essere eliminato è quello PARZIALE prodotto da un tentativo fallito, rimosso subito.
 *
 * Coerenza anche con WAL non checkpointato: si usa `VACUUM INTO` sulla STESSA connessione applicativa,
 * che vede lo stato completo (main + WAL). Il file risultante è un DB autonomo, che viene poi
 * verificato (`integrity_check` + `foreign_key_check`) e sommato con SHA-256. Se qualcosa fallisce si
 * lancia un'eccezione: il chiamante NON deve avviare le migrazioni.
 */
final class SqliteBackupService
{
    public function __construct(
        private readonly Database $db,
        private readonly string $backupDir,
    ) {
    }

    /**
     * Crea, mette in sicurezza e verifica il backup. Ritorna percorso e SHA-256.
     *
     * @return array{path: string, sha256: string}
     */
    public function backup(): array
    {
        $this->prepareDirectory();

        // Nome SERVER-SIDE, nessun input utente: timestamp + suffisso casuale (unicità).
        $name = 'aimanager-pre-migration-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
        $path = $this->backupDir . '/' . $name;
        if (is_link($path) || file_exists($path)) {
            throw new \RuntimeException('Percorso di backup non disponibile.');
        }

        // Da qui in poi il file può essere stato creato: se un passo qualsiasi (VACUUM INTO, permessi,
        // verifica, SHA) fallisce, si elimina ESCLUSIVAMENTE questo file appena generato — nessun altro
        // backup viene toccato e non si introduce alcuna retention.
        try {
            // VACUUM INTO sulla connessione applicativa: backup coerente anche con WAL non checkpointato.
            $stmt = $this->db->pdo()->prepare('VACUUM INTO ?');
            $stmt->execute([$path]);

            if (!is_file($path)) {
                throw new \RuntimeException('Backup SQLite non creato.');
            }
            $this->restrict($path, 0600);

            $this->verify($path);

            $sha = hash_file('sha256', $path);
            if (!is_string($sha)) {
                throw new \RuntimeException('SHA-256 del backup non calcolabile.');
            }
        } catch (\Throwable $e) {
            if (is_file($path)) {
                @unlink($path);
            }
            throw $e;
        }

        return ['path' => $path, 'sha256' => $sha];
    }

    /** Directory backup a 0700, mai un symlink. */
    private function prepareDirectory(): void
    {
        if (is_link($this->backupDir)) {
            throw new \RuntimeException('Directory di backup non sicura (symlink).');
        }
        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0700, true) && !is_dir($this->backupDir)) {
                throw new \RuntimeException('Impossibile creare la directory dei backup.');
            }
        }
        $this->restrict($this->backupDir, 0700);
    }

    /** Verifica il backup su una connessione al file: integrità e FK devono essere puliti. */
    private function verify(string $path): void
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $integrity = (string) ($pdo->query('PRAGMA integrity_check')->fetch()['integrity_check'] ?? '');
        $fkFailures = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        $pdo = null;

        if ($integrity !== 'ok' || $fkFailures !== []) {
            throw new \RuntimeException('Backup non verificato (integrità o foreign key).');
        }
    }

    private function restrict(string $path, int $mode): void
    {
        @chmod($path, $mode);
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== $mode) {
            throw new \RuntimeException('Permessi del backup non restringibili.');
        }
    }
}
