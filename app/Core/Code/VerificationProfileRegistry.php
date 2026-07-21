<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 5 / F5.2. Il set CURATO dei profili di verifica: PHP, JavaScript, Python.
 *
 * È lato SERVER e NON modificabile dal modello: il modello può solo scegliere per `id` uno dei
 * profili qui dentro, che sia anche ABILITATO in configurazione e RILEVATO nel workspace (binario
 * presente + eventuali marker). Nessun profilo esegue shell, git, installazioni, npm script o
 * Makefile: solo lint, sintassi e test tramite binari dichiarati.
 *
 * L'insieme è volutamente MINIMO (obiettivo del primo gate): sintassi per i tre linguaggi, più
 * lint/test quando gli strumenti del progetto sono presenti. Si espande solo a gate superato.
 */
final class VerificationProfileRegistry
{
    /** @var array<string, VerificationProfile>|null cache dei profili curati */
    private ?array $profiles = null;

    /** @return array<string, VerificationProfile> id → profilo */
    private function curated(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        $list = [
            // PHP: sintassi (php -l). Il binario php è quasi sempre presente dove gira AIManager.
            new VerificationProfile(
                id: 'php-lint',
                language: 'php',
                kind: 'lint',
                program: 'php',
                args: ['-l', VerificationProfile::FILE_PLACEHOLDER],
                requiredBinary: 'php',
            ),
            // PHP: test con PHPUnit del progetto (mai un comando arbitrario). Disponibile solo se
            // `vendor/bin/phpunit` esiste ed è eseguibile nella root.
            new VerificationProfile(
                id: 'php-test',
                language: 'php',
                kind: 'test',
                program: 'vendor/bin/phpunit',
                args: ['--no-coverage', '--do-not-cache-result'],
                requiredFiles: ['vendor/bin/phpunit'],
                maySpawnChildren: true, // un test runner e i test possono avviare sottoprocessi
            ),
            // JavaScript: sintassi (node --check).
            new VerificationProfile(
                id: 'js-syntax',
                language: 'javascript',
                kind: 'syntax',
                program: 'node',
                args: ['--check', VerificationProfile::FILE_PLACEHOLDER],
                requiredBinary: 'node',
            ),
            // JavaScript: lint con l'ESLint locale del progetto (mai npx, mai installazioni).
            new VerificationProfile(
                id: 'js-lint',
                language: 'javascript',
                kind: 'lint',
                program: 'node_modules/.bin/eslint',
                args: ['--no-color', VerificationProfile::FILE_PLACEHOLDER],
                requiredFiles: ['node_modules/.bin/eslint'],
            ),
            // Python: sintassi NON mutante. `py_compile` scriverebbe bytecode (__pycache__/*.pyc):
            // qui si usa `compile()` in memoria su un `-c` COSTANTE, che non crea alcun file. Il
            // `{file}` è letto via sys.argv[1] (argv, non shell). Errore di sintassi → exit != 0.
            new VerificationProfile(
                id: 'py-syntax',
                language: 'python',
                kind: 'syntax',
                program: 'python3',
                args: [
                    '-B', // non scrivere bytecode nemmeno se qualcosa lo tentasse
                    '-c',
                    'import sys; compile(open(sys.argv[1], "rb").read(), sys.argv[1], "exec")',
                    VerificationProfile::FILE_PLACEHOLDER,
                ],
                requiredBinary: 'python3',
            ),
            // Python: test con pytest, disponibile solo se il progetto lo configura (marker file).
            new VerificationProfile(
                id: 'py-test',
                language: 'python',
                kind: 'test',
                program: 'python3',
                args: ['-m', 'pytest', '-q', '--no-header'],
                requiredBinary: 'python3',
                requiredFiles: ['pytest.ini'],
                maySpawnChildren: true, // pytest e i test possono avviare sottoprocessi/worker
            ),
        ];

        $byId = [];
        foreach ($list as $profile) {
            $byId[$profile->id] = $profile;
        }

        return $this->profiles = $byId;
    }

    /** @return list<VerificationProfile> tutti i profili curati (ordine deterministico) */
    public function all(): array
    {
        return array_values($this->curated());
    }

    /** @return list<string> gli id di tutti i profili curati */
    public function ids(): array
    {
        return array_keys($this->curated());
    }

    /** Il profilo con quell'id, o null se non esiste (id ignoto/spoofato). */
    public function find(string $id): ?VerificationProfile
    {
        return $this->curated()[$id] ?? null;
    }

    /**
     * I profili ABILITATI dal server: intersezione fra gli id richiesti in configurazione e quelli
     * curati. Un id sconosciuto viene ignorato (fail closed: non abilita nulla di nuovo). Con
     * `$enabledIds` null si intende «tutti i curati».
     *
     * @param list<string>|null $enabledIds
     * @return list<VerificationProfile>
     */
    public function enabled(?array $enabledIds): array
    {
        $curated = $this->curated();
        if ($enabledIds === null) {
            return array_values($curated);
        }

        $out = [];
        foreach ($enabledIds as $id) {
            if (is_string($id) && isset($curated[$id])) {
                $out[$id] = $curated[$id];
            }
        }

        return array_values($out);
    }
}
