<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * AI Talker — the external model that PHRASES replies.
 *
 * The Rule Brain decides what may be said (rule, facts, money figures, the
 * negotiation floor). The talker only rewrites that decided draft in a
 * natural voice, in the customer's own language. Two tiers:
 *  - normal tier  (default Gemini)  — everyday replies
 *  - complex tier (default Claude)  — angry customers, long bargaining,
 *    long/complex questions, when routing sends them there
 * Providers and models are plain settings, so the talker stays swappable
 * without code changes. Any failure (no key, timeout, bad reply) returns
 * null and the caller falls back to the Rule Brain's own template text —
 * and every phrased reply still passes RuleBrainService::moneyCheck()
 * before it can be auto-sent.
 */
class AiTalkerService
{
    public const PROVIDERS = ['gemini', 'claude'];

    public const DEFAULT_MODELS = [
        'gemini' => 'gemini-3.5-flash',
        'claude' => 'claude-sonnet-4-5',
    ];

    public function __construct(private SettingsService $settings)
    {
    }

    /** Talker config for the Settings page — never includes key values. */
    public function config(): array
    {
        $c = $this->raw();

        return [
            'enabled' => (bool) ($c['enabled'] ?? false),
            'normal_provider' => $this->provider($c['normal_provider'] ?? 'gemini', 'gemini'),
            'normal_model' => (string) ($c['normal_model'] ?? self::DEFAULT_MODELS['gemini']),
            'complex_provider' => $this->provider($c['complex_provider'] ?? 'claude', 'claude'),
            'complex_model' => (string) ($c['complex_model'] ?? self::DEFAULT_MODELS['claude']),
            'route_angry' => (bool) ($c['route_angry'] ?? true),
            'route_long_bargain' => (bool) ($c['route_long_bargain'] ?? true),
            'gemini_key_set' => ! empty($c['gemini_key_enc']),
            'claude_key_set' => ! empty($c['claude_key_enc']),
        ];
    }

    private function raw(): array
    {
        $c = $this->settings->get('ai.talker', []);

        return is_array($c) ? $c : [];
    }

    private function provider($value, string $fallback): string
    {
        return in_array($value, self::PROVIDERS, true) ? $value : $fallback;
    }

    private function apiKey(string $provider): ?string
    {
        $enc = $this->raw()[$provider . '_key_enc'] ?? null;
        if (! $enc) {
            return null;
        }
        try {
            $key = Crypt::decryptString((string) $enc);
        } catch (\Throwable) {
            return null;
        }

        return $key !== '' ? $key : null;
    }

    /** Should this conversation go to the complex tier? */
    public function isComplex(array $ctx, array $cfg): bool
    {
        if (($cfg['route_angry'] ?? true) && ($ctx['intent'] ?? null) === 'angry') {
            return true;
        }
        if (($cfg['route_long_bargain'] ?? true) && ($ctx['intent'] ?? null) === 'bargain' && (int) ($ctx['neg_step'] ?? 0) >= 2) {
            return true;
        }

        return mb_strlen((string) ($ctx['incoming'] ?? '')) >= 240;
    }

    /**
     * Phrase the decided draft. Returns ['text','provider','model','tier']
     * or null when the talker is off, unconfigured, unreachable, or its
     * reply fails the figure guard — the caller then uses the draft as-is.
     */
    public function phrase(array $ctx): ?array
    {
        $cfg = $this->config();
        if (! $cfg['enabled']) {
            return null;
        }
        $draft = trim((string) ($ctx['draft'] ?? ''));
        if ($draft === '') {
            return null;
        }
        $tier = $this->isComplex($ctx, $cfg) ? 'complex' : 'normal';
        $provider = $cfg[$tier . '_provider'];
        $model = trim((string) $cfg[$tier . '_model']) ?: self::DEFAULT_MODELS[$provider];
        $key = $this->apiKey($provider);
        if (! $key) {
            return null;
        }

        $prompt = $this->prompt($ctx, $draft);
        $text = $provider === 'gemini'
            ? $this->callGemini($key, $model, $prompt)
            : $this->callClaude($key, $model, $prompt);
        $text = is_string($text) ? trim($text) : null;
        if ($text === null || $text === '') {
            return null;
        }
        // Figure guard: every ৳ figure in the decided draft must survive
        // phrasing word-for-word. moneyCheck() still runs after this.
        if (! $this->figuresPreserved($draft, $text)) {
            return null;
        }

        return ['text' => $text, 'provider' => $provider, 'model' => $model, 'tier' => $tier];
    }

    private function figuresPreserved(string $draft, string $text): bool
    {
        $clean = static fn (string $s) => str_replace(',', '', $s);
        if (! preg_match_all('/৳\s*(\d+(?:\.\d+)?)/u', $clean($draft), $m)) {
            return true;
        }
        $phrased = $clean($text);
        foreach ($m[1] as $figure) {
            if (! str_contains($phrased, $figure)) {
                return false;
            }
        }

        return true;
    }

    private function prompt(array $ctx, string $draft): string
    {
        $incoming = trim((string) ($ctx['incoming'] ?? ''));

        return implode("\n", [
            'You write customer replies for a Bangladeshi online shop (Facebook/WhatsApp commerce).',
            "The shop's rule system has already decided the facts and written a draft reply.",
            'Rewrite the draft so it sounds natural, warm and human, in the SAME language the customer used (Bangla, Banglish or English).',
            'STRICT RULES:',
            '- Never change, add or remove any number, price, discount or total. Copy every figure exactly as written in the draft.',
            '- Do not promise anything the draft does not say — no extra discount, no new delivery promise.',
            '- Keep it short: at most 3 short sentences. No emojis unless the draft already has them.',
            '- Output ONLY the reply text, nothing else.',
            '',
            'Customer message: "' . $incoming . '"',
            'Draft reply: "' . $draft . '"',
        ]);
    }

    private function callGemini(string $key, string $model, string $prompt): ?string
    {
        try {
            $res = Http::timeout(30)->connectTimeout(6)
                ->withHeaders(['x-goog-api-key' => $key])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent', [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 300, 'thinkingConfig' => ['thinkingBudget' => 0]],
                ]);
            if (! $res->successful()) {
                return null;
            }

            return $res->json('candidates.0.content.parts.0.text');
        } catch (\Throwable) {
            return null;
        }
    }

    private function callClaude(string $key, string $model, string $prompt): ?string
    {
        try {
            $res = Http::timeout(30)->connectTimeout(6)
                ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 300,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
            if (! $res->successful()) {
                return null;
            }

            return $res->json('content.0.text');
        } catch (\Throwable) {
            return null;
        }
    }

    /** Settings "Check connection" — one tiny live call per provider. */
    public function testConnection(string $provider): array
    {
        $key = $this->apiKey($provider);
        if (! $key) {
            return ['ok' => false, 'detail' => 'No API key saved for this provider yet'];
        }
        $cfg = $this->config();
        $model = $provider === $cfg['normal_provider'] ? $cfg['normal_model'] : $cfg['complex_model'];
        $model = trim((string) $model) ?: self::DEFAULT_MODELS[$provider];
        $text = $provider === 'gemini'
            ? $this->callGemini($key, $model, 'Reply with exactly: OK')
            : $this->callClaude($key, $model, 'Reply with exactly: OK');

        return $text !== null && trim($text) !== ''
            ? ['ok' => true, 'detail' => 'Connected — ' . $model . ' answered']
            : ['ok' => false, 'detail' => 'Could not reach ' . $model . ' — check the API key and model name'];
    }
}
