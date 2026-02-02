<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | LLM Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the primary and fallback LLM providers. The system will
    | automatically fail over to the fallback provider if the primary fails.
    |
    */
    'llm' => [
        'primary' => [
            'provider' => env('SISLY_LLM_PRIMARY', 'openai'),
            'model' => env('SISLY_LLM_MODEL', 'gpt-4-turbo'),
            'api_key' => env('OPENAI_API_KEY'),
        ],
        'fallback' => [
            'provider' => env('SISLY_LLM_FALLBACK', 'gemini'),
            'model' => env('SISLY_LLM_FALLBACK_MODEL', 'gemini-pro'),
            'api_key' => env('GEMINI_API_KEY'),
        ],
        'timeout' => env('SISLY_LLM_TIMEOUT', 30),
        'retry_attempts' => env('SISLY_LLM_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | FSM (Finite State Machine) Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the state machine behavior including maximum turns and
    | turn limits per state.
    |
    */
    'fsm' => [
        'max_total_turns' => 20,
        'turn_limits' => [
            'intake' => 1,
            'risk_triage' => 0,
            'exploration' => 2,
            'deepening' => 1,
            'problem_solving' => 3,
            'closing' => 1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how sessions are stored. The default uses Laravel's cache
    | system, but Redis can be used for production workloads.
    |
    | Supported drivers: "cache", "redis"
    |
    */
    'session' => [
        'driver' => env('SISLY_SESSION_DRIVER', 'cache'),
        'prefix' => 'sisly:session:',
        'ttl' => 1800, // 30 minutes in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Coach Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which coaches are enabled and the default coach to use
    | when no specific coach is requested.
    |
    */
    'coaches' => [
        'default' => 'meetly',
        'enabled' => ['meetly', 'vento', 'loopy', 'presso', 'boostly'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Safety Configuration
    |--------------------------------------------------------------------------
    |
    | Configure safety features including crisis detection and post-response
    | validation. These settings are critical for user safety.
    |
    | WARNING: Do not disable crisis_detection in production!
    |
    */
    'safety' => [
        'crisis_detection' => true,
        'crisis_lexicon_path' => null, // Use package default if null
        'post_response_validation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Arabic/Bilingual Configuration
    |--------------------------------------------------------------------------
    |
    | Configure Arabic language support for GCC users. When enabled, responses
    | can include an Arabic "mirror" translation.
    |
    */
    'arabic' => [
        'enabled' => true,
        'dialect' => 'gulf', // "gulf" (Khaleeji) or "msa" (Modern Standard Arabic)
        'mirror_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Crisis Resources Configuration
    |--------------------------------------------------------------------------
    |
    | Configure crisis resources (hotlines, emergency contacts) for different
    | countries. The package includes GCC defaults.
    |
    */
    'crisis_resources' => [
        'use_package_defaults' => true, // Use built-in GCC resources
        'custom_path' => null,          // Path to custom crisis resources JSON
    ],
];
