<?php

declare(strict_types=1);

namespace App\Core\Cancellation;

final class CancellationStore
{
    public function __construct(private readonly string $path)
    {
        if (!is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }
    }

    public function token(string $id): CancellationToken
    {
        return new CancellationToken($this, $this->sanitize($id));
    }

    /**
     * Registra l'inizio di una richiesta correlata. Un eventuale `.cancel` gia' presente viene
     * conservato: lo Stop puo' arrivare sul secondo worker un istante PRIMA che la richiesta
     * streaming abbia completato il proprio bootstrap.
     */
    public function begin(string $id): void
    {
        $id = $this->sanitize($id);
        if ($id === '') {
            return;
        }

        $this->synchronized(function () use ($id): void {
            @unlink($this->doneFile($id));
            file_put_contents($this->activeFile($id), (string) time());
        });
    }

    /**
     * Conclude atomicamente la richiesta: rimuove stato attivo/cancellazione e lascia un
     * tombstone breve. Cosi' uno Stop tardivo sa che l'id e' gia' terminato e non crea un
     * `.cancel` orfano. Il tombstone viene eliminato da prune().
     */
    public function finish(string $id): void
    {
        $id = $this->sanitize($id);
        if ($id === '') {
            return;
        }

        $this->synchronized(function () use ($id): void {
            @unlink($this->activeFile($id));
            @unlink($this->file($id));
            file_put_contents($this->doneFile($id), (string) time());
        });
    }

    /**
     * Cancella una richiesta non ancora iniziata o attiva. Se e' gia' conclusa non scrive
     * nulla: il controllo del tombstone e la scrittura sono protetti dallo stesso lock.
     */
    public function cancelPendingOrActive(string $id): bool
    {
        $id = $this->sanitize($id);
        if ($id === '') {
            return false;
        }

        return $this->synchronized(function () use ($id): bool {
            if (is_file($this->doneFile($id))) {
                return false;
            }
            file_put_contents($this->file($id), (string) time());

            return true;
        });
    }

    public function cancel(string $id): void
    {
        $id = $this->sanitize($id);
        if ($id === '') {
            return;
        }

        file_put_contents($this->file($id), (string) time());
    }

    public function clear(string $id): void
    {
        $id = $this->sanitize($id);
        if ($id === '') {
            return;
        }

        $file = $this->file($id);
        if (is_file($file)) {
            unlink($file);
        }
    }

    public function isCancelled(string $id): bool
    {
        $id = $this->sanitize($id);
        return $id !== '' && is_file($this->file($id));
    }

    /**
     * Rimuove i file di cancellazione piu' vecchi di $maxAgeSeconds: sono stati transitori
     * che nessuno consuma piu' dopo la fine della richiesta e resterebbero su disco (audit).
     */
    public function prune(int $maxAgeSeconds = 3600): void
    {
        $cutoff = time() - max(0, $maxAgeSeconds);
        foreach (glob($this->path . '/*.{cancel,active,done}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) && (int) @filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function file(string $id): string
    {
        return $this->path . '/' . $id . '.cancel';
    }

    private function activeFile(string $id): string
    {
        return $this->path . '/' . $id . '.active';
    }

    private function doneFile(string $id): string
    {
        return $this->path . '/' . $id . '.done';
    }

    /** @template T @param callable(): T $operation @return T */
    private function synchronized(callable $operation): mixed
    {
        $handle = fopen($this->path . '/.lifecycle.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Impossibile aprire il lock delle cancellazioni.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Impossibile acquisire il lock delle cancellazioni.');
            }

            return $operation();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function sanitize(string $id): string
    {
        return preg_match('/^[a-zA-Z0-9_-]{12,80}$/', $id) ? $id : '';
    }
}
