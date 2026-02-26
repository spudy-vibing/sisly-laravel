<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * MEETLY - The meeting and performance anxiety coach.
 *
 * Handles:
 * - Pre-meeting nerves
 * - Presentation anxiety
 * - Interview stress
 * - Post-meeting replay and regret
 * - Fear of judgment in professional settings
 */
class MeetlyCoach extends BaseCoach
{
    /**
     * @var array<string> Emotional domains this coach handles
     */
    private const DOMAINS = [
        'meeting anxiety',
        'presentation nerves',
        'interview stress',
        'performance anxiety',
        'professional judgment fear',
    ];

    /**
     * @var array<string> Trigger keywords for routing
     */
    private const TRIGGERS = [
        'meeting',
        'presentation',
        'interview',
        'nervous',
        'scared',
        'anxious',
        'judged',
        'freeze',
        'blank',
        'speak',
        'audience',
        'boss',
        'manager',
        'review',
        'evaluation',
        'performance',
        'stage',
        'shaking',
        'sweating',
        'heart racing',
    ];

    public function __construct(
        LLMProviderInterface $llm,
        ?PromptLoader $promptLoader = null,
        ?CoEEngine $coeEngine = null,
    ) {
        parent::__construct($llm, $promptLoader, $coeEngine);
    }

    /**
     * Get the coach's identifier.
     */
    public function getId(): CoachId
    {
        return CoachId::MEETLY;
    }

    /**
     * Get the coach's display name.
     */
    public function getName(): string
    {
        return 'MEETLY';
    }

    /**
     * Get the coach's description.
     */
    public function getDescription(): string
    {
        return 'Meeting and performance anxiety coach - calms nerves and builds grounded readiness.';
    }

    /**
     * Get the system prompt for a specific state.
     */
    public function getSystemPrompt(SessionState $state): string
    {
        // For MEETLY, always use the main system prompt
        $systemPrompt = $this->promptLoader->loadCoachSystem(CoachId::MEETLY);

        if (empty($systemPrompt)) {
            return $this->getDefaultSystemPrompt();
        }

        return $systemPrompt;
    }

    /**
     * Get the state-specific prompt.
     */
    public function getStatePrompt(SessionState $state): string
    {
        $statePrompt = $this->promptLoader->loadCoachState(CoachId::MEETLY, $state);

        if (empty($statePrompt)) {
            return $this->getDefaultStatePrompt($state);
        }

        return $statePrompt;
    }

    /**
     * Get the emotional domains this coach handles.
     *
     * @return array<string>
     */
    public function getDomains(): array
    {
        return self::DOMAINS;
    }

    /**
     * Get trigger keywords for this coach.
     *
     * @return array<string>
     */
    public function getTriggers(): array
    {
        return self::TRIGGERS;
    }

    /**
     * Get a default system prompt when file is not available.
     */
    private function getDefaultSystemPrompt(): string
    {
        return <<<PROMPT
You are MEETLY, the meeting and performance anxiety coach.

Your purpose is to calm nerves and build grounded readiness around professional moments - meetings, presentations, interviews, and evaluations.

Your approach:
- Gentle but grounded
- Body-aware (where does the anxiety live?)
- Time-anchored (what's coming, when?)
- Confidence emerges, never asserted

Keep responses to 20-25 words maximum.
Use a calm, steady voice - not cheerful or peppy.
Never tell someone to "just be confident."
PROMPT;
    }

    /**
     * Get default state prompts when files are not available.
     */
    private function getDefaultStatePrompt(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE => $this->getDefaultIntakePrompt(),
            SessionState::EXPLORATION => $this->getDefaultExplorationPrompt(),
            SessionState::DEEPENING => $this->getDefaultDeepeningPrompt(),
            SessionState::PROBLEM_SOLVING => $this->getDefaultTechniquePrompt(),
            SessionState::CLOSING => $this->getDefaultClosingPrompt(),
            SessionState::CRISIS_INTERVENTION => $this->getDefaultCrisisPrompt(),
            default => '',
        };
    }

    private function getDefaultIntakePrompt(): string
    {
        return <<<PROMPT
First contact. Mirror the user's emotional state.
Acknowledge what they're feeling without analysis.
Use variations like:
- "Something's coming up that's got your attention."
- "There's a moment ahead that's pulling at you."
- "Your body's already preparing for something."
PROMPT;
    }

    private function getDefaultExplorationPrompt(): string
    {
        return <<<PROMPT
Exploration phase. Understand:
1. What specific event is triggering the anxiety?
2. What does the user fear might happen?
3. Where is this showing up in their body?

Maximum 2 clarifying questions, then move to deepening.
If event is in 5 minutes or less, skip exploration.
PROMPT;
    }

    private function getDefaultDeepeningPrompt(): string
    {
        return <<<PROMPT
Deepening phase. Your goals:
1. Summarize the emotional pattern in one sentence
2. Offer a small insight or reframe (optional)
3. Pivot to time choice: "How much time - 30 seconds, 1 minute, or 2?"

This is the pivot between understanding and action.
PROMPT;
    }

    private function getDefaultTechniquePrompt(): string
    {
        return <<<PROMPT
Technique delivery phase.
Select ONE technique based on time choice:

30 seconds:
- Posture Reset (feet, shoulders, jaw)
- Physiological Sigh (double inhale, long exhale)

1 minute:
- 4-7-8 Breathing
- Attention Narrowing ("only the next 60 seconds")

2 minutes:
- Grounding + Breath Combo
- Permission Statement

Guide step by step. Don't rush. Don't explain why it works.
PROMPT;
    }

    private function getDefaultClosingPrompt(): string
    {
        return <<<PROMPT
Closing phase.
1. Check in: "How does that feel?" or "One word for how you feel now."
2. Anchor the gain briefly
3. End cleanly: "You're ready." or "I'm here if you need me again."

Don't drag it out. Under 30 words per response.
PROMPT;
    }

    private function getDefaultCrisisPrompt(): string
    {
        return <<<PROMPT
SAFETY MODE. The user may be in crisis.
1. Acknowledge their pain plainly
2. Do NOT diagnose or give clinical advice
3. Stay present: "I'm here with you"
4. The system will provide crisis resources

Keep it simple and human. No techniques.
PROMPT;
    }

    /**
     * Get the coach's greeting in the specified language.
     */
    public function getGreeting(string $language = 'en'): string
    {
        $englishGreeting = $this->getEnglishGreeting();

        if ($language === 'ar') {
            return $this->generateArabicGreeting($englishGreeting);
        }

        return $englishGreeting;
    }

    /**
     * Get the English greeting for MEETLY.
     */
    protected function getEnglishGreeting(): string
    {
        return "Hi, I'm Meetly. I help with meeting and presentation nerves. Which moment ahead is on your mind?";
    }
}
