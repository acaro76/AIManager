<?php

declare(strict_types=1);

namespace App\Core\Code;

/** Ponte macOS minimale: mostra esclusivamente il dialogo nativo di scelta cartella. */
final class MacFolderPicker
{
    public function pick(): ?string
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            throw new \RuntimeException('La selezione tramite Finder è disponibile solo su macOS.');
        }
        if (!function_exists('exec') || $this->isDisabled('exec')) {
            throw new \RuntimeException('Il dialogo Finder non è disponibile in questa installazione.');
        }

        $script = 'POSIX path of (choose folder with prompt "Scegli la cartella da autorizzare in AIManager")';
        $output = [];
        $exitCode = 1;
        // Script completamente statico: nessun dato HTTP o percorso utente entra nel comando.
        // `choose folder` usa il selettore nativo senza controllare Finder: niente richiesta
        // macOS aggiuntiva per consentire a PHP/Terminale di pilotare Finder.
        exec('/usr/bin/osascript -e ' . escapeshellarg($script) . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode !== 0) {
            return null;
        }
        $path = rtrim(implode("\n", $output), "\r\n");
        return $path !== '' ? $path : null;
    }

    private function isDisabled(string $function): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return in_array($function, $disabled, true);
    }
}
