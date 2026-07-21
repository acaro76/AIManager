<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Models\Memory;
use App\Models\Project;
use App\Models\ProviderConfig;

final class DashboardController extends BaseController
{
    public function index(Request $request): void
    {
        $projects = new Project();
        $memory = new Memory();
        $providers = new ProviderConfig();
        $providerConfigs = $providers->all();
        $enabledProviders = array_filter($providerConfigs, fn (array $row): bool => (int) $row['enabled'] === 1);
        $readyProviders = array_filter(
            $enabledProviders,
            fn (array $row): bool => strtolower((string) ($row['status'] ?? 'offline')) === 'online'
        );

        $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'stats' => [
                'projects' => $projects->stats(),
                'memories' => $memory->count(),
                'providers' => count($enabledProviders),
                'plugins' => count(array_filter($this->app->plugins->all(), fn ($row) => (int) $row['enabled'] === 1)),
            ],
            'providerReady' => $readyProviders !== [],
            'projects' => array_slice($projects->active(), 0, 5),
            'memories' => $memory->recent(5),
            'pluginWidgets' => $this->app->plugins->hook('dashboard.widgets'),
        ]);
    }
}
