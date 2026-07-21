<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 5 / F5.5. Metadati (e SOLO metadati) di una verifica tentata nel turno.
 *
 * È ciò che il ciclo consegna a valle per l'audit dedicato (`code_verification_runs`): profilo,
 * linguaggio, tipo, file bersaglio relativo, esito a vocabolario chiuso, exit code, durata, byte di
 * output e troncamento. MAI l'output del comando, il prompt o messaggi tecnici: non esiste un campo
 * dove infilarli.
 *
 * `outcome` estende gli esiti di esecuzione (VerificationResult) con i due rifiuti a monte decisi
 * dal ciclo — `denied` (file non letto/non valido) e `unavailable` (profilo non abilitato/rilevato)
 * — così anche un TENTATIVO che non ha avviato alcun processo resta tracciato.
 */
final class CodeVerificationRunRecord
{
    /** Il file bersaglio non è verificabile (non letto nel turno, o non più valido). */
    public const DENIED = 'denied';
    /** Il profilo non è abilitato lato server o non rilevato nel workspace. */
    public const UNAVAILABLE = 'unavailable';

    /** @var list<string> Vocabolario chiuso (coerente col CHECK di code_verification_runs). */
    public const OUTCOMES = [
        VerificationResult::PASSED,
        VerificationResult::FAILED,
        VerificationResult::TIMED_OUT,
        VerificationResult::KILLED,
        VerificationResult::ERROR,
        self::DENIED,
        self::UNAVAILABLE,
    ];

    public function __construct(
        public readonly string $profileId,
        public readonly string $language,
        public readonly string $kind,
        public readonly ?string $relPath,
        public readonly string $outcome,
        public readonly ?int $exitCode,
        public readonly int $durationMs,
        public readonly int $outputBytes,
        public readonly bool $truncated,
    ) {
        if (!in_array($language, VerificationProfile::LANGUAGES, true)) {
            throw new \InvalidArgumentException('CodeVerificationRunRecord: linguaggio non ammesso.');
        }
        if (!in_array($kind, VerificationProfile::KINDS, true)) {
            throw new \InvalidArgumentException('CodeVerificationRunRecord: tipo non ammesso.');
        }
        if (!in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException('CodeVerificationRunRecord: esito non ammesso.');
        }
        if ($relPath !== null) {
            RelativePath::assert($relPath);
        }
        if ($durationMs < 0 || $outputBytes < 0) {
            throw new \InvalidArgumentException('CodeVerificationRunRecord: durata/byte non validi.');
        }
    }

    /**
     * Etichetta d'esito leggibile, UNICA fonte di verità per UI live e cronologia (così i due
     * rendering non divergono). Deriva SOLO dai metadati, mai dal testo del modello.
     */
    public static function label(string $outcome, ?int $exitCode): string
    {
        return match ($outcome) {
            VerificationResult::PASSED => 'superata',
            VerificationResult::FAILED => 'fallita' . ($exitCode !== null ? ' (exit ' . $exitCode . ')' : ''),
            VerificationResult::TIMED_OUT => 'interrotta (timeout)',
            VerificationResult::KILLED => 'interrotta',
            self::DENIED => 'non eseguibile',
            self::UNAVAILABLE => 'non disponibile',
            default => 'errore',
        };
    }

    /**
     * Card STRUTTURATA per la chat: soli metadati + etichetta derivata. È ciò che UI live e
     * cronologia mostrano, senza affidarsi al testo generato dal modello.
     *
     * @return array{profile:string,kind:string,outcome:string,exit_code:?int,path:?string,label:string}
     */
    public function toCard(): array
    {
        return [
            'profile' => $this->profileId,
            'kind' => $this->kind,
            'outcome' => $this->outcome,
            'exit_code' => $this->exitCode,
            'path' => $this->relPath,
            'label' => self::label($this->outcome, $this->exitCode),
        ];
    }
}
