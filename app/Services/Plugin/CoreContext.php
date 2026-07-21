<?php

declare(strict_types=1);

namespace App\Services\Plugin;

use App\Models\Project;
use App\Models\Setting;

final class CoreContext
{
    public function __construct(
        private readonly Setting $settings = new Setting(),
        private readonly Project $projects = new Project(),
    )
    {
    }

    public function setting(string $key, ?string $default = null): ?string
    {
        return $this->settings->get($key, $default);
    }

    public function projectCount(): int
    {
        return $this->projects->stats()['total'];
    }
}
