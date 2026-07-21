<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class MigrationRunner
{
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationPath,
        private readonly string $seedPath,
    ) {
    }

    public function run(): void
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY, migrated_at TEXT NOT NULL)');

        foreach ($this->files($this->migrationPath) as $file) {
            $name = basename($file);
            $done = $this->db->fetch('SELECT name FROM migrations WHERE name = ?', [$name]);
            if ($done) {
                continue;
            }

            $migration = require $file;
            // Migrazione + registrazione nella stessa transazione: se la migrazione
            // fallisce a meta', si fa rollback e al boot successivo NON riparte su uno
            // schema parzialmente modificato.
            $this->db->transaction(function () use ($migration, $name): void {
                $migration($this->db);
                $this->db->execute('INSERT INTO migrations (name, migrated_at) VALUES (?, ?)', [$name, date('c')]);
            });
        }

        $seeded = $this->db->fetch('SELECT value FROM settings WHERE key = ?', ['seeded_at']);
        if (!$seeded) {
            // Anche i seed + il marcatore 'seeded_at' in un'unica transazione: se un seed
            // fallisce a meta', niente marcatore e niente dati seed a meta'.
            $this->db->transaction(function (): void {
                foreach ($this->files($this->seedPath) as $file) {
                    $seed = require $file;
                    $seed($this->db);
                }
                $this->db->execute('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)', ['seeded_at', date('c'), date('c')]);
            });
        }
    }

    /**
     * Nomi delle migrazioni NON ancora applicate, in ordine (Fase 10 / Step 3). SOLA LETTURA: non crea
     * la tabella `migrations` né tocca nulla, così può essere interrogata PRIMA di `run()`.
     *
     * @return list<string>
     */
    public function pendingMigrations(): array
    {
        $applied = $this->appliedNames();
        $pending = [];
        foreach ($this->files($this->migrationPath) as $file) {
            $name = basename($file);
            if (!isset($applied[$name])) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    /**
     * Nomi già applicati (chiavi del set). `[]` se la tabella `migrations` non esiste ancora: nessuna
     * mutazione, nessuna creazione di tabella.
     *
     * @return array<string, true>
     */
    private function appliedNames(): array
    {
        $exists = $this->db->fetch("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'migrations'");
        if ($exists === null) {
            return [];
        }

        $names = [];
        foreach ($this->db->fetchAll('SELECT name FROM migrations') as $row) {
            $names[(string) $row['name']] = true;
        }

        return $names;
    }

    private function files(string $path): array
    {
        $files = glob(rtrim($path, '/') . '/*.php') ?: [];
        sort($files);
        return $files;
    }
}
