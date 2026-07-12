# Releasing

This package (`oblodai/sdk`) is published to **Packagist** by CI when a `v*` tag is pushed.

## Setup (one-time)
**No CI secret needed.** Register the package once on Packagist and enable the GitHub webhook / Packagist app — every push/tag then auto-syncs.

## Cut a release
1. Register `oblodai/sdk` on https://packagist.org (one-time) and enable auto-update.
2. Bump nothing in `composer.json` (version comes from the git tag).
3. `git tag vX.Y.Z && git push origin vX.Y.Z` — Packagist picks up the new version; the **Release** workflow also creates a GitHub Release.

CI (build + tests) runs on every push and pull request via `.github/workflows/ci.yml`.
