<?php

declare(strict_types=1);

namespace App\Services;

final class ConversationTitleService
{
    private const PROVISIONAL_TITLES = [
        'chat libera',
        'nuova conversazione',
        'nuova sessione',
    ];

    /**
     * @var string[]
     */
    private const STOP_WORDS = [
        'ciao',
        'salve',
        'buongiorno',
        'buonasera',
        'hey',
        'mi',
        'puoi',
        'potresti',
        'per',
        'favore',
        'aiuti',
        'aiutami',
        'vorrei',
        'voglio',
        'fare',
        'fammi',
        'dimmi',
        'spiegami',
        'scrivimi',
        'una',
        'uno',
        'un',
        'il',
        'lo',
        'la',
        'gli',
        'le',
        'di',
        'del',
        'della',
        'dei',
        'degli',
        'delle',
        'che',
        'cosa',
        'come',
        'sono',
        'sei',
    ];

    /**
     * @var string[]
     */
    private const GREETINGS = [
        'ciao',
        'salve',
        'buongiorno',
        'buonasera',
        'hey',
    ];

    public function isProvisional(string $title): bool
    {
        return in_array(mb_strtolower(trim($title)), self::PROVISIONAL_TITLES, true);
    }

    public function fromPrompt(string $prompt): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $prompt) ?? $prompt;
        $words = preg_split('/\s+/', trim($clean)) ?: [];
        $selected = [];

        foreach ($words as $word) {
            $word = trim($word, "- \t\n\r\0\x0B");
            $normalized = mb_strtolower($word);
            if ($word === '' || mb_strlen($word) < 3 || in_array($normalized, self::STOP_WORDS, true)) {
                continue;
            }

            $selected[] = $word;
            if (count($selected) >= 5) {
                break;
            }
        }

        if (!$selected) {
            $selected = array_slice(array_values(array_filter($words, function (string $word): bool {
                $word = trim($word, "- \t\n\r\0\x0B");
                return $word !== '' && !in_array(mb_strtolower($word), self::GREETINGS, true);
            })), 0, 5);
        }

        $title = trim(implode(' ', $selected));
        if ($title === '') {
            return 'Nuova conversazione';
        }

        $title = mb_substr($title, 0, 42);
        $title = trim($title, "- \t\n\r\0\x0B");

        return mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
    }
}
