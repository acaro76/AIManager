<?php

declare(strict_types=1);

use App\Core\Code\CodeSseWriter;

// Regressione (trovata al gate Fase 5): l'evento `done` DEVE inoltrare l'esito strutturato delle
// verifiche (`verifications`), altrimenti la card live resta vuota anche se il ciclo ha verificato.

$capture = static function (): array {
    $buf = '';
    $writer = new CodeSseWriter(static function (string $chunk) use (&$buf): void {
        $buf .= $chunk;
    });
    // Lettore per RIFERIMENTO: deve vedere il buffer aggiornato dopo done(), non la copia iniziale.
    $reader = static function () use (&$buf): string {
        return $buf;
    };
    return [$writer, $reader];
};

$baseResult = static fn (array $extra = []): array => array_merge([
    'status' => 'success',
    'message' => '',
    'provider' => 'lmstudio',
    'model' => 'qwen',
    'files' => ['read' => [], 'found' => []],
    'limits_hit' => [],
    'metrics' => [],
    'citations' => [],
], $extra);

test('sse: done inoltra le verifications strutturate', function () use ($capture, $baseResult) {
    [$writer, $out] = $capture();
    $writer->done($baseResult([
        'verifications' => [
            ['profile' => 'php-lint', 'kind' => 'lint', 'outcome' => 'passed', 'exit_code' => 0, 'path' => 'Good.php', 'label' => 'superata'],
        ],
    ]));
    $line = '';
    foreach (explode("\n", $out()) as $l) {
        if (str_starts_with($l, 'data: ')) { $line = substr($l, 6); }
    }
    $decoded = json_decode($line, true);
    assertSame(true, is_array($decoded['verifications'] ?? null));
    assertSame(1, count($decoded['verifications']));
    assertSame('php-lint', $decoded['verifications'][0]['profile']);
    assertSame('passed', $decoded['verifications'][0]['outcome']);
    assertSame('superata', $decoded['verifications'][0]['label']);
});

test('sse: done inoltra la card di comando (Fase 6)', function () use ($capture, $baseResult) {
    [$writer, $out] = $capture();
    $writer->done($baseResult([
        'command' => ['command_id' => 'cmd-abc', 'program' => 'grep', 'display_summary' => 'grep «x» a.php', 'state' => 'pending', 'label' => 'in attesa di conferma', 'digest' => 'd'],
    ]));
    $line = '';
    foreach (explode("\n", $out()) as $l) {
        if (str_starts_with($l, 'data: ')) { $line = substr($l, 6); }
    }
    $decoded = json_decode($line, true);
    assertSame(true, array_key_exists('command', $decoded));
    assertSame('grep', $decoded['command']['program']);
    assertSame('pending', $decoded['command']['state']);
    // Mai path assoluti nel display.
    assertSame(false, str_contains((string) $decoded['command']['display_summary'], '/usr/'));
});

test('sse: done senza comando emette command = null', function () use ($capture, $baseResult) {
    [$writer, $out] = $capture();
    $writer->done($baseResult());
    $line = '';
    foreach (explode("\n", $out()) as $l) {
        if (str_starts_with($l, 'data: ')) { $line = substr($l, 6); }
    }
    $decoded = json_decode($line, true);
    assertSame(true, array_key_exists('command', $decoded));
    assertSame(null, $decoded['command']);
});

test('sse: done senza verifiche emette una lista vuota, non l\'assenza della chiave', function () use ($capture, $baseResult) {
    [$writer, $out] = $capture();
    $writer->done($baseResult());
    $line = '';
    foreach (explode("\n", $out()) as $l) {
        if (str_starts_with($l, 'data: ')) { $line = substr($l, 6); }
    }
    $decoded = json_decode($line, true);
    assertSame(true, array_key_exists('verifications', $decoded));
    assertSame([], $decoded['verifications']);
});
