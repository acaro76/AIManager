<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 7 / F7.2. Il piano VALIDATO e TIPIZZATO di un processo persistente: profilo fisso,
 * host imposto, porta validata e directory RELATIVA (rivalidata via CodeWorkspace subito prima
 * dell'avvio). L'unica cosa che, superata la policy e la conferma esplicita, può essere avviata.
 *
 * Il DB conserva profilo/host/porta/directory in chiaro (NON sono segreti): la card mostra solo
 * `host:porta` e la directory RELATIVA, mai un path assoluto. Il `digest` lega il piano allo scope
 * e alla root, e viene ricalcolato alla conferma: qualunque divergenza → conferma negata.
 */
final class ProcessPlan
{
    /**
     * @param string $profileId profilo server-side (solo `php-server`)
     * @param string $host       host imposto dal server (`127.0.0.1`)
     * @param int    $port       porta non privilegiata validata
     * @param string $relDir     docroot RELATIVO alla root (`''` = radice del workspace)
     */
    public function __construct(
        public readonly string $profileId,
        public readonly string $host,
        public readonly int $port,
        public readonly string $relDir,
    ) {
    }

    /** Riepilogo SANIFICATO per card e audit: host:porta + directory RELATIVA. Mai path assoluti. */
    public function displaySummary(int $maxChars): string
    {
        $dir = $this->relDir === '' ? '(radice)' : $this->relDir;
        $summary = $this->profileId . ' ' . $this->host . ':' . $this->port . ' ' . $dir;

        return Utf8::cut(Utf8::clean($summary), max(1, $maxChars));
    }

    /** Etichetta breve (profilo + host:porta), senza directory. */
    public function shortLabel(): string
    {
        return $this->profileId . ' ' . $this->host . ':' . $this->port;
    }

    /**
     * Digest canonico e stabile del piano nello scope: lega profilo, host, porta, directory, root e
     * scope. Alla conferma si ricalcola e si confronta col digest persistito: qualunque divergenza
     * (piano manomesso, registro/policy cambiati) → conferma negata.
     */
    public function digest(string $rootPath, int $workspaceId, int $sessionId, int $policyVersion): string
    {
        $canonical = json_encode([
            'profile' => $this->profileId,
            'host' => $this->host,
            'port' => $this->port,
            'directory' => $this->relDir,
            'root' => $rootPath,
            'workspace' => $workspaceId,
            'session' => $sessionId,
            'policy_version' => $policyVersion,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $canonical);
    }
}
