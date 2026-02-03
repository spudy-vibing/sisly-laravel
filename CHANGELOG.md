# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-02-02

### Added

#### Core Features
- **5 Specialized Coaches**: MEETLY (anxiety), VENTO (anger), LOOPY (overthinking), PRESSO (overwhelm), BOOSTLY (self-doubt)
- **Chain of Empathy (CoE)**: Proprietary 5-step reasoning framework for empathetic responses
- **Finite State Machine**: Structured conversation flow (Intake → Risk Triage → Exploration → Deepening → Problem Solving → Closing)
- **Automatic Coach Routing**: Dispatcher analyzes user messages and routes to appropriate coach

#### Safety Layer
- **Crisis Detection**: Deterministic keyword matching for suicide, self-harm, abuse, and other crisis categories
- **Crisis Resources**: Built-in emergency contacts and hotlines for all 6 GCC countries
- **Post-Response Validation**: Filters harmful content, medical advice, and directive language
- **Crisis Trap State**: Sessions detecting crisis cannot exit crisis intervention mode

#### LLM Integration
- **OpenAI Provider**: Full support for GPT-4 and GPT-4-Turbo models
- **Gemini Provider**: Google Gemini API integration
- **LLM Manager**: Automatic failover between providers with circuit breaker pattern
- **Configurable Retry Logic**: Exponential backoff with jitter for rate limiting and errors

#### Arabic Support
- **Language Detection**: Automatic detection of Arabic vs English text
- **Arabic Mirror**: Gulf dialect (Khaleeji) translations for all responses
- **Bilingual Crisis Detection**: Keywords in both English and Arabic
- **MSA Support**: Optional Modern Standard Arabic dialect

#### Session Management
- **Session Persistence**: Cache and Redis storage adapters
- **History Management**: Automatic pruning at 20 turns
- **State Tracking**: Turn counts and state transitions per session
- **TTL Configuration**: Configurable session expiration

#### Developer Experience
- **Laravel Integration**: Service provider, facade, and config publishing
- **Comprehensive Events**: SessionStarted, MessageReceived, CrisisDetected, etc.
- **Exception Hierarchy**: Typed exceptions for all error cases
- **Mock Provider**: Full testing support without LLM API calls

#### Documentation
- **README**: Quick start guide and feature overview
- **CONFIGURATION.md**: All configuration options documented
- **INTEGRATION.md**: API integration examples with Laravel
- **EXTENDING.md**: Guide for custom coaches and providers
- **CHANGELOG.md**: Version history

### Security
- Crisis detection runs before LLM calls (deterministic, not AI-dependent)
- API keys stored in environment variables only
- Post-response validation prevents harmful content
- No personal data stored in logs

### Technical Details
- PHP 8.1+ required
- Laravel 10.x and 11.x supported
- 513 automated tests with comprehensive coverage
- PSR-4 autoloading
- Strict types throughout

---

## Version History

| Version | Date | Highlights |
|---------|------|------------|
| 1.0.0 | 2026-02-02 | Initial release with MEETLY coach and full GCC support |

---

## Upgrade Guide

### From Beta to 1.0.0

If you were using a beta version:

1. Update composer dependency:
   ```bash
   composer require sisly/sisly-laravel:^1.0
   ```

2. Republish configuration:
   ```bash
   php artisan vendor:publish --tag=sisly-config --force
   ```

3. Review configuration changes in `config/sisly.php`

4. Clear caches:
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

---

## Roadmap

### 1.1.0 (Planned)
- [ ] Additional coaches: PRESSO, VENTO, LOOPY, BOOSTLY full implementations
- [ ] Coach handoff between sessions
- [ ] Analytics dashboard integration
- [ ] Webhook support for external integrations

### 1.2.0 (Planned)
- [ ] Voice input support
- [ ] Multi-language expansion (Urdu, Hindi, Tagalog for GCC expat population)
- [ ] Custom coach builder UI
- [ ] Session export/import

---

[Unreleased]: https://github.com/sisly/sisly-laravel/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sisly/sisly-laravel/releases/tag/v1.0.0
