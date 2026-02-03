<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\LLM\Providers;

use Illuminate\Support\Facades\Http;
use Sisly\LLM\Providers\OpenAIProvider;
use Sisly\Tests\TestCase;

class OpenAIProviderTest extends TestCase
{
    private OpenAIProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new OpenAIProvider([
            'api_key' => 'test-api-key',
            'model' => 'gpt-4-turbo',
            'timeout' => 30,
            'max_retries' => 1, // Reduce retries for faster tests
        ]);
    }

    public function test_get_name_returns_openai(): void
    {
        $this->assertEquals('openai', $this->provider->getName());
    }

    public function test_is_available_returns_true_when_api_key_set(): void
    {
        $this->assertTrue($this->provider->isAvailable());
    }

    public function test_is_available_returns_false_when_api_key_empty(): void
    {
        $provider = new OpenAIProvider(['api_key' => '']);
        $this->assertFalse($provider->isAvailable());
    }

    public function test_generate_returns_failure_when_not_configured(): void
    {
        $provider = new OpenAIProvider(['api_key' => '']);
        $response = $provider->generate('Hello');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('not configured', $response->error);
    }

    public function test_generate_sends_correct_request(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => 'Test response'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
                'model' => 'gpt-4-turbo',
            ], 200),
        ]);

        $response = $this->provider->generate('Hello');

        $this->assertTrue($response->success);
        $this->assertEquals('Test response', $response->content);
        $this->assertEquals(10, $response->promptTokens);
        $this->assertEquals(5, $response->completionTokens);
        $this->assertEquals('gpt-4-turbo', $response->model);
    }

    public function test_chat_sends_messages_with_system_prompt(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Response'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
                'model' => 'gpt-4-turbo',
            ], 200),
        ]);

        $messages = [
            ['role' => 'user', 'content' => 'I am anxious'],
        ];

        $response = $this->provider->chat($messages, 'You are a coach');

        $this->assertTrue($response->success);

        // Verify request was made with correct format
        Http::assertSent(function ($request) {
            $body = $request->data();
            return isset($body['messages']) &&
                   $body['messages'][0]['role'] === 'system' &&
                   $body['messages'][0]['content'] === 'You are a coach';
        });
    }

    public function test_handles_rate_limiting(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Rate limited']], 429)
                ->push([
                    'choices' => [['message' => ['content' => 'Success']]],
                    'model' => 'gpt-4-turbo',
                ], 200),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-key',
            'max_retries' => 2,
            'retry_delay' => 10,
        ]);

        $response = $provider->generate('Test');

        $this->assertTrue($response->success);
        $this->assertEquals('Success', $response->content);
    }

    public function test_handles_server_error_with_retry(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Server error']], 500)
                ->push([
                    'choices' => [['message' => ['content' => 'Success']]],
                    'model' => 'gpt-4-turbo',
                ], 200),
        ]);

        $provider = new OpenAIProvider([
            'api_key' => 'test-key',
            'max_retries' => 2,
            'retry_delay' => 10,
        ]);

        $response = $provider->generate('Test');

        $this->assertTrue($response->success);
    }

    public function test_handles_client_error_without_retry(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $response = $this->provider->generate('Test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('Invalid API key', $response->error);
    }

    public function test_handles_empty_choices(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [],
                'model' => 'gpt-4-turbo',
            ], 200),
        ]);

        $response = $this->provider->generate('Test');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('no choices', $response->error);
    }

    public function test_respects_temperature_option(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Response']]],
                'model' => 'gpt-4-turbo',
            ], 200),
        ]);

        $this->provider->generate('Test', ['temperature' => 0.0]);

        Http::assertSent(function ($request) {
            return $request->data()['temperature'] === 0.0;
        });
    }

    public function test_respects_max_tokens_option(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Response']]],
                'model' => 'gpt-4-turbo',
            ], 200),
        ]);

        $this->provider->generate('Test', ['max_tokens' => 500]);

        Http::assertSent(function ($request) {
            return $request->data()['max_tokens'] === 500;
        });
    }

    public function test_get_model_returns_configured_model(): void
    {
        $this->assertEquals('gpt-4-turbo', $this->provider->getModel());
    }

    public function test_set_api_key_updates_key(): void
    {
        $provider = new OpenAIProvider(['api_key' => '']);
        $this->assertFalse($provider->isAvailable());

        $provider->setApiKey('new-key');
        $this->assertTrue($provider->isAvailable());
    }
}
