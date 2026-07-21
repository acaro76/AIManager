<?php

declare(strict_types=1);

use App\Core\Code\CodeUploadReader;

$upload = static function (string $path, string $name): array {
    return [
        'name' => [$name],
        'tmp_name' => [$path],
        'error' => [UPLOAD_ERR_OK],
        'size' => [filesize($path)],
    ];
};

test('CodeUploadReader: legge un file testuale senza spostarlo o copiarlo', function () use ($upload) {
    $path = tempnam(sys_get_temp_dir(), 'code_upload_');
    file_put_contents($path, "const answer = 42;\n");
    try {
        $files = (new CodeUploadReader())->read($upload($path, 'example.js'), static fn (): bool => true);
        assertSame('example.js', $files[0]['name']);
        assertSame(true, str_contains($files[0]['content'], 'answer'));
        assertSame(true, is_file($path));
    } finally {
        @unlink($path);
    }
});

test('CodeUploadReader: rifiuta estensioni non testuali e contenuti binari', function () use ($upload) {
    $path = tempnam(sys_get_temp_dir(), 'code_upload_');
    file_put_contents($path, "A\0B");
    try {
        $throwsExtension = false;
        try { (new CodeUploadReader())->read($upload($path, 'app.zip'), static fn (): bool => true); } catch (InvalidArgumentException) { $throwsExtension = true; }
        assertSame(true, $throwsExtension);
        $throwsBinary = false;
        try { (new CodeUploadReader())->read($upload($path, 'app.txt'), static fn (): bool => true); } catch (InvalidArgumentException) { $throwsBinary = true; }
        assertSame(true, $throwsBinary);
    } finally {
        @unlink($path);
    }
});

test('CodeUploadReader: il sorgente non contiene primitive di persistenza', function () {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Core/Code/CodeUploadReader.php');
    foreach (['move_uploaded_file', 'file_put_contents', 'rename(', 'copy('] as $forbidden) {
        assertSame(false, str_contains($source, $forbidden), $forbidden);
    }
});
