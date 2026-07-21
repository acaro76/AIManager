<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\ContextEngine\ContextBuilderInterface;
use App\Core\ContextEngine\ContextEngine;
use App\Core\Security\LocalPermissions;
use App\Services\MigrationRunner;
use App\Services\PluginManager;
use App\Services\SqliteBackupService;

final class App
{
    private static ?self $instance = null;

    private function __construct(
        public readonly string $root,
        public readonly array $config,
        public readonly Database $db,
        public readonly Session $session,
        public readonly PluginManager $plugins,
        public readonly ContextBuilderInterface $context,
    ) {
    }

    public static function boot(string $root): self
    {
        if (self::$instance) {
            return self::$instance;
        }

        $config = require $root . '/config/app.php';
        date_default_timezone_set($config['timezone'] ?? 'UTC');

        Directory::ensure(dirname($config['database']));
        Directory::ensure($config['paths']['cache'] ?? $root . '/storage/cache');
        Directory::ensure($config['paths']['plugins'] ?? $root . '/plugins');

        // Fase 10 / Step 2 — permessi locali restrittivi su dati e segreti (puntuali, mai ricorsivi),
        // applicati NON appena i percorsi esistono, PRIMA di aprire il DB e di migrare. Il file SQLite
        // può non esistere ancora: a quel punto è un no-op e viene ristretto subito dopo `new Database`.
        LocalPermissions::secureEnv($root . '/.env');
        LocalPermissions::secureStorage(
            $config['paths']['storage'] ?? $root . '/storage',
            $config['database']
        );

        $session = new Session();
        $session->start();

        // Fase 10 / Step 3 — il DB è PREESISTENTE se il file esisteva PRIMA di aprirlo (`new Database`
        // lo crea se manca). Questo, non la presenza della tabella `migrations`, identifica davvero un
        // DB da proteggere: un file già presente ma con `migrations` assente/vuota va comunque
        // sottoposto a backup se ha migrazioni pendenti.
        $databaseExisted = is_file($config['database']);

        $db = new Database($config['database']);
        // Il file SQLite ora esiste: restringilo PRIMA del MigrationRunner.
        LocalPermissions::secureDatabaseFile($config['database']);

        // Backup automatico SOLO se il DB era preesistente E ci sono migrazioni pendenti. Il backup
        // (VACUUM INTO, coerente con WAL) è verificato prima; se fallisce, le migrazioni NON partono
        // (l'eccezione risale al confine d'errore del front controller).
        $runner = new MigrationRunner($db, $config['paths']['migrations'], $config['paths']['seeds']);
        if ($databaseExisted && $runner->pendingMigrations() !== []) {
            (new SqliteBackupService(
                $db,
                ($config['paths']['storage'] ?? $root . '/storage') . '/backups'
            ))->backup();
        }
        $runner->run();

        $plugins = new PluginManager($db, $config['paths']['plugins']);
        $plugins->discover();

        return self::$instance = new self($root, $config, $db, $session, $plugins, ContextEngine::default());
    }

    public static function get(): self
    {
        if (!self::$instance) {
            throw new \RuntimeException('Application has not been booted.');
        }

        return self::$instance;
    }
}
