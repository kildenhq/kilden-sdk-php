# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-alpha.5] - 2026-07-16

### Fixed

- `Client::VERSION` reports the release it actually ships in. alpha.3 and
  alpha.4 both went out declaring `0.1.0-alpha.2`, so their User-Agent
  (spec §4.1) misidentified them to ingest and the two were indistinguishable
  in the logs. Those artifacts keep the wrong value — tags are immutable — so
  this is the first release since alpha.2 to report itself honestly.
- The release refuses to publish when the tag and `Client::VERSION` disagree,
  which is what allowed the above to happen twice unnoticed: composer.json
  carries no version, so nothing compared the constant to anything.
- A prerelease is no longer published as GitHub's "Latest" release. The flag
  was never set, so alpha.4 was presented as the current release of the SDK.

## [0.1.0-alpha.4] - 2026-07-15

Released without a changelog entry; reconstructed here.

### Fixed

- Laravel: frontend host override for split server/browser ingest.

### Documentation

- Laravel: `@kildenScript` resolves the identity route by name.

## [0.1.0-alpha.3] - 2026-07-15

Released without a changelog entry; reconstructed here.

### Added

- Laravel: `@kildenScript` renders the web SDK loader.

### Fixed

- `IdentitySigner` escapes the JS line separators U+2028/U+2029 the way Go's
  `encoding/json` does (spec §6.1). Affects byte-identity with the frozen
  vectors and the other SDKs; tokens from earlier releases still verify.

### Changed

- Repository moved to the kildenhq org; `packages/laravel` is subtree-split to
  kildenhq/kilden-laravel.

## [0.1.0-alpha.2] - 2026-07-14

### Fixed

- Any 2xx from `/capture` is success; the response body is never parsed
  (spec clarification — a 200 with a corrupt body was retried before).

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

[Unreleased]: https://github.com/kildenhq/kilden-sdk-php/compare/v0.1.0-alpha.5...HEAD
[0.1.0-alpha.5]: https://github.com/kildenhq/kilden-sdk-php/compare/v0.1.0-alpha.4...v0.1.0-alpha.5
[0.1.0-alpha.4]: https://github.com/kildenhq/kilden-sdk-php/compare/v0.1.0-alpha.3...v0.1.0-alpha.4
[0.1.0-alpha.3]: https://github.com/kildenhq/kilden-sdk-php/compare/v0.1.0-alpha.2...v0.1.0-alpha.3
[0.1.0-alpha.2]: https://github.com/kildenhq/kilden-sdk-php/compare/v0.1.0-alpha.1...v0.1.0-alpha.2
[0.1.0-alpha.1]: https://github.com/kildenhq/kilden-sdk-php/releases/tag/v0.1.0-alpha.1
