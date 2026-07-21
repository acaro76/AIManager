<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

abstract class BaseController
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function view(string $template, array $data = []): void
    {
        View::render($template, $data);
    }

    protected function guard(Request $request): void
    {
        if (!$this->app->session->verify((string) $request->input('_csrf', ''))) {
            Response::json(['ok' => false, 'message' => 'Sessione non valida. Ricarica la pagina.'], 419);
        }
    }

    protected function flash(string $message, string $redirect): never
    {
        $this->app->session->flash('status', $message);
        Response::redirect($redirect);
    }
}
