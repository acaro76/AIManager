<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 6 / F6.5. Deposito PROTETTO del CommandPlan canonico di una proposta, analogo a
 * CodePatchStore. Vive FUORI dal DB (che tiene solo `digest` e metadati sanificati) e fuori dal
 * workspace: sotto `storage/code_commands/` (gitignored), permessi ristretti (dir 0700, file 0600).
 *
 * Serve a DUE cose locali, entrambe fino alla conferma: ri-mostrare la card (programma, flag,
 * pattern bounded, path relativi) ed ESEGUIRE il comando esatto quando l'utente conferma. Il pattern
 * grezzo vive SOLO qui, mai nel DB.
 *
 * Difese: id validato, scrittura atomica (tmp + rename), lettura che RIFIUTA un file symlink
 * (no-symlink) e un JSON corrotto (fail closed → nessuna esecuzione), e TTL (prune per mtime).
 */
final class CommandStore
{
    private readonly string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * Directory `base/proposals` VALIDATA contro i directory-symlink: base e proposals non possono
     * essere symlink (a nessun livello). Null (fail closed) se non sicura o non creabile.
     */
    private function safeDir(bool $create): ?string
    {
        $anchor = dirname($this->baseDir);
        $dir = SafeStorageDir::ensure($anchor, [basename($this->baseDir), 'proposals'], false);
        if ($dir === null) {
            if ($create) {
                throw new \RuntimeException('Directory delle proposte comando non sicura o non creabile.');
            }
            return null;
        }

        return $dir;
    }

    /**
     * @param array{program:string,flags:list<string>,pattern:?string,rel_paths:list<string>} $plan
     */
    public function write(string $commandId, string $digest, int $policyVersion, string $programExe, array $plan): void
    {
        $dir = $this->safeDir(true);
        $payload = [
            'command_id' => $commandId,
            'digest' => $digest,
            'policy_version' => $policyVersion,
            'program_exe' => $programExe,
            'plan' => $plan,
            'created_at' => date('c'),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('Serializzazione del piano comando non riuscita.');
        }

        $file = $this->file((string) $dir, $commandId);
        AtomicFileWriter::replace($file, $json);
        @chmod($file, 0600);
    }

    /**
     * @return array{digest:string,policy_version:int,program_exe:string,plan:CommandPlan}|null
     */
    public function read(string $commandId): ?array
    {
        $dir = $this->safeDir(false);
        if ($dir === null) {
            return null; // catena directory non sicura → fail closed
        }
        $file = $this->file($dir, $commandId);
        if (!is_file($file) || is_link($file)) {
            return null; // no-symlink: fail closed
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['plan']) || !is_array($data['plan'])) {
            return null;
        }
        $plan = CommandPlan::fromStore($data['plan']);
        if ($plan === null) {
            return null;
        }

        return [
            'digest' => (string) ($data['digest'] ?? ''),
            'policy_version' => (int) ($data['policy_version'] ?? 0),
            'program_exe' => (string) ($data['program_exe'] ?? ''),
            'plan' => $plan,
        ];
    }

    public function delete(string $commandId): void
    {
        $dir = $this->safeDir(false);
        if ($dir === null) {
            return;
        }
        @unlink($this->file($dir, $commandId));
    }

    /** Rimuove le proposte più vecchie del TTL (le loro righe DB diventano `expired` a valle). */
    public function prune(int $maxAgeSeconds): void
    {
        $dir = $this->safeDir(false);
        if ($dir === null) {
            return;
        }
        $cutoff = time() - max(1, $maxAgeSeconds);
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (is_file($file) && !is_link($file) && @filemtime($file) !== false && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function file(string $dir, string $commandId): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $commandId) !== 1) {
            throw new \InvalidArgumentException('Identificativo di comando non valido.');
        }

        return $dir . '/' . $commandId . '.json';
    }
}
