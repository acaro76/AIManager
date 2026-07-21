<?php

declare(strict_types=1);

namespace App\Core\Code;

use App\Core\Database;

/**
 * Code — Fase 4 / F4.6. Il servizio che APPLICA, ANNULLA e RECUPERA le modifiche sicure. È l'unico
 * punto in cui Code scrive nel filesystem del workspace, e lo fa SOLO dopo conferma esplicita
 * (operation_id + patch_digest), MAI su iniziativa del modello.
 *
 * Ogni mutazione, sotto LOCK esclusivo del workspace:
 *   1. recupera eventuali operazioni interrotte da un crash (WAL journal) PRIMA di ogni nuova mutazione;
 *   2. RIVALIDA da capo contro il filesystem VIVO: root, revoca, sensibili, symlink, esistenza e HASH
 *      (base_sha256 == file attuale → altrimenti `stale`: un worktree già sporco è così preservato);
 *   3. scrive il journal write-ahead (preimage) PRIMA di toccare i file;
 *   4. applica ogni file in modo atomico (temporaneo nella dir del target + rename), poi RILEGGE e
 *      verifica l'hash risultante;
 *   5. su successo marca `applied`; su guasto COMPENSA (ripristina i preimage) e marca `failed` —
 *      mai uno stato multi-file parziale persistente.
 *
 * Il rollback è indipendente da Git: ripristina i preimage SOLO se il file conserva ancora
 * `result_sha256` (non sovrascrive modifiche successive dell'utente); per un `create` elimina il
 * file solo se ancora identico a quello creato.
 *
 * Registra SOLO metadati/stati (CodePatchOperationRepository): mai patch, contenuti o messaggi
 * tecnici. È isolato dagli LLM: non tocca ProviderManager, ChatService né le tabelle delle chat.
 */
final class CodePatchMutationService
{
    private readonly CodePatchLimits $limits;
    private readonly CodePatchOperationRepository $operations;
    private readonly CodePatchStore $store;
    private readonly CodePatchJournal $journal;
    private readonly CodeWorkspaceRepository $workspaces;
    private readonly string $baseDir;

    public function __construct(
        private readonly Database $db,
        string $baseDir,
        ?CodePatchLimits $limits = null,
    ) {
        $this->limits = $limits ?? CodePatchLimits::defaults();
        $this->baseDir = rtrim($baseDir, '/');
        $this->operations = new CodePatchOperationRepository($db);
        $this->store = new CodePatchStore($this->baseDir);
        $this->journal = new CodePatchJournal($this->baseDir);
        $this->workspaces = new CodeWorkspaceRepository($db);
    }

    /**
     * Applica una proposta CONFERMATA. Il risultato è strutturato: `status` dice all'UI cos'è
     * successo (applied | denied | stale | expired | busy | not_found | failed).
     *
     * @return array{ok: bool, status: string, message: string, operation_id: string, files: list<array{path: string, op: string}>}
     */
    public function apply(int $workspaceId, int $codeSessionId, string $operationId, string $patchDigest): array
    {
        $workspace = $this->activeWorkspace($workspaceId);
        if ($workspace === null) {
            return $this->result('denied', 'Cartella non disponibile o revocata.', $operationId);
        }

        // Le verifiche di forma sull'operation_id evitano di toccare lo store/journal con un id
        // malformato; findForScope resta il gate di appartenenza.
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $operationId) !== 1) {
            return $this->result('not_found', 'Proposta non valida.', $operationId);
        }

        $lock = new CodeWorkspaceLock($this->baseDir);
        if (!$lock->acquire($workspaceId)) {
            return $this->result('busy', 'Un\'altra operazione è in corso su questa cartella. Riprova.', $operationId);
        }

        try {
            $this->recover($workspace, $workspaceId);

            $row = $this->operations->findForScope($operationId, $workspaceId, $codeSessionId);
            if ($row === null) {
                return $this->result('not_found', 'Proposta non trovata in questa sessione.', $operationId);
            }
            if ($this->operations->expireIfDue($operationId)) {
                return $this->result('expired', 'Proposta scaduta: chiedi a Code di riproporla.', $operationId);
            }
            if ((string) $row['status'] !== 'proposed') {
                return $this->result('denied', 'Proposta non più applicabile.', $operationId);
            }
            if (!hash_equals((string) $row['patch_digest'], $patchDigest)) {
                return $this->result('denied', 'La proposta è cambiata: ricontrolla il diff e conferma di nuovo.', $operationId);
            }

            $payload = $this->store->read($operationId);
            if ($payload === null || !$this->payloadMatches($payload, (string) $row['patch_digest'])) {
                // Payload assente o incoerente col digest memorizzato: non si applica nulla.
                $this->operations->transition($operationId, ['proposed'], 'failed');
                return $this->result('failed', 'Dettaglio della proposta non disponibile: chiedine una nuova.', $operationId);
            }

            // Da qui l'operazione è "in applicazione": la guardia atomica vince le corse e impone la monouso.
            if (!$this->operations->transition($operationId, ['proposed'], 'applying')) {
                return $this->result('denied', 'Proposta non più applicabile.', $operationId);
            }

            return $this->performApply($workspace, $workspaceId, $operationId, $payload['operations']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Annulla (rollback locale) un'operazione APPLICATA, ripristinando i preimage — ma solo se i
     * file conservano ancora il risultato dell'applicazione.
     *
     * @return array{ok: bool, status: string, message: string, operation_id: string, files: list<array{path: string, op: string}>}
     */
    public function rollback(int $workspaceId, int $codeSessionId, string $operationId): array
    {
        $workspace = $this->activeWorkspace($workspaceId);

        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $operationId) !== 1) {
            return $this->result('not_found', 'Operazione non valida.', $operationId);
        }

        $lock = new CodeWorkspaceLock($this->baseDir);
        if (!$lock->acquire($workspaceId)) {
            return $this->result('busy', 'Un\'altra operazione è in corso su questa cartella. Riprova.', $operationId);
        }

        try {
            // Il recovery gira solo se il workspace è utilizzabile (serve la root per riconfinare).
            if ($workspace !== null) {
                $this->recover($workspace, $workspaceId);
            }

            $row = $this->operations->findForScope($operationId, $workspaceId, $codeSessionId);
            if ($row === null) {
                return $this->result('not_found', 'Operazione non trovata in questa sessione.', $operationId);
            }
            if ((string) $row['status'] !== 'applied') {
                return $this->result('denied', 'Solo un\'operazione applicata può essere annullata.', $operationId);
            }
            if ($workspace === null) {
                $this->operations->transition($operationId, ['applied'], 'rollback_cancelled');
                return $this->result('rollback_cancelled', 'Cartella revocata: annullamento non possibile.', $operationId);
            }

            $record = $this->journal->read($operationId);
            if ($record === null || $record['phase'] !== CodePatchJournal::PHASE_COMMITTED) {
                $this->operations->transition($operationId, ['applied'], 'rollback_denied');
                return $this->result('rollback_denied', 'Nessun preimage disponibile: annullamento non possibile.', $operationId);
            }

            return $this->performRollback($workspace, $operationId, $record['entries']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Marca `rejected` una proposta ancora `proposed`, scoped. Non tocca il filesystem.
     *
     * @return array{ok: bool, status: string, message: string, operation_id: string, files: list<array{path: string, op: string}>}
     */
    public function reject(int $workspaceId, int $codeSessionId, string $operationId): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $operationId) !== 1) {
            return $this->result('not_found', 'Proposta non valida.', $operationId);
        }
        $row = $this->operations->findForScope($operationId, $workspaceId, $codeSessionId);
        if ($row === null) {
            return $this->result('not_found', 'Proposta non trovata in questa sessione.', $operationId);
        }
        if (!$this->operations->transition($operationId, ['proposed'], 'rejected')) {
            return $this->result('denied', 'Proposta non più rifiutabile.', $operationId);
        }
        $this->store->delete($operationId);

        return $this->result('rejected', 'Proposta rifiutata.', $operationId);
    }

    // --- Applicazione vera e propria (sotto lock, stato già 'applying') -----------------------

    /**
     * @param list<array{op: string, path: string, base_sha256: ?string, result_sha256: string, new_content: string, diff: string, added: int, removed: int}> $operations
     * @return array{ok: bool, status: string, message: string, operation_id: string, files: list<array{path: string, op: string}>}
     */
    private function performApply(CodeWorkspace $workspace, int $workspaceId, string $operationId, array $operations): array
    {
        // 1) RIVALIDAZIONE contro il filesystem vivo + cattura preimage (nessuna scrittura ancora).
        $prepared = [];
        foreach ($operations as $op) {
            $check = $this->prepareOne($workspace, $op);
            if (is_string($check)) {
                // Precondizione non soddisfatta (stale/blocked/…): niente scritture, proposta fallita.
                $this->operations->transition($operationId, ['applying'], 'failed');
                return $this->result($check === 'stale' ? 'stale' : 'denied', $this->reasonMessage($check), $operationId);
            }
            $prepared[] = $check;
        }

        // 2) JOURNAL write-ahead (preimage + mode) PRIMA di toccare i file.
        $this->journal->prepare($operationId, $workspaceId, array_map(
            static fn (array $e): array => [
                'op' => $e['op'],
                'rel_path' => $e['rel'],
                'base_sha256' => $e['base'],
                'result_sha256' => $e['result'],
                'preimage' => $e['preimage'],
                'mode' => $e['mode'],
            ],
            $prepared
        ));

        // 3) SCRITTURA atomica di ogni file + verifica immediata dell'hash risultante.
        $applied = [];
        try {
            foreach ($prepared as $e) {
                AtomicFileWriter::replace($e['abs'], $e['new'], $e['mode']);
                $written = @file_get_contents($e['abs']);
                if ($written === false || !hash_equals($e['result'], hash('sha256', $written))) {
                    throw new \RuntimeException('Verifica post-scrittura fallita.');
                }
                $applied[] = $e;
            }
        } catch (\Throwable $e) {
            // COMPENSAZIONE: riporta i file già scritti al preimage.
            $allRestored = $this->compensate($applied);
            $this->operations->transition($operationId, ['applying'], 'failed');
            if ($allRestored) {
                // Tutto ripristinato: journal e payload non servono più.
                $this->journal->discard($operationId);
                $this->store->delete($operationId);
                return $this->result('failed', 'Applicazione non riuscita: nessuna modifica è rimasta.', $operationId);
            }
            // Compensazione incompleta: journal e payload CONSERVATI per il recovery alla prossima
            // mutazione. Il journal resta in fase 'prepared', il recover() lo riprenderà.
            return $this->result('failed', 'Applicazione non riuscita: il ripristino verrà completato automaticamente.', $operationId);
        }

        // 4) SUCCESSO: journal committed (preimage conservati per un eventuale rollback), stato applied.
        $this->journal->markApplied($operationId);
        $this->operations->transition($operationId, ['applying'], 'applied', true);

        return $this->result('applied', '', $operationId, $this->fileList($prepared));
    }

    /**
     * Rivalida UNA operazione contro il filesystem vivo e prepara la scrittura (con preimage).
     *
     * @param array{op: string, path: string, base_sha256: ?string, result_sha256: string, new_content: string, diff: string, added: int, removed: int} $op
     * @return array{op: string, rel: string, abs: string, base: ?string, result: string, new: string, preimage: ?string, mode: int}|string
     *         array pronto alla scrittura, oppure un motivo (string) in caso di precondizione non soddisfatta
     */
    private function prepareOne(CodeWorkspace $workspace, array $op): array|string
    {
        $rel = (string) $op['path'];
        try {
            $abs = $workspace->assertWritable($rel);
        } catch (CodeWorkspaceException) {
            return CodePatchValidation::BLOCKED;
        }

        if (($op['op'] ?? '') === CodePatchProposal::OP_CREATE) {
            if (is_link($abs) || file_exists($abs)) {
                return 'stale'; // il percorso è ora occupato
            }
            return [
                'op' => CodePatchProposal::OP_CREATE,
                'rel' => $rel,
                'abs' => $abs,
                'base' => null,
                'result' => (string) $op['result_sha256'],
                'new' => (string) $op['new_content'],
                'preimage' => null,
                'mode' => 0644,
            ];
        }

        // update
        if (is_link($abs) || !is_file($abs)) {
            return 'stale';
        }
        $current = $this->readCurrent($abs);
        if ($current === null) {
            return 'stale';
        }
        if (!hash_equals((string) $op['base_sha256'], hash('sha256', $current))) {
            return 'stale'; // il file è cambiato dall'ultima lettura: worktree preservato
        }

        return [
            'op' => CodePatchProposal::OP_UPDATE,
            'rel' => $rel,
            'abs' => $abs,
            'base' => (string) $op['base_sha256'],
            'result' => (string) $op['result_sha256'],
            'new' => (string) $op['new_content'],
            'preimage' => $current,
            'mode' => (fileperms($abs) & 0777) ?: 0644,
        ];
    }

    // --- Rollback (sotto lock, stato 'applied', journal committed) ----------------------------

    /**
     * @param list<array{op: string, rel_path: string, base_sha256: ?string, result_sha256: string, preimage: ?string, mode: int}> $entries
     * @return array{ok: bool, status: string, message: string, operation_id: string, files: list<array{path: string, op: string}>}
     */
    private function performRollback(CodeWorkspace $workspace, string $operationId, array $entries): array
    {
        // 1) Verifica che OGNI file sia ancora quello prodotto dall'applicazione (nessuna modifica
        //    successiva dell'utente) e riconfina. Se anche uno solo è cambiato → niente rollback.
        $targets = [];
        foreach ($entries as $entry) {
            $rel = (string) $entry['rel_path'];
            try {
                $abs = $workspace->assertWritable($rel);
            } catch (CodeWorkspaceException) {
                $this->operations->transition($operationId, ['applied'], 'rollback_cancelled');
                return $this->result('rollback_cancelled', 'Un percorso non è più scrivibile: annullamento interrotto.', $operationId);
            }

            if ($entry['op'] === CodePatchProposal::OP_CREATE) {
                if (is_link($abs)) {
                    $this->operations->transition($operationId, ['applied'], 'rollback_denied');
                    return $this->result('rollback_denied', 'Un file creato è stato sostituito: annullamento non eseguito.', $operationId);
                }
                if (file_exists($abs)) {
                    $current = $this->readCurrent($abs);
                    if ($current === null || !hash_equals((string) $entry['result_sha256'], hash('sha256', $current))) {
                        $this->operations->transition($operationId, ['applied'], 'rollback_denied');
                        return $this->result('rollback_denied', 'Un file creato è stato modificato: annullamento non eseguito.', $operationId);
                    }
                }
                $targets[] = ['op' => 'create', 'abs' => $abs, 'rel' => $rel, 'preimage' => null, 'mode' => (int) ($entry['mode'] ?? 0644)];
                continue;
            }

            if (is_link($abs) || !is_file($abs)) {
                $this->operations->transition($operationId, ['applied'], 'rollback_denied');
                return $this->result('rollback_denied', 'Un file modificato non è più disponibile: annullamento non eseguito.', $operationId);
            }
            $current = $this->readCurrent($abs);
            if ($current === null || !hash_equals((string) $entry['result_sha256'], hash('sha256', $current))) {
                $this->operations->transition($operationId, ['applied'], 'rollback_denied');
                return $this->result('rollback_denied', 'Un file è stato modificato dopo l\'applicazione: annullamento non eseguito.', $operationId);
            }
            $targets[] = ['op' => 'update', 'abs' => $abs, 'rel' => $rel, 'preimage' => (string) $entry['preimage'], 'mode' => (int) ($entry['mode'] ?? 0644)];
        }

        // 2) Fase rolling_back: un crash qui verrà COMPLETATO dal recovery (stesso stato-obiettivo).
        $this->journal->markRollingBack($operationId);

        // 3) Ripristino: update → riscrive il preimage col mode ORIGINALE; create → elimina il file creato.
        try {
            foreach ($targets as $t) {
                if ($t['op'] === 'create') {
                    AtomicFileWriter::delete($t['abs']);
                } else {
                    AtomicFileWriter::replace($t['abs'], (string) $t['preimage'], $t['mode']);
                }
            }
        } catch (\Throwable $e) {
            // Il journal resta in fase rolling_back: la prossima mutazione lo completa.
            return $this->result('failed', 'Annullamento non riuscito del tutto: verrà completato automaticamente.', $operationId);
        }

        $this->operations->transition($operationId, ['applied'], 'rolled_back');
        $this->journal->discard($operationId);
        $this->store->delete($operationId);

        return $this->result('rolled_back', 'Modifica annullata.', $operationId, array_map(
            static fn (array $t): array => ['path' => $t['rel'], 'op' => $t['op']],
            $targets
        ));
    }

    // --- Recovery dopo crash (sotto lock, prima di ogni nuova mutazione) -----------------------

    /**
     * Recupera le operazioni interrotte (journal in fase `prepared`/`rolling_back`) del workspace:
     * ripristina i preimage (stato PRE-APPLICAZIONE) e allinea lo stato dell'operazione. È
     * idempotente: se non c'è nulla da recuperare, non fa nulla. Se il ripristino è incompleto,
     * conserva journal e payload affinché il recovery possa essere ritentato alla mutazione
     * successiva.
     */
    private function recover(CodeWorkspace $workspace, int $workspaceId): void
    {
        foreach ($this->journal->pendingForWorkspace($workspaceId) as $pending) {
            $operationId = $pending['operation_id'];
            $record = $this->journal->read($operationId);
            if ($record === null) {
                $this->journal->discard($operationId);
                continue;
            }
            $allRestored = $this->restorePreimages($workspace, $record['entries']);

            if ($pending['phase'] === CodePatchJournal::PHASE_ROLLING_BACK) {
                // rolling_back (rollback interrotto): concluso SOLO se tutto ripristinato.
                if ($allRestored) {
                    $this->operations->transition($operationId, ['applying', 'proposed', 'applied'], 'rolled_back');
                    $this->journal->discard($operationId);
                    $this->store->delete($operationId);
                }
                // Se incompleto: nessuna transition, journal e payload CONSERVATI.
            } else {
                // prepared (apply interrotto) → failed comunque; journal/payload solo se completo.
                $this->operations->transition($operationId, ['applying', 'proposed', 'applied'], 'failed');
                if ($allRestored) {
                    $this->journal->discard($operationId);
                    $this->store->delete($operationId);
                }
            }
            // Se incompleto: journal e payload CONSERVATI. La prossima mutazione riproverà.
        }
    }

    /**
     * Riporta i file al PRE-APPLICAZIONE: update → riscrive il preimage; create → elimina il file
     * se ancora identico a quello creato. Confinato: un percorso non più scrivibile viene saltato
     * (non si forza fuori dal confine).
     *
     * NON sovrascrive modifiche successive dell'utente: per un update, ripristina SOLO se il
     * contenuto corrente corrisponde a `base_sha256` (era già stato ripristinato) oppure a
     * `result_sha256` (è ancora quello prodotto dall'applicazione). Uno stato diverso è un
     * conflitto: il file non viene toccato e il recovery è considerato incompleto.
     *
     * @param list<array{op: string, rel_path: string, base_sha256: ?string, result_sha256: string, preimage: ?string, mode: int}> $entries
     * @return bool true se TUTTI i file sono stati ripristinati o erano già nello stato atteso
     */
    private function restorePreimages(CodeWorkspace $workspace, array $entries): bool
    {
        $allOk = true;
        foreach ($entries as $entry) {
            try {
                $abs = $workspace->assertWritable((string) $entry['rel_path']);
            } catch (CodeWorkspaceException) {
                $allOk = false;
                continue; // non più scrivibile: non si tocca
            }
            try {
                if ($entry['op'] === CodePatchProposal::OP_CREATE) {
                    // Path assente = già annullato: ok.
                    if (!file_exists($abs) && !is_link($abs)) {
                        continue;
                    }
                    // Symlink, directory o qualunque non-regular-file = conflitto.
                    if (is_link($abs) || !is_file($abs)) {
                        $allOk = false;
                        continue;
                    }
                    // File regolare: verifica hash prima di eliminare.
                    $current = $this->readCurrent($abs);
                    if ($current === null) {
                        // Non leggibile / non verificabile = conflitto.
                        $allOk = false;
                        continue;
                    }
                    if (hash_equals((string) $entry['result_sha256'], hash('sha256', $current))) {
                        AtomicFileWriter::delete($abs);
                        continue;
                    }
                    // Hash diverso: l'utente ha modificato il file. Conflitto.
                    $allOk = false;
                    continue;
                }
                if (is_link($abs) || !is_file($abs) || $entry['preimage'] === null) {
                    $allOk = false;
                    continue;
                }
                $current = $this->readCurrent($abs);
                if ($current === null) {
                    $allOk = false;
                    continue;
                }
                $currentHash = hash('sha256', $current);
                // Già ripristinato (contenuto == preimage originale → base_sha256)?
                if ($entry['base_sha256'] !== null && hash_equals((string) $entry['base_sha256'], $currentHash)) {
                    continue; // già nello stato pre-applicazione, niente da fare
                }
                // Ancora il risultato dell'applicazione?
                if (hash_equals((string) $entry['result_sha256'], $currentHash)) {
                    $mode = (int) ($entry['mode'] ?? 0644);
                    AtomicFileWriter::replace($abs, (string) $entry['preimage'], $mode);
                    continue;
                }
                // Contenuto diverso: l'utente ha modificato il file. Non si sovrascrive.
                $allOk = false;
            } catch (\Throwable $e) {
                error_log('[code] recovery: ripristino non riuscito (' . get_class($e) . ')');
                $allOk = false;
            }
        }

        return $allOk;
    }

    // --- Utilità ------------------------------------------------------------------------------

    /**
     * Compensa i file già scritti riportandoli al preimage (ordine inverso). Restituisce `true`
     * se TUTTI i file sono stati ripristinati, `false` se almeno uno non è riuscito.
     *
     * @param list<array{op: string, rel: string, abs: string, base: ?string, result: string, new: string, preimage: ?string, mode: int}> $applied
     */
    private function compensate(array $applied): bool
    {
        $allOk = true;
        // In ordine INVERSO: riporta ogni file già scritto al proprio preimage / lo elimina.
        foreach (array_reverse($applied) as $e) {
            try {
                if ($e['op'] === CodePatchProposal::OP_CREATE) {
                    AtomicFileWriter::delete($e['abs']);
                } elseif ($e['preimage'] !== null) {
                    AtomicFileWriter::replace($e['abs'], (string) $e['preimage'], $e['mode']);
                }
            } catch (\Throwable $ex) {
                error_log('[code] compensazione non riuscita (' . get_class($ex) . ')');
                $allOk = false;
            }
        }

        return $allOk;
    }

    private function readCurrent(string $abs): ?string
    {
        $size = @filesize($abs);
        if ($size === false || $size > $this->limits->maxFileBytes) {
            return null;
        }
        $content = @file_get_contents($abs);

        return $content === false ? null : $content;
    }

    /**
     * @param array{digest: string, operations: list<array{op: string, path: string, base_sha256: ?string, result_sha256: string, new_content: string, diff: string, added: int, removed: int}>} $payload
     */
    private function payloadMatches(array $payload, string $storedDigest): bool
    {
        $metadata = [];
        foreach ($payload['operations'] as $op) {
            $metadata[] = [
                'path' => (string) $op['path'],
                'op' => (string) $op['op'],
                'base_sha256' => $op['base_sha256'] === null ? null : (string) $op['base_sha256'],
                'result_sha256' => (string) $op['result_sha256'],
            ];
        }
        try {
            $recomputed = CodePatch::digestFromMetadata($metadata);
        } catch (\Throwable) {
            return false;
        }

        return hash_equals($storedDigest, $recomputed) && hash_equals($storedDigest, (string) $payload['digest']);
    }

    private function activeWorkspace(int $workspaceId): ?CodeWorkspace
    {
        $workspace = $this->workspaces->findById($workspaceId);
        if ($workspace === null || $workspace->status !== 'active' || !$workspace->rootIsValid()) {
            return null;
        }

        return $workspace;
    }

    /**
     * @param list<array{op: string, rel: string, abs: string, base: ?string, result: string, new: string, preimage: ?string, mode: int}> $prepared
     * @return list<array{path: string, op: string}>
     */
    private function fileList(array $prepared): array
    {
        return array_map(
            static fn (array $e): array => ['path' => $e['rel'], 'op' => $e['op']],
            $prepared
        );
    }

    private function reasonMessage(string $reason): string
    {
        return match ($reason) {
            'stale' => 'Un file è cambiato dall\'ultima lettura: ricontrolla e chiedi una nuova proposta.',
            CodePatchValidation::BLOCKED => 'Un percorso non è consentito (fuori dalla cartella, protetto o revocato).',
            default => 'Proposta non applicabile.',
        };
    }

    /**
     * @param list<array{path: string, op: string}> $files
     * @return array{ok: bool, status: string, message: string, operation_id: string, files: list<array{path: string, op: string}>}
     */
    private function result(string $status, string $message, string $operationId, array $files = []): array
    {
        return [
            'ok' => in_array($status, ['applied', 'rolled_back', 'rejected'], true),
            'status' => $status,
            'message' => $message,
            'operation_id' => $operationId,
            'files' => $files,
        ];
    }
}
