<?php

declare(strict_types=1);

namespace Sisly;

use Illuminate\Support\Str;
use Sisly\Contracts\SessionStoreInterface;
use Sisly\Dispatcher\Dispatcher;
use Sisly\Dispatcher\HandoffDetector;
use Sisly\DTOs\CoachInfo;
use Sisly\DTOs\ConversationTurn;
use Sisly\DTOs\CrisisInfo;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\DTOs\SislyResponse;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\Events\CrisisDetected;
use Sisly\Events\SessionEnded;
use Sisly\Events\SessionStarted;
use Sisly\Events\StateTransitioned;
use Sisly\Exceptions\SessionNotFoundException;
use Sisly\Exceptions\SislyException;
use Sisly\FSM\StateMachine;
use Sisly\Safety\CrisisDetector;
use Sisly\Safety\CrisisHandler;
use Sisly\Safety\PostResponseValidator;

/**
 * Main service class for Sisly emotional coaching.
 *
 * This is the primary entry point for all Sisly operations.
 */
class SislyManager
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly SessionStoreInterface $sessionStore,
        private readonly CrisisDetector $crisisDetector,
        private readonly CrisisHandler $crisisHandler,
        private readonly PostResponseValidator $responseValidator,
        private readonly StateMachine $stateMachine,
        private readonly Dispatcher $dispatcher,
        private readonly HandoffDetector $handoffDetector,
    ) {}

    /**
     * Start a new coaching session.
     *
     * @param array{geo?: GeoContext|array<string, mixed>, preferences?: SessionPreferences|array<string, mixed>, coach_id?: string} $context
     */
    public function startSession(string $message, array $context = []): SislyResponse
    {
        // Generate session ID
        $sessionId = $this->generateSessionId();

        // Parse context
        $geo = $this->resolveGeoContext($context);
        $preferences = $this->resolvePreferences($context);

        // Determine coach - use dispatcher if not explicitly provided
        $coachId = $this->resolveCoachId($context, $message);

        // Create session
        $session = Session::create(
            id: $sessionId,
            coachId: $coachId,
            geo: $geo,
            preferences: $preferences,
        );

        // Dispatch session started event
        $this->dispatchSessionStartedEvent($session);

        // Add the user message as a turn
        $session->addTurn(ConversationTurn::user($message));

        // Track turn in FSM
        $this->stateMachine->incrementStateTurns($session->id);

        // SAFETY FIRST: Check for crisis before any LLM processing
        if ($this->isCrisisDetectionEnabled()) {
            $crisisInfo = $this->crisisDetector->check($message);

            if ($crisisInfo->detected) {
                return $this->handleCrisis($session, $crisisInfo, $geo);
            }
        }

        // TODO: Phase 5 - Coach processing with CoE engine

        // For now, generate a stub response
        $responseText = $this->generateStubResponse($session, $message);

        // Validate response before sending
        $responseText = $this->validateAndSanitizeResponse($responseText, $session);

        // Add assistant response
        $session->addTurn(ConversationTurn::assistant($responseText));

        // Check if we should advance state (FSM logic)
        $previousState = $session->state;
        if ($this->stateMachine->shouldAdvance($session)) {
            $this->stateMachine->advance($session);
            $this->dispatchStateTransitionEvent($session, $previousState);
        } else {
            // For INTAKE, always advance to EXPLORATION after first turn
            if ($session->state === SessionState::INTAKE) {
                $session->transitionTo(SessionState::EXPLORATION);
                $this->stateMachine->resetStateTurns($session->id);
                $this->dispatchStateTransitionEvent($session, $previousState);
            }
        }

        // Save session
        $this->sessionStore->save($session);

        return SislyResponse::fromSession(
            session: $session,
            responseText: $responseText,
        );
    }

    /**
     * Send a message to an existing session.
     *
     * @throws SessionNotFoundException
     */
    public function message(string $sessionId, string $message): SislyResponse
    {
        // Retrieve session
        $session = $this->sessionStore->get($sessionId);

        if ($session === null) {
            throw new SessionNotFoundException($sessionId);
        }

        if (!$session->isActive) {
            throw new SislyException("Session {$sessionId} has ended.");
        }

        // Add user turn
        $session->addTurn(ConversationTurn::user($message));

        // Track turn in FSM
        $this->stateMachine->incrementStateTurns($session->id);

        // SAFETY FIRST: Check for crisis before any LLM processing
        if ($this->isCrisisDetectionEnabled()) {
            $crisisInfo = $this->crisisDetector->check($message);

            if ($crisisInfo->detected) {
                return $this->handleCrisis($session, $crisisInfo, $session->geo);
            }
        }

        // If already in crisis intervention, continue with crisis handling
        if ($session->state === SessionState::CRISIS_INTERVENTION) {
            $responseText = $this->crisisHandler->getFollowUpResponse($session, $session->geo);

            $session->addTurn(ConversationTurn::assistant($responseText));
            $this->sessionStore->save($session);

            return SislyResponse::fromSession(
                session: $session,
                responseText: $responseText,
            );
        }

        // Check for potential handoff
        $handoffResult = $this->handoffDetector->analyze($message, $session);
        $handoffSuggested = null;
        if ($handoffResult->meetsThreshold()) {
            $handoffSuggested = $handoffResult->suggestedCoach?->value;
        }

        // TODO: Phase 5 - Coach processing

        // Generate stub response
        $responseText = $this->generateStubResponse($session, $message);

        // Validate response before sending
        $responseText = $this->validateAndSanitizeResponse($responseText, $session);

        // Add assistant turn
        $session->addTurn(ConversationTurn::assistant($responseText));

        // FSM state advancement
        $previousState = $session->state;
        if ($this->stateMachine->shouldAdvance($session)) {
            $this->stateMachine->advance($session);
            $this->dispatchStateTransitionEvent($session, $previousState);
        }

        // Check if we should end the session
        $maxTurns = $this->config['fsm']['max_total_turns'] ?? 20;
        if ($session->turnCount >= $maxTurns) {
            $previousState = $session->state;
            $session->transitionTo(SessionState::CLOSING);
            $this->dispatchStateTransitionEvent($session, $previousState);
            $this->endSessionInternal($session, 'turn_limit');
        } elseif ($this->stateMachine->isTerminal($session->state)) {
            $this->endSessionInternal($session, 'natural');
        }

        // Save session
        $this->sessionStore->save($session);

        return SislyResponse::fromSession(
            session: $session,
            responseText: $responseText,
            handoffSuggested: $handoffSuggested,
        );
    }

    /**
     * Handle a crisis situation.
     */
    private function handleCrisis(Session $session, CrisisInfo $crisisInfo, GeoContext $geo): SislyResponse
    {
        // Update session with crisis info (this also transitions to CRISIS_INTERVENTION)
        $session->setCrisis($crisisInfo);

        // Generate crisis response
        $response = $this->crisisHandler->handle($crisisInfo, $geo, $session);

        // Add assistant response to history
        $session->addTurn(ConversationTurn::assistant($response->responseText));

        // Save session
        $this->sessionStore->save($session);

        // Dispatch crisis event for logging/monitoring
        $this->dispatchCrisisEvent($session, $crisisInfo, $geo);

        return $response;
    }

    /**
     * Dispatch the CrisisDetected event.
     */
    private function dispatchCrisisEvent(Session $session, CrisisInfo $crisisInfo, GeoContext $geo): void
    {
        if ($crisisInfo->severity === null || $crisisInfo->category === null) {
            return;
        }

        $event = CrisisDetected::fromDetection(
            sessionId: $session->id,
            severity: $crisisInfo->severity,
            category: $crisisInfo->category,
            keywords: $crisisInfo->keywordsMatched,
            country: $geo->country,
            resourcesProvided: true,
        );

        event($event);
    }

    /**
     * Dispatch the SessionStarted event.
     */
    private function dispatchSessionStartedEvent(Session $session): void
    {
        $event = SessionStarted::fromSession(
            sessionId: $session->id,
            coachId: $session->coachId,
            country: $session->geo->country,
            language: $session->preferences->language,
        );

        event($event);
    }

    /**
     * Dispatch the StateTransitioned event.
     */
    private function dispatchStateTransitionEvent(Session $session, SessionState $fromState): void
    {
        $event = StateTransitioned::fromTransition(
            sessionId: $session->id,
            fromState: $fromState,
            toState: $session->state,
            turnCount: $session->turnCount,
        );

        event($event);
    }

    /**
     * Internal method to end a session with reason.
     */
    private function endSessionInternal(Session $session, string $reason): void
    {
        $session->end();

        $event = SessionEnded::fromSession(
            sessionId: $session->id,
            coachId: $session->coachId,
            finalState: $session->state,
            totalTurns: $session->turnCount,
            crisisOccurred: $session->crisis->detected,
            endReason: $reason,
            startedAt: $session->createdAt,
        );

        event($event);

        // Clean up FSM state
        $this->stateMachine->cleanup($session->id);
    }

    /**
     * Validate and sanitize a response before sending.
     */
    private function validateAndSanitizeResponse(string $responseText, Session $session): string
    {
        if (!$this->isPostResponseValidationEnabled()) {
            return $responseText;
        }

        $result = $this->responseValidator->validate($responseText);

        if (!$result->valid) {
            // Log the blocked response for review
            // TODO: Add proper logging

            // Return a safe fallback
            return $this->responseValidator->getFallbackResponse($session->preferences->language);
        }

        return $responseText;
    }

    /**
     * Check if crisis detection is enabled.
     */
    private function isCrisisDetectionEnabled(): bool
    {
        return $this->config['safety']['crisis_detection'] ?? true;
    }

    /**
     * Check if post-response validation is enabled.
     */
    private function isPostResponseValidationEnabled(): bool
    {
        return $this->config['safety']['post_response_validation'] ?? true;
    }

    /**
     * Get a session by ID.
     */
    public function getSession(string $sessionId): ?Session
    {
        return $this->sessionStore->get($sessionId);
    }

    /**
     * Get the current state of a session.
     *
     * @return array{state: string, turn_count: int, is_active: bool, coach_id: string}
     * @throws SessionNotFoundException
     */
    public function getState(string $sessionId): array
    {
        $session = $this->sessionStore->get($sessionId);

        if ($session === null) {
            throw new SessionNotFoundException($sessionId);
        }

        return [
            'state' => $session->state->value,
            'turn_count' => $session->turnCount,
            'is_active' => $session->isActive,
            'coach_id' => $session->coachId->value,
        ];
    }

    /**
     * End a session.
     *
     * @throws SessionNotFoundException
     */
    public function endSession(string $sessionId): void
    {
        $session = $this->sessionStore->get($sessionId);

        if ($session === null) {
            throw new SessionNotFoundException($sessionId);
        }

        $this->endSessionInternal($session, 'manual');
        $this->sessionStore->save($session);
    }

    /**
     * Check if a session exists.
     */
    public function sessionExists(string $sessionId): bool
    {
        return $this->sessionStore->exists($sessionId);
    }

    /**
     * Get all available coaches.
     *
     * @return array<CoachInfo>
     */
    public function getCoaches(): array
    {
        $enabled = $this->config['coaches']['enabled'] ?? CoachId::values();

        return array_filter(
            CoachInfo::all(),
            fn (CoachInfo $coach) => in_array($coach->id->value, $enabled, true)
        );
    }

    /**
     * Get information about a specific coach.
     */
    public function getCoach(CoachId $coachId): CoachInfo
    {
        return CoachInfo::byId($coachId);
    }

    /**
     * Generate a unique session ID.
     */
    private function generateSessionId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Resolve GeoContext from context array.
     *
     * @param array<string, mixed> $context
     */
    private function resolveGeoContext(array $context): GeoContext
    {
        $geo = $context['geo'] ?? null;

        if ($geo instanceof GeoContext) {
            return $geo;
        }

        if (is_array($geo)) {
            return GeoContext::fromArray($geo);
        }

        // Default to UAE
        return new GeoContext(country: 'AE');
    }

    /**
     * Resolve SessionPreferences from context array.
     *
     * @param array<string, mixed> $context
     */
    private function resolvePreferences(array $context): SessionPreferences
    {
        $prefs = $context['preferences'] ?? null;

        if ($prefs instanceof SessionPreferences) {
            return $prefs;
        }

        if (is_array($prefs)) {
            return SessionPreferences::fromArray($prefs);
        }

        return new SessionPreferences();
    }

    /**
     * Resolve coach ID from context array or via dispatcher.
     *
     * @param array<string, mixed> $context
     */
    private function resolveCoachId(array $context, ?string $message = null): CoachId
    {
        $coachId = $context['coach_id'] ?? null;

        if ($coachId instanceof CoachId) {
            return $coachId;
        }

        if (is_string($coachId)) {
            return CoachId::from($coachId);
        }

        // Use dispatcher to classify if message is provided
        if ($message !== null && $this->isDispatcherEnabled()) {
            $result = $this->dispatcher->classify($message);
            if ($result->success && $result->meetsThreshold()) {
                return $result->coach;
            }
        }

        // Default coach from config
        $default = $this->config['coaches']['default'] ?? 'meetly';
        return CoachId::from($default);
    }

    /**
     * Check if dispatcher-based coach routing is enabled.
     */
    private function isDispatcherEnabled(): bool
    {
        return $this->config['dispatcher']['enabled'] ?? true;
    }

    /**
     * Generate a stub response (to be replaced with real LLM in Phase 5-6).
     */
    private function generateStubResponse(Session $session, string $message): string
    {
        $coachName = $session->coachId->displayName();

        return match ($session->state) {
            SessionState::INTAKE => "Hi, I'm {$coachName}. I hear you. Let's take a moment to understand what you're experiencing.",
            SessionState::EXPLORATION => "Thank you for sharing that. Can you tell me a bit more about what's making you feel this way?",
            SessionState::DEEPENING => "That makes sense. It sounds like you're dealing with something that matters to you.",
            SessionState::PROBLEM_SOLVING => "Let's try something together. Would you like a quick 30-second technique, or do you have a minute?",
            SessionState::CLOSING => "You've done well to take this time for yourself. Remember, it's okay to feel what you're feeling.",
            SessionState::CRISIS_INTERVENTION => "I hear that you're going through something really difficult. Your safety matters.",
            default => "I'm here with you. Tell me more.",
        };
    }
}
