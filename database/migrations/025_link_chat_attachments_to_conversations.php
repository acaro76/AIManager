<?php

use App\Core\Database;

return function (Database $db): void {
    $columns = $db->fetchAll('PRAGMA table_info(chat_attachments)');
    $hasConversationId = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'conversation_id') {
            $hasConversationId = true;
            break;
        }
    }

    if (!$hasConversationId) {
        $db->execute('ALTER TABLE chat_attachments ADD COLUMN conversation_id INTEGER NULL');
    }

    $db->execute('CREATE INDEX IF NOT EXISTS idx_chat_attachments_conversation ON chat_attachments(conversation_id, created_at)');

    $unlinked = $db->fetchAll(
        'SELECT id, session_id, created_at
         FROM chat_attachments
         WHERE conversation_id IS NULL
         ORDER BY created_at ASC, id ASC'
    );

    foreach ($unlinked as $attachment) {
        $conversation = $db->fetch(
            'SELECT id
             FROM conversations
             WHERE session_id = ?
               AND role = ?
               AND created_at >= ?
             ORDER BY created_at ASC, id ASC
             LIMIT 1',
            [(int) $attachment['session_id'], 'user', (string) $attachment['created_at']]
        );

        if (!$conversation) {
            continue;
        }

        $db->execute(
            'UPDATE chat_attachments SET conversation_id = ? WHERE id = ?',
            [(int) $conversation['id'], (int) $attachment['id']]
        );
    }
};
