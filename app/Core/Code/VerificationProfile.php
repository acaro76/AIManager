<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 5 / F5.2. UN profilo di verifica: la SOLA forma di comando che la Fase 5 ammette.
 *
 * Non è una shell: è un programma FISSO con argomenti FISSI, dichiarati qui a mano. L'unica parte
 * variabile è il segnaposto CHIUSO `{file}`, che il server sostituisce con un percorso RELATIVO già
 * validato (mai testo del modello). Non esistono `write`, `exec`, `shell`, `git`, `install`, npm
 * script o Makefile: se un comando non è uno di questi profili curati, non è avviabile.
 *
 * La sicurezza NON dipende dall'assenza di metacaratteri nel `{file}`: l'esecuzione avviene con
 * argv (nessuna shell, nessun `system()`), quindi `; rm -rf` in un nome di file resta UN argomento
 * letterale. Il segnaposto è comunque un percorso RelativePath, e il file dev'essere già stato
 * letto nel turno (verificato dal ciclo), quindi un nome patologico non arriva neppure qui.
 *
 * Immutabile e PURO: nessun IO. La DISPONIBILITÀ (binario presente, marker del progetto) è decisa
 * altrove (VerificationDetector), su un workspace concreto.
 */
final class VerificationProfile
{
    /** Linguaggi ammessi (vocabolario chiuso, coerente col CHECK di code_verification_runs). */
    public const LANGUAGES = ['php', 'javascript', 'python'];

    /** Tipi di verifica ammessi (vocabolario chiuso). Git è ESCLUSO dalla Fase 5. */
    public const KINDS = ['lint', 'test', 'syntax'];

    /** Identificatore stabile del profilo: minuscole, cifre e trattino. */
    private const ID = '/^[a-z][a-z0-9-]{1,39}$/';

    /** Il segnaposto CHIUSO ammesso negli argomenti. L'unico. */
    public const FILE_PLACEHOLDER = '{file}';

    /**
     * @param string $id          identificatore stabile (es. `php-lint`)
     * @param string $language    uno di self::LANGUAGES
     * @param string $kind        uno di self::KINDS
     * @param string $program     binario da eseguire: un nome su PATH (`php`, `node`, `python3`)
     *                            oppure un percorso RELATIVO alla root (`vendor/bin/phpunit`).
     * @param list<string> $args  argomenti FISSI; al più UN token è esattamente `{file}`.
     * @param string $requiredBinary binario su PATH che deve esistere (vuoto se `program` è locale).
     * @param list<string> $requiredFiles file (relativi) che devono TUTTI esistere nel workspace
     *                            perché il profilo sia disponibile (es. `vendor/bin/phpunit`,
     *                            `package.json`, `pytest.ini`).
     * @param bool $maySpawnChildren il comando può generare processi figli (es. un test runner che
     *                            fa fork di worker, o test che avviano sottoprocessi). Se true, la
     *                            verifica è ammessa SOLO dove la terminazione dell'albero è
     *                            garantita (isolamento del process group): altrove fallisce chiuso.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $language,
        public readonly string $kind,
        public readonly string $program,
        public readonly array $args,
        public readonly string $requiredBinary = '',
        public readonly array $requiredFiles = [],
        public readonly bool $maySpawnChildren = false,
    ) {
        if (preg_match(self::ID, $id) !== 1) {
            throw new \InvalidArgumentException('VerificationProfile: id non valido.');
        }
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException('VerificationProfile: linguaggio non ammesso.');
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('VerificationProfile: tipo non ammesso.');
        }
        self::assertProgram($program);
        $placeholders = 0;
        foreach ($args as $arg) {
            if (!is_string($arg) || $arg === '') {
                throw new \InvalidArgumentException('VerificationProfile: argomento non valido.');
            }
            if ($arg === self::FILE_PLACEHOLDER) {
                $placeholders++;
                continue;
            }
            self::assertLiteralArg($arg);
        }
        if ($placeholders > 1) {
            throw new \InvalidArgumentException('VerificationProfile: un solo segnaposto {file} è ammesso.');
        }
        if ($requiredBinary !== '') {
            self::assertBinaryName($requiredBinary);
        }
        foreach ($requiredFiles as $file) {
            if (!is_string($file)) {
                throw new \InvalidArgumentException('VerificationProfile: requiredFiles non valido.');
            }
            RelativePath::assert($file);
        }
        // Un profilo con program locale (contiene `/`) DEVE dichiararlo tra i file richiesti: la sua
        // presenza (ed eseguibilità) è verificata nel workspace prima di avviarlo.
        if (self::isLocalProgram($program) && !in_array($program, $requiredFiles, true)) {
            throw new \InvalidArgumentException('VerificationProfile: il program locale deve essere tra requiredFiles.');
        }
    }

    /** True se il profilo ha bisogno di un file bersaglio (contiene `{file}`). */
    public function requiresFile(): bool
    {
        return in_array(self::FILE_PLACEHOLDER, $this->args, true);
    }

    /** True se il program è un percorso relativo alla root (non un nome su PATH). */
    public function hasLocalProgram(): bool
    {
        return self::isLocalProgram($this->program);
    }

    /**
     * Costruisce l'argv COMPLETO da passare al processo. Il `{file}` è sostituito con il percorso
     * RELATIVO già validato dal chiamante; ogni altro token resta letterale. Nessuna shell.
     *
     * @return list<string> [program, ...args]
     */
    public function render(?string $relFile): array
    {
        if ($this->requiresFile()) {
            if ($relFile === null || $relFile === '') {
                throw new \InvalidArgumentException('VerificationProfile: questo profilo richiede un file bersaglio.');
            }
            RelativePath::assert($relFile);
        }

        $argv = [$this->program];
        foreach ($this->args as $arg) {
            $argv[] = $arg === self::FILE_PLACEHOLDER ? (string) $relFile : $arg;
        }

        return $argv;
    }

    private static function isLocalProgram(string $program): bool
    {
        return str_contains($program, '/');
    }

    private static function assertProgram(string $program): void
    {
        if (self::isLocalProgram($program)) {
            // Percorso locale: relativo e canonico, dentro la root (il confine vero lo impone
            // comunque il workspace al momento dell'esecuzione).
            RelativePath::assert($program);
            return;
        }
        self::assertBinaryName($program);
    }

    /** Un nome di binario su PATH: solo caratteri sicuri, nessun separatore, nessun metacarattere. */
    private static function assertBinaryName(string $name): void
    {
        if (preg_match('/^[a-zA-Z0-9._-]{1,64}$/', $name) !== 1) {
            throw new \InvalidArgumentException('VerificationProfile: nome di binario non valido.');
        }
    }

    /**
     * Un argomento letterale non deve contenere caratteri di controllo o NUL: non è una difesa di
     * sicurezza (l'esecuzione è argv, non shell), ma tiene i profili puliti e prevedibili.
     */
    private static function assertLiteralArg(string $arg): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $arg) === 1) {
            throw new \InvalidArgumentException('VerificationProfile: argomento con caratteri di controllo.');
        }
    }
}
