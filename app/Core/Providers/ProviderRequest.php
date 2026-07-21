<?php

declare(strict_types=1);

namespace App\Core\Providers;

use App\Core\Cancellation\CancellationToken;
use App\Core\ContextEngine\ContextInterface;
use App\Core\Execution\ExecutionState;

final class ProviderRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly ContextInterface $context,
        // Nullable: ProviderManager e i provider non lo leggono mai. Le superfici senza stato
        // di esecuzione (Code) passano null invece di fabbricare un ExecutionState fittizio.
        public readonly ?ExecutionState $executionState = null,
        public readonly string $mode = 'auto',
        public readonly ?CancellationToken $cancellation = null,
        public readonly ?ProviderIntent $intent = null,
        public readonly array $attachments = [],
        public readonly string $preferredProvider = '',
        /** Le decisioni interne dell'agente richiedono JSON valido; le risposte utente restano libere. */
        public readonly bool $structuredJson = false,
    ) {
    }
}
