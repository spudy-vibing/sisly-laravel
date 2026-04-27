<?php

declare(strict_types=1);

namespace Sisly\Tests\Unit\Prompts;

use PHPUnit\Framework\TestCase;
use Sisly\Coaches\PromptLoader;
use Sisly\Enums\CoachId;

/**
 * Regression guard: ensures the dead "Arabic Mirror" prompt sections
 * stay deleted. Language behavior is now controlled at runtime by
 * BaseCoach::buildLanguageRule(); embedded "include Arabic mirror"
 * directives in the prompts conflict with that override and must not
 * be reintroduced.
 */
class PromptsAreCleanTest extends TestCase
{
    private PromptLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loader = new PromptLoader();
    }

    public function test_global_rules_does_not_contain_arabic_mirror_section(): void
    {
        $rules = $this->loader->loadGlobal('rules');

        $this->assertStringNotContainsString('## Arabic Mirror', $rules);
        $this->assertStringNotContainsString('Arabic mirror if first response', $rules);
        $this->assertStringNotContainsString('Include one short Arabic validation line in first response', $rules);
    }

    /**
     * @return array<string, array{0: CoachId}>
     */
    public static function coachProvider(): array
    {
        return [
            'MEETLY'  => [CoachId::MEETLY],
            'VENTO'   => [CoachId::VENTO],
            'LOOPY'   => [CoachId::LOOPY],
            'PRESSO'  => [CoachId::PRESSO],
            'BOOSTLY' => [CoachId::BOOSTLY],
        ];
    }

    /**
     * @dataProvider coachProvider
     */
    public function test_coach_system_prompt_does_not_contain_arabic_mirror_examples(CoachId $coachId): void
    {
        $prompt = $this->loader->loadCoachSystem($coachId);

        $this->assertStringNotContainsString('## Arabic Mirror Examples', $prompt);
        $this->assertStringNotContainsString('First response should include one of:', $prompt);
    }
}
