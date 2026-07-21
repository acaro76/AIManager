<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Legge allegati testuali scelti esplicitamente dall'utente per il solo turno Code corrente.
 * I file restano nei temporanei gestiti da PHP: non vengono copiati, rinominati o persistiti.
 */
final class CodeUploadReader
{
    private const MAX_FILES = 5;
    private const MAX_FILE_BYTES = 131072;
    private const MAX_TOTAL_BYTES = 393216;

    /** @var list<string> */
    private const EXTENSIONS = [
        'txt', 'md', 'csv', 'json', 'xml', 'html', 'css', 'js', 'ts', 'php', 'py',
        'sql', 'log', 'yml', 'yaml',
    ];

    /**
     * @param array<string, mixed>|null $upload valore di $_FILES['attachments']
     * @param (callable(string): bool)|null $isUploadedFile iniettabile nei test
     * @return list<array{name:string,content:string}>
     */
    public function read(?array $upload, ?callable $isUploadedFile = null): array
    {
        if ($upload === null || !isset($upload['name'])) {
            return [];
        }

        $isUploadedFile ??= static fn (string $path): bool => is_uploaded_file($path);
        $names = is_array($upload['name']) ? $upload['name'] : [$upload['name']];
        $tmpNames = is_array($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : [$upload['tmp_name'] ?? ''];
        $errors = is_array($upload['error'] ?? null) ? $upload['error'] : [$upload['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = is_array($upload['size'] ?? null) ? $upload['size'] : [$upload['size'] ?? 0];

        if (count($names) > self::MAX_FILES) {
            throw new \InvalidArgumentException('Puoi aggiungere al massimo 5 file per messaggio.');
        }

        $result = [];
        $total = 0;
        foreach ($names as $index => $rawName) {
            $error = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new \InvalidArgumentException('Non è stato possibile leggere uno dei file selezionati.');
            }

            $name = trim(basename(str_replace('\\', '/', (string) $rawName)));
            $tmpName = (string) ($tmpNames[$index] ?? '');
            $size = (int) ($sizes[$index] ?? 0);
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            if ($name === '' || !in_array($extension, self::EXTENSIONS, true)) {
                throw new \InvalidArgumentException('Sono ammessi solo file di testo o codice.');
            }
            if ($size < 0 || $size > self::MAX_FILE_BYTES || $total + $size > self::MAX_TOTAL_BYTES) {
                throw new \InvalidArgumentException('I file selezionati superano il limite consentito.');
            }
            if ($tmpName === '' || !$isUploadedFile($tmpName) || !is_readable($tmpName)) {
                throw new \InvalidArgumentException('Il file selezionato non è leggibile.');
            }

            $content = file_get_contents($tmpName, false, null, 0, self::MAX_FILE_BYTES + 1);
            if ($content === false || strlen($content) > self::MAX_FILE_BYTES || str_contains($content, "\0")) {
                throw new \InvalidArgumentException('Il file selezionato non è un documento di testo consultabile.');
            }
            $total += strlen($content);
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new \InvalidArgumentException('I file selezionati superano il limite consentito.');
            }
            $result[] = ['name' => Utf8::clean($name), 'content' => Utf8::clean($content)];
        }

        return $result;
    }
}
