# Releasing

This package (`oblodai/sdk`) is published to **Packagist** by CI when a `v*` tag is pushed.

**The version bump is scripted for the whole SDK family.** From the backend checkout,
`tools/sdkgen/release.sh X.Y.Z` raises the version in all eight SDKs (manifest, version constant,
lock files, the install lines of the READMEs), closes the `## [X.Y.Z] — Unreleased` (or
`## [Unreleased]`) section of every `CHANGELOG.md` with today's date, commits and tags `vX.Y.Z`
locally; `-n` only checks. Pushing the tag — the step that publishes — stays manual.

## Setup (one-time)
**No CI secret needed.** Register the package once on Packagist and enable the GitHub webhook / Packagist app — every push/tag then auto-syncs.

## Cut a release
1. Register `oblodai/sdk` on https://packagist.org (one-time) and enable auto-update.
2. Bump nothing in `composer.json` (version comes from the git tag).
3. `git tag vX.Y.Z && git push origin vX.Y.Z` — Packagist picks up the new version; the **Release** workflow also creates a GitHub Release.

CI (build + tests) runs on every push and pull request via `.github/workflows/ci.yml`.
