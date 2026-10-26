<?php

namespace App\Analytics\Ingestion;

class Sources
{
    public const PROVIDERS = [
        'chatgpt' => ['label' => 'ChatGPT', 'key' => 'chatgpt.com', 'aliases' => ['chatgpt', 'chatgpt.com', 'www.chatgpt.com', 'chat.openai.com']],
        'claude' => ['label' => 'Claude', 'key' => 'claude.ai', 'aliases' => ['claude', 'claude.ai', 'www.claude.ai']],
        'perplexity' => ['label' => 'Perplexity', 'key' => 'perplexity.ai', 'aliases' => ['perplexity', 'perplexity.ai', 'www.perplexity.ai']],
        'gemini' => ['label' => 'Gemini', 'key' => 'gemini.google.com', 'aliases' => ['gemini', 'gemini.google.com', 'bard.google.com']],
        'copilot' => ['label' => 'Copilot', 'key' => 'copilot.microsoft.com', 'aliases' => ['copilot', 'copilot.microsoft.com']],
        'grok' => ['label' => 'Grok', 'key' => 'grok.com', 'aliases' => ['grok', 'grok.com', 'www.grok.com']],
    ];

    public function describe(string $value): array
    {
        $normalized = strtolower(trim($value));
        foreach (self::PROVIDERS as $provider => $entry) {
            if (in_array($normalized, $entry['aliases'], true)) {
                return ['value' => $entry['key'], 'label' => $entry['label'], 'provider' => $provider, 'category' => 'ai', 'icon' => $provider];
            }
        }

        return ['value' => $value, 'label' => match ($value) {
            'Direct' => 'Direct / unidentified origin', 'Unknown' => 'Invalid referrer / unknown origin', default => $value
        }, 'provider' => null, 'category' => $value === 'Direct' ? 'direct' : 'other', 'icon' => null];
    }

    public function resolve(?string $utm, ?string $host, string $status): string
    {
        if ($utm !== null && $utm !== '' && $utm !== ':redacted') {
            return $this->describe(strtolower(trim($utm)))['value'];
        }

        return $host ? $this->describe($host)['value'] : ($status === 'invalid' ? 'Unknown' : 'Direct');
    }
}
