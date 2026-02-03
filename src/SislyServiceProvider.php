<?php

declare(strict_types=1);

namespace Sisly;

use Illuminate\Support\ServiceProvider;
use Sisly\Coaches\CoachRegistry;
use Sisly\Coaches\PromptLoader;
use Sisly\Contracts\LLMProviderInterface;
use Sisly\Contracts\SessionStoreInterface;
use Sisly\Dispatcher\Dispatcher;
use Sisly\Dispatcher\HandoffDetector;
use Sisly\FSM\StateMachine;
use Sisly\LLM\MockProvider;
use Sisly\Safety\CrisisDetector;
use Sisly\Safety\CrisisHandler;
use Sisly\Safety\CrisisResourceProvider;
use Sisly\Safety\PostResponseValidator;
use Sisly\Session\Adapters\LaravelCacheAdapter;

class SislyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/sisly.php',
            'sisly'
        );

        // Register session store
        $this->registerSessionStore();

        // Register safety components
        $this->registerSafetyComponents();

        // Register FSM and Dispatcher
        $this->registerFSMComponents();

        // Register main manager
        $this->app->singleton(SislyManager::class, function ($app) {
            return new SislyManager(
                config: $app['config']->get('sisly'),
                sessionStore: $app->make(SessionStoreInterface::class),
                crisisDetector: $app->make(CrisisDetector::class),
                crisisHandler: $app->make(CrisisHandler::class),
                responseValidator: $app->make(PostResponseValidator::class),
                stateMachine: $app->make(StateMachine::class),
                dispatcher: $app->make(Dispatcher::class),
                handoffDetector: $app->make(HandoffDetector::class),
                coachRegistry: $app->make(CoachRegistry::class),
            );
        });

        $this->app->alias(SislyManager::class, 'sisly');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sisly.php' => config_path('sisly.php'),
            ], 'sisly-config');

            $this->publishes([
                __DIR__ . '/../resources/data' => resource_path('sisly/data'),
            ], 'sisly-data');

            $this->publishes([
                __DIR__ . '/../resources/prompts' => resource_path('sisly/prompts'),
            ], 'sisly-prompts');
        }
    }

    /**
     * Register the session store based on config.
     */
    protected function registerSessionStore(): void
    {
        $this->app->singleton(SessionStoreInterface::class, function ($app) {
            $driver = $app['config']->get('sisly.session.driver', 'cache');
            $config = $app['config']->get('sisly.session', []);

            return match ($driver) {
                'cache' => new LaravelCacheAdapter($config),
                'redis' => new Session\Adapters\RedisAdapter($config),
                default => new LaravelCacheAdapter($config),
            };
        });
    }

    /**
     * Register safety layer components.
     */
    protected function registerSafetyComponents(): void
    {
        // Crisis Detector
        $this->app->singleton(CrisisDetector::class, function ($app) {
            $customPath = $app['config']->get('sisly.safety.crisis_lexicon_path');

            if ($customPath !== null && file_exists($customPath)) {
                $lexicon = json_decode(file_get_contents($customPath), true);
                return new CrisisDetector($lexicon);
            }

            return new CrisisDetector();
        });

        // Crisis Resource Provider
        $this->app->singleton(CrisisResourceProvider::class, function ($app) {
            $useDefaults = $app['config']->get('sisly.crisis_resources.use_package_defaults', true);
            $customPath = $app['config']->get('sisly.crisis_resources.custom_path');

            if (!$useDefaults && $customPath !== null && file_exists($customPath)) {
                $resources = json_decode(file_get_contents($customPath), true);
                return new CrisisResourceProvider($resources);
            }

            return new CrisisResourceProvider();
        });

        // Crisis Handler
        $this->app->singleton(CrisisHandler::class, function ($app) {
            return new CrisisHandler(
                resourceProvider: $app->make(CrisisResourceProvider::class),
            );
        });

        // Post Response Validator
        $this->app->singleton(PostResponseValidator::class, function ($app) {
            return new PostResponseValidator();
        });
    }

    /**
     * Register FSM and Dispatcher components.
     */
    protected function registerFSMComponents(): void
    {
        // State Machine
        $this->app->singleton(StateMachine::class, function ($app) {
            return new StateMachine(
                $app['config']->get('sisly.fsm', [])
            );
        });

        // LLM Provider (MockProvider for testing, real providers in Phase 6)
        $this->app->singleton(LLMProviderInterface::class, function ($app) {
            // TODO: Phase 6 - Add OpenAI and Gemini providers
            return new MockProvider();
        });

        // Prompt Loader
        $this->app->singleton(PromptLoader::class, function ($app) {
            $overridePath = $app['config']->get('sisly.prompts.override_path');
            return new PromptLoader($overridePath);
        });

        // Coach Registry
        $this->app->singleton(CoachRegistry::class, function ($app) {
            return new CoachRegistry(
                llm: $app->make(LLMProviderInterface::class),
                promptLoader: $app->make(PromptLoader::class),
                enabledCoaches: $app['config']->get('sisly.coaches.enabled', []),
            );
        });

        // Dispatcher
        $this->app->singleton(Dispatcher::class, function ($app) {
            return new Dispatcher(
                llm: $app->make(LLMProviderInterface::class),
                config: [
                    'enabled_coaches' => $app['config']->get('sisly.coaches.enabled'),
                    'default_coach' => $app['config']->get('sisly.coaches.default', 'meetly'),
                ],
            );
        });

        // Handoff Detector
        $this->app->singleton(HandoffDetector::class, function ($app) {
            return new HandoffDetector();
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            SislyManager::class,
            'sisly',
            SessionStoreInterface::class,
            CrisisDetector::class,
            CrisisResourceProvider::class,
            CrisisHandler::class,
            PostResponseValidator::class,
            StateMachine::class,
            LLMProviderInterface::class,
            PromptLoader::class,
            CoachRegistry::class,
            Dispatcher::class,
            HandoffDetector::class,
        ];
    }
}
