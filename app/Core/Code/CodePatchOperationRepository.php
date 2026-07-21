<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 4 / F4.4. Persistenza del CICLO DI VITA delle operazioni di modifica sicura
 * (tabella `code_patch_operations`), scoped al workspace/sessione, mai alle tabelle LLM.
 *
 * Registra SOLO METADATI: id opaco, scope, id del turno assistant, digest, stato e, per ogni
 * file, percorso relativo + tipo + hash. Ogni campo testuale è a VOCABOLARIO CHIUSO o passa da
 * una validazione (RelativePath, hash esadecimale, stato ammesso): non esiste un parametro in cui
 * infilare una patch, un contenuto, un prompt o un messaggio d'errore. Se un valore non è
 * ammesso, NESSUNA riga viene scritta.
 *
 * Le TRANSIZIONI di stato sono atomiche e GUARDATE (`UPDATE … WHERE status IN (…)`): è così che
 * si impone la monouso (una proposta si applica/rifiuta una volta sola) e si evita che due
 * richieste concorrenti applichino la stessa proposta.
 */
final class CodePatchOperationRepository
{
    private const OPS = [CodePatchProposal::OP_UPDATE, CodePatchProposal::OP_CREATE];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Crea una proposta (`proposed`) SCOPED: inserisce solo se la sessione appartiene a quel
     * workspace (verifica nella stessa INSERT, come l'audit e le conversazioni).
     *
     * @param list<array{path: string, op: string, base_sha256: ?string, result_sha256: string}> $files
     */
    public function create(
        string $operationId,
        int $workspaceId,
        int $codeSessionId,
        ?int $assistantConversationId,
        string $patchDigest,
        array $files,
        int $ttlSeconds,
    ): void {
        $this->assertOperationId($operationId);
        $this->assertDigest($patchDigest);
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('TTL della proposta non valido.');
        }
        $filesJson = $this->encodeFiles($files);
        $now = time();
        $nowIso = date('c', $now);
        $expiresIso = date('c', $now + $ttlSeconds);

        $affected = $this->db->execute(
            'INSERT INTO code_patch_operations
                (operation_id, workspace_id, code_session_id, assistant_conversation_id, patch_digest, status, files_json, created_at, updated_at, expires_at, applied_at)
             SELECT ?, ?, ?, ?, ?, \'proposed\', ?, ?, ?, ?, NULL
             WHERE EXISTS (SELECT 1 FROM code_sessions WHERE id = ? AND workspace_id = ?)',
            [$operationId, $workspaceId, $codeSessionId, $assistantConversationId, $patchDigest, $filesJson, $nowIso, $nowIso, $expiresIso, $codeSessionId, $workspaceId]
        );
        if ($affected < 1) {
            throw new CodeWorkspaceException('Sessione Code inesistente in questo workspace: proposta non registrata.');
        }
    }

    /** @return array<string, mixed>|null */
    public function find(string $operationId): ?array
    {
        return $this->db->fetch('SELECT * FROM code_patch_operations WHERE operation_id = ?', [$operationId]);
    }

    /**
     * Lookup SCOPED: l'operazione deve appartenere a QUEL workspace e QUELLA sessione. È il gate
     * che il controller usa prima di applicare/rifiutare/annullare.
     *
     * @return array<string, mixed>|null
     */
    public function findForScope(string $operationId, int $workspaceId, int $codeSessionId): ?array
    {
        return $this->db->fetch(
            'SELECT * FROM code_patch_operations WHERE operation_id = ? AND workspace_id = ? AND code_session_id = ?',
            [$operationId, $workspaceId, $codeSessionId]
        );
    }

    /**
     * Operazioni di una sessione con uno degli stati richiesti, più recenti prima. Serve alla
     * pagina per ri-mostrare le card (proposte pendenti, applicate con rollback disponibile).
     *
     * @param list<string> $statuses
     * @return list<array<string, mixed>>
     */
    public function listForSession(int $workspaceId, int $codeSessionId, array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }
        foreach ($statuses as $status) {
            if (!in_array($status, CodePatchSchema::STATUSES, true)) {
                throw new \InvalidArgumentException('Stato non ammesso nel filtro.');
            }
        }
        $placeholders = implode(', ', array_fill(0, count($statuses), '?'));

        return $this->db->fetchAll(
            "SELECT * FROM code_patch_operations
             WHERE workspace_id = ? AND code_session_id = ? AND status IN ({$placeholders})
             ORDER BY id DESC",
            array_merge([$workspaceId, $codeSessionId], array_values($statuses))
        );
    }

    /**
     * Transizione ATOMICA e guardata: passa a $to solo se lo stato attuale è fra $from. Restituisce
     * true se ha effettivamente cambiato stato (usato per imporre monouso e vincere le corse).
     *
     * @param list<string> $from
     */
    public function transition(string $operationId, array $from, string $to, bool $setAppliedAt = false): bool
    {
        if (!in_array($to, CodePatchSchema::STATUSES, true)) {
            throw new \InvalidArgumentException('Stato di destinazione non ammesso.');
        }
        foreach ($from as $status) {
            if (!in_array($status, CodePatchSchema::STATUSES, true)) {
                throw new \InvalidArgumentException('Stato di partenza non ammesso.');
            }
        }
        if ($from === []) {
            throw new \InvalidArgumentException('Serve almeno uno stato di partenza.');
        }
        $placeholders = implode(', ', array_fill(0, count($from), '?'));
        $now = date('c');

        $sql = "UPDATE code_patch_operations SET status = ?, updated_at = ?"
            . ($setAppliedAt ? ', applied_at = ?' : '')
            . " WHERE operation_id = ? AND status IN ({$placeholders})";
        $params = $setAppliedAt ? [$to, $now, $now] : [$to, $now];
        $params[] = $operationId;
        foreach ($from as $status) {
            $params[] = $status;
        }

        return $this->db->execute($sql, $params) > 0;
    }

    /**
     * Una proposta `proposed` la cui scadenza (`expires_at`) è passata NON deve più comparire come
     * pendente (Fase 10 / Step 3). Predicato PURO e read-only per il rendering: non muta nulla (la
     * transizione a `expired` avviene all'ingresso mutante già previsto, via {@see self::expireIfDue()}).
     * Gli stati non-`proposed` (applied/rejected/expired) restano gestiti dalla card storica.
     *
     * @param array<string, mixed> $row
     */
    public static function isPendingVisible(array $row, ?int $now = null): bool
    {
        if ((string) ($row['status'] ?? '') !== 'proposed') {
            return true;
        }
        $expires = (string) ($row['expires_at'] ?? '');
        if ($expires === '') {
            return true;
        }
        $ts = strtotime($expires);

        return $ts === false || $ts >= ($now ?? time());
    }

    /**
     * Marca `expired` una proposta ancora `proposed` la cui scadenza è passata. Atomica: se un
     * altro processo l'ha appena applicata, non fa nulla. Restituisce true se l'ha scaduta ora.
     */
    public function expireIfDue(string $operationId, ?int $now = null): bool
    {
        $nowIso = date('c', $now ?? time());

        return $this->db->execute(
            "UPDATE code_patch_operations SET status = 'expired', updated_at = ?
             WHERE operation_id = ? AND status = 'proposed' AND expires_at < ?",
            [date('c'), $operationId, $nowIso]
        ) > 0;
    }

    /**
     * @param list<array{path: string, op: string, base_sha256: ?string, result_sha256: string}> $files
     */
    private function encodeFiles(array $files): string
    {
        $clean = [];
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            RelativePath::assert($path);
            $op = (string) ($file['op'] ?? '');
            if (!in_array($op, self::OPS, true)) {
                throw new \InvalidArgumentException('Tipo di operazione non ammesso nei metadati.');
            }
            $base = $file['base_sha256'] ?? null;
            if ($base !== null) {
                $this->assertDigest((string) $base);
            }
            $this->assertDigest((string) ($file['result_sha256'] ?? ''));
            $clean[] = [
                'path' => $path,
                'op' => $op,
                'base_sha256' => $base === null ? null : (string) $base,
                'result_sha256' => (string) $file['result_sha256'],
            ];
        }
        $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '[]';
    }

    private function assertOperationId(string $operationId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $operationId) !== 1) {
            throw new \InvalidArgumentException('Identificativo di operazione non valido.');
        }
    }

    private function assertDigest(string $digest): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
            throw new \InvalidArgumentException('Digest non valido (atteso SHA-256 esadecimale).');
        }
    }
}
