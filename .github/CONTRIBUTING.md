# Contributing

## Local development

```bash
npm install && composer install
npm run env:start          # WordPress at http://localhost:8890 (admin / password), fixtures imported
```

The plugin renders the whole front end and ships one stylesheet and one script with no build step. The only other script on the page is core's `wp-hooks`, which the extension API sits on, plus whatever registered extensions bring. Keep it that way: transform and opacity animations only, no font-weight changes for state, native controls first. A new player feature is built as an extension on the standard in [docs/extending.md](../docs/extending.md) and comes with a contract test.

Fetching from YouTube needs `yt-dlp` (and `ffmpeg` for mp3) wherever WP-CLI runs. `wp callboard doctor` tells you what is available.

## Running checks

```bash
npm run lint               # PHPCS (WordPress Coding Standards) + ESLint
npm run test:e2e           # Playwright, desktop + iPhone viewport (wp-env must be running)
```

Playwright smoke tests run **before every commit**, from the hook in `.githooks` that `npm install` wires up, against your running wp-env, and the full suite runs in GitHub Actions on every pull request. The hook catches obvious problems before they reach a pull request; CI catches what a contributor's machine missed. Skip it for one commit with `CALLBOARD_SKIP_E2E=1 git commit ...`, or skip every hook with `--no-verify`. CI also runs PHPCS, ESLint, the spell check, and a lint of the workflow files. Plugin Check runs by hand from its workflow page.

## Pull requests

1. Branch off `main`.
2. Title the pull request as a [Conventional Commit](https://www.conventionalcommits.org/). Pull requests squash-merge with the title as the commit subject, so the title is what release-please reads. `lint-pr.yml` enforces this.
3. Fill in the template. The line at the top saying what we would miss by only reading the diff is the part that matters most.
4. Every pull request gets a sticky comment with a WordPress Playground link built from its own commit. Use it to check the change on a phone.

## What runs itself

The repository is meant to be left alone for long stretches, so the routine work is automated and gated on the same checks a pull request gets:

- **Dependencies.** Dependabot opens one grouped pull request per ecosystem each month for minor and patch bumps, and `dependabot-auto-merge.yml` merges it once every required check is green. Major bumps open on their own and wait for a person. Security updates arrive as soon as an advisory does.
- **Required checks.** `main` is protected behind the single `CI status` check: PHPCS, ESLint, the spell check, and the Playwright suite against wp-env. Plugin Check runs by hand from its workflow page.
- **Weekly run.** CI runs the full suite every Monday against current WordPress core, so a core release that breaks something shows up as a failed run in your inbox rather than in production. The release and Playground workflows run weekly too, because a commit produced by a self-merging Dependabot pull request does not start push workflows on its own.
- **Version strings.** `scripts/sync-versions.sh` writes the version everywhere WordPress reads it and sets `Tested up to` from the core version the suite just ran against, so neither goes stale.

## Translations

`languages/callboard.pot` is the template translators start from. Regenerate it with `npm run pot` (wp-env must be running) when strings change, and commit it.

## Releases

Releases are automated by [release-please](https://github.com/googleapis/release-please). It reads the merged pull request titles on `main` to decide the next version and write `CHANGELOG.md`:

- `feat: ...` → minor bump
- `fix: ...` → patch bump
- `feat!: ...` or a `BREAKING CHANGE:` footer → major bump
- `chore:`, `docs:`, `refactor:`, `test:`, `ci:`, `build:`, `style:` → no version bump

release-please keeps a release pull request open on `main`. Merging it tags the release, publishes a GitHub Release, and attaches `callboard.zip` built from `.distignore`.

<details>
<summary>Keeping the version numbers in step</summary>

`scripts/sync-versions.sh` reads the version from `.release-please-manifest.json` and updates the plugin header `Version:`, the `CALLBOARD_VERSION` constant, `readme.txt`'s `Stable tag:`, and `package.json`. The release-please workflow runs it on every release pull request; you can run it locally too:

```bash
bash scripts/sync-versions.sh
```

</details>

<details>
<summary>How the release pull request gets its checks</summary>

release-please opens its pull request with the workflow's own `GITHUB_TOKEN`. GitHub holds the CI runs for a pull request opened that way until someone approves them, so after syncing the version strings the release workflow approves them itself. CI then runs the full suite on the exact commit that will be tagged, and the protected `main` branch reads those runs. The title lint does not fire for bot-authored pull requests, so it is not a required check; release-please writes a conventional title anyway. No personal access token is involved, so nothing expires.

</details>
