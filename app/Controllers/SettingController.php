<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Models\Setting;

final class SettingController extends BaseController
{
    public function theme(Request $request): never
    {
        $this->guard($request);
        (new Setting())->set('app_theme', $this->normalizeTheme((string) $request->input('app_theme', 'dark')));
        $target = (string) ($_SERVER['HTTP_REFERER'] ?? '/');
        $path = parse_url($target, PHP_URL_PATH) ?: '/';
        $query = parse_url($target, PHP_URL_QUERY);
        $redirect = $path . ($query ? '?' . $query : '');
        $this->flash('Tema aggiornato.', $redirect);
    }

    private function normalizeTheme(string $value): string
    {
        return in_array($value, ['light', 'dark', 'blue'], true) ? $value : 'dark';
    }
}
