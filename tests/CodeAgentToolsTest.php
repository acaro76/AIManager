<?php

declare(strict_types=1);

use App\Core\Code\CodeAgentAction;
use App\Core\Code\CodeAgentLimits;
use App\Core\Code\CodeAgentTools;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;

// Fase 3 — gli strumenti del ciclo: SOLO LETTURA e sempre mediati da CodeWorkspace. Il confine
// non è una promessa del prompt: qui si verifica che regga qualunque cosa il modello chieda.
// Cartelle temporanee, nessun DB, nessun provider.

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_link($path)) { @unlink($path); return; }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) {
            if ($e === '.' || $e === '..') { continue; }
            $rmrf($path . '/' . $e);
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$mkroot = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aimanager_agtools_' . uniqid('', true);
    mkdir($root . '/app/Auth', 0777, true);
    file_put_contents($root . '/app/Auth/Login.php', "<?php\nfunction login() { return true; }\n");
    file_put_contents($root . '/README.md', "Il login del progetto.\n");
    file_put_contents($root . '/.env', "SECRET_KEY=super-segreto\n");
    file_put_contents($root . '/logo.png', "\x89PNG\r\n\x1a\n\0\0binario");
    // fuori dalla root: bersaglio classico di un tentativo di uscita dal confine
    file_put_contents(dirname($root) . '/fuori-' . basename($root) . '.txt', "segreto fuori dalla root\n");
    return $root;
};

$ws = static fn (string $root, string $status = 'active'): CodeWorkspace
    => new CodeWorkspace(1, $root, basename($root), $status, new SensitivePathPolicy());

$tools = static fn (?callable $isActive = null): CodeAgentTools
    => new CodeAgentTools(RetrievalLimits::defaults(), CodeAgentLimits::defaults(), $isActive);

$action = static fn (array $data): CodeAgentAction
    => CodeAgentAction::parse((string) json_encode($data), CodeAgentLimits::defaults());

test('CodeAgentTools: read_file legge un file consentito e produce evidenza', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $step = $tools()->execute($ws($root), $action(['action' => 'read_file', 'path' => 'app/Auth/Login.php']), 12, 262144);

        assertSame('ok', $step->outcome);
        assertSame('read', $step->auditAction);           // audit: vocabolario ESISTENTE
        assertSame('app/Auth/Login.php', $step->relPath);
        assertSame('app/Auth/Login.php', $step->readFile['path']);
        assertSame(true, str_contains($step->observation, 'function login'));
        assertSame(1, $step->metrics['filesRead']);
        // il contenuto rientra DELIMITATO e marcato come dato
        assertSame(true, str_contains($step->observation, 'DATI NON FIDATI'));
    } finally { $rmrf($root); }
});

test('CodeAgentTools: file SENSIBILE negato — .env non si legge nemmeno se il modello insiste', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $step = $tools()->execute($ws($root), $action(['action' => 'read_file', 'path' => '.env']), 12, 262144);

        assertSame('denied', $step->outcome);             // negato = DATO, non eccezione
        assertSame(null, $step->readFile);
        assertSame(false, str_contains($step->observation, 'super-segreto'));
        assertSame(['read:skipped'], $step->limits);
    } finally { $rmrf($root); }
});

test('CodeAgentTools: file BINARIO rifiutato (mai nel contesto)', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $step = $tools()->execute($ws($root), $action(['action' => 'read_file', 'path' => 'logo.png']), 12, 262144);
        assertSame('denied', $step->outcome);
        assertSame(null, $step->readFile);
    } finally { $rmrf($root); }
});

test('CodeAgentTools: SYMLINK verso l\'esterno non attraversato', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $target = dirname($root) . '/fuori-' . basename($root) . '.txt';
        @symlink($target, $root . '/scorciatoia.txt');
        if (!is_link($root . '/scorciatoia.txt')) {
            assertSame(true, true); // symlink non creabile in questo ambiente: nulla da provare
            return;
        }
        $step = $tools()->execute($ws($root), $action(['action' => 'read_file', 'path' => 'scorciatoia.txt']), 12, 262144);

        assertSame('denied', $step->outcome);
        assertSame(false, str_contains($step->observation, 'segreto fuori dalla root'));

        // e nemmeno comparendo nell'elenco della directory
        $list = $tools()->execute($ws($root), $action(['action' => 'list_dir', 'path' => '']), 12, 262144);
        assertSame(false, str_contains($list->observation, 'scorciatoia.txt'));
    } finally {
        @unlink(dirname($root) . '/fuori-' . basename($root) . '.txt');
        $rmrf($root);
    }
});

test('CodeAgentTools: workspace REVOCATO — nessuno strumento tocca il filesystem', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $revoked = $ws($root, 'revoked');
        $isActive = static fn (): bool => false;

        $read = $tools($isActive)->execute($revoked, $action(['action' => 'read_file', 'path' => 'README.md']), 12, 262144);
        assertSame('denied', $read->outcome);
        assertSame(['revoked'], $read->limits);
        assertSame(null, $read->readFile);

        $list = $tools($isActive)->execute($revoked, $action(['action' => 'list_dir', 'path' => '']), 12, 262144);
        assertSame('denied', $list->outcome);

        $find = $tools($isActive)->execute($revoked, $action(['action' => 'find_files', 'query' => 'login']), 12, 262144);
        assertSame('error', $find->outcome);              // l'inventario stesso è negato
        assertSame([], $find->hits);
    } finally { $rmrf($root); }
});

test('CodeAgentTools: budget di lettura esaurito → limited, non un errore', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $noFiles = $tools()->execute($ws($root), $action(['action' => 'read_file', 'path' => 'README.md']), 0, 262144);
        assertSame('limited', $noFiles->outcome);
        assertSame(['read:files'], $noFiles->limits);

        $noBytes = $tools()->execute($ws($root), $action(['action' => 'read_file', 'path' => 'README.md']), 12, 0);
        assertSame('limited', $noBytes->outcome);
        assertSame(['read:totalBytes'], $noBytes->limits);
        assertSame(null, $noBytes->readFile);
    } finally { $rmrf($root); }
});

test('CodeAgentTools: find_files e search_text producono indizi, non contenuto', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $find = $tools()->execute($ws($root), $action(['action' => 'find_files', 'query' => 'login']), 12, 262144);
        assertSame('ok', $find->outcome);
        assertSame('retrieval', $find->auditAction);
        assertSame(null, $find->relPath);                 // una ricerca non è una lettura
        assertSame(true, str_contains($find->observation, 'app/Auth/Login.php'));
        assertSame(null, $find->readFile);                // nessun file letto

        $search = $tools()->execute($ws($root), $action(['action' => 'search_text', 'query' => 'login']), 12, 262144);
        assertSame('retrieval', $search->auditAction);
        assertSame(true, count($search->hits) > 0);
        assertSame(null, $search->readFile);
    } finally { $rmrf($root); }
});

test('CodeAgentTools: la ricerca NON attraversa i file sensibili', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $step = $tools()->execute($ws($root), $action(['action' => 'search_text', 'query' => 'segreto']), 12, 262144);
        assertSame(false, str_contains($step->observation, 'super-segreto'));
        foreach ($step->hits as $hit) {
            assertSame(false, str_contains($hit['path'], '.env'));
        }
    } finally { $rmrf($root); }
});

test('CodeAgentTools: l\'inventario viene scansionato UNA volta e riusato', function () use ($mkroot, $ws, $tools, $action, $rmrf) {
    $root = $mkroot();
    try {
        $t = $tools();
        assertSame(0, count($t->inventory()->files()));   // non ancora scansionato
        $t->execute($ws($root), $action(['action' => 'find_files', 'query' => 'login']), 12, 262144);
        $first = count($t->inventory()->files());
        assertSame(true, $first > 0);
        $t->execute($ws($root), $action(['action' => 'search_text', 'query' => 'progetto']), 12, 262144);
        assertSame($first, count($t->inventory()->files()));
    } finally { $rmrf($root); }
});
