<?php

use App\Core\Database;

/**
 * Agnes AI (https://apihub.agnes-ai.com/v1), gateway OpenAI-compatibile, solo testo.
 *
 * Nasce ABILITATA (`enabled = 1`), a differenza di Cerebras/DeepSeek che nacquero spente: qui la
 * chiave e' gia' in .env ed e' stata verificata contro l'API reale, e l'utente ha chiesto la IA
 * funzionante subito. Si spegne da /providers con un clic.
 *
 * Additiva e idempotente: se la riga esiste gia' non la duplica e ne completa solo i campi vuoti,
 * senza mai sovrascrivere scelte gia' fatte dall'utente (enabled, priorita', modello).
 *
 * Priorita' 70: sotto Cerebras (78). E' gratuita ma lenta (~7s fissi a risposta, misurati), quindi
 * non deve scavalcare provider piu' pronti nel routing.
 */
return function (Database $db): void {
    $now = date('c');
    $exists = $db->fetch('SELECT provider FROM provider_configs WHERE provider = ?', ['agnes']);
    if ($exists) {
        $db->execute(
            'UPDATE provider_configs SET
                label = CASE WHEN label = "" THEN ? ELSE label END,
                base_url = CASE WHEN base_url = "" THEN ? ELSE base_url END,
                model = CASE WHEN model = "" THEN ? ELSE model END,
                updated_at = ?
             WHERE provider = ? AND (label = "" OR base_url = "" OR model = "")',
            ['Agnes AI', 'https://apihub.agnes-ai.com/v1', 'agnes-2.0-flash', $now, 'agnes']
        );
        return;
    }

    $db->execute(
        'INSERT INTO provider_configs (provider, label, base_url, model, enabled, timeout_seconds, temperature, max_tokens, top_p, priority, mode, status, last_error, last_checked_at, last_request_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        ['agnes', 'Agnes AI', 'https://apihub.agnes-ai.com/v1', 'agnes-2.0-flash', 1, 30, 0.7, 2048, 1.0, 70, 'auto', 'offline', '', '', '', $now]
    );
};
