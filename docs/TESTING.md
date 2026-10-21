# Testing SEO Pilot 0.3.0

## 1. What was actually run (automated)

| Suite | Count | Where it ran | Command |
|---|---|---|---|
| Unit tests (pure logic: Arabic/Latin text, parser, diff, intent, audit engine, similarity, content gap, link insertion, criteria, CSV) | 209 | PHP 8.3, no WordPress | `php tests/run-unit.php` |
| Integration tests | 391 | Real WordPress (master, 7.2-alpha) + PHP 8.3 + **SQLite**, real PHP web server for the live-output check, AI provider **mocked** | see below |
| PHPCS `WordPress-Extra`, security sniffs only (output escaping, nonces, input sanitising, SQL preparation, i18n) | 0 findings | PHPCS + WPCS from GitHub | see below |
| `php -l` on every file | clean | PHP 8.3 | |

Integration tests cover: activation and tables; settings validation and API-key handling (encryption, no autoload, never printed, never in errors); rate limiting; migration from 0.1.0; every AI error path (401/404/429/5xx/timeout/empty/cut-off/temperature retry); prompt limits and anti-fabrication rules; the validator (stripped invented links, flagged invented figures, rejected non-JSON); audit on real posts incl. Arabic JSON round-trip; duplicates and cannibalization; Yoast/AIOSEO ownership; front-end output; block-content edits (real block parser); every form handler with permission, bad-nonce and logged-out cases; confirmation gates for live pages and third-party fields; revisions, history, re-audit; conflict-aware rollback; forged-history refusal; draft-copy mode; stale suggestions; alt text; advisory items; legacy MVP form actions; rendering of every admin screen without PHP notices; XSS escaping; RTL markup; real-HTTP live-output check; uninstall. **Phase 2:** database upgrade 1→2; CSV parsing (BOM, delimiters, decimal commas, Arabic, localized-header rejection, bad encoding); import counting, foreign-host and non-existent-post rows, latest-import precedence, delete; verified totals/top queries/gaps/cannibalization; similar-content detection on real posts incl. cache refresh; local analysis (no AI request, no content change, noindex/unpublished targets excluded); internal-link edit safety, apply, rollback, refusal when the target was unpublished or the paragraph edited; report aggregation and CSV formula neutralisation; new screens (escaping, no PHP notices); import/export permission and nonce guards.

### Running the integration tests
Use a **throwaway** WordPress site (the tests create posts, and the last test runs the uninstall routine with data deletion):
```
# plugin installed at wp-content/plugins/seo-pilot, WP_DEBUG_LOG on
SEO_PILOT_WP_LOAD=/path/to/wp-load.php php tests/integration/run.php
```
The live-output tests need the site reachable over HTTP at its own `home_url()` (they print SKIPPED otherwise). They also use a test-only mu-plugin that prints a second meta description: copy `tests/integration/fixtures/sp-test-dup.php` into the test site's `wp-content/mu-plugins/` (never into a real site).
Security-focused coding-standards check:
```
phpcs --standard=WordPress-Extra --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery,WordPress.WP.I18n .
```
Not clean: the full WordPress-Extra formatting rules (the code uses 4-space indentation, as the 0.1.0 MVP did, instead of tabs).

## 2. NOT tested: needs a real environment (manual checklist)

Tick each item on a staging site.

**Environment**
- [ ] MySQL/MariaDB (all automated runs used SQLite): activation creates both tables; upgrade from 0.1.0 works; uninstall with data deletion drops them.
- [ ] A stable WordPress release (6.0, and the current release) and PHP 7.4 (static check only; never executed on 7.4).
- [ ] Multisite network activation (untested; single site only).
- [ ] Site with 300+ published posts: audit completes within the host's time limit; Overview and list stay responsive.
- [ ] Object cache (Redis/Memcached) enabled: recommendations, history and audit results behave.

**AI provider (the mock was used in all automated tests)**
- [ ] A real provider returns valid JSON for each task; the model you choose follows the JSON instruction (some models wrap or truncate).
- [ ] A provider that rejects `temperature` works through the automatic retry.
- [ ] Arabic page: the AI answers in Arabic, titles/descriptions/rewrites are in Arabic, notes follow the admin locale.
- [ ] The text shown under "Show exactly what will be sent" matches the provider's request log.
- [ ] Wrong key, wrong model, wrong base URL, and an unreachable host each show a helpful message with no key in it.
- [ ] After hitting the hourly limit, further requests are refused before being sent.

**SEO plugins (only simulated through a filter and post meta)**
- [ ] Yoast SEO active: detection, owner label, reading its title/description/focus keyword, writing a description after confirmation, **then confirm the front-end output updates** (Yoast may need the post saved once to refresh its index; the Live check should show the match). Rollback restores it.
- [ ] Rank Math active: same checks (`rank_math_title`, `rank_math_description`).
- [ ] All in One SEO active: values are read (**column names `title`/`description` in `aioseo_posts` are unverified**); applying is refused with the "copy manually" message.
- [ ] Two SEO plugins active: conflict notice appears; Live check warns about duplicate tags.
- [ ] No SEO plugin: SEO Pilot's own meta description and SEO title appear in the page source exactly once. Check with several themes, including a block theme, a theme without `title-tag` support, and a theme that prints its own description.

**Editor and content**
- [ ] After applying a paragraph rewrite to a Gutenberg post, open it in the block editor: no "unexpected or invalid content" warning, formatting intact. Test paragraphs inside columns/groups, with inline links and with custom classes.
- [ ] After adding alt text, the image block still validates.
- [ ] Classic-editor posts and posts with shortcodes.
- [ ] Page-builder pages (Elementor/Divi): the audit says the stored content is empty/short and explains why; nothing is changed.
- [ ] Revisions disabled (`WP_POST_REVISIONS` false): the apply panel says rollback relies on SEO Pilot's stored copy; rollback works.
- [ ] A scheduled (future) post and a private post are treated as "live" and need confirmation.

**Safety**
- [ ] Applying changes never alters slug, publish date, status, canonical, robots or structured data (compare page source and the post screen before/after).
- [ ] Draft-copy mode: the live page and its SEO fields are untouched; the draft contains the approved paragraph and SEO fields.
- [ ] Edit a post in another browser tab between "Accept" and "Apply": the stale suggestion is refused and your edit is kept.
- [ ] Roll back after editing the post by hand: rollback stops and names the conflicting field; "restore anyway" restores.
- [ ] Log in as an editor (no `manage_options`): the SEO Pilot menus and all actions are denied. With `add_filter( 'seo_pilot_capability', fn() => 'edit_others_posts' )`, an editor can work only on posts they may edit.
- [ ] Browser developer tools / page source / network tab: the API key appears nowhere.

**Interface**
- [ ] Arabic admin locale and RTL layout: diff blocks, tables, badges and forms mirror correctly; mixed Arabic/English text displays in the right order (`dir="auto"` is used, but this was only checked in markup, never visually).
- [ ] Keyboard-only use and a screen reader: forms, buttons and the checklist are operable.
- [ ] Narrow screens (the two-column comparison collapses).

**Phase 2: manual items**
- [ ] A real Google Search Console export (Pages report, and a Queries report filtered to one page) imports; check the counts and that the numbers shown match the file. Exports from an interface language other than English are rejected with a clear message.
- [ ] **Uploading through the browser:** the upload handler's success path (`is_uploaded_file`, PHP upload limits) cannot run from the command line and was not executed; only its rejection paths were tested. Try a 1 MB, a 5 MB and a too-large file.
- [ ] A 20,000-row import completes within the host's time limit.
- [ ] Similar-content detection on a real site: no false alarms on pages that share a template/footer, and it finds real near-duplicates. Check a site with 300+ pages for audit time (the first audit builds a sketch per page).
- [ ] Internal links applied in the block editor: no "invalid content" warning; links inside columns, lists-adjacent paragraphs, and paragraphs with formatting.
- [ ] *Analyse related pages* on a large site (hundreds of pages) finishes in time.
- [ ] Report CSV opens correctly in Excel and Google Sheets with Arabic text, and no cell executes as a formula. (The download itself streams from the browser; automated tests covered the CSV builder and the permission/nonce guards, not the file download.)
- [ ] Arabic site: related-page and link suggestions behave sensibly given exact-phrase matching.

## 3. Manual test cases (quick script)
1. Activate → Settings → enter endpoint/model/key and tick the confirmation → Run audit.
2. Open a post with no meta description → finding "No meta description" with what/why/action → Generate (SEO title + meta) → proposals appear, page unchanged.
3. Edit the description proposal, **Accept**, open "Apply accepted changes": diff shown, confirmation required → tick → Apply → view source: one description tag → Check live output: all green.
4. History shows who/when/what → Roll back → description gone → Roll back again: refused.
5. Repeat with a paragraph rewrite; edit the paragraph manually first and watch it become "stale".
6. Arabic post: set a primary keyword in Arabic; confirm keyword checks find it through attached prefixes (e.g. "والتحسين"); Arabic title of ~45 characters is not called too long.
7. Set another SEO plugin active and repeat step 3: a second confirmation appears for that plugin's field.
