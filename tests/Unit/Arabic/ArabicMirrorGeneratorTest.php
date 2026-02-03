<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Arabic;

use PHPUnit\Framework\TestCase;
use Sisly\Arabic\ArabicMirrorGenerator;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\LLM\LLMResponse;

class ArabicMirrorGeneratorTest extends TestCase
{
    public function test_generate_returns_translation_on_success(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('generate')->willReturn(
            LLMResponse::success('أفهم شعورك بالقلق')
        );

        $generator = new ArabicMirrorGenerator($llm);
        $result = $generator->generate('I understand you feel anxious');

        $this->assertEquals('أفهم شعورك بالقلق', $result);
    }

    public function test_generate_returns_null_when_disabled(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->expects($this->never())->method('generate');

        $generator = new ArabicMirrorGenerator($llm, 'gulf', false);
        $result = $generator->generate('Hello');

        $this->assertNull($result);
    }

    public function test_generate_returns_null_on_failure(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('generate')->willReturn(
            LLMResponse::failure('API error')
        );

        $generator = new ArabicMirrorGenerator($llm);
        $result = $generator->generate('Hello');

        $this->assertNull($result);
    }

    public function test_generate_returns_null_for_empty_input(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->expects($this->never())->method('generate');

        $generator = new ArabicMirrorGenerator($llm);

        $this->assertNull($generator->generate(''));
        $this->assertNull($generator->generate('   '));
    }

    public function test_generate_with_metadata_returns_success(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('generate')->willReturn(
            LLMResponse::success('مرحبا')
        );

        $generator = new ArabicMirrorGenerator($llm, 'gulf');
        $result = $generator->generateWithMetadata('Hello');

        $this->assertTrue($result['success']);
        $this->assertEquals('مرحبا', $result['arabic']);
        $this->assertEquals('gulf', $result['dialect']);
        $this->assertNull($result['error']);
    }

    public function test_generate_with_metadata_returns_failure_when_disabled(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);

        $generator = new ArabicMirrorGenerator($llm, 'gulf', false);
        $result = $generator->generateWithMetadata('Hello');

        $this->assertFalse($result['success']);
        $this->assertNull($result['arabic']);
        $this->assertStringContainsString('disabled', $result['error']);
    }

    public function test_generate_with_metadata_returns_failure_for_empty_input(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);

        $generator = new ArabicMirrorGenerator($llm);
        $result = $generator->generateWithMetadata('');

        $this->assertFalse($result['success']);
        $this->assertNull($result['arabic']);
        $this->assertStringContainsString('Empty', $result['error']);
    }

    public function test_generate_with_metadata_returns_failure_on_llm_error(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('generate')->willReturn(
            LLMResponse::failure('Rate limited')
        );

        $generator = new ArabicMirrorGenerator($llm);
        $result = $generator->generateWithMetadata('Hello');

        $this->assertFalse($result['success']);
        $this->assertNull($result['arabic']);
        $this->assertEquals('Rate limited', $result['error']);
    }

    public function test_set_and_get_dialect(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $generator = new ArabicMirrorGenerator($llm, 'gulf');

        $this->assertEquals('gulf', $generator->getDialect());

        $generator->setDialect('msa');
        $this->assertEquals('msa', $generator->getDialect());
    }

    public function test_set_dialect_defaults_to_gulf_for_invalid_value(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $generator = new ArabicMirrorGenerator($llm);

        $generator->setDialect('invalid');
        $this->assertEquals('gulf', $generator->getDialect());
    }

    public function test_set_and_check_enabled(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $generator = new ArabicMirrorGenerator($llm, 'gulf', true);

        $this->assertTrue($generator->isEnabled());

        $generator->setEnabled(false);
        $this->assertFalse($generator->isEnabled());
    }

    public function test_is_available_checks_llm_and_enabled(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('isAvailable')->willReturn(true);

        $generator = new ArabicMirrorGenerator($llm, 'gulf', true);
        $this->assertTrue($generator->isAvailable());

        $generator->setEnabled(false);
        $this->assertFalse($generator->isAvailable());
    }

    public function test_is_available_false_when_llm_unavailable(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('isAvailable')->willReturn(false);

        $generator = new ArabicMirrorGenerator($llm, 'gulf', true);
        $this->assertFalse($generator->isAvailable());
    }

    public function test_cleans_translation_artifacts(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('generate')->willReturn(
            LLMResponse::success('Translation: مرحبا')
        );

        $generator = new ArabicMirrorGenerator($llm);
        $result = $generator->generate('Hello');

        // Should strip "Translation:" prefix
        $this->assertEquals('مرحبا', $result);
    }

    public function test_cleans_arabic_translation_prefix(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->method('generate')->willReturn(
            LLMResponse::success('الترجمة: مرحبا')
        );

        $generator = new ArabicMirrorGenerator($llm);
        $result = $generator->generate('Hello');

        // Should strip Arabic "الترجمة:" prefix
        $this->assertEquals('مرحبا', $result);
    }

    public function test_uses_correct_temperature_for_translation(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $llm->expects($this->once())
            ->method('generate')
            ->with(
                $this->anything(),
                $this->callback(function (array $options) {
                    return isset($options['temperature']) && $options['temperature'] === 0.3;
                })
            )
            ->willReturn(LLMResponse::success('مرحبا'));

        $generator = new ArabicMirrorGenerator($llm);
        $generator->generate('Hello');
    }

    public function test_fluent_interface(): void
    {
        $llm = $this->createMock(LLMProviderInterface::class);
        $generator = new ArabicMirrorGenerator($llm);

        $result = $generator
            ->setDialect('msa')
            ->setEnabled(true);

        $this->assertSame($generator, $result);
        $this->assertEquals('msa', $generator->getDialect());
        $this->assertTrue($generator->isEnabled());
    }
}
