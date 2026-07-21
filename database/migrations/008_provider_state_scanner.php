<?php

use App\Core\Database;

return function (Database $db): void {
    $db->execute('CREATE TABLE IF NOT EXISTS provider_states (
        provider TEXT PRIMARY KEY,
        provider_label TEXT NOT NULL DEFAULT "",
        model TEXT NOT NULL DEFAULT "",
        status TEXT NOT NULL DEFAULT "unknown",
        utilization_percent INTEGER NULL,
        remaining_quota TEXT NOT NULL DEFAULT "",
        limit_type TEXT NOT NULL DEFAULT "",
        reset_at TEXT NOT NULL DEFAULT "",
        limitation_message TEXT NOT NULL DEFAULT "",
        warning_message TEXT NOT NULL DEFAULT "",
        detected_at TEXT NOT NULL,
        source TEXT NOT NULL DEFAULT "screenshot",
        reliability INTEGER NOT NULL DEFAULT 95,
        raw_text TEXT NOT NULL DEFAULT "",
        screenshot_path TEXT NOT NULL DEFAULT "",
        updated_at TEXT NOT NULL
    )');

    $db->execute('CREATE TABLE IF NOT EXISTS provider_state_scans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider TEXT NOT NULL DEFAULT "",
        screenshot_path TEXT NOT NULL DEFAULT "",
        status TEXT NOT NULL DEFAULT "processed",
        raw_text TEXT NOT NULL DEFAULT "",
        parsed_json TEXT NOT NULL DEFAULT "{}",
        error TEXT NOT NULL DEFAULT "",
        created_at TEXT NOT NULL
    )');

    $db->execute('CREATE INDEX IF NOT EXISTS idx_provider_state_scans_provider ON provider_state_scans(provider)');
    $db->execute('CREATE INDEX IF NOT EXISTS idx_provider_state_scans_created ON provider_state_scans(created_at)');
};
