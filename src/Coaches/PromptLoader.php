<?php

declare(strict_types=1);

namespace Sisly\Coaches;

use Sisly\Enums\CoachId;
use Sisly\Enums\SessionState;
use Sisly\Exceptions\SislyException;

/**
 * Loads prompt files from the resources directory.
 *
 * Supports both package defaults and consumer overrides.
 */
class PromptLoader
{
    /**
     * @var string Base path for package prompts
     */
    private string $packagePath;

    /**
     * @var string|null Override path for consumer prompts
     */
    private ?string $overridePath;

    /**
     * @var array<string, string> Cached prompts
     */
    private array $cache = [];

    public function __construct(?string $overridePath = null)
    {
        $this->packagePath = __DIR__ . '/../../resources/prompts';
        $this->overridePath = $overridePath;
    }

    /**
     * Load a global prompt file.
     */
    public function loadGlobal(string $name): string
    {
        $cacheKey = "global:{$name}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $content = $this->loadFile("global/{$name}.md");
        $this->cache[$cacheKey] = $content;

        return $content;
    }

    /**
     * Load a coach-specific prompt file.
     */
    public function loadCoach(CoachId $coach, string $name): string
    {
        $coachDir = strtolower($coach->value);
        $cacheKey = "coach:{$coachDir}:{$name}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $content = $this->loadFile("coaches/{$coachDir}/{$name}.md");
        $this->cache[$cacheKey] = $content;

        return $content;
    }

    /**
     * Load the system prompt for a coach.
     */
    public function loadCoachSystem(CoachId $coach): string
    {
        return $this->loadCoach($coach, 'system');
    }

    /**
     * Load a state-specific prompt for a coach.
     */
    public function loadCoachState(CoachId $coach, SessionState $state): string
    {
        $stateName = $this->mapStateToPromptName($state);
        return $this->loadCoach($coach, $stateName);
    }

    /**
     * Map SessionState to prompt file name.
     */
    private function mapStateToPromptName(SessionState $state): string
    {
        return match ($state) {
            SessionState::INTAKE => 'system', // Use system prompt for intake
            SessionState::EXPLORATION => 'exploration',
            SessionState::DEEPENING => 'deepening',
            SessionState::PROBLEM_SOLVING => 'technique',
            SessionState::CLOSING => 'closing',
            SessionState::CRISIS_INTERVENTION => 'crisis', // Falls back to global
            default => 'system',
        };
    }

    /**
     * Load a file from either override path or package path.
     */
    private function loadFile(string $relativePath): string
    {
        // Try override path first
        if ($this->overridePath !== null) {
            $overrideFile = $this->overridePath . '/' . $relativePath;
            if (file_exists($overrideFile)) {
                $content = file_get_contents($overrideFile);
                if ($content !== false) {
                    return $this->parsePrompt($content);
                }
            }
        }

        // Fall back to package path
        $packageFile = $this->packagePath . '/' . $relativePath;
        if (file_exists($packageFile)) {
            $content = file_get_contents($packageFile);
            if ($content !== false) {
                return $this->parsePrompt($content);
            }
        }

        // If file doesn't exist, return empty string (allows graceful fallback)
        return '';
    }

    /**
     * Parse a prompt file, extracting content and handling metadata.
     */
    private function parsePrompt(string $content): string
    {
        // Remove YAML front matter if present
        if (str_starts_with($content, '---')) {
            $parts = preg_split('/^---\s*$/m', $content, 3);
            if ($parts !== false && count($parts) >= 3) {
                $content = $parts[2];
            }
        }

        return trim($content);
    }

    /**
     * Check if a prompt file exists.
     */
    public function exists(string $relativePath): bool
    {
        if ($this->overridePath !== null) {
            $overrideFile = $this->overridePath . '/' . $relativePath;
            if (file_exists($overrideFile)) {
                return true;
            }
        }

        $packageFile = $this->packagePath . '/' . $relativePath;
        return file_exists($packageFile);
    }

    /**
     * Clear the prompt cache.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Set a custom override path.
     */
    public function setOverridePath(?string $path): void
    {
        $this->overridePath = $path;
        $this->clearCache();
    }

    /**
     * Get available prompt files for a coach.
     *
     * @return array<string>
     */
    public function getAvailablePrompts(CoachId $coach): array
    {
        $coachDir = strtolower($coach->value);
        $prompts = [];

        // Check package path
        $packageDir = $this->packagePath . '/coaches/' . $coachDir;
        if (is_dir($packageDir)) {
            $files = glob($packageDir . '/*.md');
            if ($files !== false) {
                foreach ($files as $file) {
                    $prompts[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        return array_unique($prompts);
    }
}
