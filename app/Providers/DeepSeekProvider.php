<?php

declare(strict_types=1);

namespace App\Providers;

/**
 * DeepSeek, API ufficiale (https://api.deepseek.com), OpenAI-compatibile.
 *
 * Usa l'endpoint ufficiale DeepSeek con la chiave DEEPSEEK_API_KEY.
 *
 * Modello di default: `deepseek-v4-flash` con il RAGIONAMENTO DISATTIVATO (vedi extraPayload):
 * generalista tuttofare veloce ed economico ($0.14/$0.28 per 1M token). Si usa l'id V4 diretto
 * invece dell'alias `deepseek-chat` perche' quest'ultimo viene deprecato il 2026-07-24.
 * Gli id V4 partono di default in modalita' ragionamento: la spegniamo noi. Solo testo: niente vision.
 */
final class DeepSeekProvider extends OpenAICompatibleProvider
{
    public function key(): string
    {
        return 'deepseek';
    }

    public function label(): string
    {
        return 'DeepSeek';
    }

    protected function baseUrl(): string
    {
        return 'https://api.deepseek.com';
    }

    protected function defaultModel(): string
    {
        return 'deepseek-v4-flash';
    }

    /**
     * I modelli V4 (deepseek-v4-flash/pro) partono in modalita' ragionamento. La disattiviamo
     * per restare generalisti veloci ed economici. L'alias legacy `deepseek-chat` e' gia'
     * non-thinking: non gli mandiamo il parametro (eviterebbe un possibile 400).
     */
    protected function extraPayload(array $config): array
    {
        $model = (string) ($config['model'] ?? $this->defaultModel());
        if (str_starts_with($model, 'deepseek-v4')) {
            return ['thinking' => ['type' => 'disabled']];
        }

        return [];
    }

    /**
     * Saldo prepagato reale via endpoint ufficiale GET /user/balance.
     * @return array{total:string,currency:string,available:bool}|null
     */
    public function accountBalance(array $config): ?array
    {
        if ((string) ($config['api_key'] ?? '') === '') {
            return null;
        }

        $response = $this->getJson(rtrim($this->baseUrl(), '/') . '/user/balance', $this->headers($config), (int) ($config['timeout_seconds'] ?? 10));
        if (empty($response['ok'])) {
            return null;
        }

        $body = json_decode((string) ($response['body'] ?? ''), true);
        $info = $body['balance_infos'][0] ?? null;
        if (!is_array($info)) {
            return null;
        }

        return [
            'total' => (string) ($info['total_balance'] ?? ''),
            'currency' => (string) ($info['currency'] ?? ''),
            'available' => (bool) ($body['is_available'] ?? false),
        ];
    }
}
