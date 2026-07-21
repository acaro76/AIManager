<?php

declare(strict_types=1);

use App\Core\Cancellation\CancellationStore;

/** F1.8 — lifecycle atomico dello Stop Code, senza server né DB. */

$make = static function (): array {
    $path = sys_get_temp_dir() . '/aimanager_cancel_lifecycle_' . uniqid('', true);

    return [$path, new CancellationStore($path)];
};

test('Cancellation lifecycle: Stop anticipato viene visto quando la richiesta inizia', function () use ($make) {
    [$path, $store] = $make();
    $id = 'code-stop-before-001';

    assertSame(true, $store->cancelPendingOrActive($id));
    $store->begin($id);
    assertSame(true, $store->token($id)->isCancelled());

    $store->finish($id);
    assertSame(false, is_file($path . '/' . $id . '.cancel'));
});

test('Cancellation lifecycle: Stop attivo cancella e finish pulisce il marker', function () use ($make) {
    [$path, $store] = $make();
    $id = 'code-stop-active-001';

    $store->begin($id);
    assertSame(true, $store->cancelPendingOrActive($id));
    assertSame(true, $store->token($id)->isCancelled());
    $store->finish($id);

    assertSame(false, is_file($path . '/' . $id . '.active'));
    assertSame(false, is_file($path . '/' . $id . '.cancel'));
    assertSame(true, is_file($path . '/' . $id . '.done'));
});

test('Cancellation lifecycle: Stop tardivo non crea un marker orfano', function () use ($make) {
    [$path, $store] = $make();
    $id = 'code-stop-late-0001';

    $store->begin($id);
    $store->finish($id);

    assertSame(false, $store->cancelPendingOrActive($id));
    assertSame(false, is_file($path . '/' . $id . '.cancel'));
    assertSame(false, $store->token($id)->isCancelled());
});

test('Cancellation lifecycle: due request id restano indipendenti', function () use ($make) {
    [, $store] = $make();
    $a = 'code-stop-isolate-a';
    $b = 'code-stop-isolate-b';

    $store->begin($a);
    $store->begin($b);
    assertSame(true, $store->cancelPendingOrActive($a));
    assertSame(true, $store->token($a)->isCancelled());
    assertSame(false, $store->token($b)->isCancelled());

    $store->finish($a);
    $store->finish($b);
});

test('Cancellation lifecycle: prune elimina tombstone e stati transitori scaduti', function () use ($make) {
    [$path, $store] = $make();
    $done = 'code-stop-prune-done';
    $active = 'code-stop-prune-live';
    $cancel = 'code-stop-prune-stop';

    $store->begin($done);
    $store->finish($done);
    $store->begin($active);
    $store->cancelPendingOrActive($cancel);
    foreach (glob($path . '/*.{done,active,cancel}', GLOB_BRACE) ?: [] as $file) {
        touch($file, time() - 7200);
    }

    $store->prune(3600);
    assertSame([], glob($path . '/*.{done,active,cancel}', GLOB_BRACE) ?: []);
});
