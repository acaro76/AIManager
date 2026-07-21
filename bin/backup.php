<?php

declare(strict_types=1);

/**
 * Fase 10 / Step 4 — CLI minimale di backup del DB SQLite. NON avvia l'applicazione (nessun
 * bootstrap) e non esegue migrazioni: apre il DB ESISTENTE e riusa Database + LocalPermissions +
 * SqliteBackupService (già coperto dai test operativi). Stampa percorso e SHA-256 del backup
 * verificato; esce non-zero con messaggio controllato se il DB manca o il backup fallisce.
 *
 * Uso tipico: prima di un aggiornamento side-by-side, con la versione VECCHIA (vedi docs/RELEASE.md).
 */

require dirname(__DIR__) . '/app/Core/autoload.php';

use App\Core\Database;
use App\Core\Security\LocalPermissions;
use App\Services\SqliteBackupService;

$root = dirname(__DIR__);
$config = require $root . '/config/app.php';
$databasePath = (string) $config['database'];

// DB assente: nessun backup possibile. Non creare il file (new Database lo creerebbe).
if (!is_file($databasePath)) {
    fwrite(STDERR, "Errore: database non trovato ($databasePath): nessun backup.\n");
    exit(1);
}

try {
    $db = new Database($databasePath);
    LocalPermissions::secureDatabaseFile($databasePath);
    $result = (new SqliteBackupService(
        $db,
        ($config['paths']['storage'] ?? $root . '/storage') . '/backups'
    ))->backup();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Errore: backup non riuscito (' . get_class($e) . ").\n");
    exit(1);
}

fwrite(STDOUT, 'Backup:  ' . $result['path'] . "\n");
fwrite(STDOUT, 'SHA-256: ' . $result['sha256'] . "\n");
exit(0);
