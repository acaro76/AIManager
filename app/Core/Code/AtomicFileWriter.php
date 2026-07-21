<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 4 / F4.6. Scrittura ATOMICA di un file: staging di un temporaneo NELLA STESSA
 * directory del target, flush + fsync, poi rename atomico. Chi legge il target vede sempre o il
 * contenuto vecchio o quello nuovo, mai un file scritto a metà; un crash a metà scrittura lascia
 * il target intatto (resta solo il temporaneo, che viene rimosso).
 *
 * Non conosce il workspace né il confine: riceve un percorso ASSOLUTO già risolto e verificato
 * dal chiamante (CodePatchMutationService, via CodeWorkspace::assertWritable). I permessi del
 * file risultante sono responsabilità del chiamante: per i nuovi file passa 0644, per gli update
 * preserva il mode originale (incluso l'eventuale bit eseguibile).
 */
final class AtomicFileWriter
{
    /**
     * Sostituisce (o crea) $absPath con $content in modo atomico. $mode sono i permessi del file
     * risultante (default 0644). I bit applicati sono i 9 bit standard (owner/group/other rwx):
     * il chiamante decide se includere il bit eseguibile. Lancia \RuntimeException su qualunque
     * errore, così il chiamante può compensare.
     */
    public static function replace(string $absPath, string $content, int $mode = 0644): void
    {
        $dir = dirname($absPath);
        // Il temporaneo DEVE stare nella stessa directory del target, sullo stesso filesystem,
        // altrimenti il rename non sarebbe atomico.
        $tmp = @tempnam($dir, '.codepatch-');
        if ($tmp === false) {
            throw new \RuntimeException('Impossibile creare il file temporaneo per la scrittura atomica.');
        }

        try {
            $handle = @fopen($tmp, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Apertura del file temporaneo non riuscita.');
            }
            try {
                $written = @fwrite($handle, $content);
                if ($written === false || $written !== strlen($content)) {
                    throw new \RuntimeException('Scrittura del file temporaneo non riuscita.');
                }
                @fflush($handle);
                if (function_exists('fsync')) {
                    @fsync($handle);
                }
            } finally {
                @fclose($handle);
            }

            @chmod($tmp, $mode & 0777);
            if (!@rename($tmp, $absPath)) {
                throw new \RuntimeException('Rename atomico non riuscito.');
            }
            $tmp = null; // rinominato con successo: niente da ripulire
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /** Elimina un file (per il rollback di una creazione). Idempotente. */
    public static function delete(string $absPath): void
    {
        if (is_file($absPath)) {
            if (!@unlink($absPath)) {
                throw new \RuntimeException('Eliminazione del file non riuscita.');
            }
        }
    }
}
