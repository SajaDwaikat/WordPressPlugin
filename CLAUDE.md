# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

SEO Pilot: a WordPress plugin (v0.3.0, PHP 7.4+, WP 6.0+) for local-first SEO audits plus an approval-based, AI-assisted content optimizer. There is no Composer, npm or build step; the plugin is plain PHP loaded by WordPress. Full detail is in `docs/ARCHITECTURE.md` (data model, safety design, known limitations) and `docs/TESTING.md`.

## Commands

```bash
# Unit tests: pure logic, no WordPress needed
php tests/run-unit.php

# Integration tests: need a real WordPress. Use a THROWAWAY site only: tests create posts,
# and the last one (test-99-uninstall.php) runs the uninstall routine with data deletion.
SEO_PILOT_WP_LOAD=/path/to/wp-load.php php tests/integration/run.php

# Syntax check a file
php -l includes/audit/class-seo-pilot-audit.php

# Security-focused coding-standards check (what the project actually keeps clean)
phpcs --standard=WordPress-Extra --sniffs=WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery,WordPress.WP.I18n .
```

`tests/run-unit.php` and `tests/integration/run.php` run whole suites; there is no single-test flag. To run one file, look at how the runner includes the `test-*.php` files and invoke it the same way. The live-output integration tests need the site reachable at its own `home_url()` and the mu-plugin `tests/integration/fixtures/sp-test-dup.php` copied into the test site's `wp-content/mu-plugins/`.

## Architecture

- **Bootstrap:** `seo-pilot.php` defines constants, registers `SEO_Pilot_Loader` (a hand-maintained class-map autoloader), and boots `SEO_Pilot::instance()` on `plugins_loaded`. **Every new class must be added to the `$map` in `includes/class-seo-pilot-loader.php`**, or it will not autoload.
- **Module layout under `includes/`:** `core/` (Settings, Installer, Util, Lang = language model), `audit/` (Text, Parser, Audit engine, Title (detailed title analysis), Images (alt-text analysis), Hreflang (language codes and tags), Fixes (Fix assistant catalog), Verify (before/after audit check), Audit_Runner, Diff, Live_Check, Similarity, Gap, Links, Report), `intent/`, `ai/` (AI_Client, Prompt, Validator, Rate_Limiter, Alt_AI for opt-in vision alt-text drafts), `compat/` (Yoast / Rank Math / AIOSEO field ownership), `storage/` (Recs, History, Searchdata repositories), `revisions/` (Approval), `admin/` (Admin, Admin_Data, Workspace = form handlers, Workspace_View = screens).
- **Pure vs. WordPress-bound code:** `Text`, `Parser` (classic mode), `Diff`, `Intent`, `Audit`, and `Live_Check::parse/notes` must stay WordPress-free so the unit tests can load them without WP. Put anything touching `$wpdb`, post meta or hooks in the WP-bound classes.
- **Recommendation lifecycle:** AI generation writes only to the `seo_pilot_recs` table and never touches a post. `SEO_Pilot_Approval::plan()` computes the exact changes, `apply()` performs them (saving a WP revision and a history row per changed field), and rollback is conflict-aware. The audit re-runs after every apply and rollback.
- **Safety invariants to preserve when editing `Approval`/`Recs`/`History`:** writes are whitelisted by destination (`post_content`, `post_title` for legacy rollback, six documented SEO meta keys); paragraph edits locate the original by hash of its inner HTML and refuse (marking the rec `stale`) if it changed or is duplicated; live posts and third-party SEO plugin fields each require their own confirmation flag; a forged history row must not be able to redirect a rollback write.
- **Data:** custom tables `{prefix}seo_pilot_recs`, `_history`, `_searchdata` (schema version in `SEO_PILOT_DB_VERSION`, migrations in `Installer`), per-page post meta prefixed `_seo_pilot_`, and options `seo_pilot_settings` (no secrets) and `seo_pilot_secret` (encrypted API key, autoload off, never printed or logged).
- **No invented metrics:** search volume, keyword difficulty, rankings and CTR are never estimated. Real numbers come only from an imported Search Console CSV (English headers). Keep that rule when adding features.
- **Arabic/RTL is first-class:** `SEO_Pilot_Text` handles Arabic prefixes and length thresholds; intent estimation and keyword matching must keep working for Arabic. Admin markup uses `dir="auto"`.
- **Code style:** 4-space indentation (not WordPress tabs), `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top of each file, class names `SEO_Pilot_*` with files named `class-seo-pilot-*.php`.
