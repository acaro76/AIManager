<?php

declare(strict_types=1);

/**
 * Revoca cartella Code: nessun flash di SUCCESSO.
 *
 * Il notice globale viene reso sopra la superficie Code e ne rompe il layout a tutta altezza, rendendo
 * scomoda la chat. Dopo una revoca riuscita si fa un redirect normale a `/code`; i flash di ERRORE dei
 * due catch restano, perché lì il messaggio è l'unico modo di sapere che non ha funzionato.
 *
 * `revoke()` non è invocabile in test: `guard()` richiede il CSRF di sessione e `Response::redirect()`
 * fa `exit`, che abbatterebbe il runner. Si asserisce quindi sul corpo del metodo, come per le altre
 * regressioni sui controller Code (vedi CodeEnvironmentTest).
 */
$revokeBody = static function (): string {
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    $at = strpos($src, 'public function revoke(Request $request): never');
    assertSame(true, $at !== false, 'metodo revoke() non trovato');
    $end = strpos($src, 'public function createSession', (int) $at);
    assertSame(true, $end !== false, 'fine di revoke() non trovata');

    return substr($src, (int) $at, (int) $end - (int) $at);
};

test('revoca cartella: il successo non produce flash, solo un redirect a /code', function () use ($revokeBody) {
    $body = $revokeBody();
    // Il difetto: dopo la revoca riuscita partiva $this->flash('Cartella revocata.', '/code').
    assertSame(true, str_contains($body, "Response::redirect('/code');"));
    assertSame(false, str_contains($body, 'Cartella revocata'));
    // Nessun messaggio sostitutivo travestito da successo.
    $src = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/CodeController.php');
    assertSame(false, str_contains($src, 'Cartella revocata'));
});

test('revoca cartella: i flash di errore dei due catch restano invariati', function () use ($revokeBody) {
    $body = $revokeBody();
    // Esattamente due flash: uno per catch. Nessuno sul percorso di successo.
    assertSame(2, substr_count($body, '$this->flash('));
    assertSame(true, str_contains($body, "} catch (CodeWorkspaceException \$exception) {\n            \$this->flash('Revoca non riuscita: ' . \$exception->getMessage(), '/code');"));
    assertSame(true, str_contains($body, "\$this->flash('Revoca non riuscita per un errore interno.', '/code');"));
    // L'errore inatteso resta anche nel log del server, non solo a schermo.
    assertSame(true, str_contains($body, "error_log('[code] revoke error: '"));
});

test('revoca cartella: la revoca in sé non è toccata', function () use ($revokeBody) {
    $body = $revokeBody();
    // Stessa CSRF, stesso id, stessa chiamata al repository: cambia solo l'esito a schermo.
    assertSame(true, str_contains($body, '$this->guard($request);'));
    assertSame(true, str_contains($body, "\$id = (int) \$request->input('id');"));
    assertSame(true, str_contains($body, '(new CodeWorkspaceRepository($this->app->db))->revoke($id);'));
});
