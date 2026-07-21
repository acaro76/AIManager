<?php

declare(strict_types=1);

namespace App\Core\Providers;

use App\Providers\OpenAICompatibleProvider;
use App\Services\AIProviderRegistry;

/**
 * Catena di fallback dei servizi ausiliari (classificatore web, consolidamento Brain).
 *
 * Quei servizi NON passano da `ProviderManager`: fanno una POST diretta a
 * `base_url/chat/completions` con Bearer, perche' vogliono un JSON secco, veloce e a temperatura 0 —
 * non una risposta streamata. Avevano pero' una catena FISSA di due nomi presi dall'env
 * (`deepseek,cerebras`): se quei due erano giu' o spenti, la funzione si spegneva, mentre la chat
 * principale continuava a cascare su tutti i provider disponibili. Sostituire una IA che non
 * risponde e' il motivo di esistenza di AIManager: la cascata non puo' fermarsi al secondo anello.
 *
 * Qui la catena esplicita dell'env resta PRIMA (e' la preferenza dell'utente), e in coda si
 * aggiungono automaticamente tutti gli altri provider utilizzabili. Nessuno viene tolto: si aggiunge
 * soltanto.
 */
final class ProviderChain
{
    /**
     * Deadline complessivo della catena: al massimo due timeout pieni, con un tetto esplicito.
     * Evita che l'aggiunta di provider moltiplichi senza limite il tempo della richiesta.
     */
    public static function deadline(int $perAttemptSeconds, int $maxTotalSeconds, ?float $now = null): float
    {
        $perAttemptSeconds = max(1, $perAttemptSeconds);
        $maxTotalSeconds = max(1, $maxTotalSeconds);

        return ($now ?? microtime(true)) + min($maxTotalSeconds, $perAttemptSeconds * 2);
    }

    /** Timeout del prossimo tentativo, limitato sia al valore configurato sia al tempo residuo. */
    public static function remainingTimeout(float $deadline, int $perAttemptSeconds, ?float $now = null): int
    {
        $remaining = (int) ceil($deadline - ($now ?? microtime(true)));
        if ($remaining <= 0) {
            return 0;
        }

        return min(max(1, $perAttemptSeconds), $remaining);
    }

    /**
     * Catena esplicita (CSV dall'env) + coda automatica, senza duplicati e senza vuoti.
     *
     * @param list<string> $tail slug da accodare, gia' ordinati
     * @return list<string>
     */
    public static function resolve(string $raw, array $tail = []): array
    {
        $chain = [];
        foreach ([...explode(',', $raw), ...$tail] as $name) {
            $name = strtolower(trim((string) $name));
            if ($name !== '' && !in_array($name, $chain, true)) {
                $chain[] = $name;
            }
        }

        return $chain;
    }

    /**
     * Provider ABILITATI su cui la POST OpenAI dei servizi ausiliari puo' davvero funzionare,
     * ordinati per punteggio: prima i piu' veloci ed economici, che e' cio' che serve a un
     * classificatore.
     *
     * Il filtro e' `OpenAICompatibleProvider`, non un elenco di nomi: Gemini e Claude parlano un
     * altro protocollo e risponderebbero 400: accodarli sprecherebbe un giro HTTP per nulla. Un
     * provider nuovo compatibile entra invece nella coda da solo, senza toccare questo file.
     *
     * @return list<string>
     */
    public static function fallbackTail(ProviderConfigStoreInterface $configs, ?AIProviderRegistry $registry = null): array
    {
        $registry ??= AIProviderRegistry::fromConfig();

        $candidates = [];
        foreach ($configs->enabled() as $config) {
            $slug = strtolower(trim((string) ($config['provider'] ?? '')));
            if ($slug === '' || !$registry->get($slug) instanceof OpenAICompatibleProvider) {
                continue;
            }
            $candidates[] = $slug;
        }

        $score = static function (string $slug): int {
            $profile = ModelRegistry::profile($slug);

            return (int) ($profile['latency'] ?? 0) + (int) ($profile['cost'] ?? 0);
        };
        // Punteggio decrescente; a parita', ordine alfabetico: la catena dev'essere deterministica.
        usort($candidates, static fn (string $a, string $b): int => [$score($b), $a] <=> [$score($a), $b]);

        return $candidates;
    }
}
