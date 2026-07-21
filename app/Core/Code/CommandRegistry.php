<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.3. Registro CHIUSO dei programmi ammessi, con la loro CommandSpec.
 *
 * Minimo e non mutante (correzioni finali): SOLO utility di lettura, nessun interprete generico
 * (php/node/python restano SOLO in run_check, Fase 5), nessun package manager, nessuno script,
 * nessuna rete, nessun Git, nessun eseguibile del workspace. Un programma non elencato → NEGATO.
 *
 * `policyVersion()` è la versione del ruleset: viene persistita nella proposta e riverificata alla
 * conferma. Se il registro cambia tra proposta e conferma, la conferma è negata (stale).
 *
 * PURO e IMMUTABILE a runtime: il modello non può aggiungere programmi né allargare una spec.
 */
final class CommandRegistry
{
    private const POLICY_VERSION = 1;

    /** @var array<string, CommandSpec>|null */
    private ?array $specs = null;

    public function policyVersion(): int
    {
        return self::POLICY_VERSION;
    }

    public function find(string $program): ?CommandSpec
    {
        return $this->specs()[$program] ?? null;
    }

    /** @return list<string> nomi dei programmi registrati (ordine deterministico) */
    public function programs(): array
    {
        $names = array_keys($this->specs());
        sort($names);

        return array_values($names);
    }

    /** @return array<string, CommandSpec> */
    private function specs(): array
    {
        if ($this->specs !== null) {
            return $this->specs;
        }

        $specs = [
            // Lettura di contenuto: solo file regolari, validati a valle. Nessuna ricorsione.
            new CommandSpec('cat', bareFlags: ['-n', '-b', '-s'], minPaths: 1, maxPaths: 10),
            new CommandSpec(
                'head',
                numericFlags: ['-n' => [1, 100000], '-c' => [1, 10485760]],
                minPaths: 1,
                maxPaths: 10,
            ),
            // NB: nessun -f/-F/--follow: seguirebbe il file e resterebbe appeso fino al timeout.
            new CommandSpec(
                'tail',
                numericFlags: ['-n' => [1, 100000], '-c' => [1, 10485760]],
                minPaths: 1,
                maxPaths: 10,
            ),
            new CommandSpec('wc', bareFlags: ['-l', '-w', '-c', '-m'], minPaths: 1, maxPaths: 20),
            // grep: pattern (bounded) + file espliciti. NIENTE -r/-R/--include/-f/-P: la ricorsione
            // aprirebbe file bypassando SensitivePathPolicy (è il processo, non CodeWorkspace, ad aprirli).
            new CommandSpec(
                'grep',
                bareFlags: ['-n', '-i', '-c', '-w', '-v', '-F', '-E', '-l', '-o', '-H'],
                numericFlags: ['-A' => [0, 1000], '-B' => [0, 1000], '-C' => [0, 1000]],
                allowsPattern: true,
                patternRequired: true,
                maxPatternLength: 200,
                minPaths: 1,
                maxPaths: 20,
            ),
            // diff di DUE file espliciti. Niente -r (dir-walk).
            new CommandSpec('diff', bareFlags: ['-u', '-q', '-i', '-w', '-b'], minPaths: 2, maxPaths: 2),
            // stat: NESSUN flag (portabilità GNU/BSD — correzione #10). Solo metadati del file.
            new CommandSpec('stat', bareFlags: [], minPaths: 1, maxPaths: 10),
            new CommandSpec('file', bareFlags: ['-b', '-i'], minPaths: 1, maxPaths: 10),
        ];

        $map = [];
        foreach ($specs as $spec) {
            $map[$spec->program] = $spec;
        }
        $this->specs = $map;

        return $map;
    }
}
