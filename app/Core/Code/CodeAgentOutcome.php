<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 3 / F3.5. Esito del ciclo agente.
 *
 * Il ciclo NON produce un tipo nuovo per il resto della catena: produce lo stesso
 * `RetrievalResult` del single-shot. Così contesto (CodeContextPacker), citazioni, evidenze
 * (`code_response_evidence`) e audit (`code_operation_logs`) restano identici e non serve alcuna
 * migrazione: cambia COME si raccoglie l'evidenza, non come la si rappresenta.
 *
 * `stopReason` è un vocabolario CHIUSO e resta in memoria (UI/log applicativo): non entra nei
 * campi a vocabolario chiuso del DB, dove il tetto di un ciclo si esprime come esito `limited`.
 */
final class CodeAgentOutcome
{
    /** Il ciclo si è concluso perché… */
    public const REASONS = [
        'answer',     // il modello ha detto di avere abbastanza contesto
        'proposal',   // il modello ha prodotto una PROPOSTA di modifica (Fase 4): terminale
        'command',    // il modello ha prodotto una PROPOSTA di comando (Fase 6): terminale
        'process',    // il modello ha prodotto una PROPOSTA di processo (Fase 7): terminale
        'git_stage',  // il modello ha prodotto una PROPOSTA di staging selettivo (Fase 8): terminale
        'iterations', // tetto di iterazioni raggiunto
        'timeout',    // tetto di tempo totale raggiunto
        'budget',     // budget cumulativo dei risultati esaurito
        'cancelled',  // Stop dell'utente
        'invalid',    // il modello non ha prodotto azioni valide → fallback single-shot
        'error',      // guasto del provider/strumento → fallback single-shot
        'revoked',    // autorizzazione revocata durante il ciclo
        'root',       // cartella sparita durante il ciclo
        'command_unavailable', // comando chiesto esplicitamente, ma qui non ce ne sono: nessuna decisione
        'process_unavailable', // processo chiesto esplicitamente, ma qui non è avviabile: nessuna decisione
    ];

    /**
     * @param list<CodeAgentStep> $steps
     * @param list<CodeVerificationRunRecord> $verificationRuns verifiche tentate nel turno (Fase 5),
     *        audit dedicato: NON confluiscono in code_operation_logs.
     */
    public function __construct(
        public readonly RetrievalResult $retrieval,
        public readonly string $stopReason,
        public readonly int $iterations,
        public readonly array $steps,
        public readonly ?CodePatchProposal $proposal = null,
        public readonly array $verificationRuns = [],
        public readonly ?CommandPlan $commandPlan = null,
        public readonly ?ProcessPlan $processPlan = null,
        /** @var list<string> Osservazioni Git read-only raccolte nel turno (Fase 8): dati strutturati e
         *  cappati per la risposta finale. Nomi/contenuti sensibili/runtime sono già esclusi a monte. */
        public readonly array $gitObservations = [],
        /** Proposta di staging selettivo (Fase 8): piano terminale immutabile; non esegue nulla. */
        public readonly ?GitStagePlan $gitStagePlan = null,
        /** Ultimo rifiuto strutturato e anonimo ricevuto tentando lo staging richiesto. */
        public readonly ?string $gitStageFailureObservation = null,
        /** Osservazione prodotta davvero da git_status nel turno corrente; mai dedotta dallo storico. */
        public readonly ?string $gitStatusObservation = null,
    ) {
        if (!in_array($stopReason, self::REASONS, true)) {
            throw new \InvalidArgumentException('CodeAgentOutcome: motivo di arresto non ammesso.');
        }
    }

    /** Il ciclo si è concluso con una PROPOSTA di modifica da validare e mostrare (Fase 4). */
    public function hasProposal(): bool
    {
        return $this->proposal !== null && $this->stopReason === 'proposal';
    }

    /** Il ciclo si è concluso con una PROPOSTA di comando da confermare e mostrare (Fase 6). */
    public function hasCommandProposal(): bool
    {
        return $this->commandPlan !== null && $this->stopReason === 'command';
    }

    /** Il ciclo si è concluso con una PROPOSTA di processo da confermare e mostrare (Fase 7). */
    public function hasProcessProposal(): bool
    {
        return $this->processPlan !== null && $this->stopReason === 'process';
    }

    /** Il ciclo si è concluso con una PROPOSTA di staging selettivo da mostrare (Fase 8). */
    public function hasGitStageProposal(): bool
    {
        return $this->gitStagePlan !== null && $this->stopReason === 'git_stage';
    }

    /** Il ciclo ha raccolto evidenza reale (file letti o riscontri), non solo tentativi. */
    public function hasEvidence(): bool
    {
        return $this->retrieval->readFiles() !== [] || $this->retrieval->searchHits() !== [];
    }

    /** Il ciclo ha raccolto osservazioni Git read-only (Fase 8): dato utile per la risposta finale. */
    public function hasGit(): bool
    {
        return $this->gitObservations !== [];
    }

    /**
     * L'esito è usabile per rispondere? No quando il ciclo è fallito (`invalid`/`error`) o quando
     * non ha raccolto nulla: in quei casi il chiamante ricade sul recupero single-shot
     * deterministico, che è sempre sicuro (nessuna scrittura, nessun turno duplicato).
     * `cancelled` non è "usabile": è una conclusione, e va gestita come tale.
     */
    public function usableForAnswer(): bool
    {
        return !in_array($this->stopReason, ['invalid', 'error', 'cancelled'], true)
            && ($this->hasEvidence() || $this->hasGit());
    }

    /** Il tetto del ciclo è stato morso: l'audit lo registra come `limited`. */
    public function limitedByAgent(): bool
    {
        return in_array($this->stopReason, ['iterations', 'timeout', 'budget'], true);
    }
}
