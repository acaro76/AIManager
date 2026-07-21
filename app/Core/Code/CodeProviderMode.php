<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 1 / F1.8a. Risolve il PROVIDER MODE della chat Code.
 *
 * La chat Code non ha (e non deve avere) un selettore di provider nella UI: la scelta è una
 * decisione di CONFIGURAZIONE del server. Il valore finisce nel parametro `providerMode` già
 * esistente di CodeChatService::stream() e da lì in ProviderRequest::$mode.
 *
 * Perché serve: con `auto` ProviderManager ordina TUTTI i provider abilitati e, se il primo
 * fallisce, ricade sul successivo — quindi anche su un provider cloud. Con un valore specifico
 * (es. `lmstudio`) i candidati vengono filtrati a QUEL solo provider: nessun fallback, nessun
 * contatto cloud possibile. Nulla di tutto ciò cambia scoring, routing o fallback: si sfrutta
 * il comportamento già esistente di ProviderManager::candidates().
 *
 * Precedenza: variabile d'ambiente AIMANAGER_CODE_PROVIDER_MODE → config `code.provider_mode`
 * → default `auto` (quindi il comportamento attuale resta invariato se non si configura nulla).
 *
 * FAIL CLOSED: un valore mal formato NON ricade su `auto`. Viene sostituito da un sentinella
 * che non corrisponde ad alcun provider: i candidati risultano ZERO e la richiesta fallisce in
 * modo controllato, senza mai tentare un provider non voluto.
 */
final class CodeProviderMode
{
    /** Routing normale (comportamento storico, invariato). */
    public const DEFAULT = 'auto';

    /** Variabile d'ambiente dedicata. */
    public const ENV = 'AIMANAGER_CODE_PROVIDER_MODE';

    /**
     * Sentinella per configurazioni mal formate: non è `auto` e non è (né può essere) la chiave
     * di un provider, quindi produce zero candidati → fail closed.
     */
    public const INVALID = 'invalid-provider-mode';

    /** Identificatore sicuro: minuscole, cifre, trattino e underscore. */
    private const SAFE = '/^[a-z0-9_-]{1,32}$/';

    /**
     * @param string|null $configured valore da config (`code.provider_mode`)
     * @param string|null $env  valore d'ambiente; se null viene letto da getenv() (iniettabile nei test)
     */
    public static function resolve(?string $configured = null, ?string $env = null): string
    {
        // Un env assente, vuoto o fatto di soli spazi vale come NON impostato: si ricade sulla
        // config, non sul default.
        $env = trim($env ?? self::fromEnvironment());

        // L'ambiente ha la precedenza; se non impostato si usa la config.
        $raw = $env !== '' ? $env : (string) ($configured ?? '');
        $value = strtolower(trim($raw));

        if ($value === '') {
            return self::DEFAULT; // niente configurato → comportamento attuale
        }
        if (preg_match(self::SAFE, $value) !== 1) {
            return self::INVALID; // mal formato → zero candidati, MAI un ripiego su auto
        }

        // Ben formato: può essere 'auto' oppure la chiave di un provider. Se il provider non
        // esiste o non è abilitato, ProviderManager produrrà comunque zero candidati.
        return $value;
    }

    private static function fromEnvironment(): string
    {
        $value = getenv(self::ENV);

        return is_string($value) ? trim($value) : '';
    }
}
