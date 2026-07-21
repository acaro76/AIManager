<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Cancellation\CancellationToken;
use App\Core\Configuration\ConfigurationManager;
use App\Core\Providers\LmStudioContextWindow;

/**
 * Recupero mirato per documenti residenti grandi quando il progetto e' pinnato in
 * locale (LM Studio): il documento intero non entra nella finestra reale del modello
 * locale (spesso 8k), quindi lo si spezza in pezzi e si tengono solo quelli piu'
 * pertinenti alla domanda, entro il budget della finestra.
 *
 * La pertinenza usa gli embedding locali di LM Studio (modello configurabile via
 * LMSTUDIO_EMBED_MODEL, es. bge-m3 multilingua). Se il modello di embedding non e'
 * configurato o l'API non risponde, ripiega sui primi pezzi in ordine di documento:
 * niente overflow, contesto pulito (comportamento "prime pagine" ma coerente).
 */
final class LocalDocumentRetriever
{
    private const CHARS_PER_TOKEN = 4;
    private const CHUNK_TARGET_CHARS = 1500;
    // Riserva (token) per system prompt + domanda + storico + output: il resto della
    // finestra e' il budget per il documento residente.
    private const RESERVE_TOKENS = 2500;
    private const FALLBACK_WINDOW_TOKENS = 8192;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $embedModel,
        private readonly string $cacheDir = '',
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    public static function fromRoot(string $root): self
    {
        $config = ConfigurationManager::fromRoot($root);
        return new self(
            $config->get('LMSTUDIO_ENDPOINT'),
            $config->get('LMSTUDIO_EMBED_MODEL'),
            rtrim($root, '/') . '/storage/cache/doc-embeddings',
        );
    }

    /**
     * Budget in caratteri per il documento residente = (finestra reale - riserva) * char/token.
     */
    public function budgetChars(?CancellationToken $cancellation = null): int
    {
        $window = $this->loadedContextWindow($cancellation) ?? self::FALLBACK_WINDOW_TOKENS;
        $budgetTokens = max(0, $window - self::RESERVE_TOKENS);
        return $budgetTokens * self::CHARS_PER_TOKEN;
    }

    /**
     * Riduce il documento ai pezzi pertinenti alla domanda che entrano in $budgetChars.
     * Se il documento ci sta gia' tutto, lo restituisce invariato.
     */
    public function selectRelevant(string $document, string $query, int $budgetChars, ?CancellationToken $cancellation = null): string
    {
        $document = trim($document);
        if ($document === '' || $budgetChars <= 0 || $cancellation?->isCancelled()) {
            return $document;
        }
        if (mb_strlen($document) <= $budgetChars) {
            return $document;
        }

        $chunks = $this->chunk($document);
        if (!$chunks) {
            return mb_substr($document, 0, $budgetChars);
        }

        $order = $this->rankByRelevance($document, $chunks, $query, $cancellation);
        if ($order === null) {
            // Nessun embedding: primi pezzi in ordine di documento (pulito, niente overflow).
            $order = array_keys($chunks);
        }

        return $this->assemble($chunks, $order, $budgetChars);
    }

    /**
     * @return string[]
     */
    private function chunk(string $text): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];
        $chunks = [];
        $buffer = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim((string) $paragraph);
            if ($paragraph === '') {
                continue;
            }
            if ($buffer !== '' && mb_strlen($buffer) + mb_strlen($paragraph) > self::CHUNK_TARGET_CHARS) {
                $chunks[] = $buffer;
                $buffer = $paragraph;
            } else {
                $buffer = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;
            }
        }
        if ($buffer !== '') {
            $chunks[] = $buffer;
        }
        return $chunks;
    }

    /**
     * Indici dei chunk ordinati per pertinenza (piu' simili prima), oppure null se
     * gli embedding non sono disponibili (modello non configurato o API muta).
     *
     * @param string[] $chunks
     * @return int[]|null
     */
    private function rankByRelevance(string $document, array $chunks, string $query, ?CancellationToken $cancellation = null): ?array
    {
        if ($this->embedModel === '' || trim($query) === '' || $cancellation?->isCancelled()) {
            return null;
        }

        // I vettori dei pezzi non cambiano finche' il documento e' lo stesso: si calcolano
        // una volta e si mettono in cache. A ogni domanda si embedda solo la query (veloce).
        $chunkVectors = $this->chunkVectors($document, $chunks, $cancellation);
        if ($chunkVectors === null) {
            return null;
        }
        $queryVectors = $this->embed([$query], $cancellation);
        if ($queryVectors === null || $queryVectors === []) {
            return null;
        }
        $queryVector = $queryVectors[0];

        $scores = [];
        foreach ($chunkVectors as $index => $vector) {
            $scores[$index] = $this->cosine($queryVector, $vector);
        }
        arsort($scores);
        return array_keys($scores);
    }

    /**
     * Vettori dei pezzi, da cache su file (chiave = modello + hash del documento) o
     * calcolati e salvati al primo uso.
     *
     * @param string[] $chunks
     * @return array<int, float[]>|null
     */
    private function chunkVectors(string $document, array $chunks, ?CancellationToken $cancellation = null): ?array
    {
        if ($cancellation?->isCancelled()) {
            return null;
        }
        $file = '';
        if ($this->cacheDir !== '') {
            $file = $this->cacheDir . '/' . sha1($this->embedModel . '|' . $document) . '.json';
            if (is_file($file)) {
                $data = json_decode((string) file_get_contents($file), true);
                if (is_array($data) && count($data) === count($chunks)) {
                    return array_map(static fn ($v): array => array_map('floatval', (array) $v), $data);
                }
            }
        }

        $vectors = $this->embed($chunks, $cancellation);
        if ($vectors === null || count($vectors) !== count($chunks)) {
            return null;
        }

        if ($file !== '') {
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0775, true);
            }
            @file_put_contents($file, json_encode($vectors));
        }
        return $vectors;
    }

    /**
     * Prende i chunk seguendo $order fino a riempire il budget, poi li rimette in
     * ordine di documento per una lettura coerente.
     *
     * @param string[] $chunks
     * @param int[] $order
     */
    private function assemble(array $chunks, array $order, int $budgetChars): string
    {
        $separator = "\n\n[...]\n\n";
        $separatorLength = mb_strlen($separator);
        $chosen = [];
        $used = 0;
        foreach ($order as $index) {
            if (!isset($chunks[$index])) {
                continue;
            }
            // Conta anche il separatore che verra' inserito tra i pezzi, cosi' il
            // risultato non sfora il budget.
            $length = mb_strlen($chunks[$index]) + ($chosen === [] ? 0 : $separatorLength);
            if ($chosen === [] && $length > $budgetChars) {
                // Primo pezzo gia' oltre il budget: taglialo.
                $chosen[$index] = mb_substr($chunks[$index], 0, $budgetChars);
                break;
            }
            if ($used + $length > $budgetChars) {
                continue; // salta questo, prova a riempire con pezzi piu' piccoli
            }
            $chosen[$index] = $chunks[$index];
            $used += $length;
        }
        ksort($chosen);
        return implode($separator, $chosen);
    }

    /**
     * @param float[] $a
     * @param float[] $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @param string[] $inputs
     * @return array<int, float[]>|null
     */
    private function embed(array $inputs, ?CancellationToken $cancellation = null): ?array
    {
        if ($cancellation?->isCancelled()) {
            return null;
        }
        $url = rtrim($this->endpoint, '/') . '/embeddings';
        $payload = json_encode(['model' => $this->embedModel, 'input' => array_values($inputs)]);
        if ($payload === false) {
            return null;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        if ($cancellation !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function (...$_) use ($cancellation): int {
                return $cancellation->isCancelled() ? 1 : 0;
            });
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($cancellation?->isCancelled()) {
            return null;
        }
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || empty($decoded['data']) || !is_array($decoded['data'])) {
            return null;
        }

        $vectors = [];
        foreach ($decoded['data'] as $row) {
            if (!is_array($row) || !isset($row['embedding']) || !is_array($row['embedding'])) {
                return null;
            }
            $vectors[] = array_map('floatval', $row['embedding']);
        }
        return $vectors;
    }

    /**
     * Finestra reale del modello caricato in LM Studio (loaded_context_length), o null.
     */
    private function loadedContextWindow(?CancellationToken $cancellation = null): ?int
    {
        if ($cancellation?->isCancelled()) {
            return null;
        }
        $base = LmStudioContextWindow::apiBase($this->endpoint);
        if ($base === '') {
            return null;
        }

        $ch = curl_init($base . '/api/v0/models');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        if ($cancellation !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function (...$_) use ($cancellation): int {
                return $cancellation->isCancelled() ? 1 : 0;
            });
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($cancellation?->isCancelled()) {
            return null;
        }
        if (!is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }

        return LmStudioContextWindow::fromModelsResponse(json_decode($body, true));
    }
}
