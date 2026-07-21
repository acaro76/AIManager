<?php

use App\Core\Database;

// (1) DeepSeek: l'alias deepseek-chat e' deprecato dal 2026-07-24 -> passa all'id V4 diretto
//     (thinking disattivato lato codice). (2) Gemini gira sul free tier: azzera i costi stimati
//     storici (erano calcolati con la tariffa a pagamento, fuorvianti per un servizio gratuito).
return function (Database $db): void {
    $now = date('c');
    $db->execute(
        'UPDATE provider_configs SET model = ?, updated_at = ? WHERE provider = ? AND model = ?',
        ['deepseek-v4-flash', $now, 'deepseek', 'deepseek-chat']
    );
    $db->execute('UPDATE ai_request_logs SET estimated_cost = 0 WHERE provider = ?', ['gemini']);
};
