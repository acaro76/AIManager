<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.7. Journal WRITE-AHEAD del rollback locale, INDIPENDENTE da Git.
 *
 * Prima di scrivere qualunque file, l'applicazione registra QUI, in un unico file atomico e
 * fsync-ato, i PREIMAGE (i byte originali dei file da modificare) più i percorsi relativi e gli
 * hash attesi. Il journal attraversa tre FASI:
 *   - `prepared`     : applicazione avviata, non ancora conclusa;
 *   - `committed`    : applicazione conclusa e verificata (preimage conservati per un futuro rollback);
 *   - `rolling_back` : rollback avviato, non ancora concluso.
 *
 * Da qui discende la crash-safety richiesta (nessuno stato multi-file parziale):
 *  - un journal `prepared` interrotto → si COMPENSA (ripristino dei preimage) e l'operazione torna
 *    `failed`: l'applicazione non è mai rimasta a metà;
 *  - un journal `rolling_back` interrotto → si COMPLETA il ripristino dei preimage e l'operazione
 *    diventa `rolled_back`;
 *  - un journal `committed` resta in attesa di un eventuale rollback su richiesta dell'utente.
 * In tutte e tre lo stato-obiettivo della compensazione è identico: il PRE-APPLICAZIONE.
 *
 * I preimage sono in base64 per preservare i byte esatti. Il file vive sotto
 * `storage/code_patches/journal/`, con permessi ristretti; è pura I/O, non conosce il confine
 * (lo impone il chiamante ri-risolvendo i percorsi relativi via CodeWorkspace).
 */
final class CodePatchJournal
{
    public const PHASE_PREPARED = 'prepared';
    public const PHASE_COMMITTED = 'committed';
    public const PHASE_ROLLING_BACK = 'rolling_back';

    /** Fasi che indicano un'operazione INTERROTTA, da recuperare alla prossima mutazione. */
    public const PENDING_PHASES = [self::PHASE_PREPARED, self::PHASE_ROLLING_BACK];

    private readonly string $dir;

    public function __construct(string $baseDir)
    {
        $this->dir = rtrim($baseDir, '/') . '/journal';
    }

    /**
     * Registra il journal (fase `prepared`) in modo atomico e durevole PRIMA di ogni scrittura.
     *
     * @param list<array{op: string, rel_path: string, base_sha256: ?string, result_sha256: string, preimage: ?string, mode: int}> $entries
     *        `preimage` = byte originali (update) o null (create); `mode` = permessi originali.
     */
    public function prepare(string $operationId, int $workspaceId, array $entries): void
    {
        $this->ensureDir();
        $this->save([
            'operation_id' => $operationId,
            'workspace_id' => $workspaceId,
            'phase' => self::PHASE_PREPARED,
            'created_at' => date('c'),
            'entries' => $this->encodeEntries($entries),
        ]);
    }

    /** Applicazione conclusa e verificata: fase `committed` (preimage conservati per il rollback). */
    public function markApplied(string $operationId): void
    {
        $this->rephase($operationId, self::PHASE_COMMITTED);
    }

    /** Rollback avviato: fase `rolling_back` (un crash qui verrà COMPLETATO dal recovery). */
    public function markRollingBack(string $operationId): void
    {
        $this->rephase($operationId, self::PHASE_ROLLING_BACK);
    }

    /**
     * @return array{operation_id: string, workspace_id: int, phase: string, entries: list<array{op: string, rel_path: string, base_sha256: ?string, result_sha256: string, preimage: ?string, mode: int}>}|null
     */
    public function read(string $operationId): ?array
    {
        $file = $this->file($operationId);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            return null;
        }

        $entries = [];
        foreach ($data['entries'] as $e) {
            if (!is_array($e)) {
                return null;
            }
            $preimage = null;
            if (isset($e['preimage_b64']) && $e['preimage_b64'] !== null) {
                $decoded = base64_decode((string) $e['preimage_b64'], true);
                if ($decoded === false) {
                    return null;
                }
                $preimage = $decoded;
            }
            $entries[] = [
                'op' => (string) ($e['op'] ?? ''),
                'rel_path' => (string) ($e['rel_path'] ?? ''),
                'base_sha256' => isset($e['base_sha256']) && $e['base_sha256'] !== null ? (string) $e['base_sha256'] : null,
                'result_sha256' => (string) ($e['result_sha256'] ?? ''),
                'preimage' => $preimage,
                'mode' => isset($e['mode']) ? (int) $e['mode'] : 0644,
            ];
        }

        return [
            'operation_id' => (string) ($data['operation_id'] ?? ''),
            'workspace_id' => (int) ($data['workspace_id'] ?? 0),
            'phase' => (string) ($data['phase'] ?? self::PHASE_PREPARED),
            'entries' => $entries,
        ];
    }

    public function discard(string $operationId): void
    {
        @unlink($this->file($operationId));
    }

    public function exists(string $operationId): bool
    {
        return is_file($this->file($operationId));
    }

    /**
     * Journal INTERROTTI (fasi `prepared`/`rolling_back`) di un workspace: da recuperare alla
     * prossima mutazione dello stesso workspace.
     *
     * @return list<array{operation_id: string, phase: string}>
     */
    public function pendingForWorkspace(int $workspaceId): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }
        $out = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $phase = (string) ($data['phase'] ?? '');
            if ((int) ($data['workspace_id'] ?? 0) === $workspaceId && in_array($phase, self::PENDING_PHASES, true)) {
                $out[] = ['operation_id' => (string) ($data['operation_id'] ?? ''), 'phase' => $phase];
            }
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['operation_id'], $b['operation_id']));

        return $out;
    }

    private function rephase(string $operationId, string $phase): void
    {
        $record = $this->read($operationId);
        if ($record === null) {
            throw new \RuntimeException('Journal assente al cambio di fase.');
        }
        $this->save([
            'operation_id' => $record['operation_id'],
            'workspace_id' => $record['workspace_id'],
            'phase' => $phase,
            'created_at' => date('c'),
            'entries' => $this->encodeEntries($record['entries']),
        ]);
    }

    /**
     * @param list<array{op: string, rel_path: string, base_sha256: ?string, result_sha256: string, preimage: ?string, mode: int}> $entries
     * @return list<array<string, mixed>>
     */
    private function encodeEntries(array $entries): array
    {
        $encoded = [];
        foreach ($entries as $entry) {
            $encoded[] = [
                'op' => (string) $entry['op'],
                'rel_path' => (string) $entry['rel_path'],
                'base_sha256' => $entry['base_sha256'],
                'result_sha256' => (string) $entry['result_sha256'],
                'preimage_b64' => $entry['preimage'] === null ? null : base64_encode((string) $entry['preimage']),
                'mode' => (int) ($entry['mode'] ?? 0644),
            ];
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function save(array $record): void
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Serializzazione del journal non riuscita.');
        }
        AtomicFileWriter::replace($this->file((string) $record['operation_id']), $json, 0600);
    }

    private function file(string $operationId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $operationId) !== 1) {
            throw new \InvalidArgumentException('Identificativo di operazione non valido.');
        }

        return $this->dir . '/' . $operationId . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Impossibile creare la directory del journal delle patch.');
        }
    }
}
