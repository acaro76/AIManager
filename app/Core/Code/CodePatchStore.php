<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.5. Deposito LOCALE del payload di una proposta di patch: il contenuto
 * risultante di ogni file e il diff da mostrare. Sta FUORI dal DB (che tiene solo metadati) e
 * fuori dal repository/worktree: vive sotto `storage/code_patches/payload/` (gitignored come i
 * backup), con permessi ristretti.
 *
 * Serve a due cose, entrambe LOCALI: ri-mostrare la card (proposta + diff) e APPLICARE la patch
 * quando l'utente conferma (scrivendo il contenuto risultante già calcolato). Il contenuto è
 * salvato in base64 per preservarne i byte ESATTI (l'hash `result_sha256` deve tornare al bit).
 *
 * Non è la fonte di verità del ciclo di vita (quello è il DB) né del rollback (quello è il
 * journal): è solo il "cosa scrivere", scritto in modo atomico (file temporaneo + rename).
 */
final class CodePatchStore
{
    private readonly string $dir;

    public function __construct(string $baseDir)
    {
        $this->dir = rtrim($baseDir, '/') . '/payload';
    }

    /**
     * Scrive il payload di una proposta. Atomico: file temporaneo nella stessa directory + rename.
     *
     * @param list<array{path: string, op: string, diff: string, added: int, removed: int}> $entries
     */
    public function write(string $operationId, CodePatch $patch, array $entries): void
    {
        $this->ensureDir();
        $operations = [];
        foreach ($patch->operations as $i => $op) {
            $entry = $entries[$i] ?? ['diff' => '', 'added' => 0, 'removed' => 0];
            $operations[] = [
                'op' => $op->kind,
                'path' => $op->path,
                'base_sha256' => $op->baseSha256,
                'result_sha256' => $op->resultSha256,
                'new_content_b64' => base64_encode($op->newContent),
                'diff_b64' => base64_encode((string) $entry['diff']),
                'added' => (int) $entry['added'],
                'removed' => (int) $entry['removed'],
            ];
        }

        $payload = [
            'operation_id' => $operationId,
            'digest' => $patch->digest(),
            'created_at' => date('c'),
            'operations' => $operations,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Serializzazione del payload della patch non riuscita.');
        }

        AtomicFileWriter::replace($this->file($operationId), $json);
    }

    /**
     * Legge il payload. Contenuto e diff sono decodificati. Restituisce null se assente o corrotto
     * (fail closed: chi applica, senza payload, non applica).
     *
     * @return array{digest: string, operations: list<array{op: string, path: string, base_sha256: ?string, result_sha256: string, new_content: string, diff: string, added: int, removed: int}>}|null
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
        if (!is_array($data) || !isset($data['operations']) || !is_array($data['operations'])) {
            return null;
        }

        $operations = [];
        foreach ($data['operations'] as $op) {
            if (!is_array($op)) {
                return null;
            }
            $content = base64_decode((string) ($op['new_content_b64'] ?? ''), true);
            $diff = base64_decode((string) ($op['diff_b64'] ?? ''), true);
            if ($content === false || $diff === false) {
                return null;
            }
            $operations[] = [
                'op' => (string) ($op['op'] ?? ''),
                'path' => (string) ($op['path'] ?? ''),
                'base_sha256' => isset($op['base_sha256']) && $op['base_sha256'] !== null ? (string) $op['base_sha256'] : null,
                'result_sha256' => (string) ($op['result_sha256'] ?? ''),
                'new_content' => $content,
                'diff' => $diff,
                'added' => (int) ($op['added'] ?? 0),
                'removed' => (int) ($op['removed'] ?? 0),
            ];
        }

        return [
            'digest' => (string) ($data['digest'] ?? ''),
            'operations' => $operations,
        ];
    }

    public function delete(string $operationId): void
    {
        @unlink($this->file($operationId));
    }

    private function file(string $operationId): string
    {
        // operation_id è già validato (alfanumerico/_/-) da chi lo genera; qui una difesa in più.
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $operationId) !== 1) {
            throw new \InvalidArgumentException('Identificativo di operazione non valido.');
        }

        return $this->dir . '/' . $operationId . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Impossibile creare la directory del payload delle patch.');
        }
    }
}
