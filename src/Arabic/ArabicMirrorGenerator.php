<?php

declare(strict_types=1);

namespace Sisly\Arabic;

use Sisly\Contracts\LLMProviderInterface;
use Sisly\LLM\LLMResponse;

/**
 * Generates Arabic "mirror" translations of English coaching responses.
 * Uses Gulf (Khaleeji) dialect by default for GCC market.
 */
class ArabicMirrorGenerator
{
    private LLMProviderInterface $llm;
    private string $dialect;
    private bool $enabled;

    /**
     * System prompt for Arabic translation.
     */
    private const TRANSLATION_PROMPT_GULF = <<<'PROMPT'
You are a professional Arabic translator specializing in Gulf Arabic (Khaleeji dialect).
Your task is to translate emotional coaching responses from English to Gulf Arabic.

Guidelines:
1. Use Gulf Arabic dialect (اللهجة الخليجية) appropriate for UAE, Saudi Arabia, Kuwait, Bahrain, Qatar, and Oman
2. Maintain the warm, supportive, empathetic tone of the original
3. Use culturally appropriate expressions and idioms
4. Keep the translation natural and conversational, not formal MSA
5. Preserve any technical coaching terms that are commonly understood in English
6. Do not add or remove meaning from the original text
7. Use appropriate Arabic punctuation

Translate the following coaching response to Gulf Arabic:
PROMPT;

    private const TRANSLATION_PROMPT_MSA = <<<'PROMPT'
You are a professional Arabic translator specializing in Modern Standard Arabic (MSA/Fusha).
Your task is to translate emotional coaching responses from English to Modern Standard Arabic.

Guidelines:
1. Use clear, accessible Modern Standard Arabic (العربية الفصحى)
2. Maintain the warm, supportive, empathetic tone of the original
3. Use appropriate formal expressions while remaining approachable
4. Keep the translation clear and understandable to all Arabic speakers
5. Preserve any technical coaching terms that are commonly understood in English
6. Do not add or remove meaning from the original text
7. Use appropriate Arabic punctuation

Translate the following coaching response to Modern Standard Arabic:
PROMPT;

    public function __construct(
        LLMProviderInterface $llm,
        string $dialect = 'gulf',
        bool $enabled = true
    ) {
        $this->llm = $llm;
        $this->dialect = $dialect;
        $this->enabled = $enabled;
    }

    /**
     * Generate Arabic mirror of an English response.
     *
     * @return string|null Arabic translation, or null if disabled/failed
     */
    public function generate(string $englishText): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        if (empty(trim($englishText))) {
            return null;
        }

        $prompt = $this->getTranslationPrompt();
        $fullPrompt = $prompt . "\n\n" . $englishText;

        $response = $this->llm->generate($fullPrompt, [
            'temperature' => 0.3, // Lower temperature for consistent translations
            'max_tokens' => 500,
        ]);

        if (!$response->success) {
            return null;
        }

        return $this->cleanTranslation($response->content);
    }

    /**
     * Generate Arabic mirror with metadata.
     *
     * @return array{
     *     arabic: string|null,
     *     dialect: string,
     *     success: bool,
     *     error: string|null
     * }
     */
    public function generateWithMetadata(string $englishText): array
    {
        if (!$this->enabled) {
            return [
                'arabic' => null,
                'dialect' => $this->dialect,
                'success' => false,
                'error' => 'Arabic mirror generation is disabled',
            ];
        }

        if (empty(trim($englishText))) {
            return [
                'arabic' => null,
                'dialect' => $this->dialect,
                'success' => false,
                'error' => 'Empty input text',
            ];
        }

        $prompt = $this->getTranslationPrompt();
        $fullPrompt = $prompt . "\n\n" . $englishText;

        $response = $this->llm->generate($fullPrompt, [
            'temperature' => 0.3,
            'max_tokens' => 500,
        ]);

        if (!$response->success) {
            return [
                'arabic' => null,
                'dialect' => $this->dialect,
                'success' => false,
                'error' => $response->error ?? 'Translation failed',
            ];
        }

        return [
            'arabic' => $this->cleanTranslation($response->content),
            'dialect' => $this->dialect,
            'success' => true,
            'error' => null,
        ];
    }

    /**
     * Get the appropriate translation prompt based on dialect.
     */
    private function getTranslationPrompt(): string
    {
        return $this->dialect === 'msa'
            ? self::TRANSLATION_PROMPT_MSA
            : self::TRANSLATION_PROMPT_GULF;
    }

    /**
     * Clean up the translation output.
     */
    private function cleanTranslation(string $text): string
    {
        // Remove any potential prompt leakage or explanations
        $text = trim($text);

        // Remove common artifacts like "Translation:" prefixes
        $patterns = [
            '/^(Translation|الترجمة|Arabic|العربية)\s*:\s*/iu',
            '/^["\']/u',
            '/["\']$/u',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '', $text) ?? $text;
        }

        return trim($text);
    }

    /**
     * Set the dialect to use.
     */
    public function setDialect(string $dialect): self
    {
        $this->dialect = in_array($dialect, ['gulf', 'msa'], true) ? $dialect : 'gulf';
        return $this;
    }

    /**
     * Get the current dialect.
     */
    public function getDialect(): string
    {
        return $this->dialect;
    }

    /**
     * Enable or disable Arabic mirror generation.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Check if Arabic mirror generation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if the LLM provider is available for translation.
     */
    public function isAvailable(): bool
    {
        return $this->enabled && $this->llm->isAvailable();
    }
}
