<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. Policy DEDICATA e MINIMA per decidere quali percorsi Git sono
 * ESCLUSI da status e diff. Non duplica pattern: delega INTEGRALMENTE i file sensibili a
 * {@see SensitivePathPolicy} (segreti, chiavi, certificati, database, credenziali, `.git`) e aggiunge
 * soltanto l'esclusione delle directory RUNTIME del progetto Code.
 *
 * La roadmap della Fase 8 impone di non includere «segreti o runtime»: `SensitivePathPolicy` copre i
 * primi, questa policy aggiunge i secondi. Le directory runtime sono escluse a QUALSIASI profondità,
 * con confronto per SEGMENTI di percorso (mai sottostringa): `storage/` è escluso, `mystorage.php` no.
 *
 * `SensitivePathPolicy` NON è modificata: l'estensione runtime vive solo qui.
 */
final class GitPathPolicy
{
    /**
     * Directory runtime escluse a qualsiasi profondità (confronto per segmento, case-insensitive come
     * le esclusioni di rumore di RepoScanner). `storage/` raccoglie log, cache, DB e backup.
     *
     * @var list<string>
     */
    private const RUNTIME_DIRS = ['storage'];

    private readonly SensitivePathPolicy $sensitive;

    public function __construct(?SensitivePathPolicy $sensitive = null)
    {
        $this->sensitive = $sensitive ?? new SensitivePathPolicy();
    }

    /** Il percorso è escluso da Git perché sensibile OPPURE runtime? */
    public function isExcluded(string $relativePath): bool
    {
        return $this->sensitive->isSensitive($relativePath) || $this->isRuntime($relativePath);
    }

    /** Un segmento del percorso è una directory runtime? (confronto per segmenti, non sottostringa) */
    private function isRuntime(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment !== '' && in_array(strtolower($segment), self::RUNTIME_DIRS, true)) {
                return true;
            }
        }

        return false;
    }
}
