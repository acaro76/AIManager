<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.6. Lock ESCLUSIVO per workspace, per serializzare le mutazioni: una sola
 * applicazione (o rollback, o recovery) alla volta su una cartella. Basato su `flock`, quindi
 * valido anche tra PROCESSI diversi (il server CLI con più worker fa fork): due richieste
 * concorrenti non possono applicare la stessa proposta né due proposte in parallelo sullo stesso
 * workspace.
 *
 * È NON BLOCCANTE (`LOCK_NB`): se il lock è già preso, `acquire()` torna false e il chiamante
 * risponde "occupato" invece di restare appeso. Il file di lock vive sotto
 * `storage/code_patches/locks/`; un crash rilascia automaticamente il flock col processo.
 */
final class CodeWorkspaceLock
{
    private readonly string $dir;

    /** @var resource|null */
    private $handle = null;

    public function __construct(string $baseDir)
    {
        $this->dir = rtrim($baseDir, '/') . '/locks';
    }

    /** Acquisisce il lock esclusivo (non bloccante). True se ottenuto, false se già preso. */
    public function acquire(int $workspaceId): bool
    {
        if ($this->handle !== null) {
            throw new \RuntimeException('Lock già acquisito da questa istanza.');
        }
        $this->ensureDir();
        $file = $this->dir . '/ws-' . $workspaceId . '.lock';
        $handle = @fopen($file, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Impossibile aprire il file di lock del workspace.');
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
            $this->handle = null;
        }
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Impossibile creare la directory dei lock delle patch.');
        }
    }
}
