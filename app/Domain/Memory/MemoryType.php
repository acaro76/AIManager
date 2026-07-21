<?php

declare(strict_types=1);

namespace App\Domain\Memory;

final class MemoryType
{
    public const KNOWLEDGE = 'knowledge';
    public const DECISION = 'decision';
    public const DOCUMENT = 'document';
    public const CONVERSATION = 'conversation';
    public const ARTIFACT = 'artifact';
    public const NOTE = 'note';
    public const PROBLEM = 'problem';
    public const PATTERN = 'pattern';
    public const API = 'api';
    public const ENDPOINT = 'endpoint';
    public const DATABASE_TABLE = 'database_table';
    public const TODO = 'todo';
    public const HYPOTHESIS_CONFIRMED = 'hypothesis_confirmed';
    public const HYPOTHESIS_REJECTED = 'hypothesis_rejected';
    public const CHANGE = 'change';

    public static function all(): array
    {
        return [
            self::KNOWLEDGE => 'Knowledge',
            self::DECISION => 'Decision',
            self::DOCUMENT => 'Document',
            self::CONVERSATION => 'Conversation',
            self::ARTIFACT => 'Artifact',
            self::NOTE => 'Note',
            self::PROBLEM => 'Problem',
            self::PATTERN => 'Pattern',
            self::API => 'API',
            self::ENDPOINT => 'Endpoint',
            self::DATABASE_TABLE => 'Database Table',
            self::TODO => 'TODO',
            self::HYPOTHESIS_CONFIRMED => 'Hypothesis Confirmed',
            self::HYPOTHESIS_REJECTED => 'Hypothesis Rejected',
            self::CHANGE => 'Change',
        ];
    }

    public static function normalize(string $type): string
    {
        return array_key_exists($type, self::all()) ? $type : self::NOTE;
    }
}
