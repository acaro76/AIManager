<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        $app = App::get();
        $viewPath = $app->config['paths']['views'] . '/' . $template . '.php';

        if (!is_file($viewPath)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        $layoutProject = $data['project'] ?? null;
        $layoutSession = $data['activeSession'] ?? null;
        $hideTopbar = (bool) ($data['hideTopbar'] ?? false);
        $csrf = $app->session->token();
        $flash = $app->session->flash('status');
        $pluginManager = $app->plugins;

        ob_start();
        require $viewPath;
        $content = ob_get_clean();

        require $app->config['paths']['views'] . '/layouts/app.php';
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function messageContent(?string $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $lines = explode("\n", $text);
        $count = count($lines);
        $html = '';
        $list = null;

        $closeList = static function () use (&$html, &$list): void {
            if ($list !== null) {
                $html .= '</' . $list . '>';
                $list = null;
            }
        };

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // Blocco di codice fenced: tutto ciò che sta dentro è testo letterale, mai markdown.
            // Una fence non chiusa (output troncato o streaming in corso) rende comunque un blocco,
            // così live e storico coincidono.
            if (preg_match('/^\s*```/', $line)) {
                $closeList();
                $textFence = preg_match('/^\s*```text\s*$/i', $line) === 1;
                $buffer = [];
                $j = $i + 1;
                while ($j < $count && !preg_match('/^\s*```\s*$/', $lines[$j])) {
                    $buffer[] = $lines[$j];
                    $j++;
                }
                $html .= '<pre class="chat-code' . ($textFence ? ' chat-code-text' : '') . '"><code>'
                    . self::e(implode("\n", $buffer)) . '</code></pre>';
                $i = $j;
                continue;
            }

            // Tabella markdown: riga corrente = intestazione, riga successiva = separatore |---|.
            if (self::isTableRow($trimmed) && $i + 1 < $count && self::isTableSeparator($lines[$i + 1])) {
                $closeList();
                $header = self::splitTableRow($trimmed);
                $rows = [];
                $j = $i + 2;
                while ($j < $count && self::isTableRow(trim($lines[$j]))) {
                    $rows[] = self::splitTableRow(trim($lines[$j]));
                    $j++;
                }
                $html .= self::renderTable($header, $rows);
                $i = $j - 1;
                continue;
            }

            if ($trimmed === '') {
                $closeList();
                continue;
            }

            // Definizione link reference-style: [id]: https://... "titolo" -> link pulito.
            if (preg_match('/^\[([^\]]+)\]:\s*(https?:\/\/\S+?)(?:\s+["\'(](.*)["\')])?\s*$/', $trimmed, $match)) {
                $closeList();
                $refUrl = $match[2];
                $refLabel = trim((string) ($match[3] ?? '')) !== '' ? (string) $match[3] : 'Fonte: ' . self::linkDomain($refUrl);
                $html .= '<p class="chat-ref"><a href="' . self::e($refUrl) . '" target="_blank" rel="noopener noreferrer">' . self::e($refLabel) . '</a></p>';
                continue;
            }

            if (preg_match('/^\s*#{1,4}\s+(.+)$/', $line, $match)) {
                $closeList();
                $html .= '<h4>' . self::inlineMarkdown($match[1]) . '</h4>';
                continue;
            }

            if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $match)) {
                if ($list !== 'ul') {
                    $closeList();
                    $html .= '<ul>';
                    $list = 'ul';
                }
                $html .= '<li>' . self::inlineMarkdown($match[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\s*\d+\.\s+(.+)$/', $line, $match)) {
                if ($list !== 'ol') {
                    $closeList();
                    $html .= '<ol>';
                    $list = 'ol';
                }
                $html .= '<li>' . self::inlineMarkdown($match[1]) . '</li>';
                continue;
            }

            $closeList();
            $html .= '<p>' . self::inlineMarkdown($line) . '</p>';
        }

        $closeList();
        return $html;
    }

    private static function inlineMarkdown(string $value): string
    {
        // L'escape precede qualunque markdown: da qui in poi nel testo non esistono più `<` e `>`.
        // I segmenti `code` sono isolati prima di enfasi e link, così un backtick protegge davvero
        // il suo contenuto invece di lasciarlo attraversare da **/*/[]().
        $parts = preg_split('/(`[^`]+`)/', self::e($value), -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return self::e($value);
        }

        $html = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if ($part[0] === '`' && substr($part, -1) === '`' && strlen($part) > 1) {
                $html .= '<code>' . substr($part, 1, -1) . '</code>';
                continue;
            }
            $html .= self::inlineEmphasis($part);
        }

        return $html;
    }

    private static function inlineEmphasis(string $html): string
    {
        $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $html) ?? $html;
        $html = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s<]+)\)/', static function (array $match): string {
            $label = html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $url = html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if (preg_match('/^web:search_result:\d+$/i', $label)) {
                $label = 'Fonte: ' . self::linkDomain($url);
            }

            return '<a href="' . self::e($url) . '" target="_blank" rel="noopener noreferrer">' . self::e($label) . '</a>';
        }, $html) ?? $html;
        return $html;
    }

    private static function linkDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return 'web';
        }

        $host = preg_replace('/^www\./i', '', strtolower($host)) ?? $host;
        if (str_ends_with($host, 'wikipedia.org')) {
            return 'wikipedia';
        }

        return $host;
    }

    private static function isTableRow(string $line): bool
    {
        if ($line === '' || !str_contains($line, '|')) {
            return false;
        }

        return str_starts_with($line, '|') || substr_count($line, '|') >= 2;
    }

    private static function isTableSeparator(string $line): bool
    {
        return (bool) preg_match('/^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)*\|?\s*$/', $line);
    }

    /**
     * @return string[]
     */
    private static function splitTableRow(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? $line;
        $line = preg_replace('/\|$/', '', $line) ?? $line;

        return array_map('trim', explode('|', $line));
    }

    /**
     * @param string[] $header
     * @param string[][] $rows
     */
    private static function renderTable(array $header, array $rows): string
    {
        $columns = count($header);
        $html = '<div class="chat-table-wrap"><table class="chat-table"><thead><tr>';
        foreach ($header as $cell) {
            $html .= '<th>' . self::inlineMarkdown($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            for ($i = 0; $i < $columns; $i++) {
                $html .= '<td>' . self::inlineMarkdown($row[$i] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }
}
