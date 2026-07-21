<?php

declare(strict_types=1);

namespace App\Core\Providers\Policy;

interface ProviderRoutingPolicyInterface
{
    public function allows(string $provider): bool;
}
