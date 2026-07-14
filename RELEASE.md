# Releasing

## Versioning

SemVer. The version lives in `Kilden\Client::VERSION` and the git tag
(`v0.1.0-alpha.1` style). Keep `CHANGELOG.md` in Keep-a-Changelog format.

## Steps

1. Update `Client::VERSION`, `CHANGELOG.md`.
2. `composer test && composer stan && composer test:integration` (mock
   server running, see CONTRIBUTING.md).
3. Tag and push:

   ```sh
   git tag vX.Y.Z
   git push origin main vX.Y.Z
   ```

   The release workflow creates the GitHub release. Packagist picks the tag
   up via webhook.

## Pending first-time setup (manual, needs the packagist.org account)

- Submit `https://github.com/freshworkstudio/kilden-sdk-php` on
  https://packagist.org/packages/submit as `kilden/kilden-php`, then enable
  the GitHub webhook (Packagist shows the exact steps after submit).
- `kilden/laravel` lives in `packages/laravel` of this repo. Packagist wants
  one package per repository root, so publishing it needs a read-only subtree
  split (e.g. `symplify/monorepo-split-github-action` pushing to a
  `freshworkstudio/kilden-laravel` repo) and a separate Packagist submit
  pointing at the split. Not wired up yet — do this before announcing the
  Laravel package.
