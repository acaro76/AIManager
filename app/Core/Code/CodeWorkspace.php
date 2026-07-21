<?php

declare(strict_types=1);

namespace App\Core\Code;


/**
 * Code — il workspace: una cartella autorizzata (la cartella È il progetto Code, nessun
 * legame con i progetti LLM), con operazioni CONFINATE (via PathGuard) e filtro dei file
 * sensibili (SensitivePathPolicy).
 *
 * Ogni operazione ricostruisce il PathGuard, così la radice viene RI-VALIDATA a ogni
 * uso (esiste ancora? non è un symlink?): se è stata spostata/eliminata, si fallisce
 * chiuso.
 */
final class CodeWorkspace
{
    public function __construct(
        public readonly int $id,
        public readonly string $rootPath,
        public readonly string $name,
        public readonly string $status,
        private readonly SensitivePathPolicy $sensitive,
        private readonly CodeSelfProtection $selfProtection = new CodeSelfProtection(),
    ) {
    }

    private function guard(): PathGuard
    {
        // Vale anche per workspace storici già persistiti: il confine viene
        // rivalidato prima di ogni accesso al filesystem.
        $this->selfProtection->assertSeparate($this->rootPath);
        return new PathGuard($this->rootPath);
    }

    /**
     * La radice è ancora usabile? (esiste, è directory, non è symlink, non è la root del
     * filesystem). Serve alla UI/al controller per distinguere "attiva" da "non valida"
     * senza tentare un'operazione.
     */
    public function rootIsValid(): bool
    {
        try {
            $this->guard();
            return true;
        } catch (CodeWorkspaceException $e) {
            return false;
        }
    }

    /**
     * La revoca dev'essere rispettata QUI, nel componente che tocca il filesystem, non
     * demandata a ogni futuro chiamante (es. RepoScanner). Un workspace non 'active'
     * fallisce chiuso su ogni operazione.
     */
    private function assertActive(): void
    {
        if ($this->status !== 'active') {
            throw new CodeWorkspaceException('Workspace non attivo: accesso non consentito.');
        }
    }

    public function resolve(string $relative): string
    {
        $this->assertActive();
        return $this->guard()->resolve($relative);
    }

    /**
     * La policy dei file sensibili applicata al percorso relativo COMPLETO. Espone la stessa
     * decisione usata da read/scan, così la modifica sicura (Fase 4) può negare esplicitamente
     * un `.env`/chiave/DB con un motivo chiaro invece di intercettare un'eccezione generica.
     */
    public function isSensitive(string $relative): bool
    {
        return $this->sensitive->isSensitive($relative);
    }

    /**
     * Percorso assoluto CONFINATO per una SCRITTURA (Fase 4): workspace attivo, dentro la root,
     * nessun symlink nel percorso, file non sensibile. NON richiede che il file esista (serve sia
     * per update sia per create): l'esistenza/assenza la verifica chi scrive, sul path risolto.
     *
     * Riusa PathGuard e SensitivePathPolicy: il confine non è riscritto altrove. È l'unico ingresso
     * alla scrittura, esattamente come read()/readLimited() lo sono per la lettura.
     */
    public function assertWritable(string $relative): string
    {
        $this->assertActive();
        if ($this->sensitive->isSensitive($relative)) {
            throw new CodeWorkspaceException('File sensibile: scrittura non consentita.');
        }
        return $this->guard()->resolve($relative);
    }

    public function read(string $relative): string
    {
        $this->assertActive();
        // I file sensibili non si leggono nemmeno se richiesti esplicitamente (regola 2).
        if ($this->sensitive->isSensitive($relative)) {
            throw new CodeWorkspaceException('File sensibile: lettura non consentita.');
        }
        $path = $this->guard()->resolve($relative);
        // Ri-controllo al momento della lettura (file eliminato tra resolve e read,
        // symlink comparso, permessi): fail closed.
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new CodeWorkspaceException('File non trovato o non leggibile.');
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new CodeWorkspaceException('Lettura del file fallita.');
        }
        return $content;
    }

    /**
     * Lettura CONFINATA e LIMITATA: legge al massimo $maxBytes + 1 byte (non l'intero
     * file) e rifiuta se il file supera $maxBytes. Non si affida a filesize(): è la
     * lettura stessa a garantire il limite, così un file enorme non viene mai caricato.
     */
    public function readLimited(string $relative, int $maxBytes): string
    {
        $this->assertActive();
        if ($this->sensitive->isSensitive($relative)) {
            throw new CodeWorkspaceException('File sensibile: lettura non consentita.');
        }
        if ($maxBytes < 0) {
            throw new CodeWorkspaceException('Limite di lettura non valido.');
        }
        $path = $this->guard()->resolve($relative);
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new CodeWorkspaceException('File non trovato o non leggibile.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new CodeWorkspaceException('Apertura del file fallita.');
        }
        try {
            $content = @fread($handle, $maxBytes + 1);
        } finally {
            fclose($handle);
        }
        if ($content === false) {
            throw new CodeWorkspaceException('Lettura del file fallita.');
        }
        if (strlen($content) > $maxBytes) {
            throw new CodeWorkspaceException('File oltre la soglia di lettura.');
        }
        return $content;
    }

    /**
     * Elenco dei file/directory IMMEDIATI della radice (non ricorsivo), esclusi symlink
     * e file sensibili. Contratto F0.2: name + type.
     *
     * @return array<int, array{name: string, type: string}>
     */
    public function listFiles(): array
    {
        return array_map(
            static fn (array $e): array => ['name' => basename($e['path']), 'type' => $e['type']],
            $this->scanDir('')
        );
    }

    /**
     * Come listFiles ma per una sottodirectory relativa, con percorso relativo COMPLETO
     * e dimensione. È l'API confinata che il RepoScanner (F0.3) usa per la ricorsione:
     * ogni accesso passa comunque da assertActive + PathGuard + SensitivePathPolicy, così
     * la revoca e il confine non vanno ricreati altrove.
     *
     * @return array<int, array{path: string, type: string, size: int}>
     */
    public function children(string $relative = ''): array
    {
        return $this->scanDir($relative);
    }

    /**
     * Lister confinato condiviso: attivo? radice valida? dentro il confine? niente
     * symlink (nemmeno directory)? niente sensibili (sul percorso relativo COMPLETO)?
     * Risultato ordinato per path (deterministico).
     *
     * @return array<int, array{path: string, type: string, size: int}>
     */
    private function scanDir(string $relative): array
    {
        $this->assertActive();
        $abs = $this->guard()->resolve($relative);
        if (is_link($abs) || !is_dir($abs)) {
            throw new CodeWorkspaceException('Directory non valida.');
        }
        $names = @scandir($abs);
        if ($names === false) {
            throw new CodeWorkspaceException('Impossibile leggere la cartella.');
        }

        $prefix = $relative === '' ? '' : rtrim(str_replace('\\', '/', $relative), '/') . '/';
        $out = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $rel = $prefix . $name;
            $full = $abs . '/' . $name;
            if (is_link($full)) {
                continue; // nessun symlink, nemmeno a directory
            }
            if ($this->sensitive->isSensitive($rel)) {
                continue; // policy sul percorso relativo COMPLETO
            }
            if (is_dir($full)) {
                $out[] = ['path' => $rel, 'type' => 'dir', 'size' => 0];
            } elseif (is_file($full)) {
                // filesize fallita => dimensione SCONOSCIUTA (-1), non zero: chi legge
                // (RepoScanner) negherà l'estrazione invece di trattarla come vuota.
                $size = @filesize($full);
                $out[] = ['path' => $rel, 'type' => 'file', 'size' => $size === false ? -1 : (int) $size];
            }
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $out;
    }
}
