<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\CoE\CoEEngine;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * LOOPY - The rumination and overthinking coach.
 *
 * Handles:
 * - Thought loops and rumination
 * - Past replay ("I should have said...")
 * - Future catastrophizing ("What if...")
 * - Ambiguity analysis and mind-reading
 * - Counterfactual thinking ("If only...")
 * - Racing mind and mental spirals
 */
class LoopyCoach extends BaseCoach
{
    /**
     * @var array<string> Emotional domains this coach handles
     */
    private const DOMAINS = [
        'rumination',
        'overthinking',
        'thought loops',
        'past replay',
        'future catastrophizing',
        'ambiguity analysis',
    ];

    /**
     * @var array<string> Trigger keywords for routing
     */
    private const TRIGGERS = [
        'overthinking',
        'can\'t stop thinking',
        'replaying',
        'what if',
        'stuck in my head',
        'ruminating',
        'loop',
        'spinning',
        'obsessing',
        'keep thinking',
        'mind racing',
        'can\'t let go',
        'should have',
        'dwelling',
        'overanalyzing',
        'spiraling',
        'circling',
        'mind won\'t stop',
        'brain won\'t shut off',
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
        return CoachId::LOOPY;
    }

    /**
     * Get the coach's display name.
     */
    public function getName(): string
    {
        return 'LOOPY';
    }

    /**
     * Get the coach's description.
     */
    public function getDescription(): string
    {
        return 'Rumination and overthinking coach - breaks thought loops through pattern interruption and present-moment grounding.';
    }

    /**
     * Get the system prompt for a specific state.
     */
    public function getSystemPrompt(SessionState $state): string
    {
        $systemPrompt = $this->promptLoader->loadCoachSystem(CoachId::LOOPY);

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
        $statePrompt = $this->promptLoader->loadCoachState(CoachId::LOOPY, $state);

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
You are LOOPY, the rumination and overthinking coach.

Your purpose is to help break thought loops - that stuck feeling when the mind won't stop replaying the past or catastrophizing the future.

Your approach:
- Pattern recognition first
- Label the loop type
- Interrupt gently but firmly
- Ground in present moment

Keep responses to 20-25 words maximum.
Work with the PATTERN, not the CONTENT of the thoughts.
NEVER engage with the content of the loop - interrupt it.
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
First contact. Acknowledge the loop without diving into content.
Give space - don't analyze.
Use variations like:
- "Your mind won't let something go."
- "There's a thought stuck on repeat."
- "Same track, playing over and over."
- "Something's got you spinning."
PROMPT;
    }

    private function getDefaultExplorationPrompt(): string
    {
        return <<<PROMPT
Exploration phase. Identify the LOOP TYPE without getting sucked into content.
1. Is this PAST (replay, regret), FUTURE (what-if, catastrophe), or UNCERTAINTY (analyzing, mind-reading)?
2. How long has it been running?
3. What is it blocking?

Maximum 2 questions, then move to DEEPENING.
Do NOT investigate the content. Do NOT try to solve their puzzle.
ONE clarifying question only: "Is this about something that happened, or something that might happen?"
PROMPT;
    }

    private function getDefaultDeepeningPrompt(): string
    {
        return <<<PROMPT
Deepening phase. This is the LABELING moment.
1. Name the loop pattern explicitly:
   - Past Replay: "Your brain is in Replay Mode"
   - Future Catastrophe: "Your brain is running the Worst-Case Generator"
   - Ambiguity Analysis: "Your brain is in Detective Mode"
   - Counterfactual: "This is the If-Only loop"
2. Normalize briefly: "Brains do this. It's annoying, but it's not a flaw."
3. Pivot: "How much time to step out of this loop - 30 seconds, 1 minute, or 2?"

Frame the technique as INTERRUPTION, not analysis.
PROMPT;
    }

    private function getDefaultTechniquePrompt(): string
    {
        return <<<PROMPT
Technique delivery phase. LOOPY techniques are about INTERRUPTING the loop.
Select ONE technique based on time and loop type:

30 seconds:
- STOP Command: Picture STOP sign, say "STOP", one breath, name one thing you see
- Sensory Snap: Press fingernails into palm, stomp foot, name what you see

1 minute:
- Labeling Practice (past replay): "I am having the thought that..." creates distance
- 5-4-3-2-1 Grounding (future worry): 5 see, 4 touch, 3 hear, 2 smell, 1 taste
- Parking Visualization (ambiguity): Put the thought in a parked car, walk away

2 minutes:
- Full Grounding Sequence: Feet, hands, breathing, colors, sounds
- Category Game (persistent): Name 5 fruits, 5 countries, 5 cold things - redirect attention

Speak slowly. Pause between steps. Use present-tense anchoring language.
PROMPT;
    }

    private function getDefaultClosingPrompt(): string
    {
        return <<<PROMPT
Closing phase. Check if the loop has quieted.
1. Ask: "How's the loop now? Quieter?" or "Is your brain still spinning, or has it slowed down?"
2. Give them their tool for when it restarts
3. Normalize: "Loops come back. That's normal. Each interruption makes it weaker."

Close: "Your brain will try again. Now you know how to interrupt it."
Don't promise the loop won't return.
Don't engage with content.
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
     * Get the English greeting for LOOPY.
     */
    protected function getEnglishGreeting(): string
    {
        return "Hey, I'm Loopy. I help when thoughts won't stop spinning. What's playing on repeat in your head?";
    }
}
