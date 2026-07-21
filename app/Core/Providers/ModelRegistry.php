<?php

declare(strict_types=1);

namespace App\Core\Providers;

/**
 * Registro dei dati statici e noti dei modelli/provider configurati (sez. 18.2).
 *
 * Consolida in un UNICO posto i valori che prima erano sparsi (e a volte contraddittori)
 * dentro ProviderManager: finestra di contesto, profilo di scoring (punteggi 0-5) e tariffe
 * di costo. La formula di scelta dell'IA non cambia: legge gli stessi identici numeri, solo
 * da qui invece che da tre mappe hardcoded diverse.
 *
 * IMPORTANTE: pura CONSOLIDAZIONE, nessun cambio di logica. I valori sono copiati verbatim
 * da ProviderManager. La finestra REALE di LM Studio NON e' un dato statico (dipende da come
 * l'utente carica il modello) e resta calcolata a runtime in ProviderManager.
 */
final class ModelRegistry
{
    private const DEFAULT_CONTEXT_WINDOW = 32000;

    /**
     * Finestra di contesto approssimativa (token) per provider. Valori prudenti del
     * free/standard tier. Cerebras (gpt-oss free) e' il piu' piccolo.
     */
    private const CONTEXT_WINDOWS = [
        'cerebras' => 8000,
        // Agnes non documenta la finestra: si tiene il default prudente finche' non e' misurata.
        'agnes' => self::DEFAULT_CONTEXT_WINDOW,
        'openrouter' => 32000,
        'groq' => 128000,
        'deepseek' => 128000,
        'openai' => 128000,
        'claude' => 200000,
        'gemini' => 1000000,
    ];

    private const PROFILE_DEFAULTS = [
        'latency' => 2,
        'cost' => 2,
        'context' => 2,
        'reasoning' => 2,
        'files' => 1,
        'tools' => 1,
        'experimental' => 1,
        'code' => 2,
        'vision' => 0,
        'knowledge' => 2,
    ];

    /** Profilo di scoring (0-5) per provider; i campi mancanti ereditano da PROFILE_DEFAULTS. */
    private const PROFILES = [
        'lmstudio' => ['latency' => 1, 'cost' => 5, 'context' => 2, 'reasoning' => 2, 'files' => 5, 'tools' => 4, 'experimental' => 1, 'code' => 3, 'vision' => 2, 'knowledge' => 2],
        'groq' => ['latency' => 5, 'cost' => 4, 'context' => 2, 'reasoning' => 2, 'files' => 1, 'tools' => 1, 'experimental' => 2, 'code' => 2, 'knowledge' => 2],
        'cerebras' => ['latency' => 5, 'cost' => 4, 'context' => 1, 'reasoning' => 2, 'files' => 1, 'tools' => 1, 'experimental' => 2, 'code' => 2, 'knowledge' => 2],
        'gemini' => ['latency' => 4, 'cost' => 4, 'context' => 4, 'reasoning' => 3, 'files' => 2, 'tools' => 2, 'experimental' => 2, 'code' => 3, 'vision' => 5, 'knowledge' => 5],
        'openrouter' => ['latency' => 2, 'cost' => 5, 'context' => 3, 'reasoning' => 3, 'files' => 1, 'tools' => 1, 'experimental' => 5, 'code' => 3, 'knowledge' => 3],
        'deepseek' => ['latency' => 4, 'cost' => 5, 'context' => 5, 'reasoning' => 5, 'files' => 2, 'tools' => 2, 'experimental' => 3, 'code' => 4, 'vision' => 0, 'knowledge' => 5],
        'openai' => ['latency' => 3, 'cost' => 1, 'context' => 4, 'reasoning' => 5, 'files' => 2, 'tools' => 3, 'experimental' => 2, 'code' => 5, 'knowledge' => 5],
        'claude' => ['latency' => 2, 'cost' => 1, 'context' => 5, 'reasoning' => 5, 'files' => 2, 'tools' => 3, 'experimental' => 2, 'code' => 5, 'knowledge' => 5],
        // Agnes: gratis, ma ~7s FISSI a risposta (misurati, anche per un solo token) -> latenza
        // minima, non deve mai vincere un fast-path. Capacita' non dichiarate: restano ai default
        // prudenti finche' non sono verificate sul campo. Gateway nuovo -> experimental alto.
        // I modelli immagine/video di Agnes non passano da qui: questo provider e' solo testo.
        'agnes' => ['latency' => 1, 'cost' => 5, 'context' => 2, 'reasoning' => 2, 'files' => 1, 'tools' => 1, 'experimental' => 4, 'code' => 2, 'vision' => 0, 'knowledge' => 2],
    ];

    /**
     * Tariffe [input, output] in $/token. 0 = free tier / locale. Stime prudenti.
     * DeepSeek V4 (deepseek-chat = v4-flash non-thinking): $0.14/1M in, $0.28/1M out.
     * Gemini gira sul free tier (gemini-2.5-flash-lite, 20 req/min): costo reale 0; se un
     * domani si passa al tier a pagamento, ripristinare ~[0.00000035, 0.00000105].
     */
    private const COST_RATES = [
        'openai' => [0.00000015, 0.0000006],
        'claude' => [0.000003, 0.000015],
        'deepseek' => [0.00000014, 0.00000028],
        'gemini' => [0.0, 0.0],
        'groq' => [0.0, 0.0],
        'openrouter' => [0.0, 0.0],
        'lmstudio' => [0.0, 0.0],
        'cerebras' => [0.0, 0.0],
        'agnes' => [0.0, 0.0],
    ];

    public static function contextWindow(string $provider): int
    {
        return self::CONTEXT_WINDOWS[$provider] ?? self::DEFAULT_CONTEXT_WINDOW;
    }

    /**
     * @return array<string, int> profilo completo (override provider + default)
     */
    public static function profile(string $provider): array
    {
        return (self::PROFILES[$provider] ?? []) + self::PROFILE_DEFAULTS;
    }

    /**
     * @return array{0: float, 1: float} tariffe [input, output] in $/token
     */
    public static function costRate(string $provider): array
    {
        return self::COST_RATES[$provider] ?? [0.0, 0.0];
    }
}
