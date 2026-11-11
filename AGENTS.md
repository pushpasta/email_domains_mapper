# AGENTS.md

Guidance for AI coding agents and contributors working on the Roundcube **`email_domains_mapper`** plugin. Keep it accurate: this file is the shared source of truth for how the project is built, tested, and released.

## Project

- Roundcube plugin `email_domains_mapper`.
- The repo root **is** the plugin folder; it installs as `plugins/email_domains_mapper` in Roundcube.
- Folder name = main file name = class name: `email_domains_mapper.php` / `class email_domains_mapper extends rcube_plugin`.

## Working rules

- Stay inside this project directory. Never run commands that read or write outside it.
- Put temporary files in `./.tmp/` (for example `./.tmp/<task>/`).
- Delete any temp dirs you create when done. Never delete `.tmp` itself.

## Workflow

- Read the [Roundcube Plugin API](https://github.com/roundcube/roundcubemail/wiki/Plugin-API) docs before plugin-architecture changes.
- Run `php -l <file>` on every PHP file you touch.
- Verify the change works (tests, lint) before committing.

## Git hooks

Hooks live in `.githooks/` and are activated via `git config core.hooksPath .githooks`.

- `composer hooks:install` activates them (also runs automatically on `composer install` / `composer update`).
- `pre-commit` — `php -l` on staged PHP files.
- `pre-push` — `php -l` on all tracked PHP files and `config.inc.php.dist`.
- `commit-msg` — enforces a title ≤ 60 chars, a lowercase summary, and no trailing period. A missing or unknown `<type>:` prefix currently warns only; it will become blocking.

## Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/): `<type>: <summary>`.
- Types: `feat`, `fix`, `docs`, `test`, `chore`, `ci`, `refactor`, `perf`, `build`.
- Summary is imperative and lowercase, with no trailing period.
- A file's first commit is `add`, not "update"/"change" — e.g. `test: add unit tests for domain mapping`.
- Commit step by step; stage only what belongs to the step, never unrelated changes.

## Conventions

- `composer.json` `type` stays `roundcube-plugin`; keep `autoload.classmap` in sync with new files.
- No localization: user-facing strings are hardcoded (single string, not worth i18n).
- No code comments unless asked.

## Changelog

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/) and is the source of truth for release notes.

- Every user-visible change (feat, fix, security, deprecation, removal) gets an `## [Unreleased]` entry under Added/Changed/Deprecated/Removed/Fixed/Security. Omit empty categories.
- Never edit a released version section; append new changes to `[Unreleased]`.
- At release: move `[Unreleased]` entries to `## [x.y.z] - YYYY-MM-DD`, bump `email_domains_mapper::VERSION`, commit `chore: release x.y.z`, create annotated tag `vX.Y.Z`, push with `--follow-tags`. The release workflow verifies the tag matches the plugin version and that `CHANGELOG.md` has a matching section.
- `CHANGELOG.md` ships in release archives: keep it out of `.distignore` / `.gitattributes`.

## Quality checks

- `composer test` — PHPUnit suite.
- `composer cs` — coding style.
- `composer phpstan` — static analysis against Roundcube 1.7.
- `composer check` — `cs` + `phpstan` + `test` in sequence.
- `composer validate` — validates `composer.json` (CI runs it with `--strict`).
- CI also runs `actionlint` on workflow files and verifies packaging sync (`packaging.yml`).
- `php -l <file>` on every touched PHP file (also enforced by the git hooks).

## Releasing

- Tag an annotated `vX.Y.Z` matching `email_domains_mapper::VERSION`, **or** run the `Release` workflow manually (`workflow_dispatch`, with optional `version`, `prerelease`, and `dry_run` inputs). Manual runs create the GitHub release and tag without a local git tag.
- Prefer a clean working tree on `main`; run `composer check` before releasing.
- Packagist syncs automatically from this public repo: register the package once and no manual version push is needed.
- See `.github/workflows/release.yml` for the automated build/test/publish steps (dry-run via `workflow_dispatch` with `dry_run: true`).
- Manual installs: archives are built and attached for non-Composer installs. To go Composer-only, remove the archive build/verify steps and the `files:` entries from `release.yml`.
