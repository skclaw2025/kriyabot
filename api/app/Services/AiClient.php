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

    /**
     * Read a bill, invoice or receipt (photo or PDF) and return structured fields.
     * The model may only choose a head from the person's own list; otherwise it suggests a name.
     *
     * @param  array{income: string[], expense: string[]}  $heads
     * @return array{ok: bool, data?: array, message?: string, status?: int}
     */
    public function extractBill(string $bytes, string $mime, array $heads, string $today, string $timezone): array
    {
        if ($this->provider() !== 'anthropic') {
            return ['ok' => false, 'status' => 422, 'message' => 'Bill scanning uses Claude. Set KRIYABOT_AI_PROVIDER=anthropic and add ANTHROPIC_API_KEY.'];
        }
        $isPdf = $mime === 'application/pdf';
        $block = $isPdf
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];

        $list = fn (array $names) => $names ? implode(', ', array_map(fn ($n) => '"'.$n.'"', $names)) : '(none yet)';
        $system = 'You read Indian bills, invoices, receipts, salary slips and payment screenshots (UPI, bank SMS) for a personal and office finance book. '
            .'Today is '.$today.' ('.$timezone.'). Amounts are in INR unless the document clearly shows another currency. '
            .'Decide whether money was paid out by the user (expense) or received by the user (income). A shop bill, invoice addressed to the user, fuel slip, utility bill or a UPI "paid to" screen is an expense; a payment received, salary credit, fee receipt issued by the user, or "received from" screen is income. '
            .'amount is the final total actually payable or paid (grand total including tax, after discounts). Use digits only, no commas. '
            .'entry_date is the bill/transaction date as YYYY-MM-DD; Indian dates are usually day-first (05/11/2026 = 5 November 2026). If no date is visible use today. '
            .'bill_no is the invoice/bill/receipt/UTR/transaction number exactly as printed. party is the shop, company or person on the other side. '
            .'description is a short summary of what was bought or received (max 120 characters). '
            .'Expense heads available: '.$list($heads['expense']).'. Income heads available: '.$list($heads['income']).'. '
            .'Set head_name to the single best matching head of the correct type, copied exactly, or null if none fits. If none fits, put a short sensible new head name in suggested_head (e.g. "Groceries", "Fuel", "Electricity"). '
            .'If the image is not a bill, receipt or payment record, set document_kind to "not_a_bill". Never invent numbers you cannot read; use null and lower confidence instead.';

        $nullable = fn (string $type, string $description) => ['type' => [$type, 'null'], 'description' => $description];
        $schema = ['type' => 'object', 'properties' => [
            'document_kind' => ['type' => 'string', 'enum' => ['bill', 'not_a_bill']],
            'type' => ['type' => 'string', 'enum' => ['expense', 'income']],
            'amount' => $nullable('number', 'Grand total in the currency shown'),
            'tax_amount' => $nullable('number', 'Total GST/tax if shown'),
            'currency' => ['type' => 'string', 'description' => 'ISO code, usually INR'],
            'entry_date' => $nullable('string', 'YYYY-MM-DD'),
            'bill_no' => $nullable('string', 'Invoice / bill / UTR number'),
            'party' => $nullable('string', 'Vendor or payer name'),
            'description' => $nullable('string', 'Short summary'),
            'payment_method' => ['type' => ['string', 'null'], 'enum' => ['cash', 'upi', 'card', 'bank', 'cheque', 'other', null]],
            'head_name' => $nullable('string', 'Exact existing head name or null'),
            'suggested_head' => $nullable('string', 'New head name when none fits'),
            'confidence' => ['type' => 'number', 'description' => '0 to 1: how sure you are about amount and date'],
        ], 'required' => ['document_kind', 'type', 'amount', 'entry_date', 'confidence']];

        $result = $this->anthropic([
            'model' => config('taskflow.ai.model', 'claude-sonnet-5-5'),
            'max_tokens' => 1024,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => 'Read this document and record it.']]]],
            'tools' => [['name' => 'record_bill', 'description' => 'Record the bill details.', 'input_schema' => $schema]],
            'tool_choice' => ['type' => 'tool', 'name' => 'record_bill'],
        ], 60);
        if (! $result['ok']) return $result;
        $tool = collect($result['json']['content'] ?? [])->first(fn ($b) => ($b['type'] ?? '') === 'tool_use');
        return is_array($tool['input'] ?? null)
            ? ['ok' => true, 'data' => $tool['input']]
            : ['ok' => false, 'status' => 502, 'message' => 'The bill could not be read. Try a clearer, well-lit photo.'];
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
