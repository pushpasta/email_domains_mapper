# AGENTS.md

## Scope
Roundcube plugin `email_domains_mapper`. Repo root is the plugin folder (installed as `plugins/email_domains_mapper` in Roundcube).

## Working directory boundary
- ALWAYS stay inside this project directory. Never run commands that read/write outside it.
- If a temp dir is needed, create it under `./.tmp/` in this project (e.g. `./.tmp/task-name/`).
- Delete any temp dirs you created when done. NEVER delete `.tmp` itself.

## Workflow
- Read the Roundcube Plugin API docs before plugin-architecture changes: https://github.com/roundcube/roundcubemail/wiki/Plugin-API
- Run `php -l <file>` on every PHP file you touch.
- Verify the change works (tests, lint) before committing.

## Git hooks
- Hooks live in `.githooks/` (`pre-commit`, `pre-push`, `commit-msg`) and are activated via `git config core.hooksPath .githooks`.
- Run `composer hooks:install` to activate (also runs automatically on `composer install` / `composer update`).
- `pre-commit` runs `php -l` on staged PHP files; `pre-push` runs it on all tracked PHP files and `config.inc.php.dist`.
- `commit-msg` enforces a title ≤ 60 chars; a missing/unknown `<type>:` prefix only warns for now (flip to blocking later), lowercase summary, no trailing period.

## Commits
- Commits are allowed after each change is verified; commit step by step, not one giant commit.
- Use Conventional Commits: `<type>: <summary>` — types: `feat`, `fix`, `docs`, `test`, `chore`, `ci`, `refactor`, `perf`, `build`.
- Summary is imperative, lowercase, no trailing period.
- Never say "updated/changed X" for something that never existed in a commit before — the first commit of a file is `add`, e.g. `test: add unit tests for domain mapping`.
- Do not commit unrelated changes together; stage only what belongs to the step.

## Conventions
- Folder name = main file name = class name (`email_domains_mapper.php` / `class email_domains_mapper extends rcube_plugin`).
- `composer.json` type stays `roundcube-plugin`; keep `autoload.classmap` in sync with new files.
- No localization: user-facing strings are hardcoded (single string, not worth i18n).
- No comments in code unless asked.

## Changelog
- `CHANGELOG.md` (Keep a Changelog format) is the source of truth for release notes.
- Every user-visible change (feat, fix, security, deprecation, removal) gets an entry under `## [Unreleased]` (Added/Changed/Deprecated/Removed/Fixed/Security). Omit empty categories.
- Never edit a released version section; append new changes to `[Unreleased]`.
- At release: move `[Unreleased]` entries to `## [x.y.z] - YYYY-MM-DD`, bump `email_domains_mapper::VERSION`, commit `chore: release x.y.z`, create annotated tag `vX.Y.Z`, push with `--follow-tags`. The release workflow verifies the tag matches the plugin version and that `CHANGELOG.md` has a matching section.
- `CHANGELOG.md` ships in release archives: keep it out of `.distignore`/`.gitattributes`.

## Quality checks
- `composer test` runs the PHPUnit suite; `composer cs` checks coding style; `composer phpstan` runs static analysis against Roundcube 1.7.
- `composer check` runs cs + phpstan + test in sequence.
- `composer validate` checks `composer.json` (CI runs `--strict`).
- CI also runs `actionlint` on workflow files and verifies packaging sync (`packaging.yml`).
- Run `php -l <file>` on every PHP file touched (enforced by git hooks).

## Releasing
- Use annotated tags `vX.Y.Z` matching `email_domains_mapper::VERSION`.
- Prefer a clean working tree on `main`; run `composer check` before tagging.
- Packagist syncs automatically once the repo is public and registered; no manual version push needed.
- See `.github/workflows/release.yml` for the automated build/test/publish steps (dry-run via `workflow_dispatch`).
