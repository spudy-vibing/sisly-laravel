<?php

declare(strict_types=1);

namespace Sisly\DTOs;

use DateTimeImmutable;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

/**
 * Session data stored between turns.
 */
final class Session
{
    private const MAX_HISTORY_TURNS = 20;

    /**
     * @param array<ConversationTurn> $history
     */
    public function __construct(
        public readonly string $id,
        public CoachId $coachId,
        public SessionState $state,
        public int $turnCount,
        public int $stateTurns,
        public readonly GeoContext $geo,
        public readonly SessionPreferences $preferences,
        public array $history,
        public CrisisInfo $crisis,
        public bool $isActive,
        public readonly DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastActivity,
    ) {}

    /**
     * Create a new session.
     */
    public static function create(
        string $id,
        CoachId $coachId,
        GeoContext $geo,
        ?SessionPreferences $preferences = null,
    ): self {
        $now = new DateTimeImmutable();

        return new self(
            id: $id,
            coachId: $coachId,
            state: SessionState::INTAKE,
            turnCount: 0,
            stateTurns: 0,
            geo: $geo,
            preferences: $preferences ?? new SessionPreferences(),
            history: [],
            crisis: CrisisInfo::none(),
            isActive: true,
            createdAt: $now,
            lastActivity: $now,
        );
    }

    /**
     * Add a conversation turn to the history.
     */
    public function addTurn(ConversationTurn $turn): void
    {
        $this->history[] = $turn;
        $this->turnCount++;
        $this->lastActivity = new DateTimeImmutable();

        // Enforce max history (FIFO pruning)
        if (count($this->history) > self::MAX_HISTORY_TURNS) {
            array_shift($this->history);
        }
    }

    /**
     * Transition to a new state.
     */
    public function transitionTo(SessionState $newState): void
    {
        $this->state = $newState;
        $this->stateTurns = 0; // Reset turn counter for new state
        $this->lastActivity = new DateTimeImmutable();
    }

    /**
     * Mark the session as ended.
     */
    public function end(): void
    {
        $this->isActive = false;
        $this->lastActivity = new DateTimeImmutable();
    }

    /**
     * Set crisis information.
     */
    public function setCrisis(CrisisInfo $crisis): void
    {
        $this->crisis = $crisis;
        if ($crisis->detected) {
            $this->state = SessionState::CRISIS_INTERVENTION;
        }
    }

    /**
     * Get the most recent user message.
     */
    public function getLastUserMessage(): ?string
    {
        for ($i = count($this->history) - 1; $i >= 0; $i--) {
            if ($this->history[$i]->isUser()) {
                return $this->history[$i]->content;
            }
        }
        return null;
    }

    /**
     * Get conversation history for LLM context.
     *
     * @return array<array{role: string, content: string}>
     */
    public function getHistoryForLLM(): array
    {
        return array_map(
            fn (ConversationTurn $turn) => [
                'role' => $turn->role,
                'content' => $turn->content,
            ],
            $this->history
        );
    }

    /**
     * Create instance from array (for deserialization).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            coachId: CoachId::from($data['coach_id']),
            state: SessionState::from($data['state']),
            turnCount: $data['turn_count'],
            stateTurns: $data['state_turns'] ?? 0,
            geo: GeoContext::fromArray($data['geo']),
            preferences: SessionPreferences::fromArray($data['preferences']),
            history: array_map(
                fn (array $turn) => ConversationTurn::fromArray($turn),
                $data['history']
            ),
            crisis: CrisisInfo::fromArray($data['crisis']),
            isActive: $data['is_active'],
            createdAt: new DateTimeImmutable($data['created_at']),
            lastActivity: new DateTimeImmutable($data['last_activity']),
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'coach_id' => $this->coachId->value,
            'state' => $this->state->value,
            'turn_count' => $this->turnCount,
            'state_turns' => $this->stateTurns,
            'geo' => $this->geo->toArray(),
            'preferences' => $this->preferences->toArray(),
            'history' => array_map(
                fn (ConversationTurn $turn) => $turn->toArray(),
                $this->history
            ),
            'crisis' => $this->crisis->toArray(),
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('c'),
            'last_activity' => $this->lastActivity->format('c'),
        ];
    }
}
