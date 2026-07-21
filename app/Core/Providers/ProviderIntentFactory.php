<?php

declare(strict_types=1);

namespace App\Core\Providers;

/**
 * Punto unico di calcolo del ProviderIntent da un prompt. Prima esistevano due
 * copie quasi identiche (ChatService::providerIntent per lo streaming,
 * Orchestrator::providerIntent come fallback non-streaming), modificate a
 * mano ogni volta e a rischio di divergenza (audit sez. 37): stesso messaggio
 * instradato in modo diverso a seconda del percorso di codice che lo
 * intercettava. Questa e' l'unica logica, usata da entrambi i chiamanti.
 */
final class ProviderIntentFactory
{
    public static function fromPrompt(string $prompt, bool $hasFiles, bool $hasImages): ProviderIntent
    {
        $lower = mb_strtolower($prompt);
        $codeWords = ['codice', 'php', 'javascript', 'sqlite', 'sql', 'classe', 'funzione', 'bug', 'refactor', 'audit', 'architettura'];
        $reasoningWords = ['analizza', 'audit', 'piano', 'strategia', 'confronta', 'progetta', 'valuta', 'spiega'];
        $longWords = ['documento', 'pdf', 'lungo', 'completo', 'dettagliato', 'tutto', 'intero'];

        $taskType = $hasImages ? 'vision' : (self::containsAny($lower, $codeWords) ? 'code' : 'general');
        $complexity = self::containsAny($lower, $reasoningWords) ? 4 : 2;
        $contextSize = mb_strlen($prompt) > 6000 || self::containsAny($lower, $longWords) || $hasFiles ? 4 : 1;
        $latency = $complexity >= 4 || $contextSize >= 4 ? 2 : 4;
        $cost = 4;

        return new ProviderIntent(
            $taskType,
            $complexity,
            $latency,
            $cost,
            $contextSize,
            requiresTools: $taskType === 'code',
            requiresFiles: $hasFiles,
            requiresReasoning: $complexity >= 4,
            requiresVision: $hasImages,
            requiresWeb: self::requiresWeb($lower, $hasFiles, $hasImages, $taskType),
            requiresKnowledge: self::requiresKnowledge($lower, $hasFiles, $hasImages, $taskType),
            requiresDeepReasoning: self::requiresDeepReasoning($lower, $prompt, $hasImages, $taskType),
        );
    }

    public static function hasImageAttachments(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            if (!empty($attachment['is_image'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rompicapo logico / problema a piu' vincoli: serve il miglior ragionatore disponibile
     * (profilo `reasoning` alto), NON un modello veloce-debole come flash-lite. Riconosce
     * parole-chiave di logica o prompt lunghi e articolati (non immagini, non codice).
     */
    private static function requiresDeepReasoning(string $lower, string $prompt, bool $hasImages, string $taskType): bool
    {
        if ($hasImages || $taskType === 'code') {
            return false;
        }

        $puzzleWords = ['indovinello', 'rompicapo', 'enigma', 'quesito', 'sillogismo', 'logica', 'logico', 'dimostra', 'deduci', 'deduzione', 'quante domande', 'tre domande', 'puzzle', 'paradosso', 'incognite', 'a quante persone', 'quanti modi'];
        if (self::containsAny($lower, $puzzleWords)) {
            return true;
        }

        // Prompt lungo e articolato con domanda esplicita: problema non banale.
        return mb_strlen($prompt) > 700 && str_contains($prompt, '?');
    }

    /**
     * Domanda di "pura conoscenza generale": fattuale o esplicativa, dove conta
     * l'accuratezza piu' della velocita'. In AUTO viene preferito Gemini (piu' accurato),
     * con Groq come fallback. Esclude codice, immagini, allegati e richieste creative/di
     * trasformazione (scrivere, tradurre, riassumere...), dove la velocita' di Groq va bene.
     */
    private static function requiresKnowledge(string $lower, bool $hasFiles, bool $hasImages, string $taskType): bool
    {
        if ($hasImages || $hasFiles || $taskType === 'code') {
            return false;
        }

        $creative = ['scrivi', 'scrivimi', 'genera', 'generami', 'traduci', 'traducimi', 'riassumi', 'correggi', 'crea', 'creami', 'inventa', 'componi', 'racconta', 'poesia', 'poema', 'canzone', 'email', 'e-mail', 'lettera', 'racconto', 'storia', 'rendimi', 'riscrivi', 'parafrasa'];
        if (self::containsAny($lower, $creative)) {
            return false;
        }

        $interrogatives = ['chi ', "chi e", 'chi è', 'cosa ', "cos'e", "cos'è", 'che cos', 'qual', 'quale', 'quali', 'quando', 'dove ', 'perche', 'perché', 'come funziona', 'come si', 'spiega', 'spiegami', 'dimmi', 'parlami', 'differenza tra', 'cosa significa', 'che significa', 'significato di', 'quanti', 'quante', 'quanto', 'definizione', 'definisci'];
        return str_contains($lower, '?') || self::containsAny($lower, $interrogatives);
    }

    /**
     * Decide se servono fonti web. Conservativo: solo quando c'e' un segnale chiaro di
     * informazione fresca/verificabile, oppure su richiesta esplicita dell'utente.
     * Evita su immagini, codice e allegati (a meno di trigger esplicito): in quei casi
     * la risposta deve ancorarsi al contenuto fornito, non al web.
     */
    /**
     * L'utente ha chiesto ESPLICITAMENTE una ricerca web ("cerca sul web", "/web"...).
     * Fonte unica: usata sia da requiresWeb sia dall'Orchestrator per decidere se il
     * web deve scavalcare la memoria residente di progetto (sez. 43).
     */
    public static function isExplicitWebRequest(string $prompt): bool
    {
        $explicit = ['cerca sul web', 'cerca online', 'cerca su internet', 'su internet', 'sul web', 'cerca su google', 'google ', '/web', 'fai una ricerca'];
        return self::containsAny(mb_strtolower($prompt), $explicit);
    }

    private static function requiresWeb(string $lower, bool $hasFiles, bool $hasImages, string $taskType): bool
    {
        if (self::isExplicitWebRequest($lower)) {
            return true;
        }

        if ($hasImages || $hasFiles || $taskType === 'code') {
            return false;
        }

        $freshness = [
            'oggi', 'attuale', 'attualmente', 'adesso', 'in questo momento', 'ultim', 'recente', 'recenti',
            'novita', 'novità', 'notizia', 'notizie', 'meteo', 'prezzo', 'quotazione', 'quanto costa',
            'borsa', 'classifica', 'risultato', 'chi ha vinto', 'in corso', 'ultima versione',
            'chi e ', "chi e'", 'chi è', 'di chi e', 'di chi è', 'chi possiede', 'cos e', "cos'e", "cos'è", 'che cos', 'quando esce', 'quando uscira', 'quando è uscito', 'quando e uscito',
            '2024', '2025', '2026', '2027',
        ];

        return self::containsAny($lower, $freshness);
    }

    private static function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
