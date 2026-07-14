# Contributing

Behavior in this SDK is governed by
[kilden-sdk-spec](https://github.com/freshworkstudio/kilden-sdk-spec): the
spec document, the frozen test vectors, and the mock capture server the
integration suite runs against. **A PR that changes behavior without a
matching spec change will be rejected** — five SDKs stay identical only if
the spec moves first. Bug fixes toward spec compliance are always welcome.

## Setup

```sh
composer install
composer test          # unit suite
composer stan          # PHPStan, level max, PHP 7.4 target
```

The integration suite needs the spec repo's mock server:

```sh
git clone https://github.com/freshworkstudio/kilden-sdk-spec ../kilden-sdk-spec
(cd ../kilden-sdk-spec/mockserver && go run . -addr :8091) &
composer test:integration
```

## Constraints that are not up for debate

- **PHP 7.4 floor, zero runtime dependencies** in the core. No enums, no
  `match`, no constructor promotion, no union types. PHPStan at level max is
  the compensation.
- Contract 1: nothing throws after construction. If your change can throw in
  the hot path, it is wrong.
- `IdentitySigner` output is byte-frozen. If the signer vectors fail, the
  change is a spec problem, not a formatting preference.

The Laravel package (`packages/laravel`, PHP 8.2+) lives in this repo and
follows the same rules, minus the 7.4 floor.

## Questions

[Discussions](https://github.com/freshworkstudio/kilden-sdk-php/discussions),
please — answers there stay searchable.
