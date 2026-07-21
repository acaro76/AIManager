<?php

declare(strict_types=1);

use App\Core\Code\CodeAgentLimits;
use App\Core\Code\CodeAgentLoop;
use App\Core\Code\CodeAgentTools;
use App\Core\Code\CodeWorkspace;
use App\Core\Code\RetrievalLimits;
use App\Core\Code\SensitivePathPolicy;

// Fase 3 — il CICLO: deve fermarsi correttamente per completamento, tetto di iterazioni, timeout,
// budget, Stop utente, output non valido e revoca. Decisore FAKE (nessun provider), orologio
// iniettato (nessuna attesa reale), cartelle temporanee.

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path) && !is_link($path)) {
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
    $root = $base . '/aimanager_agloop_' . uniqid('', true);
    mkdir($root . '/app', 0777, true);
    file_put_contents($root . '/app/Login.php', "<?php\nfunction login() { return true; }\n");
    file_put_contents($root . '/README.md', "Il login del progetto.\n");
    return $root;
};

$ws = static fn (string $root, string $status = 'active'): CodeWorkspace
    => new CodeWorkspace(1, $root, basename($root), $status, new SensitivePathPolicy());

/** Decisore a copione: restituisce in ordine le risposte grezze del "modello". */
$scripted = static function (array $script, ?int &$calls = null): callable {
    $calls = 0;
    $i = 0;
    return static function (string $system, string $user) use ($script, &$i, &$calls): string {
        $calls++;
        return (string) ($script[$i++] ?? '{"action":"answer"}');
    };
};

$loop = static function (
    callable $decider,
    ?CodeAgentLimits $agentLimits = null,
    ?callable $isActive = null,
    ?callable $isCancelled = null,
    ?callable $clock = null,
): CodeAgentLoop {
    $limits = RetrievalLimits::defaults();
    $agent = $agentLimits ?? CodeAgentLimits::defaults();

    return new CodeAgentLoop(
        limits: $limits,
        agentLimits: $agent,
        tools: new CodeAgentTools($limits, $agent, $isActive),
        decider: $decider,
        isActive: $isActive,
        isCancelled: $isCancelled,
        clock: $clock,
    );
};

test('CodeAgentLoop: completamento — cerca, legge, poi answer', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        $out = $loop($scripted([
            '{"action":"search_text","query":"login"}',
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"answer"}',
        ]))->run($ws($root), 'dove viene gestito il login');

        assertSame('answer', $out->stopReason);
        assertSame(3, $out->iterations);
        assertSame(true, $out->usableForAnswer());
        assertSame(['app/Login.php'], $out->retrieval->filesConsulted()['read']);
        assertSame(2, count($out->steps));                  // answer non è un'azione eseguita
        assertSame(false, $out->limitedByAgent());
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: TETTO DI ITERAZIONI — si ferma e risponde con quel che ha', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        $calls = 0;
        // un modello che non conclude mai
        $decider = $scripted(array_fill(0, 20, '{"action":"search_text","query":"login"}'), $calls);
        $out = $loop($decider, new CodeAgentLimits(2, 90.0, 24000, 6000, 2, 120))->run($ws($root), 'login');

        assertSame('iterations', $out->stopReason);
        assertSame(2, $out->iterations);
        assertSame(2, $calls);                              // il tetto vincola le chiamate al provider
        assertSame(true, $out->limitedByAgent());           // audit: `limited`, non `ok`
        assertSame(true, $out->usableForAnswer());          // evidenza raccolta: si risponde comunque
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: TIMEOUT totale — l\'orologio ferma il ciclo', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        $t = 0.0;
        $clock = static function () use (&$t): float { $t += 10.0; return $t; };
        $out = $loop(
            $scripted(array_fill(0, 20, '{"action":"search_text","query":"login"}')),
            new CodeAgentLimits(10, 25.0, 24000, 6000, 2, 120),
            clock: $clock
        )->run($ws($root), 'login');

        assertSame('timeout', $out->stopReason);
        assertSame(true, $out->limitedByAgent());
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: BUDGET cumulativo esaurito', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        // budget minuscolo: dopo il primo risultato non c'è più spazio nel dialogo
        $out = $loop(
            $scripted(array_fill(0, 10, '{"action":"read_file","path":"app/Login.php"}')),
            new CodeAgentLimits(10, 90.0, 40, 40, 2, 120)
        )->run($ws($root), 'login');

        assertSame('budget', $out->stopReason);
        assertSame(1, $out->iterations);
        assertSame(true, $out->limitedByAgent());
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: STOP dell\'utente verificato a OGNI iterazione', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        // lo Stop arriva dopo la prima decisione
        $checks = 0;
        $isCancelled = static function () use (&$checks): bool {
            $checks++;
            return $checks > 2;
        };
        $calls = 0;
        $decider = $scripted(array_fill(0, 10, '{"action":"search_text","query":"login"}'), $calls);

        $out = $loop($decider, null, null, $isCancelled)->run($ws($root), 'login');

        assertSame('cancelled', $out->stopReason);
        assertSame(false, $out->usableForAnswer());        // cancellato non è una risposta
        assertSame(true, $calls < 10);                     // il ciclo non ha continuato a chiamare
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: STOP prima di eseguire lo strumento — nessuna azione eseguita', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        $out = $loop(
            $scripted(['{"action":"read_file","path":"app/Login.php"}']),
            null,
            null,
            static fn (): bool => true   // già cancellato in partenza
        )->run($ws($root), 'login');

        assertSame('cancelled', $out->stopReason);
        assertSame(0, $out->iterations);
        assertSame([], $out->steps);
        assertSame([], $out->retrieval->readFiles());
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: OUTPUT NON VALIDO — è un dato, torna al modello; poi si rinuncia', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        // il modello si riprende al secondo tentativo
        $ok = $loop($scripted([
            'Certo, adesso guardo il codice!',                 // non JSON → errore-dato
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"answer"}',
        ]))->run($ws($root), 'login');
        assertSame('answer', $ok->stopReason);
        assertSame(['app/Login.php'], $ok->retrieval->filesConsulted()['read']);

        // il modello non si riprende mai: dopo maxInvalidOutputs+1 si abbandona
        $calls = 0;
        $ko = $loop(
            $scripted(array_fill(0, 10, 'non produco JSON'), $calls),
            new CodeAgentLimits(10, 90.0, 24000, 6000, 2, 120)
        )->run($ws($root), 'login');

        assertSame('invalid', $ko->stopReason);
        assertSame(false, $ko->usableForAnswer());          // → il chiamante ricade sul single-shot
        assertSame(3, $calls);                              // 2 tollerati + 1 che sfora
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: azione NON ESEGUIBILE (write/exec) non esiste: resta output non valido', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        $out = $loop(
            $scripted(array_fill(0, 6, '{"action":"write_file","path":"app/Login.php","content":"hack"}')),
            new CodeAgentLimits(6, 90.0, 24000, 6000, 1, 120)
        )->run($ws($root), 'modifica il login');

        assertSame('invalid', $out->stopReason);
        assertSame([], $out->steps);                        // nessuno strumento eseguito
        // il file NON è stato toccato
        assertSame(true, str_contains((string) file_get_contents($root . '/app/Login.php'), 'function login'));
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: guasto del provider a metà ciclo → error (il chiamante ricade sul single-shot)', function () use ($mkroot, $ws, $loop, $rmrf) {
    $root = $mkroot();
    try {
        $decider = static function (string $s, string $u): string {
            throw new \RuntimeException('provider giù');
        };
        $out = $loop($decider)->run($ws($root), 'login');

        assertSame('error', $out->stopReason);
        assertSame(false, $out->usableForAnswer());
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: REVOCA durante il ciclo — si ferma e la segnala come limite', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        $calls = 0;
        $active = 0;
        // attivo alla prima verifica, revocato dalla seconda in poi
        $isActive = static function () use (&$active): bool {
            $active++;
            return $active < 2;
        };
        $out = $loop(
            $scripted(array_fill(0, 10, '{"action":"search_text","query":"login"}'), $calls),
            null,
            $isActive
        )->run($ws($root), 'login');

        assertSame('revoked', $out->stopReason);
        assertSame(true, in_array('revoked', $out->retrieval->limitsHit(), true));
    } finally { $rmrf($root); }
});

test('CodeAgentLoop: i tetti di LETTURA del retrieval valgono dentro il ciclo', function () use ($mkroot, $ws, $rmrf, $scripted) {
    $root = $mkroot();
    try {
        // un solo file leggibile per turno: la seconda lettura è `limited`, non un errore
        $limits = new RetrievalLimits(
            scanMaxDepth: 12, scanMaxFiles: 2000, scanMaxReadBytes: 262144, scanMaxSeconds: 5.0,
            searchMaxFilesScanned: 2000, searchMaxMatches: 100, searchMaxBytesPerFile: 262144,
            searchMaxTotalBytes: 4194304, searchMaxSeconds: 5.0,
            readMaxFiles: 1, readMaxBytesPerFile: 65536, readMaxTotalBytes: 262144, contextMaxChars: 48000,
        );
        $agent = CodeAgentLimits::defaults();
        $loop = new CodeAgentLoop(
            limits: $limits,
            agentLimits: $agent,
            tools: new CodeAgentTools($limits, $agent),
            decider: $scripted([
                '{"action":"read_file","path":"app/Login.php"}',
                '{"action":"read_file","path":"README.md"}',
                '{"action":"answer"}',
            ]),
        );
        $out = $loop->run($ws($root), 'login');

        assertSame(['app/Login.php'], $out->retrieval->filesConsulted()['read']);
        assertSame(true, in_array('read:files', $out->retrieval->limitsHit(), true));
        assertSame('limited', $out->steps[1]->outcome);
    } finally { $rmrf($root); }
});

// ---------------------------------------------------------------------------------------------
// DEDUPLICA delle azioni (difetto emerso nello smoke reale: il modello rileggeva lo stesso file
// finché non esauriva le iterazioni). Un'azione identica già completata non si riesegue.
// ---------------------------------------------------------------------------------------------

/** Decisore a copione che REGISTRA anche i prompt ricevuti (per ispezionare il dialogo). */
$spy = static function (array $script, ?array &$prompts = null): callable {
    $prompts = [];
    $i = 0;
    return static function (string $system, string $user) use ($script, &$i, &$prompts): string {
        $prompts[] = $user;
        return (string) ($script[$i++] ?? '{"action":"answer"}');
    };
};

test('CodeAgentLoop/dedup: read_file duplicato eseguito UNA sola volta', function () use ($mkroot, $ws, $loop, $spy, $rmrf) {
    $root = $mkroot();
    try {
        $prompts = null;
        $out = $loop($spy([
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"read_file","path":"./app/Login.php"}',   // stessa azione, scritta diversamente
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"answer"}',
        ], $prompts))->run($ws($root), 'login');

        assertSame('answer', $out->stopReason);
        assertSame(4, $out->iterations);               // le decisioni sono state 4…
        assertSame(1, count($out->steps));             // …ma lo strumento è stato eseguito UNA volta
        assertSame(['app/Login.php'], $out->retrieval->filesConsulted()['read']);
        assertSame(1, $out->retrieval->metrics()['filesRead']);

        // il byte-count NON è triplicato: il file è stato letto una volta sola
        $atteso = strlen((string) file_get_contents($root . '/app/Login.php'));
        assertSame($atteso, $out->retrieval->metrics()['readBytes']);
    } finally { $rmrf($root); }
});

test('CodeAgentLoop/dedup: al modello torna un DATO sintetico, non di nuovo il file', function () use ($mkroot, $ws, $loop, $spy, $rmrf) {
    $root = $mkroot();
    try {
        $prompts = null;
        $loop($spy([
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"read_file","path":"app/Login.php"}',
            '{"action":"answer"}',
        ], $prompts))->run($ws($root), 'login');

        // terzo prompt: contiene l'avviso di azione già eseguita…
        $terzo = $prompts[2];
        assertSame(true, str_contains($terzo, 'AZIONE GIÀ ESEGUITA IN QUESTO TURNO: read_file app/Login.php'));
        assertSame(true, str_contains($terzo, 'Scegli un\'azione DIVERSA'));
        assertSame(true, str_contains($terzo, '{"action":"answer"}'));

        // …e il contenuto del file compare UNA sola volta nel dialogo (non ri-incluso)
        assertSame(1, substr_count($terzo, 'function login'));
    } finally { $rmrf($root); }
});

test('CodeAgentLoop/dedup: search e list duplicati non duplicano risultati né metriche', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        $singolo = $loop($scripted([
            '{"action":"search_text","query":"login"}',
            '{"action":"answer"}',
        ]))->run($ws($root), 'login');

        $doppio = $loop($scripted([
            '{"action":"search_text","query":"login"}',
            '{"action":"search_text","query":"  LOGIN "}',   // stessa query: maiuscole e spazi
            '{"action":"list_dir","path":"app"}',
            '{"action":"list_dir","path":"app/"}',           // stessa directory
            '{"action":"answer"}',
        ]))->run($ws($root), 'login');

        // stessi identici riscontri e metriche del turno senza duplicati
        assertSame(
            count($singolo->retrieval->searchHits()),
            count($doppio->retrieval->searchHits())
        );
        assertSame(
            $singolo->retrieval->metrics()['searchMatches'],
            $doppio->retrieval->metrics()['searchMatches']
        );
        assertSame(
            $singolo->retrieval->metrics()['contentFilesScanned'],
            $doppio->retrieval->metrics()['contentFilesScanned']
        );
        assertSame(2, count($doppio->steps));   // search_text + list_dir: una volta ciascuna
    } finally { $rmrf($root); }
});

test('CodeAgentLoop/dedup: il duplicato NON consuma budget degli strumenti', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        // budget sufficiente per UNA lettura. Se i duplicati lo consumassero, il ciclo si
        // fermerebbe per 'budget'; deve invece arrivare al tetto delle DECISIONI.
        $budget = 100000;
        $out = $loop(
            $scripted(array_fill(0, 10, '{"action":"read_file","path":"app/Login.php"}')),
            new CodeAgentLimits(3, 90.0, $budget, 6000, 2, 120)
        )->run($ws($root), 'login');

        assertSame('iterations', $out->stopReason);   // non 'budget'
        assertSame(3, $out->iterations);              // il modello insiste: si ferma a maxIterations
        assertSame(1, count($out->steps));            // una sola esecuzione reale
        assertSame(true, $out->usableForAnswer());    // l'evidenza raccolta resta valida
    } finally { $rmrf($root); }
});

test('CodeAgentLoop/dedup: Stop e revoca continuano a valere anche sui duplicati', function () use ($mkroot, $ws, $loop, $scripted, $rmrf) {
    $root = $mkroot();
    try {
        // STOP durante una sequenza di duplicati
        $checks = 0;
        $out = $loop(
            $scripted(array_fill(0, 10, '{"action":"read_file","path":"app/Login.php"}')),
            null,
            null,
            static function () use (&$checks): bool { $checks++; return $checks > 3; }
        )->run($ws($root), 'login');
        assertSame('cancelled', $out->stopReason);
        assertSame(1, count($out->steps));

        // REVOCA durante una sequenza di duplicati
        $active = 0;
        $revocato = $loop(
            $scripted(array_fill(0, 10, '{"action":"read_file","path":"app/Login.php"}')),
            null,
            static function () use (&$active): bool { $active++; return $active < 3; }
        )->run($ws($root), 'login');
        assertSame('revoked', $revocato->stopReason);
        assertSame(true, in_array('revoked', $revocato->retrieval->limitsHit(), true));
    } finally { $rmrf($root); }
});
