<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Reparto Code — Fase 0. Policy CONDIVISA dei file sensibili (regola trasversale 2):
 * un unico punto di verità usato sia dalla scansione (RepoScanner, F0.3) sia dalla
 * lettura mirata (TargetedRetriever, F0.5). Nessuna duplicazione.
 *
 * Decide se un percorso è "sensibile" e quindi mai letto né mappato: segreti, chiavi,
 * certificati, database, credenziali e la directory .git. La lista base è estendibile.
 */
final class SensitivePathPolicy
{
    /** @var string[] pattern fnmatch (match sul basename), sempre in minuscolo */
    private const DEFAULTS = [
        '.env', '.env.*',
        '*.pem', '*.key', '*.ppk', 'id_rsa', 'id_rsa.*', 'id_dsa', 'id_ecdsa', 'id_ed25519',
        '*.crt', '*.cer', '*.p12', '*.pfx',
        '*.sqlite', '*.sqlite3', '*.db',
        '.htpasswd', '.netrc', '.pgpass', '.npmrc',
        'credentials', 'credentials.*', '*.secret', 'secrets.*',
    ];

    /** @var string[] */
    private array $patterns;

    /**
     * @param string[] $extraPatterns pattern fnmatch aggiuntivi (match sul basename)
     */
    public function __construct(array $extraPatterns = [])
    {
        $this->patterns = array_map('strtolower', array_merge(self::DEFAULTS, $extraPatterns));
    }

    public function isSensitive(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);

        // .git ovunque nel percorso (directory o suoi contenuti), case-insensitive
        // come il resto della policy.
        foreach (explode('/', $normalized) as $segment) {
            if (strtolower($segment) === '.git') {
                return true;
            }
        }

        $base = strtolower(basename($normalized));
        if ($base === '') {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            if (fnmatch($pattern, $base)) {
                return true;
            }
        }

        return false;
    }
}
