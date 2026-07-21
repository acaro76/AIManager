<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;

final class PluginController extends BaseController
{
    public function index(Request $request): void
    {
        $this->view('plugins/index', [
            'title' => 'Plugin',
            'plugins' => $this->app->plugins->all(),
        ]);
    }

    public function toggle(Request $request): never
    {
        $this->guard($request);
        $this->app->plugins->toggle((string) $request->input('slug'));
        $this->flash('Stato plugin aggiornato.', '/plugins');
    }

    public function refresh(Request $request): never
    {
        $this->guard($request);
        $this->app->plugins->discover();
        $this->flash('Plugin riletti.', '/plugins');
    }
}
