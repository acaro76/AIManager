<?php

declare(strict_types=1);

use App\Core\View;

/**
 * Renderer markdown condiviso fra chat LLM e chat Code.
 *
 * Copre le sole sintassi approvate, i confini di sicurezza (nessun HTML dal modello, nessuno
 * schema di URL fuori da http/https) e l'equivalenza fra il renderer PHP (storico) e quello JS
 * (streaming), che sono due implementazioni parallele dello stesso contratto.
 */

$md = static fn (string $input): string => View::messageContent($input);

// --- Sintassi approvate ---------------------------------------------------------------------

test('grassetto e corsivo diventano strong ed em', function () use ($md) {
    assertSame('<p><strong>forte</strong> e <em>lieve</em></p>', $md('**forte** e *lieve*'));
});

test('codice inline diventa code', function () use ($md) {
    assertSame('<p>usa <code>array_map()</code> qui</p>', $md('usa `array_map()` qui'));
});

test('il codice inline non viene attraversato da grassetto e corsivo', function () use ($md) {
    assertSame('<p><code>**non_grassetto**</code></p>', $md('`**non_grassetto**`'));
});

test('blocco fenced diventa pre/code', function () use ($md) {
    assertSame(
        '<pre class="chat-code"><code>$a = 1;
$b = 2;</code></pre>',
        $md("```\n\$a = 1;\n\$b = 2;\n```")
    );
});

test('blocco fenced text riceve la classe per il ritorno a capo', function () use ($md) {
    assertSame(
        '<pre class="chat-code chat-code-text"><code>riga molto lunga</code></pre>',
        $md("```text\nriga molto lunga\n```")
    );
});

test('il linguaggio della fence non finisce nel contenuto', function () use ($md) {
    assertSame('<pre class="chat-code"><code>echo 1;</code></pre>', $md("```php\necho 1;\n```"));
});

test('il markdown dentro una fence resta letterale', function () use ($md) {
    assertSame(
        '<pre class="chat-code"><code>**non_grassetto** e |non|tabella|</code></pre>',
        $md("```\n**non_grassetto** e |non|tabella|\n```")
    );
});

test('una fence non chiusa rende comunque un blocco', function () use ($md) {
    assertSame('<pre class="chat-code"><code>parziale</code></pre>', $md("```\nparziale"));
});

test('elenco puntato diventa ul', function () use ($md) {
    assertSame('<ul><li>uno</li><li>due</li></ul>', $md("- uno\n- due"));
});

test('elenco numerato diventa ol', function () use ($md) {
    assertSame('<ol><li>uno</li><li>due</li></ol>', $md("1. uno\n2. due"));
});

test('tabella markdown diventa una table', function () use ($md) {
    assertSame(
        '<div class="chat-table-wrap"><table class="chat-table"><thead><tr><th>File</th><th>Righe</th></tr></thead>'
            . '<tbody><tr><td>a.php</td><td>10</td></tr></tbody></table></div>',
        $md("| File | Righe |\n| --- | --- |\n| a.php | 10 |")
    );
});

// --- Link: solo http/https ------------------------------------------------------------------

test('un link http e https e valido e porta rel noopener noreferrer', function () use ($md) {
    assertSame(
        '<p><a href="https://example.com/a?b=1" target="_blank" rel="noopener noreferrer">sito</a></p>',
        $md('[sito](https://example.com/a?b=1)')
    );
    assertSame(
        '<p><a href="http://example.com" target="_blank" rel="noopener noreferrer">sito</a></p>',
        $md('[sito](http://example.com)')
    );
});

test('javascript: non diventa mai un link', function () use ($md) {
    $html = $md('[clicca](javascript:alert(1))');
    assertSame(false, str_contains($html, '<a '), 'nessuna ancora emessa');
    assertSame(false, str_contains($html, 'javascript:alert(1)"'), 'nessun href pericoloso');
    assertSame('<p>[clicca](javascript:alert(1))</p>', $html);
});

test('data: e vbscript: non diventano mai link', function () use ($md) {
    foreach (['data:text/html;base64,PHNjcmlwdD4=', 'vbscript:msgbox(1)', 'file:///etc/passwd'] as $url) {
        $html = $md('[x](' . $url . ')');
        assertSame(false, str_contains($html, '<a '), $url . ' non deve produrre un link');
    }
});

test('uno schema pericoloso mascherato da maiuscole resta testo', function () use ($md) {
    assertSame(false, str_contains($md('[x](JaVaScRiPt:alert(1))'), '<a '));
});

// --- HTML ostile e XSS ----------------------------------------------------------------------

test('un tag script del modello viene neutralizzato', function () use ($md) {
    $html = $md('<script>alert(1)</script>');
    assertSame(false, str_contains($html, '<script'), 'nessuno script vivo');
    assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
});

test('un handler inline in HTML grezzo viene neutralizzato', function () use ($md) {
    $html = $md('<img src=x onerror=alert(1)>');
    assertSame(false, str_contains($html, '<img'), 'nessun img vivo');
    assertSame(false, str_contains($html, 'onerror=alert(1)>'), 'handler non attivo');
});

test('HTML ostile dentro una fence resta letterale', function () use ($md) {
    $html = $md("```\n<script>alert(1)</script>\n```");
    assertSame(false, str_contains($html, '<script'), 'nessuno script vivo');
    assertSame('<pre class="chat-code"><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>', $html);
});

test('HTML ostile dentro una tabella resta letterale', function () use ($md) {
    $html = $md("| A |\n| --- |\n| <script>alert(1)</script> |");
    assertSame(false, str_contains($html, '<script'), 'nessuno script vivo');
});

test('le virgolette nella label di un link non rompono l attributo', function () use ($md) {
    $html = $md('[a" onmouseover="alert(1)](https://example.com)');
    assertSame(false, str_contains($html, 'onmouseover="alert(1)"'), 'nessun handler iniettato');
});

// --- Unicode, accenti, emoji ----------------------------------------------------------------

test('accenti simboli ed emoji restano invariati', function () use ($md) {
    assertSame('<p>però è €5 — 100% ✅ 🎉 👍🏽</p>', $md('però è €5 — 100% ✅ 🎉 👍🏽'));
});

test('accenti ed emoji sopravvivono dentro grassetto e codice', function () use ($md) {
    assertSame('<p><strong>caffè ☕</strong> <code>naïve 🚀</code></p>', $md('**caffè ☕** `naïve 🚀`'));
});

test('gli emoji restano invariati dentro una fence', function () use ($md) {
    assertSame('<pre class="chat-code"><code>// città 🇮🇹</code></pre>', $md("```\n// città 🇮🇹\n```"));
});

// --- Cablaggio dei due percorsi della chat Code ----------------------------------------------

test('lo storico Code rende l assistant col renderer e l utente come testo puro', function () {
    $chat = (string) file_get_contents(dirname(__DIR__) . '/app/Views/code/_chat.php');
    assertSame(true, str_contains($chat, 'View::messageContent((string) $turn[\'content\'])'), 'assistant reso dal renderer');
    assertSame(true, str_contains($chat, 'nl2br(View::e((string) $turn[\'content\']))'), 'utente resta testo puro');
    assertSame(true, str_contains($chat, 'chat-content code-msg-content'), 'contenitore di blocco, non <p>');
});

test('lo streaming Code usa il renderer condiviso e non scrive testo puro', function () {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/code-chat.js');
    assertSame(true, str_contains($js, 'window.AIManagerMarkdown.render'), 'renderer condiviso in uso');
    assertSame(true, str_contains($js, 'assistant.setText(partial)'), 'i delta passano da setText');
    assertSame(false, str_contains($js, 'assistant.text.textContent'), 'nessuna scrittura di testo puro sull assistant');
});

test('il renderer non e duplicato e viene caricato prima di chi lo usa', function () {
    $app = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
    assertSame(true, str_contains($app, 'window.AIManagerMarkdown.render'), 'app.js riusa il modulo condiviso');
    assertSame(false, str_contains($app, 'const renderTable = function'), 'nessuna copia del renderer in app.js');

    // Solo i tag <script>: nel layout gli stessi path compaiono prima come variabili PHP
    // per il cache busting, e conterebbero come falsi positivi sull'ordine.
    $layout = (string) file_get_contents(dirname(__DIR__) . '/app/Views/layouts/app.php');
    preg_match_all('/<script[^>]+src="\/assets\/js\/([a-z-]+\.js)/', $layout, $tags);
    $order = $tags[1];
    assertSame(['markdown.js', 'app.js', 'code-chat.js'], $order, 'markdown.js precede chi lo consuma');
});

// --- Equivalenza storico (PHP) / streaming (JS) ----------------------------------------------

/**
 * I due renderer sono implementazioni parallele: se divergono, la stessa risposta cambia aspetto
 * al refresh. Il test esegue davvero il renderer JS e confronta l'output con quello PHP sugli
 * stessi casi. Runtime: `node` se presente, altrimenti `jsc` (JavaScriptCore, di serie su macOS).
 * Se non c'è nessun runtime il test FALLISCE invece di passare in silenzio: una parità non
 * verificata non deve somigliare a una parità verificata.
 */
function markdownJsRuntime(): ?array
{
    exec('command -v node 2>/dev/null', $out, $code);
    if ($code === 0 && isset($out[0])) {
        return ['bin' => $out[0], 'node' => true];
    }

    $jsc = '/System/Library/Frameworks/JavaScriptCore.framework/Versions/A/Helpers/jsc';

    return is_executable($jsc) ? ['bin' => $jsc, 'node' => false] : null;
}

test('il renderer JS produce lo stesso HTML di quello PHP', function () use ($md) {
    $runtime = markdownJsRuntime();
    if ($runtime === null) {
        throw new \RuntimeException('nessun runtime JS (node o jsc): parità PHP/JS non verificabile');
    }

    $cases = [
        '**forte** e *lieve*',
        'usa `array_map()` qui',
        '`**non_grassetto**`',
        "```\n\$a = 1;\n\$b = 2;\n```",
        "```php\necho 1;\n```",
        "```\n**non_grassetto** e |non|tabella|\n```",
        "```\nparziale",
        "- uno\n- due",
        "1. uno\n2. due",
        "| File | Righe |\n| --- | --- |\n| a.php | 10 |",
        '[sito](https://example.com/a?b=1)',
        '[clicca](javascript:alert(1))',
        '[x](data:text/html;base64,PHNjcmlwdD4=)',
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        "```\n<script>alert(1)</script>\n```",
        'però è €5 — 100% ✅ 🎉 👍🏽',
        '**caffè ☕** `naïve 🚀`',
        "```\n// città 🇮🇹\n```",
        "# Titolo\ntesto\n\n- a\n- b",
    ];

    $renderer = dirname(__DIR__) . '/public/assets/js/markdown.js';
    $casesFile = tempnam(sys_get_temp_dir(), 'mdcases');
    file_put_contents($casesFile, (string) json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // Un solo script per entrambi i runtime: node espone process/require, jsc readFile/print.
    $script = <<<'JS'
        var isNode = (typeof process !== 'undefined' && !!(process.versions && process.versions.node));
        var read = isNode ? function (p) { return require('fs').readFileSync(p, 'utf8'); } : readFile;
        var argv = isNode ? process.argv.slice(2) : arguments;
        var emit = isNode ? function (s) { process.stdout.write(s); } : print;
        // Come nel browser: il modulo si registra su `window` globale.
        globalThis.window = {};
        (new Function(read(argv[0])))();
        var cases = JSON.parse(read(argv[1]));
        emit(JSON.stringify(cases.map(function (c) { return globalThis.window.AIManagerMarkdown.render(c); })));
        JS;

    $scriptFile = tempnam(sys_get_temp_dir(), 'mdparity') . '.js';
    file_put_contents($scriptFile, $script);

    $cmd = sprintf(
        '%s %s %s %s %s 2>&1',
        escapeshellarg($runtime['bin']),
        escapeshellarg($scriptFile),
        $runtime['node'] ? '' : '--',
        escapeshellarg($renderer),
        escapeshellarg($casesFile)
    );
    $raw = shell_exec($cmd);
    unlink($scriptFile);
    unlink($casesFile);

    $jsResults = json_decode((string) $raw, true);
    if (!is_array($jsResults) || count($jsResults) !== count($cases)) {
        throw new \RuntimeException('renderer JS non eseguibile: ' . trim((string) $raw));
    }

    foreach ($cases as $i => $case) {
        assertSame($md($case), $jsResults[$i], 'divergenza PHP/JS sul caso ' . var_export($case, true));
    }
});
