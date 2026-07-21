<?php

declare(strict_types=1);

use App\Core\Code\CodeAgentLimits;
use App\Core\Code\CodeAgentLoop;
use App\Core\Code\CodeAgentTools;
use App\Core\Code\CodeChatSchema;
use App\Core\Code\CodeCommandTool;
use App\Core\Code\CodePatchLimits;
use App\Core\Code\CodePatchSchema;
use App\Core\Code\CodeSessionRepository;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\CodeWorkspaceRepository;
use App\Core\Code\CommandPlan;
use App\Core\Code\CommandProgramResolver;
use App\Core\Code\CommandRunner;
use App\Core\Code\CommandRunSchema;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;
use App\Core\Database;
use App\Core\Providers\ProviderRequest;
use App\Services\AIProviderResult;
use App\Services\CodeChatService;

/**
 * Coerenza delle richieste ESPLICITE di comando.
 *
 * Caso reale: a «proponi un comando che mi dica il tipo di bin.dat», ripetuto tre volte, il ciclo
 * accettò due `propose_file` (una rifiutata, una APPLICATA a un file mai in discussione) e al terzo
 * turno chiuse con del testo che dichiarava una proposta inesistente. Nel DB: patch operations
 * 25/26, nessun `command_run`. Causa: esisteva `proposalRequired` ma nessun equivalente per i
 * comandi, quindi con write e commands attivi il ciclo accettava indifferentemente `run_command`,
 * `propose_file`, `propose_patch` o `answer`.
 */
$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_link($path)) { @unlink($path); return; }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) { if ($e === '.' || $e === '..') { continue; } $rmrf($path . '/' . $e); }
        @rmdir($path);
        return;
    }
    @unlink($path);
};
$mkroot = static function (): string {
    $base = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $root = $base . '/aim_cmdintent_' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/bin.dat', "\x00\x01binario\n");
    file_put_contents($root . '/config.php', "<?php\nreturn ['debug' => false];\n");
    return $root;
};
$ws = static fn (string $root): CodeWorkspace => new CodeWorkspace(1, $root, basename($root), 'active', new SensitivePathPolicy());
$scripted = static function (array $script, ?int &$calls = null): callable {
    $i = 0;
    return static function () use ($script, &$i, &$calls): string {
        $calls = ++$i;
        return (string) ($script[$i - 1] ?? end($script));
    };
};
$loop = static function (callable $decider, bool $withCommands): CodeAgentLoop {
    $limits = RetrievalLimits::defaults();
    $agent = CodeAgentLimits::defaults();
    return new CodeAgentLoop(
        limits: $limits,
        agentLimits: $agent,
        tools: new CodeAgentTools($limits, $agent),
        decider: $decider,
        writeEnabled: true,
        patchLimits: CodePatchLimits::defaults(),
        commandsEnabled: $withCommands,
        commands: $withCommands ? new CodeCommandTool() : null,
    );
};
// I comandi sono offribili solo dove l'isolamento del process group c'è e `cat` è in bin fidata.
$commandsUsable = CommandRunner::supportsProcessGroupIsolation()
    && (new CommandProgramResolver())->resolve('cat') !== null;

$intent = static function (string $prompt): bool {
    $svc = (new ReflectionClass(CodeChatService::class))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod(CodeChatService::class, 'commandRequested');

    return (bool) $m->invoke($svc, $prompt);
};

test('intento comando: riconosce le richieste esplicite, e SOLO quelle', function () use ($intent) {
    // Il prompt reale del difetto, e stretti equivalenti.
    assertSame(true, $intent('proponi un comando che mi dica il tipo di bin.dat'));
    assertSame(true, $intent('Ora proponi il comando tail -n 3 prova3.txt'));
    assertSame(true, $intent('esegui un comando per contare le righe'));
    assertSame(true, $intent('Mostrami le prime 5 righe di prova3.txt usando un comando di sistema'));
    // Richieste normali: NON devono diventare obbligo di comando, o non potrebbero più chiudersi
    // con `answer` o con una patch.
    assertSame(false, $intent('leggi il README e dimmi cosa fa'));
    assertSame(false, $intent('aggiungi una riga a prova3.txt'));
    assertSame(false, $intent('modifica config.php per attivare il debug'));
    assertSame(false, $intent('che tipo di file e bin.dat?'));
    // Domanda teorica su un comando: non chiede di eseguirlo.
    assertSame(false, $intent('spiega come si usa il comando cat'));
    // Il verbo dev'essere vicino alla parola comando: non basta che compaia da qualche parte.
    assertSame(false, $intent('usa il file di configurazione per capire come funziona questo lungo comando'));
});

$modIntent = static function (string $prompt): bool {
    $svc = (new ReflectionClass(CodeChatService::class))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod(CodeChatService::class, 'modificationRequested');

    return (bool) $m->invoke($svc, $prompt);
};

$gitStageIntent = static function (string $prompt): bool {
    $svc = (new ReflectionClass(CodeChatService::class))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod(CodeChatService::class, 'gitStageRequested');

    return (bool) $m->invoke($svc, $prompt);
};

$gitStatusIntent = static function (string $prompt): bool {
    $svc = (new ReflectionClass(CodeChatService::class))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod(CodeChatService::class, 'gitStatusRequested');

    return (bool) $m->invoke($svc, $prompt);
};

test('intento staging Git: richiede una richiesta affermativa esplicita', function () use ($gitStageIntent) {
    assertSame(false, $gitStageIntent('Mostrami stato e diff. Non preparare staging o commit.'));
    assertSame(false, $gitStageIntent('Non proporre staging'));
    assertSame(false, $gitStageIntent('Mostrami soltanto il diff unstaged'));
    assertSame(true, $gitStageIntent('Prepara lo staging di README.md'));
    assertSame(true, $gitStageIntent('Metti README.md in stage'));
    assertSame(true, $gitStageIntent('non preparare lo staging di app.php, proponi lo staging di README.md'));
});

test('intento stato Git: riconosce una richiesta esplicita di dati correnti', function () use ($gitStatusIntent) {
    assertSame(true, $gitStatusIntent('Mostrami lo stato Git corrente. Non preparare staging, commit o push.'));
    assertSame(true, $gitStatusIntent('Controlla lo status del repository Git'));
    assertSame(true, $gitStatusIntent('Mostrami soltanto il diff unstaged'));
    assertSame(false, $gitStatusIntent('Spiegami che cosa significa stato Git'));
    assertSame(false, $gitStatusIntent('Prepara lo staging di README.md'));
});

test('intento: una negazione davanti al verbo non attiva l intento operativo', function () use ($intent, $modIntent) {
    // I due casi reali osservati: la negazione veniva letta come richiesta.
    assertSame(false, $intent('non eseguire comandi'));
    assertSame(false, $modIntent('non aggiungere spiegazioni'));
    // Stessa forma, altri verbi e altre negazioni.
    assertSame(false, $intent('non usare comandi'));
    assertSame(false, $intent('senza eseguire comandi'));
    assertSame(false, $intent('mai eseguire comandi'));
    assertSame(false, $intent('evita di eseguire comandi'));
    assertSame(false, $intent('evitare di lanciare comandi'));
    assertSame(false, $modIntent('non modificare i file'));
    assertSame(false, $modIntent('non creare nuovi file'));
    assertSame(false, $modIntent('senza modificare il codice'));
});

test('intento: le richieste positive restano riconosciute', function () use ($intent, $modIntent) {
    assertSame(true, $intent('esegui un comando'));
    assertSame(true, $intent('proponi un comando'));
    assertSame(true, $modIntent('modifica questo file'));
    assertSame(true, $modIntent('aggiungi questa funzione'));
    // La parola «non» altrove nella frase non deve spegnere un intento reale.
    assertSame(true, $modIntent('il test non passa: modifica config.php'));
    assertSame(true, $intent('non ho capito, esegui un comando per contare le righe'));
});

test('intento: nei casi misti vince il verbo non negato', function () use ($intent, $modIntent) {
    // Una negazione sul comando non deve spegnere la modifica chiesta nella stessa frase.
    assertSame(false, $intent('modifica il file ma non eseguire comandi'));
    assertSame(true, $modIntent('modifica il file ma non eseguire comandi'));
    // E viceversa.
    assertSame(true, $intent('non modificare il file, esegui un comando'));
    assertSame(false, $modIntent('non modificare il file'));
    // Il verbo negato è il primo: l'intento sta nel secondo, e va visto lo stesso.
    assertSame(true, $modIntent('non creare file, aggiungi un test'));
});

test('ciclo: con un comando richiesto, propose_file NON conclude il turno', function () use ($mkroot, $rmrf, $ws, $scripted, $loop, $commandsUsable) {
    if (!$commandsUsable) { assertSame(true, true); return; }
    $root = $mkroot();
    try {
        // Esattamente il difetto osservato: il modello insiste con propose_file.
        $calls = 0;
        $decider = $scripted([
            '{"action":"propose_file","path":"config.php","content":"<?php\\nreturn [];\\n"}',
            '{"action":"propose_file","path":"config.php","content":"<?php\\nreturn [];\\n"}',
        ], $calls);
        $outcome = $loop($decider, true)->run($ws($root), 'proponi un comando che mi dica il tipo di bin.dat', '', false, true);
        // Nessuna proposta di modifica: non è ciò che l'utente ha chiesto.
        assertSame(null, $outcome->proposal);
        assertSame(false, $outcome->hasProposal());
        // Nessun comando: il modello non ne ha prodotti. Il turno finisce ai limiti, non "riuscito".
        assertSame(null, $outcome->commandPlan);
        assertSame('iterations', $outcome->stopReason);
    } finally {
        $rmrf($root);
    }
});

test('ciclo: con un comando richiesto, answer NON conclude il turno', function () use ($mkroot, $rmrf, $ws, $scripted, $loop, $commandsUsable) {
    if (!$commandsUsable) { assertSame(true, true); return; }
    $root = $mkroot();
    try {
        $outcome = $loop($scripted(['{"action":"answer"}']), true)
            ->run($ws($root), 'proponi un comando che mi dica il tipo di bin.dat', '', false, true);
        // `answer` è l'azione con cui il modello dichiarava a parole una proposta inesistente.
        assertSame(false, $outcome->stopReason === 'answer');
        assertSame('iterations', $outcome->stopReason);
        assertSame(null, $outcome->commandPlan);
        assertSame(null, $outcome->proposal);
    } finally {
        $rmrf($root);
    }
});

test('ciclo: con un comando richiesto, run_command resta la via corretta', function () use ($mkroot, $rmrf, $ws, $scripted, $loop, $commandsUsable) {
    if (!$commandsUsable) { assertSame(true, true); return; }
    $root = $mkroot();
    try {
        $decider = $scripted(['{"action":"run_command","program":"file","args":["bin.dat"]}']);
        $outcome = $loop($decider, true)->run($ws($root), 'proponi un comando che mi dica il tipo di bin.dat', '', false, true);
        assertSame('command', $outcome->stopReason);
        assertSame(true, $outcome->commandPlan instanceof CommandPlan);
        assertSame('file', $outcome->commandPlan->program);
        // Il vincolo non ha rotto il percorso normale: nessuna modifica di file coinvolta.
        assertSame(null, $outcome->proposal);
    } finally {
        $rmrf($root);
    }
});

test('ciclo: comando richiesto ma non disponibile → fallisce subito, senza interpellare il modello', function () use ($mkroot, $rmrf, $ws, $scripted, $loop) {
    $root = $mkroot();
    try {
        $calls = 0;
        $decider = $scripted(['{"action":"propose_file","path":"config.php","content":"<?php\\n"}'], $calls);
        $outcome = $loop($decider, false)->run($ws($root), 'proponi un comando che mi dica il tipo di bin.dat', '', false, true);
        assertSame('command_unavailable', $outcome->stopReason);
        assertSame(null, $outcome->commandPlan);
        // Nessun ripiego su una modifica mai chiesta...
        assertSame(null, $outcome->proposal);
        // ...e nessuna chiamata al modello: inutile farlo girare per negargli poi ogni azione.
        assertSame(0, $calls);
    } finally {
        $rmrf($root);
    }
});

test('chat: un comando richiesto e non prodotto è un errore controllato, senza chiamata finale libera', function () use ($mkroot, $rmrf, $commandsUsable) {
    if (!$commandsUsable) { assertSame(true, true); return; }
    $root = $mkroot();
    $dbPath = sys_get_temp_dir() . '/aim_cmdintent_' . uniqid('', true) . '.sqlite';
    try {
        $db = new Database($dbPath);
        $db->pdo()->exec('PRAGMA foreign_keys = ON');
        $db->execute('CREATE TABLE code_workspaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
            CHECK(status IN (\'active\',\'revoked\')))');
        CodeChatSchema::createForTests($db);
        CodePatchSchema::createForTests($db);
        CommandRunSchema::createForTests($db);
        $wsId = (new CodeWorkspaceRepository($db))->authorizeRoot($root)->id;
        $sid = (new CodeSessionRepository($db))->create($wsId, 'sessione');

        $finalProviderCalled = false;
        // Il modello legge il file e POI propone di modificarlo: la proposta è pienamente VALIDA, come
        // nel caso reale (una delle due fu applicata). Senza il vincolo, il turno finirebbe qui con una
        // patch persistita — quindi il test fallisce davvero se la guardia sparisce.
        $step = 0;
        $decider = static function () use (&$step): string {
            $script = [
                '{"action":"read_file","path":"config.php"}',
                '{"action":"propose_file","path":"config.php","content":"<?php\nreturn [\'debug\' => true];\n"}',
            ];
            return (string) ($script[$step++] ?? end($script));
        };
        $svc = new CodeChatService(
            $db,
            streamer: static function () use (&$finalProviderCalled): AIProviderResult {
                $finalProviderCalled = true;
                return AIProviderResult::success('Ho proposto un comando.');
            },
            decider: $decider,
            writeEnabled: true,
            patchStorageDir: $root . '_patchstore',
            commandsEnabled: true,
            commandStorageDir: $root . '_cmdstore',
        );
        $deltas = [];
        $out = $svc->stream($wsId, $sid, 'proponi un comando che mi dica il tipo di bin.dat', static function (string $t) use (&$deltas): void {
            $deltas[] = $t;
        });

        // Errore strutturato, non un successo travestito.
        assertSame('error', $out['status']);
        assertSame(false, $out['ok']);
        // La chiamata finale libera è ciò che produsse il testo con la proposta inesistente.
        assertSame(false, $finalProviderCalled);
        assertSame('', implode('', $deltas));
        // Nessuna proposta di modifica mostrata né persistita: il difetto applicava patch mai chieste.
        assertSame(null, $out['proposal'] ?? null);
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) c FROM code_patch_operations')['c']);
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) c FROM code_command_runs')['c']);
        // Nessun turno assistant che affermi qualcosa di inesistente.
        assertSame(0, (int) $db->fetch("SELECT COUNT(*) c FROM code_conversations WHERE role = 'assistant'")['c']);
    } finally {
        $rmrf($root);
        $rmrf($root . '_patchstore');
        $rmrf($root . '_cmdstore');
        @unlink($dbPath);
    }
});

test('chat: senza comandi disponibili la richiesta esplicita fallisce chiaramente, senza proporre modifiche', function () use ($mkroot, $rmrf) {
    $root = $mkroot();
    $dbPath = sys_get_temp_dir() . '/aim_cmdintent_' . uniqid('', true) . '.sqlite';
    try {
        $db = new Database($dbPath);
        $db->pdo()->exec('PRAGMA foreign_keys = ON');
        $db->execute('CREATE TABLE code_workspaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT, root_path TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'active\', authorized_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
            CHECK(status IN (\'active\',\'revoked\')))');
        CodeChatSchema::createForTests($db);
        CodePatchSchema::createForTests($db);
        $wsId = (new CodeWorkspaceRepository($db))->authorizeRoot($root)->id;
        $sid = (new CodeSessionRepository($db))->create($wsId, 'sessione');

        $finalProviderCalled = false;
        $svc = new CodeChatService(
            $db,
            streamer: static function () use (&$finalProviderCalled): AIProviderResult {
                $finalProviderCalled = true;
                return AIProviderResult::success('testo libero');
            },
            decider: static fn (): string => '{"action":"propose_file","path":"config.php","content":"<?php\\nreturn [];\\n"}',
            writeEnabled: true,
            patchStorageDir: $root . '_patchstore',
            // commandsEnabled resta false: i comandi non sono disponibili.
        );
        $out = $svc->stream($wsId, $sid, 'proponi un comando che mi dica il tipo di bin.dat', static fn () => null);

        assertSame('error', $out['status']);
        assertSame(true, str_contains($out['message'], 'comandi'));
        assertSame(false, $finalProviderCalled);
        assertSame(null, $out['proposal'] ?? null);
        assertSame(0, (int) $db->fetch('SELECT COUNT(*) c FROM code_patch_operations')['c']);
    } finally {
        $rmrf($root);
        $rmrf($root . '_patchstore');
        @unlink($dbPath);
    }
});
