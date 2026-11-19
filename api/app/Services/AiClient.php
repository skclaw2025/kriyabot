<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provider-neutral AI client for Kriyabot.
 *
 * Default provider is Anthropic Claude (Messages API). OpenAI is kept as an optional
 * fallback by setting KRIYABOT_AI_PROVIDER=openai. Controllers only see plain arrays,
 * so the frontend contract does not change when the provider changes.
 */
class AiClient
{
    public function provider(): string
    {
        $provider = strtolower((string) config('taskflow.ai.provider', 'anthropic'));
        return in_array($provider, ['anthropic', 'openai'], true) ? $provider : 'anthropic';
    }

    public function configured(): bool
    {
        return filled($this->apiKey());
    }

    public function notConfiguredMessage(): string
    {
        return $this->provider() === 'anthropic'
            ? 'The AI assistant is not configured yet. Add ANTHROPIC_API_KEY to the API .env file, then clear the Laravel config cache.'
            : 'The AI assistant is not configured yet. Add OPENAI_API_KEY to the API .env file, then clear the Laravel config cache.';
    }

    /**
     * Ask the model to fill a JSON object that matches $schema.
     *
     * @return array{ok: bool, data?: array, message?: string, status?: int}
     */
    public function structured(string $system, string $userMessage, array $schema, string $toolName = 'save_draft'): array
    {
        if ($this->provider() === 'openai') {
            $result = $this->openAi([
                'model' => config('taskflow.ai.openai_model', 'gpt-4.1-mini'),
                'store' => false,
                'input' => [['role' => 'developer', 'content' => $system."\nReturn JSON only."], ['role' => 'user', 'content' => $userMessage]],
                'text' => ['format' => ['type' => 'json_object']],
            ], 35);
            if (! $result['ok']) return $result;
            $output = collect($result['json']['output'] ?? [])->flatMap(fn ($item) => $item['content'] ?? [])->first(fn ($c) => ($c['type'] ?? null) === 'output_text');
            $raw = $output['text'] ?? $result['json']['output_text'] ?? null;
            $data = is_string($raw) ? json_decode($raw, true) : null;
            return is_array($data) ? ['ok' => true, 'data' => $data] : ['ok' => false, 'status' => 502, 'message' => 'The assistant returned an unreadable draft. Please rephrase and try again.'];
        }

        // Claude: force a single tool call so the reply is always schema-shaped JSON.
        $result = $this->anthropic([
            'model' => config('taskflow.ai.fast_model', 'claude-haiku-5-5'),
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $userMessage]],
            'tools' => [['name' => $toolName, 'description' => 'Return the interpreted Kriyabot draft.', 'input_schema' => $schema]],
            'tool_choice' => ['type' => 'tool', 'name' => $toolName],
        ], 35);
        if (! $result['ok']) return $result;
        $block = collect($result['json']['content'] ?? [])->first(fn ($b) => ($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === $toolName);
        return is_array($block['input'] ?? null)
            ? ['ok' => true, 'data' => $block['input']]
            : ['ok' => false, 'status' => 502, 'message' => 'The assistant returned an unreadable draft. Please rephrase and try again.'];
    }

    /**
     * Conversational reply, optionally with web search.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{ok: bool, answer?: string, sources?: array, searched?: bool, message?: string, status?: int}
     */
    public function chat(string $system, array $history, string $message, bool $webSearch): array
    {
        if ($this->provider() === 'openai') {
            $payload = ['model' => config('taskflow.ai.openai_model', 'gpt-4.1-mini'), 'store' => false, 'input' => [['role' => 'developer', 'content' => $system], ...$history, ['role' => 'user', 'content' => $message]]];
            if ($webSearch) $payload['tools'] = [['type' => 'web_search']];
            $result = $this->openAi($payload, 45);
            if (! $result['ok']) return $result;
            $json = $result['json'];
            $item = collect($json['output'] ?? [])->first(fn ($i) => ($i['type'] ?? '') === 'message');
            $content = collect($item['content'] ?? [])->first(fn ($c) => ($c['type'] ?? '') === 'output_text');
            $sources = collect($content['annotations'] ?? [])->filter(fn ($a) => ($a['type'] ?? '') === 'url_citation')
                ->map(fn ($a) => ['url' => $a['url'] ?? null, 'title' => $a['title'] ?? $a['url'] ?? 'Source']);
            return ['ok' => true, 'answer' => (string) ($content['text'] ?? $json['output_text'] ?? ''), 'sources' => $this->cleanSources($sources->all()), 'searched' => collect($json['output'] ?? [])->contains(fn ($i) => ($i['type'] ?? '') === 'web_search_call')];
        }

        $payload = [
            'model' => config('taskflow.ai.model', 'claude-sonnet-5-5'),
            'max_tokens' => 1500,
            'system' => $system,
            'messages' => [...$this->normaliseHistory($history), ['role' => 'user', 'content' => $message]],
        ];
        if ($webSearch) $payload['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3, 'user_location' => ['type' => 'approximate', 'country' => 'IN', 'timezone' => 'Asia/Kolkata']]];
        $result = $this->anthropic($payload, 60);
        if (! $result['ok']) return $result;

        $blocks = collect($result['json']['content'] ?? []);
        $text = $blocks->filter(fn ($b) => ($b['type'] ?? '') === 'text')->pluck('text')->implode('');
        $sources = $blocks->filter(fn ($b) => ($b['type'] ?? '') === 'text')
            ->flatMap(fn ($b) => $b['citations'] ?? [])
            ->filter(fn ($c) => ($c['type'] ?? '') === 'web_search_result_location')
            ->map(fn ($c) => ['url' => $c['url'] ?? null, 'title' => $c['title'] ?? $c['url'] ?? 'Source']);

        return [
            'ok' => true,
            'answer' => trim($text),
            'sources' => $this->cleanSources($sources->all()),
            'searched' => $blocks->contains(fn ($b) => ($b['type'] ?? '') === 'server_tool_use' && ($b['name'] ?? '') === 'web_search'),
        ];
    }

    /** Claude requires the conversation to start with a user turn and alternate roles. */
    private function normaliseHistory(array $history): array
    {
        $turns = [];
        foreach ($history as $item) {
            $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') continue;
            if ($turns === [] && $role === 'assistant') continue; // drop greeting bubbles before the first user turn
            if ($turns !== [] && end($turns)['role'] === $role) {
                $turns[array_key_last($turns)]['content'] .= "\n\n".$content;
                continue;
            }
            $turns[] = ['role' => $role, 'content' => $content];
        }
        // The new user message is appended by the caller, so history must end on an assistant turn.
        if ($turns !== [] && end($turns)['role'] === 'user') array_pop($turns);
        return $turns;
    }

    private function cleanSources(array $sources): array
    {
        return collect($sources)
            ->filter(fn ($s) => is_string($s['url'] ?? null) && str_starts_with($s['url'], 'https://'))
            ->unique('url')->values()->all();
    }

    private function apiKey(): ?string
    {
        return $this->provider() === 'anthropic' ? config('taskflow.ai.anthropic_key') : config('taskflow.ai.openai_key');
    }

    private function anthropic(array $payload, int $timeout): array
    {
        return $this->send(fn () => Http::withHeaders([
            'x-api-key' => (string) $this->apiKey(),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->timeout($timeout)->post('https://api.anthropic.com/v1/messages', $payload), 'api.anthropic.com');
    }

    private function openAi(array $payload, int $timeout): array
    {
        return $this->send(fn () => Http::withToken((string) $this->apiKey())->timeout($timeout)->post('https://api.openai.com/v1/responses', $payload), 'api.openai.com');
    }

    private function send(callable $request, string $host): array
    {
        if (! $this->configured()) return ['ok' => false, 'status' => 422, 'message' => $this->notConfiguredMessage()];
        try {
            /** @var Response $response */
            $response = $request();
        } catch (ConnectionException $e) {
            $detail = strtolower($e->getMessage());
            Log::error('Kriyabot AI connection failed', ['host' => $host, 'error' => $e->getMessage()]);
            $hint = str_contains($detail, 'certificate') || str_contains($detail, 'ssl')
                ? 'The API server could not verify the AI service TLS certificate. Check PHP curl.cainfo / openssl.cafile and the system clock.'
                : (str_contains($detail, 'resolve') || str_contains($detail, 'getaddrinfo')
                    ? "The API server could not resolve {$host}. Check DNS and outbound internet access."
                    : "The API server could not connect to {$host}. Check outbound HTTPS access and try again.");
            return ['ok' => false, 'status' => 502, 'message' => $hint];
        } catch (\Throwable $e) {
            Log::error('Kriyabot AI request failed', ['host' => $host, 'type' => get_class($e), 'error' => $e->getMessage()]);
            return ['ok' => false, 'status' => 502, 'message' => 'The AI service could not answer this request. Check storage/logs/laravel.log for details.'];
        }

        if ($response->successful()) return ['ok' => true, 'json' => $response->json() ?? []];

        $status = $response->status();
        $providerMessage = (string) data_get($response->json(), 'error.message', '');
        $providerType = (string) (data_get($response->json(), 'error.code') ?? data_get($response->json(), 'error.type', ''));
        Log::warning('Kriyabot AI service returned an error', ['host' => $host, 'status' => $status, 'type' => $providerType, 'message' => $providerMessage]);
        $billing = (bool) preg_match('/quota|billing|credit|usage limit|spend limit/i', $providerMessage.' '.$providerType);
        $message = match (true) {
            $billing => 'The AI account has run out of credits or reached its spend limit. Add credits or raise the limit in the provider console.',
            $status === 401 => 'The AI API key was rejected. Check the key in api/.env, then clear the Laravel config cache.',
            $status === 403 => 'The AI provider denied this request. Check that the key can use the selected model and web search.',
            $status === 429 => 'The AI rate limit was reached. Wait a few seconds and try again.',
            $status === 529 || $status === 503 => 'The AI service is busy right now. Please try again in a moment.',
            default => 'The AI service returned HTTP '.$status.($providerMessage !== '' ? ': '.$providerMessage : ''),
        };
        return ['ok' => false, 'status' => 502, 'message' => $message];
    }
}
