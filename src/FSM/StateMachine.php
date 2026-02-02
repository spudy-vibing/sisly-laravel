<?php

declare(strict_types=1);

namespace Sisly\FSM;

use Sisly\DTOs\Session;
use Sisly\Enums\SessionState;

/**
 * Finite State Machine for session state management.
 *
 * Controls state transitions, turn limits, and crisis trap state logic.
 */
class StateMachine
{
    /**
     * Turn limits per state.
     *
     * @var array<string, int>
     */
    private array $turnLimits;

    /**
     * Valid state transitions map.
     *
     * @var array<string, array<SessionState>>
     */
    private array $transitions;

    /**
     * Tracks turns spent in current state per session.
     *
     * @var array<string, int>
     */
    private array $stateTurns = [];

    /**
     * @param array<string, mixed> $config FSM configuration
     */
    public function __construct(array $config = [])
    {
        $this->turnLimits = $config['turn_limits'] ?? $this->getDefaultTurnLimits();
        $this->transitions = $this->buildTransitions();
    }

    /**
     * Get default turn limits per state.
     *
     * @return array<string, int>
     */
    private function getDefaultTurnLimits(): array
    {
        return [
            SessionState::INTAKE->value => 1,
            SessionState::RISK_TRIAGE->value => 0, // Automatic, no turns
            SessionState::EXPLORATION->value => 2,
            SessionState::DEEPENING->value => 1,
            SessionState::PROBLEM_SOLVING->value => 3,
            SessionState::CLOSING->value => 1,
            SessionState::CRISIS_INTERVENTION->value => PHP_INT_MAX, // Never advances
        ];
    }

    /**
     * Build the state transition map.
     *
     * @return array<string, array<SessionState>>
     */
    private function buildTransitions(): array
    {
        return [
            SessionState::INTAKE->value => [SessionState::RISK_TRIAGE],
            SessionState::RISK_TRIAGE->value => [
                SessionState::EXPLORATION,
                SessionState::CRISIS_INTERVENTION,
            ],
            SessionState::EXPLORATION->value => [SessionState::DEEPENING],
            SessionState::DEEPENING->value => [SessionState::PROBLEM_SOLVING],
            SessionState::PROBLEM_SOLVING->value => [SessionState::CLOSING],
            SessionState::CLOSING->value => [], // Terminal state
            SessionState::CRISIS_INTERVENTION->value => [], // Trap state - no exit
        ];
    }

    /**
     * Check if a transition from one state to another is valid.
     */
    public function canTransition(SessionState $from, SessionState $to): bool
    {
        $allowed = $this->transitions[$from->value] ?? [];
        return in_array($to, $allowed, true);
    }

    /**
     * Get all valid next states from the current state.
     *
     * @return array<SessionState>
     */
    public function getValidTransitions(SessionState $from): array
    {
        return $this->transitions[$from->value] ?? [];
    }

    /**
     * Check if the session should advance to the next state.
     */
    public function shouldAdvance(Session $session): bool
    {
        // Crisis intervention never advances
        if ($session->state === SessionState::CRISIS_INTERVENTION) {
            return false;
        }

        // Terminal states don't advance
        if ($this->isTerminal($session->state)) {
            return false;
        }

        // Check turn limit for current state
        $stateTurns = $this->getStateTurns($session->id);
        $limit = $this->turnLimits[$session->state->value] ?? 1;

        return $stateTurns >= $limit;
    }

    /**
     * Get the next state in the standard flow (non-crisis path).
     */
    public function getNextState(SessionState $current): ?SessionState
    {
        $allowed = $this->transitions[$current->value] ?? [];

        // Return first non-crisis option (crisis is handled separately by safety layer)
        foreach ($allowed as $state) {
            if ($state !== SessionState::CRISIS_INTERVENTION) {
                return $state;
            }
        }

        return null;
    }

    /**
     * Advance the session to the next state if appropriate.
     *
     * @return bool Whether a transition occurred
     */
    public function advance(Session $session): bool
    {
        if (!$this->shouldAdvance($session)) {
            return false;
        }

        $nextState = $this->getNextState($session->state);
        if ($nextState === null) {
            return false;
        }

        // Handle RISK_TRIAGE as an automatic pass-through
        if ($nextState === SessionState::RISK_TRIAGE) {
            $session->transitionTo($nextState);
            $this->resetStateTurns($session->id);
            // Immediately advance past RISK_TRIAGE to EXPLORATION
            $nextState = $this->getNextState($nextState);
            if ($nextState !== null) {
                $session->transitionTo($nextState);
                $this->resetStateTurns($session->id);
            }
            return true;
        }

        $session->transitionTo($nextState);
        $this->resetStateTurns($session->id);
        return true;
    }

    /**
     * Check if a state is terminal (no further transitions).
     */
    public function isTerminal(SessionState $state): bool
    {
        return $state === SessionState::CLOSING;
    }

    /**
     * Check if a state is the crisis trap state.
     */
    public function isCrisis(SessionState $state): bool
    {
        return $state === SessionState::CRISIS_INTERVENTION;
    }

    /**
     * Increment the turn counter for the current state.
     */
    public function incrementStateTurns(string $sessionId): void
    {
        if (!isset($this->stateTurns[$sessionId])) {
            $this->stateTurns[$sessionId] = 0;
        }
        $this->stateTurns[$sessionId]++;
    }

    /**
     * Get the number of turns spent in the current state.
     */
    public function getStateTurns(string $sessionId): int
    {
        return $this->stateTurns[$sessionId] ?? 0;
    }

    /**
     * Reset turn counter for a session (called on state transition).
     */
    public function resetStateTurns(string $sessionId): void
    {
        $this->stateTurns[$sessionId] = 0;
    }

    /**
     * Set turn count for a session (for restoration from storage).
     */
    public function setStateTurns(string $sessionId, int $turns): void
    {
        $this->stateTurns[$sessionId] = $turns;
    }

    /**
     * Get the turn limit for a specific state.
     */
    public function getTurnLimit(SessionState $state): int
    {
        return $this->turnLimits[$state->value] ?? 1;
    }

    /**
     * Get all turn limits.
     *
     * @return array<string, int>
     */
    public function getAllTurnLimits(): array
    {
        return $this->turnLimits;
    }

    /**
     * Clean up state turns for a session (call when session ends).
     */
    public function cleanup(string $sessionId): void
    {
        unset($this->stateTurns[$sessionId]);
    }
}
