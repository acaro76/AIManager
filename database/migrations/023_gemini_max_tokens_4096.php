<?php

use App\Core\Database;

// Gemini 2.5 flash-lite tagliava le risposte lunghe a 2048 token (finishReason MAX_TOKENS).
// Alzato a 4096 (il modello arriva fino a 8192) per coprire le risposte lunghe normali.
return function (Database $db): void {
    $db->execute(
        'UPDATE provider_configs SET max_tokens = ?, updated_at = ? WHERE provider = ? AND max_tokens < ?',
        [4096, date('c'), 'gemini', 4096]
    );
};
