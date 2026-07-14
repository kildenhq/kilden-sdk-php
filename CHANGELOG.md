# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-alpha.1] - 2026-07-14

### Added

- `Kilden\Client`: track/identify/alias with in-memory batching, gzip,
  bounded queue, retry with backoff and `Retry-After`, FPM-aware shutdown
  flush. Implements kilden-sdk-spec 0.1.
- `Kilden\IdentitySigner`: byte-exact HS256 identity tokens (canonical JSON,
  verified against the platform's frozen vectors).
- Feature flags via `/decide`: `isEnabled` / `getFeatureFlag` with 30s
  per-user cache, `person_properties`, `default` fallback.
- Pluggable transports: curl, stream wrappers, autodetection.
- `kilden/laravel` (packages/laravel): service provider, `Kilden` facade,
  queued delivery, `POST /kilden/identity` route, `Kilden::fake()`.

[Unreleased]: https://github.com/freshworkstudio/kilden-sdk-php/compare/v0.1.0-alpha.1...HEAD
[0.1.0-alpha.1]: https://github.com/freshworkstudio/kilden-sdk-php/releases/tag/v0.1.0-alpha.1
