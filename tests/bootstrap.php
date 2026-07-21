<?php

declare(strict_types=1);

/**
 * Runner di test minimale, senza dipendenze (il progetto non usa composer).
 * Ogni file *Test.php in tests/ registra casi con test('nome', fn) e verifica con
 * assertSame(...). `php tests/run.php` esegue tutto e esce con codice != 0 se qualcosa
 * fallisce, cosi' e' agganciabile a un hook di commit.
 */

require dirname(__DIR__) . '/app/Core/autoload.php';

$GLOBALS['__tests'] = ['pass' => 0, 'fail' => 0, 'failures' => []];

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__tests']['pass']++;
        fwrite(STDOUT, "  \033[32m✓\033[0m {$name}\n");
    } catch (\Throwable $e) {
        $GLOBALS['__tests']['fail']++;
        $GLOBALS['__tests']['failures'][] = $name . ' — ' . $e->getMessage();
        fwrite(STDOUT, "  \033[31m✗\033[0m {$name} — {$e->getMessage()}\n");
    }
}

function assertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $detail = sprintf('atteso %s, ottenuto %s', var_export($expected, true), var_export($actual, true));
        throw new \RuntimeException($msg !== '' ? "{$msg}: {$detail}" : $detail);
    }
}

function testSummary(): int
{
    $r = $GLOBALS['__tests'];
    $total = $r['pass'] + $r['fail'];
    fwrite(STDOUT, "\n{$r['pass']}/{$total} test passati.\n");
    if ($r['fail'] > 0) {
        fwrite(STDOUT, "\033[31m{$r['fail']} FALLITI.\033[0m\n");
        return 1;
    }
    return 0;
}
