<?php

declare(strict_types=1);

namespace App\Core\Providers\Policy;

/**
 * Policy di routing che consente sempre ogni provider.
 *
 * Sostituisce la vecchia ProviderStateRoutingPolicy, che filtrava i provider in base allo
 * stato letto dagli screenshot OCR (rimosso). Quella policy era comunque DORMIENTE: agiva
 * solo con il setting `provider_state.routing_enabled=1`, mai attivato. Quindi consentire
 * sempre = identico comportamento di prima. La scelta del provider resta interamente alla
 * formula di scoring.
 */
final class AllowAllRoutingPolicy implements ProviderRoutingPolicyInterface
{
    public function allows(string $provider): bool
    {
        return true;
    }
}
