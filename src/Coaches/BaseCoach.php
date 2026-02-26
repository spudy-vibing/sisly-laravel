<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\CoachInterface;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\DTOs\CoETrace;
use Sisly\DTOs\Session;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Base class for all Sisly coaches.
 *
 * Provides common functionality for prompt loading, LLM interaction, and CoE processing.
 */
abstract class BaseCoach implements CoachInterface
{
    protected PromptLoader $promptLoader;
    protected CoEEngine $coeEngine;

    public function __construct(
        protected readonly LLMProviderInterface $llm,
        ?PromptLoader $promptLoader = null,
        ?CoEEngine $coeEngine = null,
    ) {
        $this->promptLoader = $promptLoader ?? new PromptLoader();
        $this->coeEngine = $coeEngine ?? new CoEEngine($llm);
    }

    /**
     * Process a user message and generate a response.
     *
     * @return array{response: string, arabic_mirror: ?string, coe_trace: ?CoETrace}
     */
    public function process(Session $session, string $message): array
    {
        // Build the full prompt with language instruction
        $systemPrompt = $this->buildFullSystemPrompt($session);
        $statePrompt = $this->getStatePrompt($session->state);

        // Run CoE reasoning
        $coeResult = $this->coeEngine->reason($session, $message, $systemPrompt);

        // Generate response using LLM (in user's preferred language)
        $response = $this->generateResponse($session, $message, $systemPrompt, $statePrompt);

        // Note: Arabic mirror is no longer generated - responses are single-language
        // based on user preference (EN or AR, not both)

        return [
            'response' => $response,
            'arabic_mirror' => null, // Single-language mode: no mirror needed
            'coe_trace' => $session->preferences->includeCoETrace ? $coeResult : null,
        ];
    }

    /**
     * Build the full system prompt including global rules, coach-specific content, and language.
     */
    protected function buildFullSystemPrompt(Session $session): string
    {
        $globalRules = $this->promptLoader->loadGlobal('rules');
        $coachSystem = $this->getSystemPrompt($session->state);
        $languageInstruction = $this->getLanguageInstruction($session->preferences->language);

        return <<<PROMPT
{$globalRules}

---

{$coachSystem}

---

{$languageInstruction}
PROMPT;
    }

    /**
     * Get language-specific instruction for the LLM.
     *
     * Ensures responses are generated in the user's preferred language only.
     */
    protected function getLanguageInstruction(string $language): string
    {
        if ($language === 'ar') {
            return <<<PROMPT
LANGUAGE INSTRUCTION:
Respond ONLY in Gulf Arabic (Khaleeji dialect - اللهجة الخليجية).
- Use warm, conversational Gulf Arabic appropriate for UAE, Saudi Arabia, Kuwait, Bahrain, Qatar, and Oman
- Do NOT include any English text in your response
- Keep the supportive, empathetic coaching tone
- Use culturally appropriate expressions
PROMPT;
        }

        return <<<PROMPT
LANGUAGE INSTRUCTION:
Respond ONLY in English.
- Use warm, supportive, conversational English
- Do NOT include any Arabic text in your response
PROMPT;
    }

    /**
     * Generate a response using the LLM.
     */
    protected function generateResponse(
        Session $session,
        string $message,
        string $systemPrompt,
        string $statePrompt,
    ): string {
        $messages = $session->getHistoryForLLM();

        // Add state-specific context
        $fullSystemPrompt = $systemPrompt . "\n\n" . $statePrompt;

        $response = $this->llm->chat($messages, $fullSystemPrompt, [
            'temperature' => $this->getTemperatureForState($session->state),
            'max_tokens' => 150,
        ]);

        if (!$response->success) {
            // Log the error for debugging (only in Laravel context)
            if (function_exists('app') && app()->bound('log')) {
                app('log')->error('Sisly LLM call failed', [
                    'error' => $response->error,
                    'session_id' => $session->id,
                    'state' => $session->state->value,
                    'provider' => $this->llm->getName(),
                ]);
            }
            return $this->getFallbackResponse($session->state);
        }

        return $this->cleanResponse($response->content);
    }

    /**
     * Generate Arabic mirror for the response.
     */
    protected function generateArabicMirror(string $response, Session $session): ?string
    {
        $prompt = <<<PROMPT
Translate the emotional essence of this coaching response into one short Gulf Arabic line.
Use Gulf dialect (خليجي), be warm and conversational.
Maximum 15 words in Arabic.

Response: {$response}

Arabic mirror (one line only):
PROMPT;

        $result = $this->llm->generate($prompt, ['max_tokens' => 50]);

        if (!$result->success) {
            return null;
        }

        return trim($result->content);
    }

    /**
     * Get temperature setting for a state.
     */
    protected function getTemperatureForState(SessionState $state): float
    {
        return match ($state) {
            SessionState::CRISIS_INTERVENTION => 0.0, // Deterministic for safety
            SessionState::INTAKE => 0.7,
            SessionState::EXPLORATION => 0.7,
            SessionState::DEEPENING => 0.6,
            SessionState::PROBLEM_SOLVING => 0.5,
            SessionState::CLOSING => 0.6,
            default => 0.7,
        };
    }

    /**
     * Get a fallback response when LLM fails.
     */
    protected function getFallbackResponse(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE => "I'm here with you. Tell me what's on your mind.",
            SessionState::EXPLORATION => "Can you tell me a bit more about what you're experiencing?",
            SessionState::DEEPENING => "I hear you. That makes sense.",
            SessionState::PROBLEM_SOLVING => "Let's try something together. Do you have 30 seconds, 1 minute, or 2 minutes?",
            SessionState::CLOSING => "You've done well to take this time for yourself.",
            default => "I'm here with you.",
        };
    }

    /**
     * Clean up the LLM response.
     */
    protected function cleanResponse(string $response): string
    {
        // Remove any markdown formatting
        $response = preg_replace('/^#+\s*/m', '', $response) ?? $response;

        // Remove bullet points
        $response = preg_replace('/^[-*]\s*/m', '', $response) ?? $response;

        // Trim whitespace
        $response = trim($response);

        // Limit length (20-25 words is the guideline)
        $words = explode(' ', $response);
        if (count($words) > 40) {
            $response = implode(' ', array_slice($words, 0, 40));
        }

        return $response;
    }

    /**
     * Check if this coach can handle the given message based on triggers.
     */
    public function canHandle(string $message): bool
    {
        $messageLower = mb_strtolower($message, 'UTF-8');
        $triggers = $this->getTriggers();

        $matchCount = 0;
        foreach ($triggers as $trigger) {
            if (str_contains($messageLower, strtolower($trigger))) {
                $matchCount++;
            }
        }

        // Require at least one trigger match
        return $matchCount >= 1;
    }

    /**
     * Get the coach's greeting message.
     *
     * Each coach must provide a domain-specific greeting.
     * Override this method to provide custom greetings.
     *
     * @param string $language The preferred language ('en' or 'ar')
     * @return string The greeting message
     */
    abstract public function getGreeting(string $language = 'en'): string;

    /**
     * Get the English greeting for this coach.
     *
     * Subclasses should override this to provide their domain-specific greeting.
     */
    abstract protected function getEnglishGreeting(): string;

    /**
     * Generate the Arabic version of a greeting using LLM.
     */
    protected function generateArabicGreeting(string $englishGreeting): string
    {
        $prompt = <<<PROMPT
Translate this coaching greeting to warm Gulf Arabic (Khaleeji dialect - اللهجة الخليجية).
Keep it natural and conversational, appropriate for UAE, Saudi Arabia, Kuwait, Bahrain, Qatar, and Oman.
Maximum 25 words in Arabic.

English greeting: {$englishGreeting}

Arabic greeting (no English, just Arabic):
PROMPT;

        $result = $this->llm->generate($prompt, [
            'max_tokens' => 100,
            'temperature' => 0.3, // Low temperature for consistency
        ]);

        if (!$result->success) {
            // Fallback to a generic Arabic greeting
            return "مرحباً، أنا {$this->getName()}. أنا هنا معك.";
        }

        return trim($result->content);
    }
}
