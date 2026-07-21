<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 5 / F5.3. Rilevamento: quali profili ABILITATI sono davvero eseguibili QUI.
 *
 * Un profilo è disponibile solo se:
 *   - il suo binario su PATH esiste (per `php`, `node`, `python3`), e
 *   - tutti i suoi file richiesti esistono e sono regolari/leggibili DENTRO la root (confinati via
 *     PathGuard), e — se il program stesso è locale (`vendor/bin/phpunit`) — è eseguibile.
 *
 * Il modello NON sceglie cosa è disponibile: sceglie un id, e il server lo concede solo se qui
 * risulta rilevato. Nessun accesso fuori dalla root; nessun comando eseguito per rilevare (solo
 * presenza di file e scansione di PATH), quindi il rilevamento è a sua volta senza effetti.
 *
 * Il controllo del binario su PATH è INIETTABILE (test deterministici); il default scandisce le
 * directory di PATH cercando un file eseguibile, senza mai invocare una shell.
 */
final class VerificationDetector
{
    /** @var callable(string): bool nome binario → presente ed eseguibile su PATH */
    private $binaryExists;

    /** @var callable(): bool la terminazione dell'albero è garantita su questo sistema? */
    private $isolationSupported;

    /** @var array<string, bool> cache dei binari già risolti */
    private array $binaryCache = [];

    /**
     * @param (callable(): bool)|null $isolationSupported iniettabile (test); default: la capability
     *        reale del VerificationRunner (isolamento del process group).
     */
    public function __construct(
        private readonly VerificationProfileRegistry $registry,
        ?callable $binaryExists = null,
        ?callable $isolationSupported = null,
    ) {
        $this->binaryExists = $binaryExists ?? fn (string $bin): bool => $this->binaryOnPath($bin);
        $this->isolationSupported = $isolationSupported
            ?? static fn (): bool => VerificationRunner::supportsProcessGroupIsolation();
    }

    /**
     * I profili, tra quelli ABILITATI dal server, effettivamente disponibili su questo workspace.
     *
     * @param list<string>|null $enabledIds id abilitati in configurazione (null = tutti i curati)
     * @return list<VerificationProfile>
     */
    public function available(CodeWorkspace $workspace, ?array $enabledIds): array
    {
        $out = [];
        foreach ($this->registry->enabled($enabledIds) as $profile) {
            if ($this->isAvailable($workspace, $profile)) {
                $out[] = $profile;
            }
        }

        return $out;
    }

    /** @return list<string> gli id disponibili (comodo per il ciclo). */
    public function availableIds(CodeWorkspace $workspace, ?array $enabledIds): array
    {
        return array_map(static fn (VerificationProfile $p): string => $p->id, $this->available($workspace, $enabledIds));
    }

    public function isAvailable(CodeWorkspace $workspace, VerificationProfile $profile): bool
    {
        // FAIL CLOSED: un profilo che può generare figli è disponibile solo dove la terminazione
        // dell'intero albero è garantita. Senza isolamento del gruppo non si promette una
        // cancellazione non mantenibile: il profilo semplicemente non viene offerto.
        if ($profile->maySpawnChildren && !($this->isolationSupported)()) {
            return false;
        }
        if ($profile->requiredBinary !== '' && !$this->hasBinary($profile->requiredBinary)) {
            return false;
        }
        foreach ($profile->requiredFiles as $rel) {
            $executable = $profile->hasLocalProgram() && $rel === $profile->program;
            if (!$this->fileUsable($workspace, $rel, $executable)) {
                return false;
            }
        }

        return true;
    }

    private function hasBinary(string $bin): bool
    {
        if (!array_key_exists($bin, $this->binaryCache)) {
            $this->binaryCache[$bin] = ($this->binaryExists)($bin);
        }

        return $this->binaryCache[$bin];
    }

    /**
     * Il file richiesto esiste, è regolare, leggibile, dentro la root e non un symlink. Se
     * `$executable`, deve anche essere eseguibile. Un percorso fuori confine o un symlink fanno
     * fallire chiuso il rilevamento (non disponibile), non un'eccezione.
     */
    private function fileUsable(CodeWorkspace $workspace, string $rel, bool $executable): bool
    {
        try {
            $abs = $workspace->resolve($rel);
        } catch (CodeWorkspaceException $e) {
            return false;
        }
        if (is_link($abs) || !is_file($abs) || !is_readable($abs)) {
            return false;
        }

        return !$executable || is_executable($abs);
    }

    /**
     * Cerca un file ESEGUIBILE `$bin` nelle directory di PATH. Nessuna shell, nessun processo:
     * solo `is_file`/`is_executable`. Un nome con separatore non è un binario su PATH: rifiutato.
     */
    private function binaryOnPath(string $bin): bool
    {
        if ($bin === '' || str_contains($bin, '/') || str_contains($bin, "\0")) {
            return false;
        }
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return false;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/' . $bin;
            if (is_file($candidate) && is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }
}
