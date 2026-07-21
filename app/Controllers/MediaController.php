<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Security\MediaTypePolicy;
use App\Models\ChatAttachment;

/**
 * Serve i file allegati (upload utente e immagini generate) dallo storage, che sta
 * fuori da public/. Necessario per mostrare le immagini in chat (handoff sez. 46).
 */
final class MediaController extends BaseController
{
    public function attachment(Request $request): never
    {
        $id = (int) $request->input('id');
        $row = $id > 0 ? (new ChatAttachment())->find($id) : null;
        if (!$row) {
            $this->notFound();
        }

        $storage = (string) ($this->app->config['paths']['storage'] ?? ($this->app->root . '/storage'));
        $real = realpath($storage . '/' . ltrim((string) $row['path'], '/'));
        $storageReal = realpath($storage);
        if ($real === false || $storageReal === false || !str_starts_with($real, $storageReal . DIRECTORY_SEPARATOR) || !is_file($real)) {
            $this->notFound();
        }

        $originalName = basename(str_replace(["\r", "\n"], '', (string) ($row['name'] ?? 'file')));
        $fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'file';

        // MIME REALE dal contenuto (mai il MIME persistito): `inline` solo per un raster in allow-list e
        // non forzato; tutto il resto è octet-stream + attachment (vedi MediaTypePolicy).
        $forceDownload = (string) $request->input('download', '') === '1';
        $served = MediaTypePolicy::forFile($real, $forceDownload);

        header('Content-Type: ' . $served['mime']);
        header('Content-Length: ' . (string) filesize($real));
        header('Cache-Control: private, max-age=86400');
        // Difesa aggiuntiva: la risposta media è sandboxata (nessuno script/plugin/navigazione anche
        // se un client provasse a renderla come documento).
        header('Content-Security-Policy: sandbox');
        header(
            'Content-Disposition: ' . $served['disposition']
            . '; filename="' . $fallbackName . '"'
            . "; filename*=UTF-8''" . rawurlencode($originalName)
        );
        readfile($real);
        exit;
    }

    private function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File non trovato.';
        exit;
    }
}
