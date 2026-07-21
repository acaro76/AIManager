<?php

declare(strict_types=1);

use App\Services\ChatAttachmentService;

// storagePath non usato da promptBlock: stringa vuota va bene per questi test.
$svc = new ChatAttachmentService('');

test('promptBlock senza allegati torna stringa vuota', function () use ($svc) {
    assertSame('', $svc->promptBlock([]));
});

test('promptBlock inserisce nome e testo di un allegato testuale', function () use ($svc) {
    $out = $svc->promptBlock([['name' => 'note.txt', 'text' => 'Contenuto', 'is_image' => false]]);
    assertSame(
        "\nDocumenti allegati alla richiesta corrente:\n--- note.txt ---\nContenuto",
        $out
    );
});

test('promptBlock segnala le immagini come da analizzare in vision', function () use ($svc) {
    $out = $svc->promptBlock([['name' => 'foto.png', 'text' => '', 'is_image' => true]]);
    assertSame(
        "\nDocumenti allegati alla richiesta corrente:\n--- foto.png ---\n[Immagine allegata: deve essere analizzata da un provider con supporto vision.]",
        $out
    );
});

test('promptBlock segnala il testo non estraibile', function () use ($svc) {
    $out = $svc->promptBlock([['name' => 'x.bin', 'text' => '', 'is_image' => false]]);
    assertSame(
        "\nDocumenti allegati alla richiesta corrente:\n--- x.bin ---\n[File allegato salvato ma testo non estraibile automaticamente in questa versione.]",
        $out
    );
});

test('promptBlock elenca piu\' allegati nell\'ordine dato', function () use ($svc) {
    $out = $svc->promptBlock([
        ['name' => 'a.txt', 'text' => 'Alfa', 'is_image' => false],
        ['name' => 'b.txt', 'text' => 'Beta', 'is_image' => false],
    ]);
    assertSame(
        "\nDocumenti allegati alla richiesta corrente:\n--- a.txt ---\nAlfa\n--- b.txt ---\nBeta",
        $out
    );
});
