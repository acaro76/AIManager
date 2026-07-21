<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Fase 10 / Step 2 — servizio SICURO degli allegati. Il MIME PERSISTITO non è affidabile (deciso a
 * monte dall'upload): qui si rileva il tipo REALE dal contenuto e si serve `inline` SOLO un raster
 * effettivamente riconosciuto e in allow-list. Tutto il resto (testo, HTML, SVG, documenti, tipi
 * sconosciuti o discordanti dal MIME persistito) diventa `application/octet-stream` +
 * `Content-Disposition: attachment`, così il browser non lo esegue né lo interpreta.
 */
final class MediaTypePolicy
{
    /** @var list<string> Raster ammessi in `inline` (rilevati dal contenuto, non dal MIME persistito). */
    private const INLINE_IMAGE = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /**
     * Rileva il MIME REALE dal contenuto. Usa `getimagesize()` (magic bytes): un raster reale torna il
     * suo `image/*`, qualunque altro file (testo, SVG=XML, HTML, PDF, sconosciuto) torna
     * `application/octet-stream`. Non si fida mai dell'estensione o del MIME salvato.
     */
    public static function detect(string $path): string
    {
        $info = @getimagesize($path);
        $mime = is_array($info) && isset($info['mime']) ? (string) $info['mime'] : '';

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    /**
     * Decide MIME servito e disposizione. `inline` solo per un raster reale in allow-list e SOLO se non
     * è forzato il download; in ogni altro caso octet-stream + attachment.
     *
     * @return array{mime: string, disposition: string}
     */
    public static function decide(string $detectedMime, bool $forceDownload): array
    {
        if (!$forceDownload && in_array($detectedMime, self::INLINE_IMAGE, true)) {
            return ['mime' => $detectedMime, 'disposition' => 'inline'];
        }

        return ['mime' => 'application/octet-stream', 'disposition' => 'attachment'];
    }

    /**
     * Comodità per il controller: rileva dal file e decide in un colpo solo.
     *
     * @return array{mime: string, disposition: string}
     */
    public static function forFile(string $path, bool $forceDownload): array
    {
        return self::decide(self::detect($path), $forceDownload);
    }
}
