<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Directory;
use App\Models\ChatAttachment;

final class ChatAttachmentService
{
    private const MAX_FILE_BYTES = 52428800; // 50MB (era 5MB): macchina locale, niente server da proteggere
    private const MAX_TEXT_CHARS = 12000;
    private const MAX_TOTAL_CHARS = 24000;
    // Estrazione COMPLETA del testo dell'allegato prima del recupero mirato: non piu' "prime
    // pagine". Il recupero (LocalDocumentRetriever) poi tiene solo i pezzi pertinenti entro
    // il budget del provider. Tetto alto solo come guardia di sanita'.
    private const MAX_ATTACHMENT_TEXT_CHARS = 800000;
    private const READABLE_EXTENSIONS = ['txt', 'md', 'csv', 'json', 'xml', 'html', 'css', 'js', 'ts', 'php', 'py', 'sql', 'log', 'yml', 'yaml', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    // La memoria residente di progetto e' un documento da interrogare per intero
    // (es. una costituzione di 140 pagine): non va troncata a 12k come gli allegati
    // di chat, che sono invece transitori. Tetto alto solo come guardia di sanita'.
    private const MAX_MEMORY_TEXT_CHARS = 2000000;

    public function __construct(private readonly string $storagePath)
    {
    }

    /**
     * @param string $query domanda dell'utente: se passata insieme a $retriever, il testo
     *   dell'allegato viene ridotto ai pezzi pertinenti (recupero mirato) invece che troncato.
     * @param int $totalBudget budget totale in caratteri per tutti gli allegati del messaggio.
     */
    public function ingest(array $files, array $project, array $session, string $query = '', int $totalBudget = self::MAX_TOTAL_CHARS, ?LocalDocumentRetriever $retriever = null): array
    {
        $items = [];
        $totalChars = 0;
        foreach ($this->normalize($files['attachments'] ?? null) as $file) {
            if (!$this->valid($file)) {
                continue;
            }

            $stored = $this->store($file, (int) $project['id'], (int) $session['id']);
            if ($stored === null) {
                // Salvataggio del file fallito: salta questo allegato invece di creare
                // una riga DB con un percorso inesistente.
                continue;
            }
            // Testo COMPLETO del file (non le prime pagine), poi ridotto ai pezzi pertinenti.
            $fullText = $this->extractText($stored['path'], $stored['name'], (int) $stored['size'], self::MAX_ATTACHMENT_TEXT_CHARS);
            $remaining = max(0, $totalBudget - $totalChars);
            if ($remaining <= 0) {
                $text = '';
            } elseif ($retriever !== null && trim($query) !== '') {
                // Spezza il documento e tiene solo i pezzi pertinenti alla domanda entro il
                // budget. Se gli embedding locali non ci sono, ricade sui primi pezzi (pulito).
                $text = $retriever->selectRelevant($fullText, $query, $remaining);
            } else {
                $text = mb_substr($fullText, 0, min(self::MAX_TEXT_CHARS, $remaining));
            }
            $totalChars += mb_strlen($text);

            try {
                $attachmentId = (new ChatAttachment())->create([
                    'project_id' => (int) $project['id'],
                    'session_id' => (int) $session['id'],
                    'name' => $stored['name'],
                    'path' => $stored['relative_path'],
                    'extension' => $stored['extension'],
                    'size' => $stored['size'],
                    'mime' => $stored['mime'],
                    'text' => $text,
                    'is_image' => str_starts_with((string) $stored['mime'], 'image/'),
                ]);
            } catch (\Throwable $exception) {
                // INSERT fallito: cancella il file appena creato per non lasciarlo orfano.
                @unlink($stored['path']);
                throw $exception;
            }

            $items[] = [
                'id' => $attachmentId,
                'name' => $stored['name'],
                'path' => $stored['relative_path'],
                'absolute_path' => $stored['path'],
                'extension' => $stored['extension'],
                'size' => $stored['size'],
                'mime' => $stored['mime'],
                'is_image' => str_starts_with((string) $stored['mime'], 'image/'),
                'text' => $text,
            ];
        }

        return $items;
    }

    /**
     * Scarta allegati appena ingeriti ma mai collegati a un messaggio (orfani): riga DB + file
     * su disco. Serve quando la richiesta viene interrotta prima che il messaggio utente sia
     * salvato, cosi' non restano file appesi e invisibili nello storico (audit).
     *
     * @param array<int, array<string, mixed>> $attachments item come restituiti da ingest()
     */
    public function discard(array $attachments): void
    {
        $model = new ChatAttachment();
        foreach ($attachments as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0) {
                $model->delete($id);
            }
            $absolute = (string) ($item['absolute_path'] ?? '');
            if ($absolute !== '' && is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    public function ingestMemoryFile(?array $file, int $projectId): ?array
    {
        if (!$file || !$this->valid($file)) {
            return null;
        }

        $stored = $this->storeForMemory($file, $projectId);
        if ($stored === null) {
            return null;
        }
        $text = $this->extractText($stored['path'], $stored['name'], (int) $stored['size'], self::MAX_MEMORY_TEXT_CHARS);

        return [
            'name' => $stored['name'],
            'path' => $stored['relative_path'],
            'absolute_path' => $stored['path'],
            'extension' => $stored['extension'],
            'size' => $stored['size'],
            'mime' => $stored['mime'],
            'text' => $text,
        ];
    }

    /**
     * Salva un'immagine generata (base64) come allegato di chat (is_image), senza
     * conversation_id: verra' collegato al messaggio assistant dopo il salvataggio.
     * Handoff sez. 46.
     *
     * @return array{id: int, name: string, path: string, absolute_path: string, mime: string, size: int}|null
     */
    public function saveGeneratedImage(string $base64, string $mime, int $projectId, int $sessionId): ?array
    {
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '' || strlen($binary) > self::MAX_FILE_BYTES) {
            return null;
        }

        $extension = match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
        $mime = $mime !== '' ? $mime : 'image/png';

        $dir = $this->storagePath . '/attachments/project-' . $projectId . '/session-' . $sessionId;
        Directory::ensure($dir);
        $name = 'immagine-generata-' . date('YmdHis') . '.' . $extension;
        $filename = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $name;
        $path = $dir . '/' . $filename;
        if (file_put_contents($path, $binary) === false) {
            return null;
        }

        $relativePath = 'attachments/project-' . $projectId . '/session-' . $sessionId . '/' . $filename;
        try {
            $attachmentId = (new ChatAttachment())->create([
                'project_id' => $projectId,
                'session_id' => $sessionId,
                'name' => $name,
                'path' => $relativePath,
                'extension' => $extension,
                'size' => strlen($binary),
                'mime' => $mime,
                'text' => '',
                'is_image' => true,
            ]);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return ['id' => $attachmentId, 'name' => $name, 'path' => $relativePath, 'absolute_path' => $path, 'mime' => $mime, 'size' => strlen($binary)];
    }

    /** Elimina solo un file realmente contenuto nello storage di AIManager. */
    public function deleteStoredFile(string $relativePath): void
    {
        $storage = realpath($this->storagePath);
        $candidate = realpath($this->storagePath . '/' . ltrim($relativePath, '/'));
        if ($storage === false || $candidate === false || !str_starts_with($candidate, $storage . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
            return;
        }

        @unlink($candidate);
    }

    public function promptBlock(array $attachments): string
    {
        if (!$attachments) {
            return '';
        }

        $lines = ['', 'Documenti allegati alla richiesta corrente:'];
        foreach ($attachments as $attachment) {
            $lines[] = '--- ' . $attachment['name'] . ' ---';
            $lines[] = !empty($attachment['is_image'])
                ? '[Immagine allegata: deve essere analizzata da un provider con supporto vision.]'
                : ($attachment['text'] !== ''
                ? $attachment['text']
                : '[File allegato salvato ma testo non estraibile automaticamente in questa versione.]');
        }

        return implode("\n", $lines);
    }

    private function normalize(?array $files): array
    {
        if (!$files || !isset($files['name'])) {
            return [];
        }

        if (!is_array($files['name'])) {
            return [$files];
        }

        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $normalized;
    }

    private function valid(array $file): bool
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }

        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))
            || (int) ($file['size'] ?? 0) <= 0
            || (int) ($file['size'] ?? 0) > self::MAX_FILE_BYTES) {
            return false;
        }

        return $this->isAllowedFile((string) $file['tmp_name'], (string) $file['name']);
    }

    private function store(array $file, int $projectId, int $sessionId): ?array
    {
        $name = $this->safeName((string) $file['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $dir = $this->storagePath . '/attachments/project-' . $projectId . '/session-' . $sessionId;
        Directory::ensure($dir);
        $filename = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $name;
        $path = $dir . '/' . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
            return null;
        }

        return [
            'name' => $name,
            'path' => $path,
            'relative_path' => 'attachments/project-' . $projectId . '/session-' . $sessionId . '/' . $filename,
            'extension' => $extension,
            'size' => (int) $file['size'],
            'mime' => $this->mimeType($path) ?: (string) ($file['type'] ?? ''),
        ];
    }

    private function storeForMemory(array $file, int $projectId): ?array
    {
        $name = $this->safeName((string) $file['name']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $dir = $this->storagePath . '/memory/project-' . $projectId;
        Directory::ensure($dir);
        $filename = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $name;
        $path = $dir . '/' . $filename;
        if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
            return null;
        }

        return [
            'name' => $name,
            'path' => $path,
            'relative_path' => 'memory/project-' . $projectId . '/' . $filename,
            'extension' => $extension,
            'size' => (int) $file['size'],
            'mime' => $this->mimeType($path) ?: (string) ($file['type'] ?? ''),
        ];
    }

    private function extractText(string $path, string $name, int $size, int $maxChars = self::MAX_TEXT_CHARS): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === 'pdf') {
            return $this->extractPdfText($path, $maxChars);
        }

        if ($extension === 'docx') {
            return $this->extractDocxText($path, $maxChars);
        }

        if ($extension === 'xlsx') {
            return $this->extractXlsxText($path, $maxChars);
        }

        if (in_array($extension, ['doc', 'xls'], true)) {
            return $this->extractLegacyOfficeText($path, $extension, $maxChars);
        }

        if (!in_array($extension, self::READABLE_EXTENSIONS, true)) {
            return '';
        }

        if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], true)) {
            return '';
        }

        if ($size > self::MAX_FILE_BYTES) {
            return '';
        }

        $content = file_get_contents($path);
        if (!is_string($content)) {
            return '';
        }

        $content = preg_replace('/[^\P{C}\t\r\n]+/u', '', $content) ?? $content;
        return trim(mb_substr($content, 0, $maxChars));
    }

    /**
     * Un .docx e' uno zip OpenXML: il testo del documento sta in word/document.xml.
     * Estraiamo quell'XML e lo convertiamo in testo mantenendo gli a-capo dei
     * paragrafi. Nessuna libreria esterna: basta ZipArchive (di solito presente).
     */
    private function extractDocxText(string $path, int $maxChars = self::MAX_TEXT_CHARS): string
    {
        if (!class_exists('ZipArchive')) {
            return '';
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return '';
        }

        // Preserva la struttura: fine paragrafo / interruzione -> a-capo, tab -> tab.
        $xml = preg_replace('/<w:(p|br)\b[^>]*\/?>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<w:tab\b[^>]*\/?>/', "\t", $xml) ?? $xml;

        // Via tutti i tag rimanenti, poi decodifica le entita' XML.
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        // Normalizza gli a-capo multipli e i caratteri di controllo residui.
        $text = preg_replace('/[^\P{C}\t\r\n]+/u', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim(mb_substr($text, 0, $maxChars));
    }

    /**
     * Un .xlsx e' uno zip OpenXML. Leggiamo sharedStrings.xml e i fogli XML,
     * trasformando le celle in righe di testo separate da tab.
     */
    private function extractXlsxText(string $path, int $maxChars = self::MAX_TEXT_CHARS): string
    {
        if (!class_exists('ZipArchive') || !class_exists('SimpleXMLElement')) {
            return '';
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $sharedStrings = $this->xlsxSharedStrings($zip);
        $sheetNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetNames[] = $name;
            }
        }
        sort($sheetNames, SORT_NATURAL);

        $lines = [];
        foreach ($sheetNames as $sheetName) {
            $xml = $zip->getFromName($sheetName);
            if (!is_string($xml) || $xml === '') {
                continue;
            }

            $sheet = $this->xmlElement($xml);
            if (!$sheet) {
                continue;
            }

            $sheet->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $row) {
                $row->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $cells = [];
                foreach ($row->xpath('m:c') ?: [] as $cell) {
                    $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                    $type = (string) ($cell['t'] ?? '');
                    $value = trim((string) (($cell->xpath('m:v') ?: [])[0] ?? ''));
                    if ($type === 's') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $texts = [];
                        foreach ($cell->xpath('.//m:t') ?: [] as $textNode) {
                            $texts[] = (string) $textNode;
                        }
                        $value = trim(implode('', $texts));
                    }

                    $cells[] = $value;
                }

                $line = trim(implode("\t", array_filter($cells, static fn (string $value): bool => $value !== '')));
                if ($line !== '') {
                    $lines[] = $line;
                    if (mb_strlen(implode("\n", $lines)) >= $maxChars) {
                        break 2;
                    }
                }
            }
        }

        $zip->close();
        return trim(mb_substr(implode("\n", $lines), 0, $maxChars));
    }

    private function extractLegacyOfficeText(string $path, string $extension, int $maxChars = self::MAX_TEXT_CHARS): string
    {
        if ($extension === 'doc') {
            $textutil = $this->findExecutable('textutil', ['/usr/bin/textutil']);
            if ($textutil !== '') {
                $output = shell_exec(escapeshellarg($textutil) . ' -convert txt -stdout ' . escapeshellarg($path) . ' 2>/dev/null');
                if (is_string($output) && trim($output) !== '') {
                    return trim(mb_substr($this->cleanExtractedText($output), 0, $maxChars));
                }
            }
        }

        return $this->extractWithSoffice($path, $extension === 'xls' ? 'csv' : 'txt', $maxChars);
    }

    private function extractWithSoffice(string $path, string $targetFormat, int $maxChars = self::MAX_TEXT_CHARS): string
    {
        $binary = $this->findExecutable('soffice', [
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
            '/opt/homebrew/bin/soffice',
            '/usr/local/bin/soffice',
        ]);
        if ($binary === '') {
            $binary = $this->findExecutable('libreoffice', ['/opt/homebrew/bin/libreoffice', '/usr/local/bin/libreoffice']);
        }
        if ($binary === '') {
            return '';
        }

        $dir = sys_get_temp_dir() . '/aimanager-office-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            return '';
        }

        shell_exec(
            escapeshellarg($binary)
            . ' --headless --convert-to ' . escapeshellarg($targetFormat)
            . ' --outdir ' . escapeshellarg($dir)
            . ' ' . escapeshellarg($path)
            . ' 2>/dev/null'
        );

        $files = glob($dir . '/*.' . $targetFormat) ?: [];
        $content = '';
        if ($files) {
            $raw = file_get_contents($files[0]);
            $content = is_string($raw) ? $raw : '';
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);

        return trim(mb_substr($this->cleanExtractedText($content), 0, $maxChars));
    }

    /**
     * @return string[]
     */
    private function xlsxSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml) || $xml === '') {
            return [];
        }

        $shared = $this->xmlElement($xml);
        if (!$shared) {
            return [];
        }

        $shared->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        foreach ($shared->xpath('//m:si') ?: [] as $item) {
            $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach ($item->xpath('.//m:t') ?: [] as $textNode) {
                $parts[] = (string) $textNode;
            }
            $strings[] = trim(implode('', $parts));
        }

        return $strings;
    }

    private function xmlElement(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $element instanceof \SimpleXMLElement ? $element : null;
    }

    /**
     * @param string[] $fallbacks
     */
    private function findExecutable(string $name, array $fallbacks = []): string
    {
        $binary = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($binary !== '' && is_executable($binary)) {
            return $binary;
        }

        foreach ($fallbacks as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function cleanExtractedText(string $text): string
    {
        $text = preg_replace('/[^\P{C}\t\r\n]+/u', '', $text) ?? $text;
        $text = preg_replace('/\r\n?/', "\n", $text) ?? $text;
        return preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    }

    private function extractPdfText(string $path, int $maxChars = self::MAX_TEXT_CHARS): string
    {
        $binary = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($binary === '') {
            return '';
        }

        $output = shell_exec(escapeshellarg($binary) . ' -layout -q ' . escapeshellarg($path) . ' - 2>/dev/null');
        if (!is_string($output) || trim($output) === '') {
            return '';
        }

        return trim(mb_substr($output, 0, $maxChars));
    }

    private function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^a-zA-Z0-9._ -]/', '_', $name) ?? 'attachment';
        return trim($name) !== '' ? trim($name) : 'attachment';
    }

    private function isAllowedFile(string $path, string $name): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowedExtensions = array_merge(self::READABLE_EXTENSIONS, self::IMAGE_EXTENSIONS);
        $mime = $this->mimeType($path);

        if (str_starts_with($mime, 'text/') || str_starts_with($mime, 'image/')) {
            return in_array($extension, $allowedExtensions, true);
        }

        $allowedMimes = [
            'application/pdf',
            'application/json',
            'application/xml',
            'application/x-empty',
            'application/octet-stream',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        return in_array($extension, $allowedExtensions, true) && in_array($mime, $allowedMimes, true);
    }

    private function mimeType(string $path): string
    {
        if (!function_exists('finfo_open')) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) {
            return '';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($mime) ? $mime : '';
    }
}
