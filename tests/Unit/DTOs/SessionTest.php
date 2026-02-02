<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Sisly\DTOs\ConversationTurn;
use Sisly\DTOs\GeoContext;
use Sisly\DTOs\Session;
use Sisly\DTOs\SessionPreferences;
use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;

class SessionTest extends TestCase
{
    public function test_can_create_new_session(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $this->assertEquals('test-session-id', $session->id);
        $this->assertEquals(CoachId::MEETLY, $session->coachId);
        $this->assertEquals(SessionState::INTAKE, $session->state);
        $this->assertEquals(0, $session->turnCount);
        $this->assertTrue($session->isActive);
        $this->assertEmpty($session->history);
    }

    public function test_add_turn_increments_count(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->addTurn(ConversationTurn::user('Hello'));

        $this->assertEquals(1, $session->turnCount);
        $this->assertCount(1, $session->history);
    }

    public function test_history_is_pruned_at_max_turns(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        // Add 25 turns (more than max of 20)
        for ($i = 1; $i <= 25; $i++) {
            $session->addTurn(ConversationTurn::user("Message {$i}"));
        }

        // History should be limited to 20
        $this->assertCount(20, $session->history);
        // Turn count should reflect all turns
        $this->assertEquals(25, $session->turnCount);
        // First message should be pruned (FIFO)
        $this->assertEquals('Message 6', $session->history[0]->content);
    }

    public function test_transition_to_changes_state(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->transitionTo(SessionState::EXPLORATION);

        $this->assertEquals(SessionState::EXPLORATION, $session->state);
    }

    public function test_end_deactivates_session(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->end();

        $this->assertFalse($session->isActive);
    }

    public function test_get_last_user_message(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->addTurn(ConversationTurn::user('First message'));
        $session->addTurn(ConversationTurn::assistant('Response'));
        $session->addTurn(ConversationTurn::user('Second message'));

        $this->assertEquals('Second message', $session->getLastUserMessage());
    }

    public function test_get_history_for_llm_returns_simplified_format(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE'),
        );

        $session->addTurn(ConversationTurn::user('Hello'));
        $session->addTurn(ConversationTurn::assistant('Hi there'));

        $history = $session->getHistoryForLLM();

        $this->assertEquals([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there'],
        ], $history);
    }

    public function test_to_array_and_from_array_round_trip(): void
    {
        $session = Session::create(
            id: 'test-session-id',
            coachId: CoachId::MEETLY,
            geo: new GeoContext(country: 'AE', city: 'Dubai'),
            preferences: new SessionPreferences(language: 'ar'),
        );
        $session->addTurn(ConversationTurn::user('Test message'));

        $array = $session->toArray();
        $restored = Session::fromArray($array);

        $this->assertEquals($session->id, $restored->id);
        $this->assertEquals($session->coachId, $restored->coachId);
        $this->assertEquals($session->state, $restored->state);
        $this->assertEquals($session->turnCount, $restored->turnCount);
        $this->assertEquals($session->geo->country, $restored->geo->country);
        $this->assertEquals($session->preferences->language, $restored->preferences->language);
        $this->assertCount(1, $restored->history);
    }
}
