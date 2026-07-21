<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — F0.b. Persistenza dei workspace (tabella code_workspaces autonoma).
 *
 * Identità = la ROOT (root_path UNIQUE). Nessun legame con `projects`: la cartella È il
 * progetto Code. L'autorizzazione crea o riattiva la riga esistente per quella root
 * (nessun duplicato); la revoca usa status='revoked' (mai DELETE).
 */
final class CodeWorkspaceRepository
{
    private readonly SensitivePathPolicy $sensitive;
    private readonly CodeSelfProtection $selfProtection;

    public function __construct(
        private readonly Database $db,
        ?SensitivePathPolicy $sensitive = null,
        ?CodeSelfProtection $selfProtection = null,
    ) {
        $this->sensitive = $sensitive ?? new SensitivePathPolicy();
        $this->selfProtection = $selfProtection ?? new CodeSelfProtection();
    }

    /**
     * Autorizza una cartella (la root canonica È il progetto Code). Se la root esiste già:
     * - active  → restituisce lo stesso workspace, senza duplicati;
     * - revoked → riattiva mantenendo lo stesso id.
     */
    public function authorizeRoot(string $path, string $name = ''): CodeWorkspace
    {
        $canonical = $this->canonicalizeForAuthorize($path);
        $this->selfProtection->assertSeparate($canonical);
        $now = date('c');

        $existing = $this->db->fetch('SELECT id, status FROM code_workspaces WHERE root_path = ?', [$canonical]);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            if ((string) $existing['status'] !== 'active') {
                // revoked (o altro non-active) → riattiva, stesso id.
                $this->db->execute(
                    'UPDATE code_workspaces SET status = ?, authorized_at = ?, updated_at = ? WHERE id = ?',
                    ['active', $now, $now, $id]
                );
            }
            return $this->requireById($id);
        }

        $this->db->execute(
            'INSERT INTO code_workspaces (root_path, name, status, authorized_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$canonical, $name, 'active', $now, $now, $now]
        );
        return $this->requireById($this->db->lastInsertId());
    }

    public function findById(int $id): ?CodeWorkspace
    {
        $row = $this->db->fetch('SELECT * FROM code_workspaces WHERE id = ?', [$id]);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByRoot(string $path): ?CodeWorkspace
    {
        $canonical = realpath($path);
        // Se la cartella non esiste più su disco, si cerca comunque per il path fornito.
        $canonical = rtrim($canonical === false ? $path : $canonical, DIRECTORY_SEPARATOR);
        $row = $this->db->fetch('SELECT * FROM code_workspaces WHERE root_path = ?', [$canonical]);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Tutti i workspace, ordine deterministico (id crescente).
     *
     * @return CodeWorkspace[]
     */
    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM code_workspaces ORDER BY id ASC');
        return array_map(fn (array $row): CodeWorkspace => $this->hydrate($row), $rows);
    }

    /** @return CodeWorkspace[] */
    public function activeByRecentUse(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM code_workspaces WHERE status = 'active' ORDER BY updated_at DESC, id DESC"
        );
        return array_map(fn (array $row): CodeWorkspace => $this->hydrate($row), $rows);
    }

    /**
     * Revoca per id. Fallisce in modo controllato se non c'è nulla da revocare.
     */
    public function revoke(int $id): void
    {
        $affected = $this->db->execute(
            'UPDATE code_workspaces SET status = ?, updated_at = ? WHERE id = ?',
            ['revoked', date('c'), $id]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Nessun workspace da revocare per questo id.');
        }
    }

    private function canonicalizeForAuthorize(string $path): string
    {
        if ($path !== '' && is_link($path)) {
            throw new CodeWorkspaceException('La radice del workspace non può essere un symlink.');
        }
        $canonical = realpath($path);
        if ($canonical === false || !is_dir($canonical) || !is_readable($canonical)) {
            throw new CodeWorkspaceException('Cartella non trovata o non leggibile.');
        }
        $canonical = rtrim($canonical, DIRECTORY_SEPARATOR);
        if ($canonical === '') {
            throw new CodeWorkspaceException('La root del filesystem non è ammessa come cartella di lavoro.');
        }
        return $canonical;
    }

    private function requireById(int $id): CodeWorkspace
    {
        $ws = $this->findById($id);
        if ($ws === null) {
            throw new CodeWorkspaceException('Autorizzazione del workspace fallita.');
        }
        return $ws;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CodeWorkspace
    {
        return new CodeWorkspace(
            id: (int) $row['id'],
            rootPath: (string) $row['root_path'],
            name: (string) $row['name'],
            status: (string) $row['status'],
            sensitive: $this->sensitive,
            selfProtection: $this->selfProtection,
        );
    }
}
