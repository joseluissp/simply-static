# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repo is

A personal fork (`joseluissp/simply-static`, remote `upstream` = `Simply-Static/simply-static`) of the free **Simply Static** WordPress plugin (WP → static site generator). The fork's goal: re-implement the *Pro* features for personal use so WordPress sites can be migrated to static and hosted/maintained on **Cloudflare Workers** with zero extra configuration beyond pasting one Cloudflare API token. Work lives on branch `feature/cloudflare-workers-deploy` (not pushed; do not push without the owner's OK).

**Merge strategy:** `git fetch upstream && git merge upstream/master` must stay conflict-free. All new code goes in `src/cloudflare/` (own classes, own files); core files are touched only for one-line wiring (require in `src/class-ss-plugin.php`, `'cloudflare'` arg in `src/admin/inc/class-ss-admin-settings.php`) and the React admin. Prefer filters/actions over editing core classes.

Language: the owner speaks Spanish; answer in Spanish. Commits end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; one commit per feature.

## Commands

PHP (no WordPress needed; unit tests run against stubs in `tests/Support/wordpress-stubs.php`):
```bash
vendor/bin/phpunit --configuration phpunit.xml.dist                       # all (composer test)
vendor/bin/phpunit --configuration phpunit.xml.dist --filter CloudflareFeaturesTest   # one class / test name
node --test tests/worker/worker.test.mjs                                  # Cloudflare Worker (worker.js) tests
```
Expected baseline: 1 pre-existing error, `AioSeoSitemapHandlerTest` (`XSLTProcessor` missing: local PHP has no ext-xsl). PHPUnit is strict (`failOnRisky/failOnWarning`, no output during tests).

PHP versions: the plugin supports **PHP 7.4+** (`readme.txt` Requires PHP, composer platform 7.4) while wp-env runs 8.3 and the local CLI is 8.1. New code must be 7.4-compatible (no `str_contains`/`match`/`?->`/union or `mixed` types/constructor promotion/enums) and clean on 8.3 (no dynamic properties, no `null` into string built-ins). Check both without installing PHP:
```bash
docker run --rm -v "$PWD":/app -w /app php:7.4-cli vendor/bin/phpunit --configuration phpunit.xml.dist   # also php:8.3-cli
```
(Both pass: 706 tests, only the XSLT error; 11 tests skip because the slim image lacks some extensions.)

Admin React app (`src/admin`, `@wordpress/scripts`):
```bash
cd src/admin && npm run build     # rebuilds src/admin/build/ — build output IS committed
npm test                          # jest via wp-scripts (do NOT run `npx jest` directly, no babel config)
npm run lint
git checkout src/admin/package-lock.json   # npm build rewrites it; revert before committing
```
After any change under `src/admin/src/` rebuild and commit `build/index.js` + `index.asset.php`.

Local WordPress for live tests: `npx wp-env start` (`.wp-env.json`: PHP 8.3, plugin mounted from `.`, http://localhost, admin/password), then `scripts/wp-env-tune.sh` to raise PHP limits. WP-Cron fires over HTTP there. Exports can be driven through REST (`index.php?rest_route=/simplystatic/v1/...`: start-export, `is-running`, `settings`, `/cloudflare*`) using cookie + `X-WP-Nonce`.

`scripts/cloudflare-smoke.php` runs the real `Cloudflare_Client` against the live API with a throwaway Worker (`CLOUDFLARE_API_TOKEN` + `CLOUDFLARE_ACCOUNT_ID` from env, never from files). Release process: see `RELEASING.md`.

## Architecture

### Core export pipeline (upstream)
`Plugin` (`src/class-ss-plugin.php`) boots everything. An export is an `Archive_Creation_Job` (`src/class-ss-archive-creation-job.php`) running an ordered task list in the background queue (`src/background`): setup → fetch URLs → transfer files → wrap up. Task list is extensible via filter `simplystatic.archive_creation_job.task_list`; task classes are `Simply_Static\<Name>_Task` extending `Task` (`perform()` returns `true` done / `false` more work / `WP_Error`). Pages are rows in `Page` (`src/models`); `Url_Fetcher`/`Url_Extractor` crawl and rewrite URLs, `Util` maps paths (including the Hide WP folder renames). Export types: `export` (full) and `update` (core keeps the archive dir and refetches pages with `last_checked_at IS NULL` or `< last_modified_at`). Key extension points: filters `ss_export_type`, `ss_dom_before_save($dom,$url)`, `simply_static_content_before_save`, `ss_get_option_<name>`, `ss_get_options`; action `ss_before_static_export`. `Options::get` returns `null` (and skips the filter) for keys not present in the saved options.

The free repo only has stubs for Pro (`Pro_Integration::can_run()` false, React shells disabled by `isPro()`); the real Pro code is a separate closed plugin, so everything Pro-like is reimplemented here.

### Cloudflare delivery method (`src/cloudflare/`, this fork's work)
Activated when option `delivery_method = cloudflare`. Target is **Workers Static Assets via direct upload** (the whole site's asset manifest is uploaded; partial exports must never be deployed or they delete the live site — `Deploy_Cloudflare_Task::partial_export_reason()` guards this; unchanged files are never re-sent).

- `class-ss-cloudflare.php` (`Cloudflare`): central hub. Settings (`ss_cloudflare` option, defaults, wp-config constants `SIMPLY_STATIC_CF_ACCOUNT_ID/API_TOKEN/WORKER` win), `ss_cloudflare_status` (deployed worker name/host/state — the Worker name falls back to it so a site moved/cloned locally keeps updating the same Worker), `provision()` (bindings: forms D1 + email), `connect()`/`go_live()` (workers.dev first, later attach the final domain once its zone is on Cloudflare; optional `disable_workers_dev`), debounced **auto-publish** via WP-Cron (`DEBOUNCE`), REST routes under `simplystatic/v1/cloudflare*` (settings, submissions, versions, rollback), token-creation URL.
- `class-ss-cloudflare-client.php` (`Cloudflare_Client`): thin API wrapper (upload sessions, publish, zones, workers.dev toggle, email routing, versions/deployments/rollback). All HTTP goes through the overridable `request()` — tests subclass it with canned responses.
- `class-ss-deploy-cloudflare-task.php`: the task that deploys the finished archive; writes edge files + search, publishes with a `workers/message` annotation, then `Cloudflare_Changes::record_baseline()`.
- `class-ss-cloudflare-edge-files.php`: generates `_headers`/`_redirects`/404 (sent inside `assets.config`, not as uploaded files); cache rules follow Hide WP names.
- `class-ss-cloudflare-forms.php` + `worker.js`: forms handled by the Worker (`run_worker_first ['/_ss/*']`), stored in one D1 DB per site (`<worker>-forms`), Turnstile + honeypot, optional email notification (`env.EMAIL.send`) through `class-ss-cloudflare-email.php` (Email Routing on the zone, `send_email` binding, only a verified destination from an account-owned domain; needs a zone in the account). Alternatives for domains whose MX is at another provider (Email Routing would require deleting those MX), both from the owner's own mailbox with no DNS change:
    - **Hostinger Mail API** (verified live): `ss_cloudflare[smtp_user]` (the mailbox) + `ss_cloudflare[mail_api_token]` (token from hPanel → Agentic mail → API access; also constant `SIMPLY_STATIC_CF_MAIL_API_TOKEN`, never sent back to the browser); bindings `MAIL_API_TOKEN` (secret_text), `MAIL_FROM` = mailbox. `worker.js` `hostingerSend()` does `GET /api/v1/me` (mailbox id, cached) + `POST /api/v1/mailboxes/{id}/send` over plain HTTPS. The API has no Reply-To: the visitor's address is `displayName` and a mailto link in the body. Takes precedence over SMTP.
    - **SMTP**: `ss_cloudflare[smtp_host|smtp_password]` (password also via `SIMPLY_STATIC_CF_SMTP_PASSWORD`), bindings `MAIL_SMTP_HOST`, `MAIL_SMTP_PASS`; `worker.js` speaks SMTP over implicit TLS (`cloudflare:sockets`, port 465, AUTH PLAIN). **Does not work for SMTP hosts behind Cloudflare** (e.g. smtp.hostinger.com): Workers cannot open TCP connections to Cloudflare IPs (works with smtp.gmail.com). Tested with a fake socket only.
- `class-ss-cloudflare-changes.php` (`Cloudflare_Changes`): **Changes Only** mode. Baseline (`ss_cloudflare_baseline`: archive name, settings fingerprint, time) recorded after each successful publish; `prepare()` returns `update` only if `can_update()` (baseline valid, fingerprint unchanged, no `need_full` flag from menu/theme/plugin/permalink/widget changes) else `export`; `flag_changes()` marks changed posts and their dependent URLs (home, archives, feeds, sitemaps) by nulling `last_checked_at`, and removes files of unpublished/deleted posts. Rollback also forces the next export to be full.
- `class-ss-cloudflare-search.php` + `search.js`: static search — writes `ss-search/index.json`, copies `search.js`, builds a results page (`/buscar/` for es_* locales, else `/search/`) reusing the home layout, and points theme search forms (`ss_dom_before_save`) at it. Index is raw `post_content` text, so page-builder content is searched by title only.
- `class-ss-hide-wp.php` (`Hide_WP`): applies the Hide WP settings the core only stored. With Cloudflare delivery everything is on by default (master switch `ss_cloudflare[hide_wp]`): folders renamed to `assets/lib/media/vendor/theme` (only when the option is still the WP default — user-typed names win), generator/prefetch/RSD/oEmbed/emoji/comments/`ver=` stripped via `ss_dom_before_save` (priority 15), `custom_replacements` applied.
- `class-ss-cloudflare-ui.php` + `assets/ss-cloudflare-push.js` (`Cloudflare_Ui`): admin bar (removes the Pro/Studio upsell nodes, adds publish state + "Open live site" link) and the editor **Push** button (replaces the disabled Pro teaser `ss-single-push-teaser`). State = published and not modified after the last successful publish start (`Cloudflare_Changes::BASELINE['time']`): Pushed / Push / Unsaved (Gutenberg dirty) / Pushing… / Not published; REST `cloudflare/post-status` and `cloudflare/push` (publishes pending changes now, skipping the debounce).
- Admin UI: `src/admin/src/settings/components/CloudflareSettings.jsx` (versions/rollback, domain, email, search, Hide WP toggles), `SidebarSite.jsx` (Changes Only enabled when `options.cloudflare`), `pages/HideWP.jsx` (unlocked).
- `class-ss-cloudflare-redirects.php` (`Cloudflare_Redirects`): 301s for moved pages. `post_updated` remembers the old path of a published post that changed address or left "published" (`ss_cloudflare_moved`: path => post ID, capped at 1000); the filter `ss_cloudflare_redirects` turns it into `_redirects` lines pointing at the post's *current* address (chains collapse, skipped if the old path serves something again or the post is not published — deleted pages stay 404, never a redirect to the home). `Cloudflare_Edge_Files::merge()` strips the previous run's block even when no rules are left (a changes-only export keeps the archive).
- `class-ss-cloudflare-pages.php` (`Cloudflare_Pages`) + `class-ss-cloudflare-translatepress-crawler.php`: which pages are exported. REST `cloudflare/pages` lists what the page crawlers (home, post types, taxonomies, archives, authors) detect; unticked pages are stored as paths in `ss_cloudflare[excluded_urls]` (the home can never be excluded) and appended, as one regex per path, to the core option `urls_to_exclude` via `ss_get_option_urls_to_exclude`. They are part of the Changes Only fingerprint. With TranslatePress active (and Cloudflare delivery) the `Translatepress_Crawler` (loaded lazily, because the core base class is) seeds the translated URL of every page the active crawlers export, for each non-default published language: TranslatePress translates on the fly, so those pages are fetchable but nothing else announces them. `Crawlers` is loaded on demand by the core: `require_once` it before use.
- `class-ss-cloudflare-verify.php` (`Cloudflare_Verify`) + `Deploy_Cloudflare_Task::verify()`: after publish + `go_live`, requests the live home, up to 2 uploaded pages and 2 uploaded assets (retries ×3 for edge propagation). Fails only on proof (5xx, octet-stream, wrong page type, empty page) → `need_full`, rolls back to the version that was active before (`active_version()`), returns a `WP_Error`, and the baseline is NOT recorded. Doubtful results (network errors, 404/403) are warnings appended to the status message. Kill switch: filter `ss_cloudflare_verify_deploy`. `preflight()` only warns about 20k files / 100 `_headers` rules.


### Tests
`tests/Unit/*Test.php` extend `tests/Support/UnitTestCase` (`requireSource()` loads plugin files lazily; options via the stub WP env). The stubs are minimal: if code uses a WP function that is missing, polyfill it in the test file (see top of `CloudflareFeaturesTest.php`) rather than editing shared stubs. Cloudflare tests: `CloudflareEdgeAndFormsTest`, `CloudflareDeployTest`, `CloudflareFeaturesTest`, `CloudflareSafetyTest` (redirects + verify); Worker tests in `tests/worker/`.

## Gotchas
- Never write the Cloudflare token (or any secret) to files or the repo; it is passed through wp-config constants/env only. The test token used during development must be revoked by the owner.
- Cloudflare documented limits (context7 / developers.cloudflare.com): 25 MiB per file; 20,000 files per Worker version on Free, 100,000 on Paid (not pre-checked by the deploy task, Cloudflare rejects it); `_headers` max 100 rules / 2,000 chars per line (the generator does not cap user-added rules); `_redirects` 2,000 static + 100 dynamic (generator caps static at 2,000); `run_worker_first` accepts up to 100 path patterns.
- Cloudflare facts verified live: assets limit 25 MiB per file; `_headers`/`_redirects` must go in `assets.config`; version messages via `annotations['workers/message']`; rollback = `POST .../deployments` 100% to a version id; the post-deploy check detects a mistyped asset and rolls back (disposable Worker, live). Unverified live: email delivery, attaching a real custom domain, the exact token permission keys for Email Routing in `token_url()`.
- `.playwright-mcp/` is untracked scratch output; do not commit it.
